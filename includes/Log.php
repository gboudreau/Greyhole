<?php
/*
Copyright 2014 Guillaume Boudreau

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

class Log {
    const PERF     = 9;
    const TEST     = 8;
    const DEBUG    = 7;
    const INFO     = 6;
    const WARN     = 4;
    const ERROR    = 3;
    const CRITICAL = 2;

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
    private static $level;

    public static function setLevel($level) {
        static::$level = $level;
    }

    public static function getLevel() {
        return static::$level;
    }

    public static function setAction($action) {
        static::$old_action = static::$action;
        static::$action = str_replace(':', '', $action);
    }

    public static function actionIs($action) {
        return static::$action == $action;
    }

    public static function restorePreviousAction() {
        static::$action = static::$old_action;
    }

    public static function debug($text) {
        static::_log(static::DEBUG, $text);
    }

    public static function info($text) {
        static::_log(static::INFO, $text);
    }

    public static function warn($text) {
        static::_log(static::WARN, $text);
    }

    public static function error($text) {
        static::_log(static::ERROR, $text);
    }

    public static function critical($text) {
        static::_log(static::CRITICAL, $text);
    }

    private static function _log($local_log_level, $text) {
        if ($local_log_level > static::$level) {
            return;
        }

        $greyhole_log_file = Config::get(CONFIG_GREYHOLE_LOG_FILE);
        $use_syslog = strtolower($greyhole_log_file) == 'syslog';

        $date = date("M d H:i:s");
        if (static::$level >= static::PERF) {
            $utimestamp = microtime(true);
            $timestamp = floor($utimestamp);
            $date .= '.' . round(($utimestamp - $timestamp) * 1000000);
        }

        $log_level_string = $use_syslog ? $local_log_level : static::$log_level_names[$local_log_level];
        $log_text = sprintf("%s%s%s\n",
            "$date $log_level_string " . static::$action . ": ",
            $text,
            Config::get(CONFIG_LOG_MEMORY_USAGE) ? " [" . memory_get_usage() . "]" : ''
        );

        if ($use_syslog) {
            $worked = sys_log($local_log_level, $log_text);
        } else if (!empty($greyhole_log_file)) {
            $greyhole_error_log_file = Config::get(CONFIG_GREYHOLE_ERROR_LOG_FILE);
            if ($local_log_level <= static::WARN && !empty($greyhole_error_log_file)) {
                $worked = @error_log($log_text, 3, $greyhole_error_log_file);
            } else {
                $worked = @error_log($log_text, 3, $greyhole_log_file);
            }
        } else {
            $worked = FALSE;
        }
        if (!$worked) {
            error_log(trim($log_text));
        }

        if ($local_log_level === static::CRITICAL) {
            exit(1);
        }
    }
}
?>
