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

        if (!DB::acquireLock(ACTION_READ_SAMBA_POOL, 5)) {
            // Another thread is already processing the Samba Spool; we don't want multiple threads doing this in parallel!
            return;
        }

        $new_tasks = 0;
        $last_line = FALSE;
        $act = FALSE;
        $close_tasks = array();
        while (TRUE) {
            $files = array();
            $last_filename = FALSE;
            exec('find -L /var/spool/greyhole -type f -printf "%T@ %p\n" | sort -n 2> /dev/null | head -n 10000', $files);
            if (count($files) == 0) {
                break;
            }

            if ($last_line === FALSE) {
                Log::debug("Processing Samba spool...");
            }

            // Sometimes, the modification timestamps of the spooled files (%T@ above) are the same!
            // This sorting function will ensure that writes are after open and before close tasks.
            $fct_sort_filename = function ($file1, $file2) {
                $file1 = explode(' ', $file1);
                $ts1 = array_shift($file1);
                $file1 = implode(' ', $file1);

                $file2 = explode(' ', $file2);
                $ts2 = array_shift($file2);
                $file2 = implode(' ', $file2);


                list($ts1p1, $ts1p2) = explode('.', $ts1);
                list($ts2p1, $ts2p2) = explode('.', $ts2);
                $ts1p1 = (int) $ts1p1;
                $ts1p2 = (int) $ts1p2;
                $ts2p1 = (int) $ts2p1;
                $ts2p2 = (int) $ts2p2;

                if ($ts1p1 < $ts2p1) {
                    return -1;
                }
                if ($ts1p1 > $ts2p1) {
                    return 1;
                }
                if ($ts1p2 < $ts2p2) {
                    return -1;
                }
                if ($ts1p2 > $ts2p2) {
                    return 1;
                }

                $is_file1_write = string_starts_with($file1, '/var/spool/greyhole/mem/');
                $is_file2_write = string_starts_with($file2, '/var/spool/greyhole/mem/');
                $bfile1 = basename($file1);
                $bfile2 = basename($file2);
                $ts1 = explode('-', $bfile1)[0];
                $ts2 = explode('-', $bfile2)[0];
                $seconds1 = substr($ts1, 0, 10);
                $seconds2 = substr($ts2, 0, 10);
                $useconds1 = substr($ts1, -6);
                $useconds2 = substr($ts2, -6);
                if ($seconds1 < $seconds2) {
                    return -1;
                }
                if ($seconds1 > $seconds2) {
                    return 1;
                }
                if ($is_file1_write && $is_file2_write) {
                    return 0;
                }
                if (!$is_file1_write && !$is_file2_write) {
                    if ($useconds1 < $useconds2) {
                        return -1;
                    }
                    return 1;
                }
                if ($is_file1_write && !$is_file2_write) {
                    $other_file = $file2;
                } else {
                    $other_file = $file1;
                }
                $log = file_get_contents($other_file);
                if (string_starts_with($log, 'open')) {
                    return $is_file1_write ? 1 : -1; // open before write
                }
                if (string_starts_with($log, 'close')) {
                    return $is_file1_write ? -1 : 1; // close after write
                }
                return 0;
            };
            usort($files, $fct_sort_filename);

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
                    $db_spool->close_task($act, $share, $fd, @$fullpath, $close_tasks);
                }

                $line = $line_ar;
                $act = array_shift($line);
                $share = array_shift($line);
                if ($act == 'mkdir') {
                    // Just create the same folder on the 2 backup drives, to be able to get back empty folders, if we ever lose the LZ
                    $dir_fullpath = get_share_landing_zone($share) . "/$line[0]";
                    Log::debug("Directory created: $share/$line[0]");
                    foreach (Config::get(CONFIG_METASTORE_BACKUPS) as $metastore_backup_drive) {
                        $backup_drive = str_replace('/' . Metastores::METASTORE_BACKUP_DIR, '', $metastore_backup_drive);
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
                case 'link':
                    $fullpath = array_shift($line);
                    $fullpath_target = array_shift($line);
                    break;
                case 'fwrite':
                case 'close':
                    $fd = array_shift($line);
                    if (!empty($line)) {
                        $fullpath = array_shift($line);
                    }
                    if (empty($fullpath)) {
                        $fullpath = NULL;
                    }
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
            }
            if ($last_filename) {
                unlink($last_filename);
            }
        }

        // Close & fwrite logs are only processed when no more duplicates are found, so we'll execute this now that we're done parsing all spooled files.
        if ($act === 'fwrite' || $act === 'close') {
            /** @noinspection PhpUndefinedVariableInspection */
            $db_spool->close_task($act, $share, $fd, @$fullpath, $close_tasks);
        }

        Log::perf("Finished parsing spool.");

        // We also need to 'execute' all close tasks, now that all fwrite have been logged
        if (!empty($close_tasks)) {
            Log::perf("Found " . count($close_tasks) . " close tasks. Will finalize all write tasks for those, if any...");
            $db_spool->close_all_tasks($close_tasks);
        }

        if ($new_tasks > 0) {
            Log::debug("Found $new_tasks new tasks in spool.");
        }

        DB::releaseLock(ACTION_READ_SAMBA_POOL);

        Log::restorePreviousAction();
    }

}

?>
