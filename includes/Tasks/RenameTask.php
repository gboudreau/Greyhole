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

class RenameTask extends AbstractTask {

    private $fix_symlinks_scanned_dirs = [];

    public function execute() {
        $this->fix_symlinks_scanned_dirs = [];

        $share = $this->share;
        $full_path = $this->full_path;
        $target_full_path = $this->additional_info;
        $task_id = $this->id;

        $landing_zone = get_share_landing_zone($share);
        if (!$landing_zone) {
            return TRUE;
        }

        if ($this->should_ignore_file()) {
            return TRUE;
        }

        if (is_dir("$landing_zone/$target_full_path") || Metastores::dir_exists_in_metastores($share, $full_path)) {
            Log::info("Directory renamed: $landing_zone/$full_path -> $landing_zone/$target_full_path");

            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (!StoragePool::is_pool_drive($sp_drive)) {
                    continue;
                }
                list($original_path, ) = explode_full_path(get_share_landing_zone($share) . "/$target_full_path");

                if (is_dir("$sp_drive/$share/$full_path")) {
                    // Make sure the parent directory of target_full_path exists, before we try moving something there...
                    list($path, ) = explode_full_path("$sp_drive/$share/$target_full_path");
                    gh_mkdir($path, $original_path);

                    gh_rename("$sp_drive/$share/$full_path", "$sp_drive/$share/$target_full_path");

                    // Make sure all the copies of this folder have the right owner & permissions
                    $dir_permissions = StorageFile::get_file_permissions("$landing_zone/$target_full_path");
                    chown("$sp_drive/$share/$target_full_path", $dir_permissions->fileowner);
                    chgrp("$sp_drive/$share/$target_full_path", $dir_permissions->filegroup);
                    chmod("$sp_drive/$share/$target_full_path", $dir_permissions->fileperms);

                    Log::debug("  Directory moved: $sp_drive/$share/$full_path -> $sp_drive/$share/$target_full_path");
                }

                list($path, ) = explode_full_path("$sp_drive/" . Metastores::METASTORE_DIR . "/$share/$target_full_path");
                gh_mkdir($path, $original_path);
                $result = @gh_rename("$sp_drive/" . Metastores::METASTORE_DIR . "/$share/$full_path", "$sp_drive/" . Metastores::METASTORE_DIR . "/$share/$target_full_path");
                if ($result) {
                    Log::debug("  Metadata Store directory moved: $sp_drive/" . Metastores::METASTORE_DIR ."/$share/$full_path -> $sp_drive/" . Metastores::METASTORE_DIR . "/$share/$target_full_path");
                }
                $result = @gh_rename("$sp_drive/" . Metastores::METASTORE_BACKUP_DIR . "/$share/$full_path", "$sp_drive/" . Metastores::METASTORE_BACKUP_DIR . "/$share/$target_full_path");
                if ($result) {
                    Log::debug("  Backup Metadata Store directory moved: $sp_drive/" . Metastores::METASTORE_BACKUP_DIR . "/$share/$full_path -> $sp_drive/" . Metastores::METASTORE_BACKUP_DIR . "/$share/$target_full_path");
                }
            }

            // Let look in the LZ too, for files we didn't process yet (maybe the folder was ignored before it was renamed)
            exec('find ' . escapeshellarg("$landing_zone/$target_full_path") . ' -type f', $files_in_lz);
            foreach ($files_in_lz as $file_in_lz) {
                list($file_path, $filename) = explode_full_path($file_in_lz);
                FsckTask::getCurrentTask()->gh_fsck_file($file_path, $filename, 'file', 'landing_zone', $share);
            }

            foreach (Metastores::get_metafiles($share, $target_full_path, null, FALSE, FALSE, FALSE) as $existing_metafiles) {
                Log::debug("Existing metadata files: " . count($existing_metafiles));
                foreach ($existing_metafiles as $file_path => $file_metafiles) {
                    Log::debug("  File metafiles: " . count($file_metafiles));
                    $new_file_metafiles = array();
                    $symlinked = FALSE;
                    foreach ($file_metafiles as $key => $metafile) {
                        $old_path = $metafile->path;
                        $metafile->path = str_replace("/$share/$full_path/$file_path", "/$share/$target_full_path/$file_path", $metafile->path);
                        Log::debug("  Changing metadata file: $old_path -> $metafile->path");
                        $new_file_metafiles[$metafile->path] = $metafile;

                        // is_linked = is the target of the existing symlink
                        if ($metafile->is_linked) {
                            $symlinked = TRUE;
                            $symlink_target = $metafile->path;
                        }
                    }
                    if (!$symlinked && count($file_metafiles) > 0) {
                        // None of the metafiles were is_linked; use the last one for the symlink.
                        /** @noinspection PhpUndefinedVariableInspection */
                        $metafile->is_linked = TRUE;
                        /** @noinspection PhpUndefinedVariableInspection */
                        $file_metafiles[$key] = $metafile;
                        $symlink_target = $metafile->path;
                    }

                    if (is_link("$landing_zone/$target_full_path/$file_path") && !empty($symlink_target) && readlink("$landing_zone/$target_full_path/$file_path") != $symlink_target) {
                        Log::debug("  Updating symlink at $landing_zone/$target_full_path/$file_path to point to $symlink_target");
                        unlink("$landing_zone/$target_full_path/$file_path");
                        gh_symlink($symlink_target, "$landing_zone/$target_full_path/$file_path");
                    } else if (is_link("$landing_zone/$full_path/$file_path") && !empty($symlink_target) && !file_exists(readlink("$landing_zone/$full_path/$file_path"))) {
                        Log::debug("  Updating symlink at $landing_zone/$full_path/$file_path to point to $symlink_target");
                        unlink("$landing_zone/$full_path/$file_path");
                        gh_symlink($symlink_target, "$landing_zone/$full_path/$file_path");
                    } else {
                        $this->fix_symlinks($landing_zone, $share, "$full_path/$file_path", "$target_full_path/$file_path");
                    }

                    list($path, $filename) = explode_full_path("$target_full_path/$file_path");
                    Metastores::save_metafiles($share, $path, $filename, $new_file_metafiles);
                }
            }
        } else {
            Log::info("File renamed: $landing_zone/$full_path -> $landing_zone/$target_full_path");

            // Check if another process locked this file before we work on it.
            if ($this->is_file_locked($share, $target_full_path)) {
                return FALSE;
            }

            list($path, $filename) = explode_full_path($full_path);
            list($target_path, $target_filename) = explode_full_path($target_full_path);

            foreach (Metastores::get_metafiles($share, $path, $filename, FALSE, FALSE, FALSE) as $existing_metafiles) {
                // There might be old metafiles... for example, when a delete task was skipped.
                // Let's remove the file copies if there are any leftovers; correct copies will be re-created below.
                if (file_exists("$landing_zone/$target_full_path") && (count($existing_metafiles) > 0 || !is_link("$landing_zone/$target_full_path"))) {
                    foreach (Metastores::get_metafiles($share, $target_path, $target_filename, TRUE, FALSE, FALSE) as $existing_target_metafiles) {
                        if (count($existing_target_metafiles) > 0) {
                            foreach ($existing_target_metafiles as $metafile) {
                                Trash::trash_file($metafile->path);
                            }
                            Metastores::remove_metafiles($share, $target_path, $target_filename);
                        }
                    }
                }

                if (count($existing_metafiles) == 0) {
                    // Any NOK metafiles that need to be removed?
                    foreach (Metastores::get_metafiles($share, $path, $filename, TRUE, FALSE, FALSE) as $all_existing_metafiles) {
                        if (count($all_existing_metafiles) > 0) {
                            Metastores::remove_metafiles($share, $path, $filename);
                        }
                    }
                    // New file
                    AbstractTask::instantiate(['id' => $task_id, 'action' => 'write', 'share' => $share, 'full_path' => $target_full_path, 'complete' => 'yes'])->execute();
                } else {
                    $symlinked = FALSE;
                    foreach ($existing_metafiles as $key => $metafile) {
                        $old_path = $metafile->path;
                        $metafile->path = str_replace("/$share/$full_path", "/$share/$target_full_path", $old_path);
                        Log::debug("  Renaming copy at $old_path to $metafile->path");

                        // Make sure the target directory exists
                        list($metafile_dir_path, ) = explode_full_path($metafile->path);
                        list($original_path, ) = explode_full_path(get_share_landing_zone($share) . "/$target_full_path");
                        gh_mkdir($metafile_dir_path, $original_path);

                        $it_worked = gh_rename($old_path, $metafile->path);

                        if ($it_worked) {
                            // is_linked = is the target of the existing symlink
                            if ($metafile->is_linked) {
                                $symlinked = TRUE;
                                $symlink_target = $metafile->path;
                            }
                        } else {
                            Log::warn("    Warning! An error occurred while renaming file copy $old_path to $metafile->path.", Log::EVENT_CODE_RENAME_FILE_COPY_FAILED);
                        }
                        $existing_metafiles[$key] = $metafile;
                    }
                    if (!$symlinked && count($existing_metafiles) > 0) {
                        // None of the metafiles were is_linked; use the last one for the symlink.
                        /** @noinspection PhpUndefinedVariableInspection */
                        $metafile->is_linked = TRUE;
                        /** @noinspection PhpUndefinedVariableInspection */
                        $existing_metafiles[$key] = $metafile;
                        $symlink_target = $metafile->path;
                    }
                    Metastores::remove_metafiles($share, $path, $filename);
                    Metastores::save_metafiles($share, $target_path, $target_filename, $existing_metafiles);

                    if (is_link("$landing_zone/$target_full_path")) {
                        // New link exists...
                        /** @noinspection PhpUndefinedVariableInspection */
                        if (readlink("$landing_zone/$target_full_path") != $symlink_target) {
                            // ...and needs to be updated.
                            Log::debug("  Updating symlink at $landing_zone/$target_full_path to point to $symlink_target");
                            unlink("$landing_zone/$target_full_path");
                            gh_symlink($symlink_target, "$landing_zone/$target_full_path");
                        }
                    } else if (is_link("$landing_zone/$full_path") && !file_exists(readlink("$landing_zone/$full_path"))) {
                        /** @noinspection PhpUndefinedVariableInspection */
                        Log::debug("  Updating symlink at $landing_zone/$full_path to point to $symlink_target");
                        unlink("$landing_zone/$full_path");
                        gh_symlink($symlink_target, "$landing_zone/$full_path");
                    } else {
                        $this->fix_symlinks($landing_zone, $share, $full_path, $target_full_path);
                    }
                }
            }
        }
        DBSpool::resetSleepingTasks();

