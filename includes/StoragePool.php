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

final class StoragePool {
    private static $greyhole_owned_drives = array();
    private static $gone_ok_drives = NULL;
    private static $fscked_gone_drives = NULL;
    // df results cache:
    private static $last_df_time = 0;
    private static $last_dfs = [];

    public static function is_pool_drive($sp_drive) {
        global $going_drive;
        if (isset($going_drive) && $sp_drive == $going_drive) {
            return FALSE;
        }
        $is_greyhole_owned_drive = isset(self::$greyhole_owned_drives[$sp_drive]);
        if ($is_greyhole_owned_drive && self::$greyhole_owned_drives[$sp_drive] < time() - Config::get(CONFIG_DF_CACHE_TIME)) {
            unset(self::$greyhole_owned_drives[$sp_drive]);
            $is_greyhole_owned_drive = FALSE;
        }
        if (!$is_greyhole_owned_drive) {
            $drive_uuid = SystemHelper::directory_uuid($sp_drive);
            if (DB::isConnected()) {
                $drives_definitions = Settings::get('sp_drives_definitions', TRUE);
                if (!$drives_definitions) {
                    $drives_definitions = MigrationHelper::convertStoragePoolDrivesTagFiles();
                }
                $is_greyhole_owned_drive = @$drives_definitions[$sp_drive] === $drive_uuid && $drive_uuid !== FALSE;
            } else {
                $is_greyhole_owned_drive = is_dir("$sp_drive/.gh_metastore");
            }
            if (!$is_greyhole_owned_drive) {
                // Maybe this is a remote mount? Those don't have UUIDs, so we use the .greyhole_uses_this technique.
                $is_greyhole_owned_drive = file_exists("$sp_drive/.greyhole_uses_this");
                if ($is_greyhole_owned_drive && isset($drives_definitions[$sp_drive])) {
                    // This remote drive was listed in MySQL; it shouldn't be. Let's remove it.
                    unset($drives_definitions[$sp_drive]);
                    if (DB::isConnected()) {
                        Settings::set('sp_drives_definitions', $drives_definitions);
                    }
                }
            }
            if ($is_greyhole_owned_drive) {
                self::$greyhole_owned_drives[$sp_drive] = time();
            }
        }
        return $is_greyhole_owned_drive;
    }

