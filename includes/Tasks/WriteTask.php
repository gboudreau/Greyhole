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

class WriteTask extends AbstractTask {

    public function execute() {
        $share = $this->share;
        $full_path = $this->full_path;
        $task_id = $this->id;

        $landing_zone = get_share_landing_zone($share);
        if (!$landing_zone) {
            return TRUE;
        }

        if ($this->should_ignore_file()) {
            return TRUE;
        }

        if (!gh_file_exists("$landing_zone/$full_path", '$real_path doesn\'t exist anymore.')) {
            $new_full_path = static::find_future_full_path($share, $full_path, $task_id);
            if ($new_full_path != $full_path && gh_is_file("$landing_zone/$new_full_path")) {
                Log::debug("  Found that $full_path has been renamed to $new_full_path. Will work using that instead.");
                if (is_link("$landing_zone/$new_full_path")) {
                    $source_file = clean_dir(readlink("$landing_zone/$new_full_path"));
                } else {
                    $source_file = clean_dir("$landing_zone/$new_full_path");
                }
            } else {
                Log::info("  Skipping.");
                if (!gh_file_exists($landing_zone, '  Share "' . $share . '" landing zone "$real_path" doesn\'t exist anymore. Will not process this task until it re-appears...')) {
                    DBSpool::getInstance()->postpone_task($task_id);
                }
                return TRUE;
            }
        }

        $num_copies_required = SharesConfig::getNumCopies($share);
        if ($num_copies_required === -1) {
            return TRUE;
        }

        list($path, $filename) = explode_full_path($full_path);

        if ((isset($new_full_path) && is_link("$landing_zone/$new_full_path")) || is_link("$landing_zone/$full_path")) {
            if (!isset($source_file)) {
                $source_file = clean_dir(readlink("$landing_zone/$full_path"));
            }
            clearstatcache();
            $filesize = gh_filesize($source_file);
            if (Log::getLevel() >= Log::DEBUG) {
                Log::info("File changed: $share/$full_path - " . bytes_to_human($filesize, FALSE));
            } else {
                Log::info("File changed: $share/$full_path");
            }
            Log::debug("  Will use source file: $source_file");

            foreach (Metastores::get_metafiles($share, $path, $filename, TRUE) as $existing_metafiles) {
                // Remove old copies (but not the one that was updated!)
                $keys_to_remove = array();
                $found_source_file = FALSE;
                foreach ($existing_metafiles as $key => $metafile) {
                    $metafile->path = clean_dir($metafile->path);
                    if ($metafile->path == $source_file) {
                        $metafile->is_linked = TRUE;
                        $metafile->state = Metafile::STATE_OK;
                        $found_source_file = TRUE;
                    } else {
                        Log::debug("  Will remove copy at $metafile->path");
                        $keys_to_remove[] = $metafile->path;
                    }
                }
                if (!$found_source_file && count($keys_to_remove) > 0) {
                    // This shouldn't happen, but if we're about to remove all copies, let's make sure we keep at least one.
                    $key = array_shift($keys_to_remove);
                    $source_file = $existing_metafiles[$key]->path;
                    Log::debug("  Change of mind... Will use source file: $source_file");
                }
                $new_metafiles = $this->gh_write_process_metafiles($num_copies_required, $existing_metafiles, $share, $full_path, $source_file, $filesize, $task_id, $keys_to_remove);
                if ($new_metafiles === FALSE || $new_metafiles === TRUE) {
                    return $new_metafiles;
                }
                if (!empty($new_metafiles) && !isset($new_metafiles[$source_file])) {
                    // Delete source file; we copied it somewhere else
                    // Let's just make sure we have at least one OK file before we delete it!
                    Log::debug("  Source file is not needed anymore. Deleting...");
                    $is_ok = FALSE;
                    foreach ($new_metafiles as $metafile) {
                        if ($metafile->state == Metafile::STATE_OK) {
                            $is_ok = TRUE;
                            break;
                        }
                    }
                    if ($is_ok) {
                        // Ok, now we're sure.
                        Trash::trash_file($source_file, TRUE);
                    } else {
                        Log::debug("  Change of mind... Couldn't find any OK metadata file... Will keep the source!");
                    }
                }
            }
            FileHook::trigger(FileHook::EVENT_TYPE_EDIT, $share, $full_path);
        } else {
            if (!isset($source_file)) {
                $source_file = clean_dir("$landing_zone/$full_path");
            }
            clearstatcache();
            $filesize = gh_filesize($source_file);
            if (Log::getLevel() >= Log::DEBUG) {
                Log::info("File created: $share/$full_path - " . bytes_to_human($filesize, FALSE));
            } else {
                Log::info("File created: $share/$full_path");
            }

            if (is_dir($source_file)) {
                Log::info("$share/$full_path is now a directory! Aborting.");
                return TRUE;
            }

            // There might be old metafiles... for example, when a delete task was skipped.
            // Let's remove the file copies if there are any leftovers; correct copies will be re-created in create_copies_from_metafiles()
            foreach (Metastores::get_metafiles($share, $path, $filename) as $existing_metafiles) {
                Log::debug(count($existing_metafiles) . " metafiles loaded.");
                if (count($existing_metafiles) > 0) {
                    foreach ($existing_metafiles as $metafile) {
                        Trash::trash_file($metafile->path);
                    }
                    Metastores::remove_metafiles($share, $path, $filename);
                    $existing_metafiles = array();
                    // Maybe there's other file copies, that weren't metafiles, or were NOK metafiles!
                    foreach (Config::storagePoolDrives() as $sp_drive) {
                        if (file_exists("$sp_drive/$share/$path/$filename")) {
                            Trash::trash_file("$sp_drive/$share/$path/$filename");
                        }
                    }
                }
                $new_metafiles = $this->gh_write_process_metafiles($num_copies_required, $existing_metafiles, $share, $full_path, $source_file, $filesize, $task_id);
                if ($new_metafiles === FALSE || $new_metafiles === TRUE) {
                    return $new_metafiles;
                }
            }
            FileHook::trigger(FileHook::EVENT_TYPE_CREATE, $share, $full_path);
        }
        return TRUE;
    }


