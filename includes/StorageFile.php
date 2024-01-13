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

final class StorageFile {

    public static function create_file_copies_from_metafiles($metafiles, $share, $full_path, $source_file, $missing_only = FALSE) {
        $landing_zone = get_share_landing_zone($share);

        list($path, $filename) = explode_full_path($full_path);

        $source_file = clean_dir($source_file);

        $file_copies_to_create = [];
        foreach ($metafiles as $key => $metafile) {
            if (!Log::actionIs(ACTION_CP) && !gh_file_exists("$landing_zone/$full_path", '  $real_path doesn\'t exist anymore. Aborting.')) {
                return FALSE;
            }

            if ($metafile->path == $source_file && $metafile->state == Metafile::STATE_OK && gh_filesize($metafile->path) == gh_filesize($source_file)) {
                Log::debug("  File copy at $metafile->path is already up to date.");
                continue;
            }

            if ($missing_only && gh_file_exists($metafile->path) && $metafile->state == Metafile::STATE_OK && gh_filesize($metafile->path) == gh_filesize($source_file)) {
                Log::debug("  File copy at $metafile->path is already up to date.");
                continue;
            }

            $root_path = str_replace(clean_dir("/$share/$full_path"), '', $metafile->path);
            if (!StoragePool::is_pool_drive($root_path)) {
                Log::warn("  Warning! It seems the partition UUID of $root_path changed. This probably means this mount is currently unmounted, or that you replaced this drive and didn't use 'greyhole --replaced'. Because of that, Greyhole will NOT use this drive at this time.", Log::EVENT_CODE_STORAGE_POOL_DRIVE_UUID_CHANGED);
                $metafile->state = Metafile::STATE_GONE;
                $metafiles[$key] = $metafile;
                continue;
            }

            list($metafile_dir_path, ) = explode_full_path($metafile->path);

            list($original_path, ) = explode_full_path(get_share_landing_zone($share) . "/$full_path");
            if (!gh_mkdir($metafile_dir_path, $original_path)) {
                $metafile->state = Metafile::STATE_GONE;
                $metafiles[$key] = $metafile;
                continue;
            }

            $file_copies_to_create[$key] = $metafile;
        }

        $create_copies_in_parallel = count($file_copies_to_create) > 1 && !DBSpool::isCurrentTaskRetry() && Config::get(CONFIG_PARALLEL_COPYING);

        if ($create_copies_in_parallel) {
            // Create all file copies simultaneously
            $copy_results = static::create_file_copies($source_file, $file_copies_to_create);
        } else {
            // Will copy each file one by one below
            $copy_results = [];
        }

        foreach ($file_copies_to_create as $key => $metafile) {
            // Create a file copy, if parallel copying failed (for this file copy), or is disabled
            $need_create_copy = empty($copy_results[$key]);
            if ($need_create_copy) {
                $copy_results[$key] = static::create_file_copy($source_file, $metafile->path);
            }
        }

        $link_next = FALSE;
        foreach ($file_copies_to_create as $key => $metafile) {
            $it_worked = !empty($copy_results[$key]);

            if (!$it_worked) {
                if ($metafile->is_linked) {
                    $metafile->is_linked = FALSE;
                    $link_next = TRUE;
                    if (@readlink("$landing_zone/$full_path") == $metafile->path) {
                        // Symlink in landing zone is pointing to this file copy; we need to remove it, otherwise, we'd end up with a broken symlink after Trash::trash_file()
                        Log::debug("  Deleting symlink from landing zone, before recycling the file copy it points to.");
                        unlink("$landing_zone/$full_path");
                    }
                }
                $metafile->state = Metafile::STATE_GONE;
                Trash::trash_file($metafile->path);
                $metafiles[$key] = $metafile;
                Metastores::save_metafiles($share, $path, $filename, $metafiles);

                if (file_exists("$landing_zone/$full_path")) {
                    if (DBSpool::isCurrentTaskRetry()) {
                        Log::error("    Failed file copy (cont). We already retried this task. Aborting.", Log::EVENT_CODE_FILE_COPY_FAILED);
                        return FALSE;
                    }
                    Log::warn("    Failed file copy (cont). Will try to re-process this write task, since the source file seems intact.", Log::EVENT_CODE_FILE_COPY_FAILED);
                    // Queue a new write task, to replace the now gone copy.
                    DBSpool::setNextTask(
                        (object) array(
                            'id' => 0,
                            'action' => 'write',
                            'share' => $share,
                            'full_path' => clean_dir($full_path),
                            'complete' => 'yes'
                        )
                    );
                    return FALSE;
                }
                continue;
            }

            if ($link_next && !$metafile->is_linked) {
                $metafile->is_linked = TRUE;
            }
            $link_next = FALSE;
            if ($metafile->is_linked) {
                Log::debug("  Creating symlink in share pointing to $metafile->path");
                if (!is_dir("$landing_zone/$path/")) {
                    gh_mkdir("$landing_zone/$path/", dirname($source_file));
                }
                gh_symlink($metafile->path, "$landing_zone/$path/.gh_$filename");
                if (!file_exists("$landing_zone/$full_path") || unlink("$landing_zone/$full_path")) {
                    gh_rename("$landing_zone/$path/.gh_$filename", "$landing_zone/$path/$filename");
                } else {
                    unlink("$landing_zone/$path/.gh_$filename");
                }
            }

            if (gh_file_exists($metafile->path, '  Copy at $real_path doesn\'t exist. Will not mark it OK!')) {
                $metafile->state = Metafile::STATE_OK;
            }
            $metafiles[$key] = $metafile;
            if (!$create_copies_in_parallel) {
                Metastores::save_metafiles($share, $path, $filename, $metafiles);
            }
        }
        if ($create_copies_in_parallel) {
            Metastores::save_metafiles($share, $path, $filename, $metafiles);
        }
        return TRUE;
    }