        FileHook::trigger(FileHook::EVENT_TYPE_RENAME, $share, $target_full_path, $full_path);

        return TRUE;
    }

    public function should_ignore_file($share = NULL, $full_path = NULL) {
        // We ignore this task, if the target is ignored, whatever the source ($this->full_path) is
        if (empty($full_path)) {
            $full_path = $this->additional_info;
        }
        return parent::should_ignore_file($share, $full_path);
    }

    private function fix_symlinks($landing_zone, $share, $full_path, $target_full_path) {
        if (isset($this->fix_symlinks_scanned_dirs[$landing_zone])) {
            return;
        }
        Log::info("  Scanning $landing_zone for broken links... This can take a while!");
        exec("find -L " . escapeshellarg($landing_zone) . " -type l", $broken_links);
        Log::debug("    Found " . count($broken_links) . " broken links.");
        foreach ($broken_links as $broken_link) {
            $fixed_link_target = readlink($broken_link);
            Log::debug("    Found a broken symlink to update: $broken_link. Broken target: $fixed_link_target");
            foreach (Config::storagePoolDrives() as $sp_drive) {
                $fixed_link_target = str_replace(clean_dir("$sp_drive/$share/$full_path/"), clean_dir("$sp_drive/$share/$target_full_path/"), $fixed_link_target);
                if ($fixed_link_target == "$sp_drive/$share/$full_path") {
                    $fixed_link_target = "$sp_drive/$share/$target_full_path";
                    break;
                }
            }
            if (gh_is_file($fixed_link_target)) {
                Log::debug("      New (fixed) target: $fixed_link_target");
                unlink($broken_link);
                gh_symlink($fixed_link_target, $broken_link);
            }
        }
        $this->fix_symlinks_scanned_dirs[$landing_zone] = TRUE;
    }

}

?>
