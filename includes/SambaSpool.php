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

final class SambaSpool {

    public static function create_mem_spool() {
        $mounted_already = exec('mount | grep /var/spool/greyhole/mem | wc -l');
        if (!$mounted_already) {
            if (!file_exists('/var/spool/greyhole/mem')) {
                mkdir('/var/spool/greyhole/mem', 0777, TRUE);
                chmod('/var/spool/greyhole/mem', 0777); // mkdir mode is affected by the umask, so we need to insure proper mode on that folder.
            }
            exec('mount -o size=4M -t tmpfs none /var/spool/greyhole/mem 2> /dev/null', $mount_result);
            if (!empty($mount_result)) {
                Log::error("Error mounting tmpfs in /var/spool/greyhole/mem: $mount_result", Log::EVENT_CODE_SPOOL_MOUNT_FAILED);
            }
            return TRUE;
        }
        return FALSE;
    }

    public static function parse_samba_spool() {
        Log::setAction(ACTION_READ_SAMBA_POOL);

        $db_spool = DBSpool::getInstance();

        // Just in case the spool folder is missing!
        if (!file_exists('/var/spool/greyhole/mem')) {
            mkdir('/var/spool/greyhole/mem', 0777, TRUE);
            chmod('/var/spool/greyhole', 0777); // mkdir mode is affected by the umask, so we need to insure proper mode on that folder.
            chmod('/var/spool/greyhole/mem', 0777);
        }

        // If we have enough queued tasks (90% of $max_queued_tasks), let's not parse the log at this time, and get some work done.
        // Once we fall below that, we'll queue up to at most $max_queued_tasks new tasks, then get back to work.
        // This will effectively 'batch' large file operations to make sure the DB doesn't become a problem because of the number of rows,
        //   and this will allow the end-user to see real activity, other that new rows in greyhole.tasks...
        $num_rows = DBSpool::get_num_tasks();
        $max_queued_tasks = Config::get(CONFIG_MAX_QUEUED_TASKS);
        if ($num_rows >= ($max_queued_tasks * 0.9)) {
            Log::restorePreviousAction();
            if (time() % 10 == 0) {
                Log::debug("  More than " . ($max_queued_tasks * 0.9) . " tasks queued... Won't queue any more at this time.");
            }
            return;
        }

        $new_tasks = 0;
        $last_line = FALSE;
        $act = FALSE;
        $close_tasks = array();
        while (TRUE) {
            $files = array();
            $last_filename = FALSE;
            $space_left_in_queue = $max_queued_tasks - $num_rows - $new_tasks;
            exec('find -L /var/spool/greyhole -type f -printf "%T@ %p\n" | sort -n 2> /dev/null | head -' . $space_left_in_queue, $files);
            if (count($files) == 0) {
                break;
            }

            if ($last_line === FALSE) {
                Log::debug("Processing Samba spool...");
            }

            foreach ($files as $file) {
                // Remove timestamp prefix from $file (%T@ above), to get the complete filename
                $file = explode(' ', $file);
                array_shift($file);
                $filename = implode(' ', $file);

                if ($last_filename) {
                    unlink($last_filename);
                }

                $last_filename = $filename;

                $line = file_get_contents($filename);

                // Prevent insertion of unneeded duplicates
                if ($line === $last_line) {
                    continue;
                }

                $line_ar = explode("\n", $line);

                $last_line = $line;

                // Close & fwrite logs are only processed when no more duplicates are found, so we'll execute this now that a non-duplicate line was found.
                if ($act === 'fwrite' || $act === 'close') {
                    /** @noinspection PhpUndefinedVariableInspection */
                    $db_spool->close_task($act, $share, $fd, $close_tasks);
                }

                $line = $line_ar;
                $act = array_shift($line);
                $share = array_shift($line);
                if ($act == 'mkdir') {
                    // Just create the same folder on the 2 backup drives, to be able to get back empty folders, if we ever lose the LZ
                    $dir_fullpath = get_share_landing_zone($share) . "/$line[0]";
                    Log::debug("Directory created: $share/$line[0]");
                    foreach (Config::get(CONFIG_METASTORE_BACKUPS) as $metastore_backup_drive) {
                        $backup_drive = str_replace('/.gh_metastore_backup', '', $metastore_backup_drive);
                        if (StoragePool::is_pool_drive($backup_drive)) {
                            gh_mkdir("$backup_drive/$share/$line[0]", $dir_fullpath);
                        }
                    }
                    FileHook::trigger(FileHook::EVENT_TYPE_MKDIR, $share, $line[0]);
                    continue;
                }
                $result = array_pop($line);
                if (string_starts_with($result, 'failed')) {
                    Log::debug("Failed $act in $share/$line[0]. Skipping.");
                    continue;
                }
                unset($fullpath);
                unset($fullpath_target);
                unset($fd);
                switch ($act) {
                case 'open':
                    $fullpath = array_shift($line);
                    $fd = array_shift($line);
                    $act = 'write';
                    break;
                case 'rmdir':
                case 'unlink':
                    $fullpath = array_shift($line);
                    break;
                case 'rename':
                    $fullpath = array_shift($line);
                    $fullpath_target = array_shift($line);
                    break;
                case 'fwrite':
                case 'close':
                    $fd = array_shift($line);
                    break;
                default:
                    $act = FALSE;
                }
                if ($act === FALSE) {
                    continue;
                }

                // Close & fwrite logs are only processed when no more duplicates are found, so we won't execute it just yet; we'll process it the next time we find a non-duplicate line.
                if ($act != 'close' && $act != 'fwrite') {
                    if (isset($fd) && $fd == -1) {
                        continue;
                    }
                    if ($act != 'unlink' && $act != 'rmdir' && array_contains(ConfigHelper::$trash_share_names, $share)) { continue; }
                    $new_tasks++;

                    /** @noinspection PhpUndefinedVariableInspection */
                    $db_spool->insert($act, $share, @$fullpath, @$fullpath_target, @$fd);
                }

                // If we have enough queued tasks ($max_queued_tasks), let's stop parsing the log, and get some work done.
                if ($num_rows+$new_tasks >= $max_queued_tasks) {
                    Log::debug("  We now have more than $max_queued_tasks tasks queued... Will stop parsing for now.");
                    break;
                }
            }
            if ($last_filename) {
                unlink($last_filename);
            }
            if ($num_rows+$new_tasks >= $max_queued_tasks) {
                break;
            }
        }

        // Close & fwrite logs are only processed when no more duplicates are found, so we'll execute this now that we're done parsing all spooled files.
        if ($act === 'fwrite' || $act === 'close') {
            /** @noinspection PhpUndefinedVariableInspection */
            $db_spool->close_task($act, $share, $fd, $close_tasks);
        }
        // We also need to 'execute' all close tasks, now that we're just all fwrite have been logged
        $db_spool->close_all_tasks($close_tasks);

        if ($new_tasks > 0) {
            Log::debug("Found $new_tasks new tasks in spool.");
        }

        Log::restorePreviousAction();
    }

}

?>
