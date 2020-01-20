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

class UnlinkTask extends AbstractTask {

    public function execute() {
        $share = $this->share;
        $full_path = $this->full_path;

        $landing_zone = get_share_landing_zone($share);
        if (!$landing_zone) {
            return TRUE;
        }

        if (should_ignore_file($share, $full_path)) {
            return TRUE;
        }

        Log::info("File deleted: $landing_zone/$full_path");

        if (array_contains(ConfigHelper::$trash_share_names, $share)) {
            // Will delete the file in the trash which has no corresponding symlink in the Greyhole Trash share.
            // That symlink is what was deleted from that share to create the task we're currently working on.
            $full_path = preg_replace('/ copy [0-9]+$/', '', $full_path);
            Log::debug("  Looking for corresponding file in trash to delete...");
            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (file_exists("$sp_drive/.gh_trash/$full_path")) {
                    $delete = TRUE;

                    $trash_share = SharesConfig::getConfigForShare(CONFIG_TRASH_SHARE);
                    if ($trash_share) {
                        list($path, ) = explode_full_path("{$trash_share[CONFIG_LANDING_ZONE]}/$full_path");
                        if ($dh = @opendir($path)) {
                            while (($file = readdir($dh)) !== FALSE) {
                                if ($file == '.' || $file == '..') { continue; }
                                if (is_link("$path/$file") && readlink("$path/$file") == "$sp_drive/.gh_trash/$full_path") {
                                    $delete = FALSE;
                                    continue;
                                }
                            }
                        }
                    }

                    if ($delete) {
                        Log::debug("    Deleting corresponding copy $sp_drive/.gh_trash/$full_path");
                        unlink("$sp_drive/.gh_trash/$full_path");
                        break;
                    }
                }
            }
            return TRUE;
        }

        if (gh_file_exists("$landing_zone/$full_path") && !is_dir("$landing_zone/$full_path")) {
            Log::debug("  File still exists in landing zone; a new file replaced the one deleted here. Skipping.");
            return TRUE;
        }

        list($path, $filename) = explode_full_path($full_path);

        foreach (get_metafiles($share, $path, $filename, TRUE) as $existing_metafiles) {
            foreach ($existing_metafiles as $metafile) {
                gh_recycle($metafile->path);
            }
        }
        remove_metafiles($share, $path, $filename);

        FileHook::trigger(FileHook::EVENT_TYPE_DELETE, $share, $full_path);

        return TRUE;
    }

}

?>