    public static function create_file_copies($source_file, &$metafiles) {
        $copy_results = [];

        $copy_source = is_link($source_file) ? readlink($source_file) : $source_file;
        $source_size = gh_filesize($copy_source);
        $original_file_infos = StorageFile::get_file_permissions($copy_source);

        $file_copies_to_create = [];
        foreach ($metafiles as $key => $metafile) {
            $destination_file = $metafile->path;
            if (gh_is_file($source_file)) {
                if ($source_file == $destination_file) {
                    Log::debug("  Destination $destination_file is the same as the source. Nothing to do here; this file copy is ready!");
                    $copy_results[$key] = TRUE;
                    continue;
                }

                $source_dev = gh_file_deviceid($source_file);
                $target_dev = gh_file_deviceid(dirname($destination_file));
                if ($source_dev === $target_dev && $source_dev !== FALSE && !Config::get(CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE)) {
                    Log::debug("  Destination $destination_file is on the same drive as the source. Will be moved into storage pool drive later.");
                    $copy_results[$key] = FALSE;
                    continue;
                }
            }

            $temp_path = static::get_temp_filename($destination_file);

            $file_copies_to_create[] = $temp_path;
        }

        if (isset($source_size)) {
            Log::info("  Copying " . bytes_to_human($source_size, FALSE) . " file to: " . implode(', ', $file_copies_to_create));
        } else {
            Log::info("  Copying file to: " . implode(', ', $file_copies_to_create));
        }

        $start_time = time();
        if (!empty($file_copies_to_create)) {
            $copy_cmd = "cat " . escapeshellarg($copy_source) . " | tee " . implode(' ' , array_map('escapeshellarg', $file_copies_to_create));
            if (Config::get(CONFIG_CALCULATE_MD5_DURING_COPY)) {
                $copy_cmd .= " | md5sum";
            }
            //Log::debug("  Executing copy command: $copy_cmd");
            $out = exec($copy_cmd);
            if (Config::get(CONFIG_CALCULATE_MD5_DURING_COPY)) {
                $md5 = first(explode(' ', $out));
                Log::debug("    Copied file MD5 = $md5");
            }
        }

        $first = TRUE;
        foreach ($metafiles as $key => $metafile) {
            $destination_file = $metafile->path;
            $temp_path = static::get_temp_filename($destination_file);
            if (!array_contains($file_copies_to_create, $temp_path)) {
                continue;
            }

            $it_worked = file_exists($temp_path) && file_exists($source_file) && gh_filesize($temp_path) == $source_size;
            if (!$it_worked) {
                // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
                $it_worked = file_exists(normalize_utf8_characters($temp_path)) && file_exists($source_file) && gh_filesize($temp_path) == $source_size;
                if ($it_worked) {
                    // Bingo!
                    $temp_path = normalize_utf8_characters($temp_path);
                    $destination_file = normalize_utf8_characters($destination_file);
                    $metafile->path = $destination_file;
                    $metafiles[$key] = $metafile;
                }
            }
            $copy_results[$key] = $it_worked;
            if ($it_worked) {
                if ($first) {
                    if (time() - $start_time > 0) {
                        $speed = number_format($source_size/1024/1024 / (time() - $start_time), 1);
                        Log::debug("    Copy created at $speed MBps.");
                    }
                    if (!empty($md5)) {
                        list($share, $full_path) = get_share_and_fullpath_from_realpath($copy_source);
                        log_file_checksum($share, $full_path, $md5);
                    }
                    $first = FALSE;
                }
                gh_rename($temp_path, $destination_file);
                static::set_file_permissions($destination_file, $original_file_infos);
            } else {
                Log::warn("    Failed file copy. Will mark this metadata file 'Gone'.", Log::EVENT_CODE_FILE_COPY_FAILED);
                // Remove the failed copy, if any.
                @unlink($temp_path);
            }
        }

        return $copy_results;
    }

