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

        if (should_ignore_file($share, $full_path)) {
            return TRUE;
        }

        if (!gh_file_exists("$landing_zone/$full_path", '$real_path doesn\'t exist anymore.')) {
            $new_full_path = find_future_full_path($share, $full_path, $task_id);
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

        $num_copies_required = get_num_copies($share);
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

            foreach (get_metafiles($share, $path, $filename, TRUE) as $existing_metafiles) {
                // Remove old copies (but not the one that was updated!)
                $keys_to_remove = array();
                $found_source_file = FALSE;
                foreach ($existing_metafiles as $key => $metafile) {
                    $metafile->path = clean_dir($metafile->path);
                    if ($metafile->path == $source_file) {
                        $metafile->is_linked = TRUE;
                        $metafile->state = 'OK';
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
                $new_metafiles = gh_write_process_metafiles($num_copies_required, $existing_metafiles, $share, $full_path, $source_file, $filesize, $task_id, $keys_to_remove);
                if ($new_metafiles === FALSE || $new_metafiles === TRUE) {
                    return $new_metafiles;
                }
                if (!empty($new_metafiles) && !isset($new_metafiles[$source_file])) {
                    // Delete source file; we copied it somewhere else
                    // Let's just make sure we have at least one OK file before we delete it!
                    Log::debug("  Source file is not needed anymore. Deleting...");
                    $is_ok = FALSE;
                    foreach ($new_metafiles as $metafile) {
                        if ($metafile->state == 'OK') {
                            $is_ok = TRUE;
                            break;
                        }
                    }
                    if ($is_ok) {
                        // Ok, now we're sure.
                        gh_recycle($source_file, TRUE);
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
            foreach (get_metafiles($share, $path, $filename) as $existing_metafiles) {
                Log::debug(count($existing_metafiles) . " metafiles loaded.");
                if (count($existing_metafiles) > 0) {
                    foreach ($existing_metafiles as $metafile) {
                        gh_recycle($metafile->path);
                    }
                    remove_metafiles($share, $path, $filename);
                    $existing_metafiles = array();
                    // Maybe there's other file copies, that weren't metafiles, or were NOK metafiles!
                    foreach (Config::storagePoolDrives() as $sp_drive) {
                        if (file_exists("$sp_drive/$share/$path/$filename")) {
                            gh_recycle("$sp_drive/$share/$path/$filename");
                        }
                    }
                }
                $new_metafiles = gh_write_process_metafiles($num_copies_required, $existing_metafiles, $share, $full_path, $source_file, $filesize, $task_id);
                if ($new_metafiles === FALSE || $new_metafiles === TRUE) {
                    return $new_metafiles;
                }
            }
            FileHook::trigger(FileHook::EVENT_TYPE_CREATE, $share, $full_path);
        }
        return TRUE;
    }

    public static function queue($share, $full_path, $complete = 'yes') {
        parent::_queue('write', $share, $full_path, NULL, $complete);
    }

}

?>