    /**
     * @param int    $num_copies_required
     * @param array  $existing_metafiles
     * @param string $share
     * @param string $full_path
     * @param string $source_file
     * @param int    $filesize
     * @param int    $task_id
     * @param null   $keys_to_remove
     *
     * @return array|bool FALSE if the operation failed, and should be retried, TRUE if it failed, but should not be retried, or an array of metafiles if it succeeded.
     */
    private function gh_write_process_metafiles($num_copies_required, $existing_metafiles, $share, $full_path, $source_file, $filesize, $task_id, $keys_to_remove=NULL) {
        $landing_zone = get_share_landing_zone($share);
        list($path, $filename) = explode_full_path($full_path);

        // Only need to check for locking if we have something to do!
        if ($num_copies_required > 1 || count($existing_metafiles) == 0) {
            // Check if another process locked this file before we work on it
            if ($this->is_file_locked($share, $full_path)) {
                return FALSE;
            }
            DBSpool::resetSleepingTasks();
        }

        if ($keys_to_remove !== NULL) {
            foreach ($keys_to_remove as $key) {
                if ($existing_metafiles[$key]->path != $source_file) {
                    Trash::trash_file($existing_metafiles[$key]->path, TRUE);
                }
            }
            // Empty the existing metafiles array, to be able to recreate all new copies on the correct drives, per the dir_selection_algorithm
            $existing_metafiles = array();
        }

        $metafiles = Metastores::create_metafiles($share, $full_path, $num_copies_required, $filesize, $existing_metafiles);

        if (count($metafiles) == 0) {
            Log::error("  No metadata files could be created. Will wait until metadata files can be created to work on this file.", Log::EVENT_CODE_NO_METADATA_SAVED);
            DBSpool::getInstance()->postpone_task($task_id);
            return array();
        }

        if (!is_link("$landing_zone/$full_path")) {
            // Use the 1st metafile for the symlink; it might be on a sticky drive.
            $i = 0;
            foreach ($metafiles as $metafile) {
                $metafile->is_linked = ($i++ == 0);
            }
        }

        Metastores::save_metafiles($share, $path, $filename, $metafiles);

        // Let's look for duplicate 'write' tasks that we could safely skip
        $q = "SELECT id FROM tasks WHERE action = 'write' AND share = :share AND full_path = :full_path AND complete IN ('yes', 'thawed', 'idle') AND id > :task_id";
        $duplicate_tasks_to_delete = DB::getAllValues($q, ['share' => $share, 'full_path' => $full_path, 'task_id' => $task_id]);

        if (!StorageFile::create_file_copies_from_metafiles($metafiles, $share, $full_path, $source_file)) {
            // If create_copies_from_metafiles returns FALSE, we want to abort this op, thus why we return TRUE here
            return TRUE;
        }

        if (!empty($duplicate_tasks_to_delete)) {
            Log::debug("  Deleting " . count($duplicate_tasks_to_delete) . " future 'write' tasks that are duplicate of this one.");
            DBSpool::getInstance()->delete_tasks($duplicate_tasks_to_delete);
        }
        return $metafiles;
    }

    public static function queue($share, $full_path, $complete = 'yes') {
        parent::_queue('write', $share, $full_path, NULL, $complete);
    }

    private static function find_future_full_path($share, $full_path, $task_id) {
        $new_full_path = $full_path;
        while ($next_task = DBSpool::getInstance()->find_next_rename_task($share, $new_full_path, $task_id)) {
            if ($next_task->full_path == $full_path) {
                // File was renamed
                $new_full_path = $next_task->additional_info;
            } else {
                // A parent directory was renamed
                $new_full_path = preg_replace("@^$next_task->full_path@", $next_task->additional_info, $new_full_path);
            }
            $task_id = $next_task->id;
        }
        return $new_full_path;
    }

}

?>