    public static function create_file_copy($source_file, &$destination_file, $expected_md5 = NULL, &$error = NULL) {
        if (gh_is_file($source_file) && $source_file == $destination_file) {
            Log::debug("  Destination $destination_file is the same as the source. Nothing to do here; this file copy is ready!");
            return TRUE;
        }

        $start_time = time();
        $source_size = gh_filesize($source_file);
        $temp_path = static::get_temp_filename($destination_file);

        if (is_link($source_file)) {
            $link_target = readlink($source_file);
            $source_size = gh_filesize($link_target);
        } else if (gh_is_file($source_file)) {
            $source_size = gh_filesize($source_file);
        }

        if (isset($source_size)) {
            Log::info("  Copying " . bytes_to_human($source_size, FALSE) . " file to $destination_file");
        } else {
            Log::info("  Copying file to $destination_file");
        }

        $renamed = FALSE;
        if (gh_is_file($source_file)) {
            $source_dev = gh_file_deviceid($source_file);
            $target_dev = gh_file_deviceid(dirname($destination_file));
            if ($source_dev === $target_dev && $source_dev !== FALSE && !Config::get(CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE) && !Log::actionIs(ACTION_CP)) {
                Log::debug("  (using rename)");
                $original_file_infos = StorageFile::get_file_permissions($source_file);
                gh_rename($source_file, $temp_path);
                $renamed = TRUE;
            }
        }

        if (!$renamed) {
            // Wasn't renamed; need to be copied.
            $copy_source = is_link($source_file) ? readlink($source_file) : $source_file;
            $original_file_infos = StorageFile::get_file_permissions($copy_source);
            $copy_cmd = "cat " . escapeshellarg($copy_source) . " | tee " . escapeshellarg($temp_path);
            if (Config::get(CONFIG_CALCULATE_MD5_DURING_COPY) || !empty($expected_md5)) {
                $copy_cmd .= " | md5sum";
            }
            $out = exec($copy_cmd);
            if (Config::get(CONFIG_CALCULATE_MD5_DURING_COPY) || !empty($expected_md5)) {
                $md5 = first(explode(' ', $out));
                Log::debug("    Copied file MD5 = $md5");

                if (!empty($expected_md5)) {
                    if ($md5 != $expected_md5) {
                        Log::warn("    MD5 mismatch (expected $expected_md5). Failed file copy. Will mark this metadata file 'Gone'.", Log::EVENT_CODE_FILE_COPY_FAILED);
                        $error = "MD5 mismatch: expected $expected_md5, got $md5";
                        return FALSE;
                    } else {
                        Log::debug("    MD5 match expected value.");
                    }
                }
            }
        }

        $it_worked = file_exists($temp_path) && ($renamed || file_exists($source_file)) && gh_filesize($temp_path) == $source_size;
        if (!$it_worked) {
            // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
            $it_worked = file_exists(normalize_utf8_characters($temp_path)) && ($renamed || file_exists($source_file)) && gh_filesize($temp_path) == $source_size;
            if ($it_worked) {
                // Bingo!
                $temp_path = normalize_utf8_characters($temp_path);
                $destination_file = normalize_utf8_characters($destination_file);
            }
        }
        if ($it_worked) {
            if (time() - $start_time > 0) {
                $speed = number_format($source_size/1024/1024 / (time() - $start_time), 1);
                Log::debug("    Copy created at $speed MBps.");
            }
            gh_rename($temp_path, $destination_file);
            /** @noinspection PhpUndefinedVariableInspection */
            static::set_file_permissions($destination_file, $original_file_infos);
            if (!empty($md5)) {
                /** @noinspection PhpUndefinedVariableInspection */
                list($share, $full_path) = get_share_and_fullpath_from_realpath($destination_file);
                log_file_checksum($share, $full_path, $md5);
            }
        } else {
            if (!file_exists($temp_path)) {
                $error = "target file $temp_path doesn't exists";
            } elseif (gh_filesize($temp_path) != $source_size) {
                $error = "target filesize " . gh_filesize($temp_path) ." != source filesize $source_size";
            } else {
                $error = '?';
            }
            @Log::warn("    Failed file copy (failed check: $error). Will mark this metadata file 'Gone'.", Log::EVENT_CODE_FILE_COPY_FAILED);
            if ($renamed) {
                // Do NOT delete $temp_path if the file was renamed... Just move it back!
                gh_rename($temp_path, $source_file);
            } else {
                // Remove the failed copy, if any.
                @unlink($temp_path);
            }
        }
        return $it_worked;
    }

