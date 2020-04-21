<?php
/*
Copyright 2014-2017 Guillaume Boudreau

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

define('ACTION_INITIALIZE', 'initialize');
define('ACTION_UNKNOWN', 'unknown');
define('ACTION_DAEMON', 'daemon');
define('ACTION_PAUSE', 'pause');
define('ACTION_RESUME', 'resume');
define('ACTION_FSCK', 'fsck');
define('ACTION_CANCEL_FSCK', 'cancel-fsck');
define('ACTION_BALANCE', 'balance');
define('ACTION_CANCEL_BALANCE', 'cancel-balance');
define('ACTION_STATS', 'stats');
define('ACTION_STATUS', 'status');
define('ACTION_LOGS', 'logs');
define('ACTION_EMPTY_TRASH', 'empty-trash');
define('ACTION_VIEW_QUEUE', 'view-queue');
define('ACTION_IOSTAT', 'iostat');
define('ACTION_GETUID', 'getuid');
define('ACTION_MD5_WORKER', 'md5-worker');
define('ACTION_FIX_SYMLINKS', 'fix-symlinks');
define('ACTION_REPLACE', 'replace');
define('ACTION_WAIT_FOR', 'wait-for');
define('ACTION_GONE', 'gone');
define('ACTION_GOING', 'going');
define('ACTION_THAW', 'thaw');
define('ACTION_DEBUG', 'debug');
define('ACTION_DELETE_METADATA', 'delete-metadata');
define('ACTION_REMOVE_SHARE', 'remove-share');

define('ACTION_CHECK_POOL', 'check_pool');
define('ACTION_SLEEP', 'sleep');
define('ACTION_READ_SAMBA_POOL', 'read_smb_spool');
define('ACTION_FSCK_FILE', 'fsck_file');

final class Log {
    const PERF     = 9;
    const TEST     = 8;
    const DEBUG    = 7;
    const INFO     = 6;
    const WARN     = 4;
    const ERROR    = 3;
    const CRITICAL = 2;

    const EVENT_CODE_ALL_DRIVES_FULL = 'all_drives_full';
    const EVENT_CODE_CONFIG_DEPRECATED_OPTION = 'config_deprecated_option';
    const EVENT_CODE_CONFIG_TESTPARM_FAILED = 'config_testparm_failed';
    const EVENT_CODE_CONFIG_FILE_PARSING_FAILED = 'config_file_parsing_failed';
    const EVENT_CODE_CONFIG_HOOK_SCRIPT_NOT_EXECUTABLE = 'config_hook_script_not_executable';
    const EVENT_CODE_CONFIG_INCLUDE_INSECURE_PERMISSIONS = 'config_include_insecure_permissions';
    const EVENT_CODE_CONFIG_INVALID_VALUE = 'config_invalid_value';
    const EVENT_CODE_CONFIG_LZ_INSIDE_STORAGE_POOL = 'config_lz_inside_storage_pool';
    const EVENT_CODE_CONFIG_NO_STORAGE_POOL = 'config_no_storage_pool';
    const EVENT_CODE_CONFIG_SHARE_MISSING_FROM_SMB_CONF = 'config_share_missing_from_smb_conf';
    const EVENT_CODE_CONFIG_STORAGE_POOL_DRIVES_SAME_PARTITION = 'config_storage_pool_drives_same_partition';
    const EVENT_CODE_CONFIG_STORAGE_POOL_DRIVE_NOT_IN_DRIVE_SELECTION_ALGO = 'config_storage_pool_drive_not_in_drive_selection_algo';
    const EVENT_CODE_CONFIG_STORAGE_POOL_INSIDE_LZ = 'config_storage_pool_inside_lz';
    const EVENT_CODE_CONFIG_UNPARSEABLE_LINE = 'config_unparseable_line';
    const EVENT_CODE_DB_CONNECT_FAILED = 'db_connect_failed';
    const EVENT_CODE_DB_MIGRATION_FAILED = 'db_migration_failed';
    const EVENT_CODE_DB_TABLE_CRASHED = 'db_table_crashed';
    const EVENT_CODE_FILE_COPY_FAILED = 'file_copy_failed';
    const EVENT_CODE_FSCK_REPORT = 'fsck_report';
    const EVENT_CODE_FSCK_EMPTY_FILE_COPY_FOUND = 'fsck_empty_file_copy_found';
    const EVENT_CODE_FSCK_MD5_LOG_FAILURE = 'fsck_md5_log_failure';
    const EVENT_CODE_FSCK_MD5_MISMATCH = 'fsck_md5_mismatch';
    const EVENT_CODE_FSCK_METAFILE_ROOT_PATH_NOT_FOUND = 'fsck_metafile_root_path_not_found';
    const EVENT_CODE_FSCK_NO_FILE_COPIES = 'fsck_no_file_copies';
    const EVENT_CODE_FSCK_SIZE_MISMATCH_FILE_COPY_FOUND = 'fsck_size_mismatch_file_copy_found';
    const EVENT_CODE_FSCK_SYMLINK_FOUND_IN_STORAGE_POOL = 'fsck_symlink_found_in_storage_pool';
    const EVENT_CODE_FSCK_UNKNOWN_FOLDER = 'fsck_unknown_folder';
    const EVENT_CODE_FSCK_UNKNOWN_SHARE = 'fsck_unknown_share';
    const EVENT_CODE_HOOK_NON_ZERO_EXIT_CODE = 'hook_non_zero_exit_code';
    const EVENT_CODE_HOOK_NOT_EXECUTABLE = 'hook_not_executable';
    const EVENT_CODE_IDLE = "idle";
    const EVENT_CODE_IDLE_NOT = "busy";
    const EVENT_CODE_LIST_DIR_FAILED = 'list_dir_failed';
    const EVENT_CODE_MEMORY_LIMIT_REACHED = 'memory_limit_reached';
    const EVENT_CODE_METADATA_POINTS_TO_GONE_DRIVE = 'metadata_points_to_gone_drive';
    const EVENT_CODE_MKDIR_CHGRP_FAILED = 'mkdir_chgrp_failed';
    const EVENT_CODE_MKDIR_CHMOD_FAILED = 'mkdir_chmod_failed';
    const EVENT_CODE_MKDIR_CHOWN_FAILED = 'mkdir_chown_failed';
    const EVENT_CODE_MKDIR_FAILED = 'mkdir_failed';
    const EVENT_CODE_NO_METADATA_SAVED = 'no_metadata_saved';
    const EVENT_CODE_PHP_CRITICAL = 'php_critical';
    const EVENT_CODE_PHP_ERROR = 'php_error';
    const EVENT_CODE_PHP_WARNING = 'php_warning';
    const EVENT_CODE_RENAME_FILE_COPY_FAILED = 'rename_file_copy_failed';
    const EVENT_CODE_SAMBA_RESTART_FAILED = 'samba_restart_failed';
    const EVENT_CODE_SETTINGS_READ_ERROR = 'settings_read_error';
    const EVENT_CODE_SHARE_MISSING_FROM_GREYHOLE_CONF = 'share_missing_from_greyhole_conf';
    const EVENT_CODE_SPOOL_MOUNT_FAILED = 'spool_mount_failed';
    const EVENT_CODE_STORAGE_POOL_DRIVE_DF_FAILED = 'storage_pool_drive_df_failed';
    const EVENT_CODE_STORAGE_POOL_DRIVE_UUID_CHANGED = 'storage_pool_drive_uuid_changed';
    const EVENT_CODE_STORAGE_POOL_FOLDER_NOT_FOUND = 'storage_pool_folder_not_found';
    const EVENT_CODE_TASK_FOR_UNKNOWN_SHARE = 'task_for_unknown_share';
    const EVENT_CODE_TRASH_NOT_FOUND = 'trash_not_found';
    const EVENT_CODE_TRASH_SYMLINK_FAILED = 'trash_symlink_failed';
    const EVENT_CODE_UNEXPECTED_VAR = 'unexpected_var';
    const EVENT_CODE_VFS_MODULE_WRONG = 'vfs_module_wrong';
    const EVENT_CODE_ZFS_UNKNOWN_DEVICE = 'zfs_unknown_device';

    private static $log_level_names = array(
        9 => 'PERF',
        8 => 'TEST',
        7 => 'DEBUG',
        6 => 'INFO',
        4 => 'WARN',
        3 => 'ERROR',
        2 => 'CRITICAL',
    );

    private static $action = ACTION_INITIALIZE;
    private static $old_action;
    private static $level = Log::INFO; // Default, until we are able to read the config file

    public static function setLevel($level) {
        self::$level = $level;
    }

    public static function getLevel() {
        return self::$level;
    }

    public static function setAction($action) {
        self::$old_action = self::$action;
        self::$action = str_replace(':', '', $action);
    }

    public static function actionIs($action) {
        return self::$action == $action;
    }

    public static function restorePreviousAction() {
        self::$action = self::$old_action;
    }

    public static function debug($text, $event_code = NULL) {
        self::_log(self::DEBUG, $text, $event_code);
    }

    public static function info($text, $event_code = NULL) {
        self::_log(self::INFO, $text, $event_code);
    }

    public static function warn($text, $event_code) {
        self::_log(self::WARN, $text, $event_code);
    }

    public static function error($text, $event_code) {
        self::_log(self::ERROR, $text, $event_code);
    }

    public static function critical($text, $event_code) {
        self::_log(self::CRITICAL, $text, $event_code);
    }

    private static $last_log;

    private static function _log($local_log_level, $text, $event_code) {
        if (self::$action == 'test-config') {
            $greyhole_log_file = NULL;
            $use_syslog = FALSE;
        } elseif (self::$action == ACTION_INITIALIZE && !DaemonRunner::isCurrentProcessDaemon()) {
            return;
        } else {
            if (DaemonRunner::isCurrentProcessDaemon() && self::$action != ACTION_READ_SAMBA_POOL) {
                try {
                    if ($text != static::$last_log) { // Don't log duplicates (Sleeping... for example)
                        $q = "INSERT INTO status SET log = :log, action = :action";
                        DB::insert($q, array('log' => $text, 'action' => empty(self::$action) ? ACTION_UNKNOWN : self::$action));
                    }
                    static::$last_log = $text;
                } catch (\Exception $ex) {
                    error_log("[Greyhole] Error logging status in database: " . $ex->getMessage());
                }
            }
            if ($local_log_level > self::$level) {
                return;
            }
            $greyhole_log_file = Config::get(CONFIG_GREYHOLE_LOG_FILE);
            $use_syslog = strtolower($greyhole_log_file) == 'syslog';
        }

        $date = date("M d H:i:s");
        if (self::$level >= self::PERF) {
            $utimestamp = microtime(true);
            $timestamp = floor($utimestamp);
            $date .= '.' . round(($utimestamp - $timestamp) * 1000000);
        }

        $log_level_string = $use_syslog ? $local_log_level : self::$log_level_names[$local_log_level];
        $log_text = sprintf("%s%s%s\n",
            "$date $log_level_string " . self::$action . ": ",
            $text,
            Config::get(CONFIG_LOG_MEMORY_USAGE) ? " [" . memory_get_usage() . "]" : ''
        );

        if ($use_syslog) {
            $worked = syslog($local_log_level, $log_text);
        } else if (!empty($greyhole_log_file)) {
            $worked = @error_log($log_text, 3, $greyhole_log_file);

            // Log to error log too?
            $greyhole_error_log_file = Config::get(CONFIG_GREYHOLE_ERROR_LOG_FILE);
            if ($local_log_level <= self::WARN && !empty($greyhole_error_log_file)) {
                $worked &= @error_log($log_text, 3, $greyhole_error_log_file);
            }
        } else {
            $worked = FALSE;
        }
        if (!$worked || $local_log_level === self::CRITICAL) {
            error_log(trim($log_text));
        }

        if ($local_log_level == self::WARN) {
            // Prevent infinite loop; this warning came from running a warning Hook, so...
            if ($event_code != LogHook::EVENT_CODE_HOOK_NON_ZERO_EXIT_CODE_IN_WARN) {
                LogHook::trigger(LogHook::EVENT_TYPE_WARNING, $event_code, $log_text);
            }
        } elseif ($local_log_level == self::ERROR) {
            LogHook::trigger(LogHook::EVENT_TYPE_ERROR, $event_code, $log_text);
        } elseif ($local_log_level == self::CRITICAL) {
            LogHook::trigger(LogHook::EVENT_TYPE_CRITICAL, $event_code, $log_text);
        }

        if ($local_log_level === self::CRITICAL) {
            exit(1);
        }
    }

    public static function cleanStatusTable() {
        $q = "SELECT MAX(id) FROM status";
        $max_id = DB::getFirstValue($q);
        $q = "DELETE FROM status WHERE id < :id";
        DB::execute($q, array('id' => max(0, $max_id - 100)));
    }
}

?>
