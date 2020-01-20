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

class Md5Task extends AbstractTask {

    public function execute() {
        static::gh_check_md5($this);
        return TRUE;
    }

    public static function gh_check_md5($task) {
        $share_options = SharesConfig::getConfigForShare($task->share);

        $query = "SELECT complete, COUNT(*) AS num, GROUP_CONCAT(id) AS ids FROM tasks WHERE action = 'md5' AND share = :share AND full_path = :full_path GROUP BY complete ORDER BY complete";
        $params = array(
            'share' => $task->share,
            'full_path' => $task->full_path
        );
        $rows = DB::getAll($query, $params);
        $complete_tasks = array_shift($rows); // ORDER BY complete ASC in the above query will always return complete='yes' first
        if (empty($complete_tasks)) {
            Log::debug("  Already checked this file. Skipping.");
            return;
        }
        $incomplete_tasks = $rows;
        if (count($incomplete_tasks) > 0) {
            // We don't have all of them yet. Let's post-pone this until we do.
            $query = "INSERT INTO tasks (action, share, full_path, additional_info, complete) SELECT action, share, full_path, additional_info, complete FROM tasks WHERE id = :task_id";
            DB::insert($query, array('task_id' => $task->id));

            // If there's no worker thread alive, spawn all of them. The idle ones will just die.
            $num_worker_threads = (int) trim(exec("ps x | grep '/usr/bin/greyhole --md5-worker' | grep -v grep | grep -v bash | wc -l"));
            if ($num_worker_threads == 0) {
                Log::debug("  Will spawn new worker threads to work on this.");
                static::spawn_threads_for_pool_drives();
            } else {
                // Give the worker thread some time to catch up
                Log::debug("  Will wait some to allow for MD5 worker threads to complete.");
                sleep(5);
            }
            return;
        }

        // We have all of them; let's check the MD5 checksums
        Log::debug("Checking MD5 checksums for " . clean_dir("$task->share/$task->full_path"));
        $result_tasks = DB::getAll("SELECT * FROM tasks WHERE id IN ($complete_tasks->ids)");
        $md5s = array();
        foreach ($result_tasks as $t) {
            if (preg_match('/^(.+)=([0-9a-f]{32})$/', $t->additional_info, $regs)) {
                $md5s[$regs[2]][] = clean_dir($regs[1]);
            } else {
                $md5s['unreadable files'][] = clean_dir($t->additional_info);
            }
        }
        if (count($md5s) == 1) {
            $md5s = array_keys($md5s);
            $md5 = reset($md5s);

            if ($md5 == 'unreadable files') {
                // Oopsy!
                /** @noinspection PhpUndefinedVariableInspection */
                $logs = array(
                    "  The following file is unreadable: " . clean_dir($t->additional_info),
                    "  The underlying filesystem probably contains errors. You should unmount that partition, and check it using e2fsck -cfp"
                );
            } else {
                Log::debug("  All copies have the same MD5 checksum: $md5");
            }
        }
        else if (count($md5s) > 1) {
            // Oopsy!
            $logs = array("Mismatch in file copies checksums:");
            foreach ($md5s as $md5 => $file_copies) {
                $latest_file_copy = $file_copies[count($file_copies)-1];
                $file_copies = array_unique($file_copies);
                sort($file_copies);
                $files = implode(', ', $file_copies);

                // Automatically fix this if:
                // - there's only 2 different MD5s for all file copies (i.e. one for all other files copies, and one for this file copy)
                // - the current MD5 is only for one file copy (we assume this copy is in error, not the others)
                // - that file copy isn't used as the share symlink target
                $original_file_path = clean_dir(readlink(get_share_landing_zone($task->share) . "/" . $task->full_path));
                if (count($md5s) == 2 && count($file_copies) == 1 && $latest_file_copy != $original_file_path) {
                    // Find the original file (the file copy pointed to by the symlink on the LZ) MD5
                    $original_md5 = 'Unknown';
                    foreach ($md5s as $this_md5 => $fcs) {
                        foreach ($fcs as $file_copy) {
                            if ($file_copy == $original_file_path) {
                                $original_md5 = $this_md5;
                                break;
                            }
                        }
                    }
                    if ($original_md5 == 'Unknown') {
                        Log::error("  The MD5 checksum of the original file ($original_file_path) was NOT calculated. Why?", Log::EVENT_CODE_FSCK_MD5_MISMATCH);
                        Log::info("  Calculating MD5 for original file copy at $original_file_path ...");
                        $original_md5 = md5_file($original_file_path);
                        Log::debug("    MD5 = $original_md5");
                    }

                    Log::warn("  A file copy with a different checksum than the original was found: $latest_file_copy = $md5. Original: $original_file_path = $original_md5. This copy will be deleted, and replaced with a new copy from $original_file_path", Log::EVENT_CODE_FSCK_MD5_MISMATCH);
                    gh_recycle($latest_file_copy);

                    $metafiles = array();
                    list($path, $filename) = explode_full_path($task->full_path);
                    foreach (get_metafiles($task->share, $path, $filename, TRUE, TRUE, FALSE) as $existing_metafiles) {
                        foreach ($existing_metafiles as $key => $metafile) {
                            $metafiles[$key] = $metafile;
                        }
                    }
                    create_copies_from_metafiles($metafiles, $task->share, $task->full_path, $original_file_path, TRUE);

                    Log::debug("  Calculating MD5 for new file copy at $latest_file_copy ...");
                    $md5 = md5_file($latest_file_copy);
                    Log::debug("    MD5 = $md5");
                    if ($md5 == $original_md5) {
                        Log::debug("  All copies have the same MD5 checksum: $md5");
                        DBSpool::getInstance()->delete_tasks($complete_tasks->ids);
                        return;
                    }
                }

                $logs[] = "  [$md5] => $files";
            }
            $logs[] = "Some of the above files appear to be unreadable.";
            $logs[] = "The underlying filesystem(s) probably contains errors. You should unmount this/those partition(s), and check it/them using: fsck -cfp /dev/[...]";
            $logs[] = "You should manually check which file copy is invalid, and delete it. Re-create a valid copy with:";
            $logs[] = "  sudo greyhole --fsck --checksums --dir " . escapeshellarg(dirname(clean_dir($share_options[CONFIG_LANDING_ZONE] . "/$task->full_path")));
        }

        if (isset($logs)) {
            // Write to greyhole.log
            foreach ($logs as $log) {
                Log::error($log, Log::EVENT_CODE_FSCK_MD5_MISMATCH);
            }

            // Write in fsck_checksums.log too
            $flog = fopen(FSCKLogFile::PATH . '/fsck_checksums.log', 'a');
            if (!$flog) {
                Log::critical("Couldn't open log file: " . FSCKLogFile::PATH . "/fsck_checksums.log", Log::EVENT_CODE_FSCK_MD5_LOG_FAILURE);
            }
            fwrite($flog, $date = date("M d H:i:s") . ' ' . implode("\n", $logs) . "\n\n");
            fclose($flog);

            unset($logs);
        }

        DBSpool::getInstance()->delete_tasks($complete_tasks->ids);
    }

    public static function check_md5_workers() {
        $query = "SELECT * from tasks WHERE action = 'md5' AND complete = 'no' LIMIT 1";
        $row = DB::getFirst($query);
        if ($row) {
            $num_worker_threads = (int) trim(exec("ps x | grep '/usr/bin/greyhole --md5-worker' | grep -v grep | grep -v bash | wc -l"));
            if ($num_worker_threads == 0) {
                Log::debug("Will spawn new worker threads to work on incomplete checksums calculations.");
                static::spawn_threads_for_pool_drives();
            }
        }
    }

    public static function spawn_threads_for_pool_drives() {
        $checksums_thread_ids = array();
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (StoragePool::is_pool_drive($sp_drive)) {
                $checksums_thread_ids[] = spawn_thread('md5-worker', array($sp_drive));
            }
        }
        return $checksums_thread_ids;
    }

}

?>