    public static function check_drives() {
        Log::setAction(ACTION_CHECK_POOL);

        // If last 'df' ran less than 10s ago, all the drives are already awake; no harm checking them at this time.
        global $last_df_time;
        $force_run = ( time()-$last_df_time < 10);

        $schedule = Config::get(CONFIG_CHECK_SP_SCHEDULE);
        if (!empty($schedule) && !$force_run) {
            if (string_starts_with($schedule, '*:')) {
                if (strlen($schedule) == 4) {
                    $should_run = substr($schedule, 2) === date('i');
                } else {
                    Log::warn("Invalid format for " . CONFIG_CHECK_SP_SCHEDULE . " config option. Supported values are: *:mi or hh:mi", Log::EVENT_CODE_CONFIG_UNPARSEABLE_LINE);
                    $should_run = TRUE;
                }
            } else {
                if (strlen($schedule) == 5 && $schedule[2] == ':') {
                    $should_run = ( $schedule === date('H:i') );
                } else {
                    Log::warn("Invalid format for " . CONFIG_CHECK_SP_SCHEDULE . " config option. Supported values are: *:mi or hh:mi", Log::EVENT_CODE_CONFIG_UNPARSEABLE_LINE);
                    $should_run = TRUE;
                }
            }
            if (!$should_run) {
                return;
            }
        }

        $needs_fsck = FALSE;
        $drives_definitions = Settings::get('sp_drives_definitions', TRUE);
        $returned_drives = array();
        $missing_drives = array();
        $i = 0; $j = 0;
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (!self::is_pool_drive($sp_drive) && !self::gone_fscked($sp_drive, $i++ == 0) && !file_exists("$sp_drive/.greyhole_used_this") && !empty($drives_definitions[$sp_drive])) {
                if($needs_fsck !== 2){
                    $needs_fsck = 1;
                }
                self::mark_gone_drive_fscked($sp_drive);
                $missing_drives[] = $sp_drive;
                Log::warn("Warning! It seems the partition UUID of $sp_drive changed. This probably means this mount is currently unmounted, or that you replaced this drive and didn't use 'greyhole --replaced'. Because of that, Greyhole will NOT use this drive at this time.", Log::EVENT_CODE_STORAGE_POOL_DRIVE_UUID_CHANGED);
                Log::debug("Email sent for gone drive: $sp_drive");
                self::$gone_ok_drives[$sp_drive] = TRUE; // The upcoming fsck should not recreate missing copies just yet
            } else if ((self::gone_ok($sp_drive, $j++ == 0) || self::gone_fscked($sp_drive, $i++ == 0)) && self::is_pool_drive($sp_drive) && !empty($drives_definitions[$sp_drive])) {
                // $sp_drive is now back
                $needs_fsck = 2;
                $returned_drives[] = $sp_drive;
                Log::debug("Email sent for revived drive: $sp_drive");

                self::mark_gone_ok($sp_drive, 'remove');
                self::mark_gone_drive_fscked($sp_drive, 'remove');
                $i = 0; $j = 0;
            }
        }
        if (count($returned_drives) > 0) {
            $body = "This is an automated email from Greyhole.\n\nOne (or more) of your storage pool drives came back:\n";
            foreach ($returned_drives as $sp_drive) {
                $body .= "$sp_drive was missing; it's now available again.\n";
            }
            $body .= "\nA fsck will now start, to fix the symlinks found in your shares, when possible.\nYou'll receive a report email once that fsck run completes.\n";
            $drive_string = join(", ", $returned_drives);
            $subject = "Storage pool drive now online on " . exec ('hostname') . ": ";
            $subject = $subject . $drive_string;
            if (strlen($subject) > 255) {
                $subject = substr($subject, 0, 255);
            }
            mail(Config::get(CONFIG_EMAIL_TO), $subject, $body);
        }
        if (count($missing_drives) > 0) {
            $body = "This is an automated email from Greyhole.\n\nOne (or more) of your storage pool drives has disappeared:\n";

            foreach ($missing_drives as $sp_drive) {
                if (!is_dir($sp_drive)) {
                    $body .= "$sp_drive: directory doesn't exists\n";
                } else {
                    $current_uuid = SystemHelper::directory_uuid($sp_drive);
                    if (empty($current_uuid)) {
                        $current_uuid = 'N/A';
                    }
                    $body .= "$sp_drive: expected partition UUID: " . $drives_definitions[$sp_drive] . "; current partition UUID: $current_uuid\n";
                }
            }
            $sp_drive = $missing_drives[0];
            $body .= "\nThis either means this mount is currently unmounted, or you forgot to use 'greyhole --replaced' when you changed this drive.\n\n";
            $body .= "Here are your options:\n\n";
            $body .= "- If you forgot to use 'greyhole --replaced', you should do so now. Until you do, this drive will not be part of your storage pool.\n\n";
            $body .= "- If the drive is gone, you should either re-mount it manually (if possible), or remove it from your storage pool. To do so, use the following command:\n  greyhole --remove=" . escapeshellarg($sp_drive) . "\n  Note that the above command is REQUIRED for Greyhole to re-create missing file copies before the next fsck runs. Until either happens, missing file copies WILL NOT be re-created on other drives.\n\n";
            $body .= "- If you know this drive will come back soon, and do NOT want Greyhole to re-create missing file copies for this drive until it reappears, you should execute this command:\n  greyhole --wait-for=" . escapeshellarg($sp_drive) . "\n\n";
            $body .= "A fsck will now start, to fix the symlinks found in your shares, when possible.\nYou'll receive a report email once that fsck run completes.\n";
            $subject = "Missing storage pool drives on " . exec('hostname') . ": ";
            $drive_string = join(",",$missing_drives);
            $subject = $subject . $drive_string;
            if (strlen($subject) > 255) {
                $subject = substr($subject, 0, 255);
            }
            mail(Config::get(CONFIG_EMAIL_TO), $subject, $body);
        }
        if ($needs_fsck !== FALSE) {
            Metastores::choose_metastores_backups();
            Metastores::get_metastores(FALSE); // FALSE => Resets the metastores cache
            clearstatcache();

            $fsck_task = FsckTask::getCurrentTask();
            $fsck_task->initialize_fsck_report('All shares');
            if ($needs_fsck === 2) {
                foreach ($returned_drives as $drive) {
                    $metastores = Metastores::get_metastores_from_storage_volume($drive);
                    Log::info("Starting fsck for metadata store on $drive which came back online.");
                    foreach ($metastores as $metastore) {
                        foreach (SharesConfig::getShares() as $share_name => $share_options) {
                            $fsck_task->gh_fsck_metastore($metastore,"/$share_name", $share_name);
                        }
                    }
                    Log::info("fsck for returning drive $drive's metadata store completed.");
                }
                Log::info("Starting fsck for all shares - caused by missing drive that came back online.");
            } else {
                Log::info("Starting fsck for all shares - caused by missing drive. Will just recreate symlinks to existing copies when possible; won't create new copies just yet.");
                fix_all_symlinks();
            }
            schedule_fsck_all_shares(array('email'));
            Log::info("  fsck for all shares scheduled.");

            self::reload_gone_ok_drives();
        }
    }

    // Is it OK for a drive to be gone?
    public static function gone_ok($sp_drive, $force_reload=FALSE) {
        if ($force_reload || self::$gone_ok_drives === NULL) {
            self::reload_gone_ok_drives();
        }
        if (isset(self::$gone_ok_drives[$sp_drive])) {
            return TRUE;
        }
        return FALSE;
    }

    public static function reload_gone_ok_drives() {
        self::$gone_ok_drives = Settings::get('Gone-OK-Drives', TRUE);
        if (!self::$gone_ok_drives) {
            self::$gone_ok_drives = array();
            Settings::set('Gone-OK-Drives', self::$gone_ok_drives);
        }
    }

    public static function get_gone_ok_drives() {
        if (self::$gone_ok_drives === NULL) {
            self::reload_gone_ok_drives();
        }
        return self::$gone_ok_drives;
    }

    public static function mark_gone_ok($sp_drive, $action='add') {
        if (!array_contains(Config::storagePoolDrives(), $sp_drive)) {
            $sp_drive = '/' . trim($sp_drive, '/');
        }
        if (!array_contains(Config::storagePoolDrives(), $sp_drive)) {
            return FALSE;
        }

        self::reload_gone_ok_drives();
        if ($action == 'add') {
            self::$gone_ok_drives[$sp_drive] = TRUE;
        } else {
            unset(self::$gone_ok_drives[$sp_drive]);
        }

        Settings::set('Gone-OK-Drives', self::$gone_ok_drives);
        return TRUE;
    }

    public static function gone_fscked($sp_drive, $force_reload=FALSE) {
        if ($force_reload || self::$fscked_gone_drives == NULL) {
            self::reload_fsck_gone_drives();
        }
        if (isset(self::$fscked_gone_drives[$sp_drive])) {
            return TRUE;
        }
        return FALSE;
    }

    public static function reload_fsck_gone_drives() {
        self::$fscked_gone_drives = Settings::get('Gone-FSCKed-Drives', TRUE);
        if (!self::$fscked_gone_drives) {
            self::$fscked_gone_drives = array();
            Settings::set('Gone-FSCKed-Drives', self::$fscked_gone_drives);
        }
    }

    public static function mark_gone_drive_fscked($sp_drive, $action='add') {
        self::reload_fsck_gone_drives();
        if ($action == 'add') {
            self::$fscked_gone_drives[$sp_drive] = TRUE;
        } else {
            unset(self::$fscked_gone_drives[$sp_drive]);
        }
        Settings::set('Gone-FSCKed-Drives', self::$fscked_gone_drives);
    }

    public static function remove_drive($going_drive) {
        $drives_definitions = Settings::get('sp_drives_definitions', TRUE);
        if (!$drives_definitions) {
            $drives_definitions = MigrationHelper::convertStoragePoolDrivesTagFiles();
        }
        unset($drives_definitions[$going_drive]);
        Settings::set('sp_drives_definitions', $drives_definitions);
    }

    public static function get_file_copies_inodes($share, $file_path, $filename, &$file_metafiles, $one_is_enough = FALSE) {
        $file_copies_inodes = [];

        foreach (Config::storagePoolDrives() as $sp_drive) {
            $clean_full_path = clean_dir("$sp_drive/$share/$file_path/$filename");
            if (is_link($clean_full_path)) {
                continue;
            }
            $inode_number = @gh_fileinode($clean_full_path);
            if ($inode_number !== FALSE) {
                if (is_dir($clean_full_path)) {
                    Log::info("Found a directory that should be a file! Will try to remove it, if it's empty.");
                    @rmdir($clean_full_path);
                    continue;
                }

                Log::debug("Found $clean_full_path");

                if (!StoragePool::is_pool_drive($sp_drive)) {
                    $state = Metafile::STATE_GONE;
                    if (!$one_is_enough) {
                        Log::info("  Drive $sp_drive is not part of the Greyhole storage pool anymore. The above file will not be counted as a valid file copy, but can be used to create a new valid copy.");
                    }
                } else {
                    $state = Metafile::STATE_OK;
                    $file_copies_inodes[$inode_number] = $clean_full_path;
                    if ($one_is_enough) {
                        return $file_copies_inodes;
                    }
                }
                if (is_string($file_metafiles)) {
                    Log::critical("Fatal error! \$file_metafiles is now a string: '$file_metafiles'.", Log::EVENT_CODE_UNEXPECTED_VAR);
                }
                /** @noinspection PhpIllegalStringOffsetInspection */
                $file_metafiles[$clean_full_path] = (object) array('path' => $clean_full_path, 'is_linked' => FALSE, 'state' => $state);

                // Temp files leftovers of stopped Greyhole executions
                $temp_filename = StorageFile::get_temp_filename($clean_full_path);
                if (file_exists($temp_filename) && gh_is_file($temp_filename)) {
                    Log::info("  Found temporary file $temp_filename ... deleting.");
                    $fsck_report['temp_files'][] = $temp_filename;
                    unlink($temp_filename);
                }
            }
        }

        return $file_copies_inodes;
    }

    public static function get_free_space($for_sp_drive) {
        if (time() > static::$last_df_time + Config::get(CONFIG_DF_CACHE_TIME)) {
            $dfs = [];
            exec(ConfigHelper::$df_command, $responses);
            $responses_arr = array();
            foreach ($responses as $line) {
                if (preg_match("@\s+[0-9]+\s+([0-9]+)\s+([0-9]+)\s+[0-9]+%\s+(.+)$@", $line, $regs)) {
                    $responses_arr[] = array((float) $regs[1], (float) $regs[2], $regs[3]);
                }
            }
            $responses = $responses_arr;
            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (!StoragePool::is_pool_drive($sp_drive)) {
                    continue;
                }
                $target_drive = '';
                unset($target_freespace);
                unset($target_usedspace);
                for ($i=0; $i<count($responses); $i++) {
                    $used_space = $responses[$i][0];
                    $free_space = $responses[$i][1];
                    $mount = $responses[$i][2];
                    if (mb_strpos($sp_drive, $mount) === 0 && mb_strlen($mount) > mb_strlen($target_drive)) {
                        $target_drive = $mount;
                        $target_freespace = $free_space;
                        $target_usedspace = $used_space;
                    }
                }
                if (empty($target_drive)) {
                    // This can happen if multiple mounts exist for this drive, and the first one appearing in the output of 'df' is NOT the one for the storage pool
                    // In Docker, when using -v to mount a storage pool drive, and another folder in that storage pool drive:
                    // eg. docker run ... -v /mnt/hdd5/backups:/backups -v /mnt/hdd5:/mnt/hdd5 ...
                    // For this, 'df -k /mnt/hdd5' will actually return '/dev/sdX ... ... ... ...% /backups'
                    unset($responses);
                    exec('df -k ' . $sp_drive, $responses);
                    foreach ($responses as $line) {
                        if (preg_match("@\s+[0-9]+\s+([0-9]+)\s+([0-9]+)\s+[0-9]+%\s+(.+)$@", $line, $regs)) {
                            $responses_arr[] = array((float) $regs[1], (float) $regs[2], $sp_drive);
                            $target_freespace = (float) $regs[2];
                            $target_usedspace = (float) $regs[1];
                        }
                    }
                }
                /** @noinspection PhpUndefinedVariableInspection */
                $dfs[$sp_drive]['free'] = $target_freespace;
                /** @noinspection PhpUndefinedVariableInspection */
                $dfs[$sp_drive]['used'] = $target_usedspace;
            }
            static::$last_df_time = time();
            static::$last_dfs = $dfs;
        }

        if (empty(static::$last_dfs[$for_sp_drive])) {
            return FALSE;
        }
        return static::$last_dfs[$for_sp_drive];
    }

    public static function choose_target_drives($filesize_kb, $include_full_drives, $share, $path, $log_prefix = '', &$is_sticky = NULL) {
        global $last_OOS_notification;

        foreach (SharesConfig::get($share, CONFIG_DRIVE_SELECTION_ALGORITHM) as $ds) {
            $algo = $ds->selection_algorithm;
            break;
        }

        $sorted_target_drives = array('available_space' => array(), 'used_space' => array());
        $last_resort_sorted_target_drives = array('available_space' => array(), 'used_space' => array());
        $full_drives = array();
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $df = StoragePool::get_free_space($sp_drive);
            if (!$df) {
                if (!is_dir($sp_drive)) {
                    if (SystemHelper::is_amahi()) {
                        $details = "You should de-select, then re-select this partition in your Amahi dashboard (http://hda), in the Shares > Storage Pool page, to fix this problem.";
                    } else {
                        $details = "See the INSTALL file for instructions on how to prepare partitions to include in your storage pool.";
                    }
                    Log::error("The directory at $sp_drive doesn't exist. This drive will never be used! $details", Log::EVENT_CODE_STORAGE_POOL_FOLDER_NOT_FOUND);
                } else if (!file_exists("$sp_drive/.greyhole_used_this") && StoragePool::is_pool_drive($sp_drive)) {
                    unset($df_command_responses);
                    exec(ConfigHelper::$df_command, $df_command_responses);
                    unset($df_k_responses);
                    exec('df -k 2>&1', $df_k_responses);
                    $details = "Please report this using the 'Issues' tab found on https://github.com/gboudreau/Greyhole. You should include the following information in your ticket:\n"
                        . "===== Error report starts here =====\n"
                        . "Unknown free space for partition: $sp_drive\n"
                        . "df_command: " . ConfigHelper::$df_command . "\n"
                        . "Result of df_command: " . var_export($df_command_responses, TRUE) . "\n"
                        . "Result of df -k: " . var_export($df_k_responses, TRUE) . "\n"
                        . "===== Error report ends here =====";
                    Log::error("Can't find how much free space is left on $sp_drive. This partition will never be used! Details will follow.\n$details", Log::EVENT_CODE_STORAGE_POOL_DRIVE_DF_FAILED);
                }
                continue;
            }
            if (!StoragePool::is_pool_drive($sp_drive)) {
                continue;
            }
            $free_space = $df['free'];
            $used_space = $df['used'];
            $minimum_free_space = (float) Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive);
            $available_space = (float) $free_space - $minimum_free_space;
            if ($available_space <= $filesize_kb) {
                if ($free_space > $filesize_kb) {
                    $last_resort_sorted_target_drives['available_space'][$sp_drive] = $available_space;
                    $last_resort_sorted_target_drives['used_space'][$sp_drive] = $used_space;
                } else {
                    $full_drives[$sp_drive] = $free_space;
                }
                continue;
            }
            $sorted_target_drives['available_space'][$sp_drive] = $available_space;
            $sorted_target_drives['used_space'][$sp_drive] = $used_space;
        }

        /** @var $drives_selectors PoolDriveSelector[] */
        $drives_selectors = SharesConfig::get($share, CONFIG_DRIVE_SELECTION_ALGORITHM);
        foreach ($drives_selectors as $ds) {
            $s = $sorted_target_drives;
            $l = $last_resort_sorted_target_drives;
            $ds->init($s, $l);
        }

        $sorted_target_drives = array();
        $last_resort_sorted_target_drives = array();
        $got_all_drives = FALSE;
        while (!$got_all_drives) {
            $num_empty_ds = 0;
            global $is_forced;
            foreach ($drives_selectors as $ds) {
                $is_forced = $ds->isForced();
                list($drives, $drives_last_resort) = $ds->draft();
                foreach ($drives as $sp_drive => $space) {
                    $sorted_target_drives[$sp_drive] = $space;
                }
                foreach ($drives_last_resort as $sp_drive => $space) {
                    $last_resort_sorted_target_drives[$sp_drive] = $space;
                }
                if (count($drives) == 0 && count($drives_last_resort) == 0) {
                    $num_empty_ds++;
                }
            }
            if ($num_empty_ds == count($drives_selectors)) {
                // All DS are empty; exit.
                break;
            }
        }

        // Email notification when all drives are over-capacity
        if (count($sorted_target_drives) == 0) {
            Log::error("  Warning! All storage pool drives are over-capacity!", Log::EVENT_CODE_ALL_DRIVES_FULL);
            if (!isset($last_OOS_notification)) {
                $setting = Settings::get('last_OOS_notification');
                if ($setting === FALSE) {
                    Log::warn("Received no rows when querying settings for 'last_OOS_notification'; expected one.", Log::EVENT_CODE_SETTINGS_READ_ERROR);
                    $setting = Settings::set('last_OOS_notification', 0);
                }
                $last_OOS_notification = $setting;
            }
            if ($last_OOS_notification < strtotime('-1 day')) {
                $email_to = Config::get(CONFIG_EMAIL_TO);

                Log::info("  Sending email notification to $email_to");

                $hostname = exec('hostname');
                $body = "This is an automated email from Greyhole.

It appears all the defined storage pool drives are over-capacity.
You probably want to do something about this!

";
                foreach ($last_resort_sorted_target_drives as $sp_drive => $free_space) {
                    $minimum_free_space = (int) Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive) / 1024 / 1024;
                    $body .= "$sp_drive has " . number_format($free_space/1024/1024, 2) . " GB free; minimum specified in greyhole.conf: $minimum_free_space GB.\n";
                }
                mail($email_to, "Greyhole is out of space on $hostname!", $body);

                $last_OOS_notification = time();
                Settings::set('last_OOS_notification', $last_OOS_notification);
            }
        }

        if (Log::getLevel() >= Log::DEBUG) {
            if (count($sorted_target_drives) > 0) {
                $log = $log_prefix . "Drives with available space: ";
                foreach ($sorted_target_drives as $sp_drive => $space) {
                    /** @noinspection PhpUndefinedVariableInspection */
                    $log .= "$sp_drive (" . bytes_to_human($space*1024, FALSE) . " " . ($algo == 'most_available_space' ? 'avail' : 'used') . ") - ";
                }
                Log::debug(mb_substr($log, 0, mb_strlen($log)-2));
            }
            if (count($last_resort_sorted_target_drives) > 0) {
                $log = $log_prefix . "Drives with enough free space, but no available space: ";
                foreach ($last_resort_sorted_target_drives as $sp_drive => $space) {
                    /** @noinspection PhpUndefinedVariableInspection */
                    $log .= "$sp_drive (" . bytes_to_human($space*1024, FALSE) . " " . ($algo == 'most_available_space' ? 'avail' : 'used') . ") - ";
                }
                Log::debug(mb_substr($log, 0, mb_strlen($log)-2));
            }
            if (count($full_drives) > 0) {
                $log = $log_prefix . "Drives full: ";
                foreach ($full_drives as $sp_drive => $free_space) {
                    $log .= "$sp_drive (" . bytes_to_human($free_space*1024, FALSE) . " free) - ";
                }
                Log::debug(mb_substr($log, 0, mb_strlen($log)-2));
            }
        }

        $sorted_target_drives = array_keys($sorted_target_drives);
        $last_resort_sorted_target_drives = array_keys($last_resort_sorted_target_drives);
        $full_drives = array_keys($full_drives);

        $drives = array_merge($sorted_target_drives, $last_resort_sorted_target_drives);
        if ($include_full_drives) {
            $drives = array_merge($drives, $full_drives);
        }

        $sticky_files = Config::get(CONFIG_STICKY_FILES);
        if (!empty($sticky_files)) {
            $is_sticky = FALSE;
            foreach ($sticky_files as $share_dir => $stick_into) {
                if (gh_wild_mb_strpos("$share/$path", $share_dir) === 0) {
                    $is_sticky = TRUE;

                    $more_drives_needed = FALSE;
                    if (count($stick_into) > 0) {
                        // Stick files into specific drives: $stick_into
                        // Let's check if those drives are listed in the config file!
                        foreach ($stick_into as $key => $stick_into_dir) {
                            if (!array_contains(Config::storagePoolDrives(), $stick_into_dir)) {
                                unset($stick_into[$key]);
                                $more_drives_needed = TRUE;
                            }
                        }
                    }
                    if (count($stick_into) == 0 || $more_drives_needed) {
                        if (string_contains($share_dir, '*')) {
                            // Contains a wildcard... In this case, we want each directory that match the wildcard to have it's own setting. Let's find this directory...
                            // For example, if $share_dir == 'Videos/Movies/*/*' and "$share/$path/" == "Videos/Movies/HD/La Vita e Bella/", we want to save a 'stick_into' setting for 'Videos/Movies/HD/La Vita e Bella/'
                            // Files in other subdirectories of Videos/Movies/HD/ could end up in other drives.
                            $needles = explode('*', $share_dir);
                            $sticky_dir = '';
                            $wild_part = "$share/$path/";
                            for ($i=0; $i<count($needles); $i++) {
                                $needle = $needles[$i];
                                if ($i == 0) {
                                    $sticky_dir = $needle;
                                    $wild_part = @str_replace_first($needle, '', $wild_part);
                                } else {
                                    if ($needle == '') {
                                        $needle = '/';
                                    }
                                    $small_wild_part = mb_substr($wild_part, 0, mb_strpos($wild_part, $needle)+mb_strlen($needle));
                                    $sticky_dir .= $small_wild_part;
                                    $wild_part = str_replace_first($small_wild_part, '', $wild_part);
                                }
                            }
                            $sticky_dir = trim($sticky_dir, '/');
                        } else {
                            $sticky_dir = $share_dir;
                        }

                        // Stick files into any drives
                        $setting_name = sprintf('stick_into-%s', $sticky_dir);
                        $setting = Settings::get($setting_name, TRUE);
                        if ($setting) {
                            $stick_into = array_merge($stick_into, $setting);
                            // Let's check if those drives are listed in the config file!
                            $update_needed = FALSE;
                            foreach ($stick_into as $key => $stick_into_dir) {
                                if (!array_contains(Config::storagePoolDrives(), $stick_into_dir)) {
                                    unset($stick_into[$key]);
                                    $update_needed = TRUE;
                                }
                            }
                            if ($update_needed) {
                                $value = serialize($stick_into);
                                Settings::set($setting_name, $value);
                            }
                        } else {
                            $value = array_merge($stick_into, $drives);
                            Settings::set($setting_name, $value);
                        }
                    }

                    // Make sure the drives we want to use are not yet full and have available space
                    $priority_drives = array();
                    foreach ($stick_into as $stick_into_dir) {
                        if (array_contains(Config::storagePoolDrives(), $stick_into_dir)
                            && !array_contains($full_drives, $stick_into_dir)
                            && !array_contains($last_resort_sorted_target_drives, $stick_into_dir)) {
                            $priority_drives[] = $stick_into_dir;
                            unset($drives[array_search($stick_into_dir, $drives)]);
                        }
                    }
                    $drives = array_merge($priority_drives, $drives);
                    Log::debug($log_prefix . "Reordered drives, per sticky_files config: " . implode(' - ', $drives));
                    break;
                }
            }
        }

        return $drives;
    }

    public static function get_drives_available_space() {
        $sorted_target_drives = [];
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $df = StoragePool::get_free_space($sp_drive);
            if (!$df) {
                continue;
            }
            $free_space = $df['free'];
            $minimum_free_space = Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive);
            $available_space = (float) $free_space - $minimum_free_space;
            $sorted_target_drives[$sp_drive] = $available_space;
        }
        asort($sorted_target_drives);
        return $sorted_target_drives;
    }

    public static function getDriveFromPath($full_path) {
        $storage_volume = '';
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (string_starts_with($full_path, $sp_drive) && mb_strlen($sp_drive) > mb_strlen($storage_volume)) {
                $storage_volume = $sp_drive;
            }
        }
        return empty($storage_volume) ? FALSE : $storage_volume;
    }

}

?>
