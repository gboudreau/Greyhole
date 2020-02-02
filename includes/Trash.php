<?php
/*
Copyright 2009-2020 Guillaume Boudreau

This file is part of Greyhole.

Greyhole is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Greyhole is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.
*/

final class Trash {

    public static function trash_file($real_path, $file_was_modified = FALSE) {
        $is_symlink = FALSE;
        clearstatcache();
        if (is_link($real_path)) {
            $is_symlink = TRUE;
        } else if (!file_exists($real_path)) {
            return TRUE;
        }

        $should_move_to_trash = FALSE;
        if (!$is_symlink) {
            $share_options = SharesConfig::getShareOptions($real_path);
            if ($share_options !== FALSE) {
                $full_path = trim($share_options['name'] . "/" . str_replace($share_options[CONFIG_LANDING_ZONE], '', $real_path), '/');
            } else {
                $storage_volume = StoragePool::getDriveFromPath($real_path);
                foreach (Config::storagePoolDrives() as $sp_drive) {
                    if ($sp_drive == $storage_volume) {
                        $trash_path = "$sp_drive/.gh_trash";
                        $full_path = trim(substr($real_path, strlen($sp_drive)), '/');
                        break;
                    }
                }

                /** @noinspection PhpUndefinedVariableInspection */
                $share = mb_substr($full_path, 0, mb_strpos($full_path, '/'));

                if ($file_was_modified) {
                    $should_move_to_trash = SharesConfig::get($share, CONFIG_MODIFIED_MOVES_TO_TRASH);
                } else {
                    $should_move_to_trash = SharesConfig::get($share, CONFIG_DELETE_MOVES_TO_TRASH);
                }
            }
        }

        if ($should_move_to_trash) {
            // Move to trash
            if (!isset($trash_path)) {
                Log::warn("  Warning! Can't find trash for $real_path. Won't delete this file!", Log::EVENT_CODE_TRASH_NOT_FOUND);
                return FALSE;
            }

            /** @noinspection PhpUndefinedVariableInspection */
            $target_path = clean_dir("$trash_path/$full_path");

            list($path, ) = explode_full_path($target_path);

            if (@gh_is_file($path)) {
                unlink($path);
            }

            $dir_infos = (object) array(
                'fileowner' => 0,
                'filegroup' => 0,
                'fileperms' => (int) base_convert("0777", 8, 10)
            );
            gh_mkdir($path, NULL, $dir_infos);

            if (@is_dir($target_path)) {
                exec("rm -rf " . escapeshellarg($target_path));
            }
            if (@gh_rename($real_path, $target_path)) {
                Log::debug("  Moved copy from $real_path to trash: $target_path");

                // Create a symlink in the Greyhole Trash share, to allow the user to remove this file using that share
                static::create_trash_share_symlink($target_path, $trash_path);
                return TRUE;
            }
        } else {
            if (@unlink($real_path)) {
                if (!$is_symlink) {
                    Log::debug("  Deleted copy at $real_path");
                }
                return TRUE;
            }
        }
        return FALSE;
    }

    private static function create_trash_share_symlink($filepath_in_trash, $trash_path) {
        $trash_share = SharesConfig::getConfigForShare(CONFIG_TRASH_SHARE);
        if ($trash_share) {
            $filepath_in_trash = clean_dir($filepath_in_trash);
            $filepath_in_trash_share = str_replace($trash_path, $trash_share[CONFIG_LANDING_ZONE], $filepath_in_trash);
            if (file_exists($filepath_in_trash_share)) {
                $new_filepath = $filepath_in_trash_share;
                $i = 1;
                while (file_exists($new_filepath)) {
                    if (@readlink($new_filepath) == $filepath_in_trash) {
                        // There's already a symlink to that file in the trash share; let's not make a second one!
                        return;
                    }
                    $new_filepath = "$filepath_in_trash_share copy $i";
                    $i++;
                }
                $filepath_in_trash_share = $new_filepath;
                list(, $filename) = explode_full_path($filepath_in_trash_share);
            } else {
                list($original_path, ) = explode_full_path($filepath_in_trash);
                list($path, $filename) = explode_full_path($filepath_in_trash_share);

                $dir_infos = (object) array(
                    'fileowner' => (int) gh_fileowner($original_path),
                    'filegroup' => (int) gh_filegroup($original_path),
                    'fileperms' => (int) base_convert("0777", 8, 10)
                );
                gh_mkdir($path, NULL, $dir_infos);
            }
            if (@gh_symlink($filepath_in_trash, $filepath_in_trash_share)) {
                Log::debug("  Created symlink to deleted file in {$trash_share['name']} share ($filename).");
            } else {
                Log::warn("  Warning! Couldn't create symlink to deleted file in {$trash_share['name']} share ($filename).", Log::EVENT_CODE_TRASH_SYMLINK_FAILED);
            }
        }
    }

}

?>