    public static function get_temp_filename($full_path) {
        list($path, $filename) = explode_full_path($full_path);
        return "$path/.$filename." . mb_substr(md5($filename), 0, 5);
    }

    public static function is_temp_file($full_path) {
        list(, $filename) = explode_full_path($full_path);
        if (preg_match("/^\.(.+)\.([0-9a-f]{5})$/", $filename, $regs)) {
            $md5_stem = mb_substr(md5($regs[1]), 0, 5);
            return ($md5_stem == $regs[2]);
        }
        return FALSE;
    }

    public static function set_file_permissions($real_file_path, $file_infos) {
        chmod($real_file_path, $file_infos->fileperms);
        chown($real_file_path, $file_infos->fileowner);
        chgrp($real_file_path, $file_infos->filegroup);
        touch($real_file_path, $file_infos->filemtime, time());
    }

    public static function get_file_permissions($real_path) {
        if ($real_path == null || !file_exists($real_path)) {
            return (object) array(
                'fileowner' => 0,
                'filegroup' => 0,
                'fileperms' => (int) base_convert("0777", 8, 10),
                'filemtime' => time()
            );
        }
        if (is_link($real_path)) {
            $real_path = readlink($real_path);
        }
        return (object) array(
            'fileowner' => (int) gh_fileowner($real_path),
            'filegroup' => (int) gh_filegroup($real_path),
            'fileperms' => (int) base_convert(gh_fileperms($real_path), 8, 10),
            'filemtime' => filemtime($real_path),
        );
    }

}

?>
