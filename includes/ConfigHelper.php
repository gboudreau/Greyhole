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

define('CONFIG_LOG_LEVEL', 'log_level');
define('CONFIG_DELETE_MOVES_TO_TRASH', 'delete_moves_to_trash');
define('CONFIG_MODIFIED_MOVES_TO_TRASH', 'modified_moves_to_trash');
define('CONFIG_LOG_MEMORY_USAGE', 'log_memory_usage');
define('CONFIG_CHECK_FOR_OPEN_FILES', 'check_for_open_files');
define('CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE', 'allow_multiple_sp_per_device');
define('CONFIG_STORAGE_POOL_DRIVE', 'storage_pool_drive');
define('CONFIG_MIN_FREE_SPACE_POOL_DRIVE', 'min_free_space_pool_drive');
define('CONFIG_STICKY_FILES', 'sticky_files');
define('CONFIG_STICK_INTO', 'stick_into');
define('CONFIG_FROZEN_DIRECTORY', 'frozen_directory');
define('CONFIG_MEMORY_LIMIT', 'memory_limit');
define('CONFIG_TIMEZONE', 'timezone');
define('CONFIG_DRIVE_SELECTION_GROUPS', 'drive_selection_groups');
define('CONFIG_DRIVE_SELECTION_ALGORITHM', 'drive_selection_algorithm');
define('CONFIG_IGNORED_FILES', 'ignored_files');
define('CONFIG_IGNORED_FOLDERS', 'ignored_folders');
define('CONFIG_NUM_COPIES', 'num_copies');
define('CONFIG_LANDING_ZONE', 'landing_zone');
define('CONFIG_MAX_QUEUED_TASKS', 'max_queued_tasks');
define('CONFIG_EXECUTED_TASKS_RETENTION', 'executed_tasks_retention');
define('CONFIG_GREYHOLE_LOG_FILE', 'greyhole_log_file');
define('CONFIG_GREYHOLE_ERROR_LOG_FILE', 'greyhole_error_log_file');
define('CONFIG_EMAIL_TO', 'email_to');
define('CONFIG_DF_CACHE_TIME', 'df_cache_time');
define('CONFIG_DB_HOST', 'db_host');
define('CONFIG_DB_USER', 'db_user');
define('CONFIG_DB_PASS', 'db_pass');
define('CONFIG_DB_NAME', 'db_name');
define('CONFIG_METASTORE_BACKUPS', 'metastore_backups');
define('CONFIG_TRASH_SHARE', '===trash_share===');
define('CONFIG_HOOK', 'hook');
define('CONFIG_CHECK_SP_SCHEDULE', 'check_storage_pool_schedule');
define('CONFIG_CALCULATE_MD5_DURING_COPY', 'calculate_md5');
define('CONFIG_PARALLEL_COPYING', 'parallel_copying');

function recursive_include_parser($file) {
    $regex = '/^[ \t]*include[ \t]*=[ \t]*([^#\r\n]+)/im';
    $ok_to_execute = FALSE;

    if (is_array($file) && count($file) > 1) {
        $file = $file[1];
    }

    $file = trim($file);

    if (file_exists($file)) {
        if (is_executable($file)) {
            $perms = fileperms($file);

            // Not user-writable, or owned by root
            $ok_to_execute = !($perms & 0x0080) || fileowner($file) === 0;

            // Not group-writable, or group owner is root
            $ok_to_execute &= !($perms & 0x0010) || filegroup($file) === 0;

            // Not world-writable
            $ok_to_execute &= !($perms & 0x0002);

            if (!$ok_to_execute) {
                Log::warn("Config file '{$file}' is executable but file permissions are insecure, only the file's contents will be included.", Log::EVENT_CODE_CONFIG_INCLUDE_INSECURE_PERMISSIONS);
            }
        }

        $contents = $ok_to_execute ? shell_exec(escapeshellcmd($file)) : file_get_contents($file);

        return preg_replace_callback($regex, 'recursive_include_parser', $contents);
    } else {
        return false;
    }
}

final class ConfigHelper {
    static $df_command;
    public static $config_file = '/etc/greyhole.conf';
    public static $smb_config_file = '/etc/samba/smb.conf';
    public static $trash_share_names = array('Greyhole Attic', 'Greyhole Trash', 'Greyhole Recycle Bin');
    static $deprecated_options = array(
        'delete_moves_to_attic' => CONFIG_DELETE_MOVES_TO_TRASH,
        'storage_pool_directory' => CONFIG_STORAGE_POOL_DRIVE,
        'dir_selection_groups' => CONFIG_DRIVE_SELECTION_GROUPS,
        'dir_selection_algorithm' => CONFIG_DRIVE_SELECTION_ALGORITHM,
    );
    static $config_options = array(
        'bool' => array(
            CONFIG_DELETE_MOVES_TO_TRASH,
            CONFIG_MODIFIED_MOVES_TO_TRASH,
            CONFIG_LOG_MEMORY_USAGE,
            CONFIG_CHECK_FOR_OPEN_FILES,
            CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE,
            CONFIG_CALCULATE_MD5_DURING_COPY,
            CONFIG_PARALLEL_COPYING,
        ),
        'number' => array(
            CONFIG_MAX_QUEUED_TASKS,
            CONFIG_EXECUTED_TASKS_RETENTION,
            CONFIG_DF_CACHE_TIME,
        ),
        'string' => array(
            CONFIG_DB_HOST,
            CONFIG_DB_USER,
            CONFIG_DB_PASS,
            CONFIG_DB_NAME,
            CONFIG_EMAIL_TO,
            CONFIG_GREYHOLE_LOG_FILE,
            CONFIG_GREYHOLE_ERROR_LOG_FILE,
            CONFIG_TIMEZONE,
            CONFIG_MEMORY_LIMIT,
            CONFIG_CHECK_SP_SCHEDULE
        ),
    );

    public static function removeShare($share) {
        $conf_file = escapeshellarg(self::$config_file);
        $tmp_file = escapeshellarg(self::$config_file . '.tmp');
        exec("/bin/sed 's/^.*num_copies\[".$share."\].*$//' $conf_file >$tmp_file && cat $tmp_file >$conf_file");
    }

    public static function removeStoragePoolDrive($sp_drive) {
        $escaped_drive = str_replace('/', '\/', $sp_drive);
        $conf_file = escapeshellarg(self::$config_file);
        $tmp_file = escapeshellarg(self::$config_file . '.tmp');
        exec("/bin/sed 's/^.*storage_pool_drive.*$escaped_drive.*$//' $conf_file >$tmp_file && cat $tmp_file >$conf_file");
    }

    public static function randomStoragePoolDrive() {
        $storage_pool_drives = (array) Config::storagePoolDrives();
        return $storage_pool_drives[array_rand($storage_pool_drives)];
    }

    public static function parse() {
        if (!ini_get('date.timezone')) {
            // To prevent warnings that would be logged if something gets logged before the timezone setting is parsed and applied.
            date_default_timezone_set('UTC');
        }

        $config_text = recursive_include_parser(self::$config_file);

        global $parsing_drive_selection_groups;

        foreach (explode("\n", $config_text) as $line) {
            if (preg_match("/^[ \t]*([^=\t]+)[ \t]*=[ \t]*([^#]+)/", $line, $regs)) {
                $name = trim($regs[1]);
                $value = trim($regs[2]);
                self::parse_line($name, $value);
            } else if ($parsing_drive_selection_groups !== FALSE) {
                $value = trim($line);
                if (strlen($value) == 0 || $value[0] == '#') {
                    continue;
                }
                if (preg_match("/(.+):(.+)/", $value, $regs)) {
                    $group_name = trim($regs[1]);
                    $drives = array_map('trim', explode(',', $regs[2]));
                    if (is_string($parsing_drive_selection_groups)) {
                        $share = $parsing_drive_selection_groups;
                        SharesConfig::add($share, CONFIG_DRIVE_SELECTION_GROUPS, $drives, $group_name);
                    } else {
                        Config::add(CONFIG_DRIVE_SELECTION_GROUPS, $drives, $group_name);
                    }
                }
            }
        }

        return self::init();
    }

    private static function parse_line($name, $value) {
        if ($name[0] == '#') {
            return;
        }

        // Handles old notations for some config options
        self::normalize_name($name);

        global $parsing_drive_selection_groups;
        $parsing_drive_selection_groups = FALSE;

        // Booleans
        if (self::parse_line_bool($name, $value)) return;

        // Numbers
        if (self::parse_line_number($name, $value)) return;

        // Strings
        if (self::parse_line_string($name, $value)) return;

        // Log level
        if (self::parse_line_log($name, $value)) return;

        // Storage pool drives
        if (self::parse_line_pool_drive($name, $value)) return;

        // Drive selection algorithms & groups
        if (self::parse_line_drive_selection($name, $value)) return;

        // Sticky files
        if (self::parse_line_sticky($name, $value)) return;

        // Frozen directories
        if (self::parse_line_frozen($name, $value)) return;

        // Ignored files, folders
        if (self::parse_line_ignore($name, $value)) return;

        // Share options
        if (self::parse_line_share_option($name, $value)) return;

        // Hooks
        if (self::parse_line_hook($name, $value)) return;

        // Unknown
        if (is_numeric($value)) {
            $value = (int) $value;
        }
        Config::set($name, $value);
    }

    private static function normalize_name(&$name) {
        foreach (self::$deprecated_options as $old_name => $new_name) {
            if (string_contains($name, $old_name)) {
                $fixed_name = str_replace($old_name, $new_name, $name);
                Log::warn("Deprecated option found in greyhole.conf: $name. You should change that to: $fixed_name", Log::EVENT_CODE_CONFIG_DEPRECATED_OPTION);
                $name = $fixed_name;
            }
        }
    }

    private static function parse_line_bool($name, $value) {
        if (array_contains(self::$config_options['bool'], $name)) {
            $bool = trim($value) === '1' || mb_stripos($value, 'yes') !== FALSE || mb_stripos($value, 'true') !== FALSE;
            Config::set($name, $bool);
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_number($name, $value) {
        if (array_contains(self::$config_options['number'], $name)) {
            if (is_numeric($value)) {
                $value = (int) $value;
            }
            Config::set($name, $value);
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_string($name, $value) {
        if (array_contains(self::$config_options['string'], $name)) {
            Config::set($name, $value);
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_log($name, $value) {
        if ($name == CONFIG_LOG_LEVEL) {
            self::assert(defined("Log::$value"), "Invalid value for log_level: '$value'", Log::EVENT_CODE_CONFIG_INVALID_VALUE);
            Config::set(CONFIG_LOG_LEVEL, constant("Log::$value"));
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_pool_drive($name, $value) {
        if ($name == CONFIG_STORAGE_POOL_DRIVE) {
            if (preg_match("/(.*) ?, ?min_free ?: ?([0-9]+) ?([gmk])b?/i", $value, $regs)) {
                $sp_drive = '/' . trim(trim($regs[1]), '/');
                Config::add(CONFIG_STORAGE_POOL_DRIVE, $sp_drive);

                $units = strtolower($regs[3]);
                if ($units == 'g') {
                    $value = (float) trim($regs[2]) * 1024.0 * 1024.0;
                } else if ($units == 'm') {
                    $value = (float) trim($regs[2]) * 1024.0;
                } else if ($units == 'k') {
                    $value = (float) trim($regs[2]);
                }
                Config::add(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $value, $sp_drive);
            } else {
                Log::warn("Warning! Unable to parse " . CONFIG_STORAGE_POOL_DRIVE . " line from config file. Value = $value", Log::EVENT_CODE_CONFIG_UNPARSEABLE_LINE);
            }
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_sticky($name, $value) {
        if ($name == CONFIG_STICKY_FILES) {
            global $last_sticky_files_dir;
            $last_sticky_files_dir = trim($value, '/');
            Config::add(CONFIG_STICKY_FILES, array(), $last_sticky_files_dir);
            return TRUE;
        }
        if ($name == CONFIG_STICK_INTO) {
            global $last_sticky_files_dir;
            $sticky_files = Config::get(CONFIG_STICKY_FILES);
            $sticky_files[$last_sticky_files_dir][] = '/' . trim($value, '/');
            Config::set(CONFIG_STICKY_FILES, $sticky_files);
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_frozen($name, $value) {
        if ($name == CONFIG_FROZEN_DIRECTORY) {
            Config::add(CONFIG_FROZEN_DIRECTORY, trim($value, '/'));
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_drive_selection($name, $value) {
        if ($name == CONFIG_DRIVE_SELECTION_GROUPS) {
            if (preg_match("/(.+):(.*)/", $value, $regs)) {
                $group_name = trim($regs[1]);
                $group_definition = array_map('trim', explode(',', $regs[2]));
                Config::add(CONFIG_DRIVE_SELECTION_GROUPS, $group_definition, $group_name);
                global $parsing_drive_selection_groups;
                $parsing_drive_selection_groups = TRUE;
            }
            return TRUE;
        }
        if ($name == CONFIG_DRIVE_SELECTION_ALGORITHM) {
            Config::set(CONFIG_DRIVE_SELECTION_ALGORITHM, PoolDriveSelector::parse($value, Config::get(CONFIG_DRIVE_SELECTION_GROUPS)));
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_ignore($name, $value) {
        if ($name == CONFIG_IGNORED_FILES) {
            Config::add(CONFIG_IGNORED_FILES, $value);
            return TRUE;
        }
        if ($name == CONFIG_IGNORED_FOLDERS) {
            Config::add(CONFIG_IGNORED_FOLDERS, $value);
            return TRUE;
        }
        return FALSE;
    }

    private static function parse_line_share_option($name, $value) {
        if (!string_starts_with($name, [CONFIG_NUM_COPIES, CONFIG_DELETE_MOVES_TO_TRASH, CONFIG_MODIFIED_MOVES_TO_TRASH, CONFIG_DRIVE_SELECTION_GROUPS, CONFIG_DRIVE_SELECTION_ALGORITHM])) {
            return FALSE;
        }
        if (!preg_match('/^(.*)\[\s*(.*)\s*]$/', $name, $matches)) {
            error_log("Error parsing config file; can't find share name in $name");
            return FALSE;
        }

        $name = trim($matches[1]);
        $share = trim($matches[2]);

        switch ($name) {
        case CONFIG_NUM_COPIES:
            if (mb_stripos($value, 'max') === 0) {
                $value = 9999;
            } else {
                $value = (int) $value;
            }
            SharesConfig::set($share, $name, $value);
            break;
        case CONFIG_DELETE_MOVES_TO_TRASH:
        case CONFIG_MODIFIED_MOVES_TO_TRASH:
            $value = strtolower($value);
            $bool = $value === '1' || mb_stripos($value, 'yes') !== FALSE || mb_stripos($value, 'true') !== FALSE;
            SharesConfig::set($share, $name, $bool);
            break;
        case CONFIG_DRIVE_SELECTION_GROUPS:
            if (preg_match("/(.+):(.+)/", $value, $regs)) {
                $group_name = trim($regs[1]);
                $group_definition = array_map('trim', explode(',', $regs[2]));
                SharesConfig::add($share, CONFIG_DRIVE_SELECTION_GROUPS, $group_definition, $group_name);
                global $parsing_drive_selection_groups;
                $parsing_drive_selection_groups = $share;
            }
            break;
        case CONFIG_DRIVE_SELECTION_ALGORITHM:
            if (SharesConfig::get($share, CONFIG_DRIVE_SELECTION_GROUPS) === FALSE) {
                SharesConfig::set($share, CONFIG_DRIVE_SELECTION_GROUPS, Config::get(CONFIG_DRIVE_SELECTION_GROUPS));
            }
            SharesConfig::set($share, CONFIG_DRIVE_SELECTION_ALGORITHM, PoolDriveSelector::parse($value, SharesConfig::get($share, CONFIG_DRIVE_SELECTION_GROUPS)));
            break;
        }
        return TRUE;
    }

    private static function parse_line_hook($name, $value) {
        if (string_starts_with($name, CONFIG_HOOK)) {
            if (!preg_match('/hook\[([^]]+)]/', $name, $re)) {
                Log::warn("Can't parse the following config line: $name; ignoring.", Log::EVENT_CODE_CONFIG_UNPARSEABLE_LINE);
                return TRUE;
            }
            if (!is_executable($value)) {
                Log::warn("Hook script $value is not executable; ignoring.", Log::EVENT_CODE_CONFIG_HOOK_SCRIPT_NOT_EXECUTABLE);
                return TRUE;
            }
            $events = explode('|', $re[1]);
            foreach ($events as $event) {
                Hook::add($event, $value);
            }
            return TRUE;
        }
        return FALSE;
    }

    private static function init() {
        Log::setLevel(Config::get(CONFIG_LOG_LEVEL));

        if (count(Config::storagePoolDrives()) == 0) {
            Log::error("You have no '" . CONFIG_STORAGE_POOL_DRIVE . "' defined. Greyhole can't run.", Log::EVENT_CODE_CONFIG_NO_STORAGE_POOL);
            return FALSE;
        }

        self::$df_command = "df -k";
        foreach (Config::storagePoolDrives() as $sp_drive) {
            self::$df_command .= " " . escapeshellarg($sp_drive);
        }
        self::$df_command .= " 2>&1 | grep '%' | grep -v \"^df: .*: No such file or directory$\"";

        exec('testparm -s ' . escapeshellarg(self::$smb_config_file) . ' 2> /dev/null', $config_text);
        if (empty($config_text)) {
            Log::critical("Failed to list Samba configuration using 'testparm -s ".self::$smb_config_file."'.", Log::EVENT_CODE_CONFIG_TESTPARM_FAILED);
        }
        foreach ($config_text as $line) {
            $line = trim($line);
            if (mb_strlen($line) == 0) { continue; }
            if ($line[0] == '[' && preg_match('/\[([^]]+)]/', $line, $regs)) {
                $share_name = $regs[1];
            }
            if (isset($share_name) && !SharesConfig::exists($share_name) && !array_contains(self::$trash_share_names, $share_name)) {
                continue;
            }
            if (isset($share_name) && preg_match('/^\s*path[ \t]*=[ \t]*(.+)$/i', $line, $regs)) {
                SharesConfig::set($share_name, CONFIG_LANDING_ZONE, '/' . trim($regs[1], '/"'));
                SharesConfig::set($share_name, 'name', $share_name);
            }
        }

        $drive_selection_algorithm = Config::get(CONFIG_DRIVE_SELECTION_ALGORITHM);
        if (!empty($drive_selection_algorithm)) {
            foreach ($drive_selection_algorithm as $ds) {
                $ds->update();
            }
        } else {
            // Default drive_selection_algorithm
            $drive_selection_algorithm = PoolDriveSelector::parse('most_available_space', null);
        }
        Config::set(CONFIG_DRIVE_SELECTION_ALGORITHM, $drive_selection_algorithm);

        if (!Config::exists(CONFIG_MODIFIED_MOVES_TO_TRASH)) {
            Config::set(CONFIG_MODIFIED_MOVES_TO_TRASH, Config::get(CONFIG_DELETE_MOVES_TO_TRASH));
        }

        foreach (SharesConfig::getShares() as $share_name => $share_options) {
            if (array_contains(self::$trash_share_names, $share_name)) {
                SharesConfig::set(CONFIG_TRASH_SHARE, 'name', $share_name);
                SharesConfig::set(CONFIG_TRASH_SHARE, CONFIG_LANDING_ZONE, SharesConfig::get($share_name, CONFIG_LANDING_ZONE));
                SharesConfig::removeShare($share_name);
                continue;
            }
            if ($share_options[CONFIG_NUM_COPIES] > count(Config::storagePoolDrives())) {
                SharesConfig::set($share_name, CONFIG_NUM_COPIES, count(Config::storagePoolDrives()));
            }
            if (!isset($share_options[CONFIG_LANDING_ZONE])) {
                Log::warn("Found a share ($share_name) defined in " . self::$config_file . " with no path in " . self::$smb_config_file . ". Either add this share in " . self::$smb_config_file . ", or remove it from " . self::$config_file . ", then restart Greyhole.", Log::EVENT_CODE_CONFIG_SHARE_MISSING_FROM_SMB_CONF);
                return FALSE;
            }
            if (!isset($share_options[CONFIG_DELETE_MOVES_TO_TRASH])) {
                SharesConfig::set($share_name, CONFIG_DELETE_MOVES_TO_TRASH, Config::get(CONFIG_DELETE_MOVES_TO_TRASH));
            }
            if (!isset($share_options[CONFIG_MODIFIED_MOVES_TO_TRASH])) {
                SharesConfig::set($share_name, CONFIG_MODIFIED_MOVES_TO_TRASH, SharesConfig::get($share_name, CONFIG_DELETE_MOVES_TO_TRASH));
            }
            if (isset($share_options[CONFIG_DRIVE_SELECTION_ALGORITHM])) {
                foreach ($share_options[CONFIG_DRIVE_SELECTION_ALGORITHM] as $ds) {
                    $ds->update();
                }
            } else {
                SharesConfig::set($share_name, CONFIG_DRIVE_SELECTION_ALGORITHM, $drive_selection_algorithm);
            }
            if (isset($share_options[CONFIG_DRIVE_SELECTION_GROUPS])) {
                SharesConfig::remove($share_name, CONFIG_DRIVE_SELECTION_GROUPS);
            }

            // Validate that the landing zone is NOT a subdirectory of a storage pool drive, and that storage pool drives are not subdirectories of the landing zone!
            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (string_starts_with($share_options[CONFIG_LANDING_ZONE], $sp_drive)) {
                    Log::critical("Found a share ($share_name), with path " . $share_options[CONFIG_LANDING_ZONE] . ", which is INSIDE a storage pool drive ($sp_drive). Share directories should never be inside a directory that you have in your storage pool.\nFor your shares to use your storage pool, you just need them to have 'vfs objects = greyhole' in their (smb.conf) config; their location on your file system is irrelevant.", Log::EVENT_CODE_CONFIG_LZ_INSIDE_STORAGE_POOL);
                }
                if (string_starts_with($sp_drive, $share_options[CONFIG_LANDING_ZONE])) {
                    Log::critical("Found a storage pool drive ($sp_drive), which is INSIDE a share landing zone (" . $share_options[CONFIG_LANDING_ZONE] . "), for share $share_name. Storage pool drives should never be inside a directory that you use as a share landing zone ('path' in smb.conf).\nFor your shares to use your storage pool, you just need them to have 'vfs objects = greyhole' in their (smb.conf) config; their location on your file system is irrelevant.", Log::EVENT_CODE_CONFIG_STORAGE_POOL_INSIDE_LZ);
                }
            }
        }

        // Check that all drives are included in at least one $drive_selection_algorithm
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $found = FALSE;
            foreach (SharesConfig::getShares() as $share_name => $share_options) {
                foreach ($share_options[CONFIG_DRIVE_SELECTION_ALGORITHM] as $ds) {
                    if (array_contains($ds->drives, $sp_drive)) {
                        $found = TRUE;
                    }
                }
            }
            if (!$found) {
                Log::warn("The storage pool drive '$sp_drive' is not part of any drive_selection_algorithm definition, and will thus never be used to receive any files.", Log::EVENT_CODE_CONFIG_STORAGE_POOL_DRIVE_NOT_IN_DRIVE_SELECTION_ALGO);
            }
        }

        $memory_limit = Config::get(CONFIG_MEMORY_LIMIT);
        ini_set('memory_limit', $memory_limit);
        if (preg_match('/G$/i',$memory_limit)) {
            $memory_limit = preg_replace('/G$/i','',$memory_limit);
            $memory_limit = $memory_limit * 1024 * 1024 * 1024;
        } else if (preg_match('/M$/i',$memory_limit)) {
            $memory_limit = preg_replace('/M$/i','',$memory_limit);
            $memory_limit = $memory_limit * 1024 * 1024;
        } else if (preg_match('/K$/i',$memory_limit)) {
            $memory_limit = preg_replace('/K$/i','',$memory_limit);
            $memory_limit = $memory_limit * 1024;
        }
        Config::set(CONFIG_MEMORY_LIMIT, $memory_limit);

        $tz = Config::get(CONFIG_TIMEZONE);
        if (empty($tz)) {
            $tz = @date_default_timezone_get();
        }
        date_default_timezone_set($tz);

        $db_options = array(
            'engine' => 'mysql',
            'schema' => "/usr/share/greyhole/schema-mysql.sql",
            'host' => Config::get(CONFIG_DB_HOST),
            'user' => Config::get(CONFIG_DB_USER),
            'pass' => Config::get(CONFIG_DB_PASS),
            'name' => Config::get(CONFIG_DB_NAME),
        );

        DB::setOptions($db_options);

        if (strtolower(Config::get(CONFIG_GREYHOLE_LOG_FILE)) == 'syslog') {
            openlog("Greyhole", LOG_PID, LOG_USER);
        }

        return TRUE;
    }

    private static function assert($check, $error_message, $event_code) {
        if ($check === FALSE) {
            Log::critical($error_message, $event_code);
        }
    }

    public static function test() {
        while (!ConfigHelper::parse()) {
            // Invalid config file; either it's missing storage_pool_drive, or it contains a share that isn't in smb.conf
            if (SystemHelper::is_amahi() && Log::actionIs(ACTION_DAEMON)) {
                // If running on Amahi, loop until the config works.
                // User might configure Greyhole later, and they don't want to show Greyhole 'offline' until then. Those users are easy to confuse! ;)
                sleep(600); // 10 minutes
            } else {
                // Otherwise, die.
                Log::critical("Config file parsing failed. Exiting.", Log::EVENT_CODE_CONFIG_FILE_PARSING_FAILED);
            }
        }
        // Config is OK; go on!
    }

}

final class Config {
    // Defaults
    public static $config = array(
        CONFIG_LOG_LEVEL                   => Log::DEBUG,
        CONFIG_DELETE_MOVES_TO_TRASH       => TRUE,
        CONFIG_LOG_MEMORY_USAGE            => FALSE,
        CONFIG_CALCULATE_MD5_DURING_COPY   => TRUE,
        CONFIG_PARALLEL_COPYING            => TRUE,
        CONFIG_CHECK_FOR_OPEN_FILES        => TRUE,
        CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE => FALSE,
        CONFIG_STORAGE_POOL_DRIVE          => array(),
        CONFIG_MIN_FREE_SPACE_POOL_DRIVE   => array(),
        CONFIG_STICKY_FILES                => array(),
        CONFIG_FROZEN_DIRECTORY            => array(),
        CONFIG_MEMORY_LIMIT                => '512M',
        CONFIG_TIMEZONE                    => FALSE,
        CONFIG_DRIVE_SELECTION_GROUPS      => array(),
        CONFIG_IGNORED_FILES               => array(),
        CONFIG_IGNORED_FOLDERS             => array(),
        CONFIG_MAX_QUEUED_TASKS            => 10000000,
        CONFIG_EXECUTED_TASKS_RETENTION    => 60,
        CONFIG_GREYHOLE_LOG_FILE           => '/var/log/greyhole.log',
        CONFIG_GREYHOLE_ERROR_LOG_FILE     => FALSE,
        CONFIG_EMAIL_TO                    => 'root',
        CONFIG_DF_CACHE_TIME               => 15,
        CONFIG_CHECK_SP_SCHEDULE           => NULL
    );

    /**
     * @param string $name The name of the config you want.
     * @param string $index (optional) If the specified config is an array, you can specify which element you want.
     * @return mixed|false FALSE if config is not found. Otherwise, its value.
     */
    public static function get($name, $index=NULL) {
        if ($index === NULL) {
            return isset(self::$config[$name]) ? self::$config[$name] : FALSE;
        } else {
            return isset(self::$config[$name][$index]) ? self::$config[$name][$index] : FALSE;
        }
    }

    public static function exists($name) {
        return isset(self::$config[$name]);
    }

    /**
     * @return array
     */
    public static function storagePoolDrives() {
        return self::get(CONFIG_STORAGE_POOL_DRIVE);
    }

    public static function set($name, $value) {
        self::$config[$name] = $value;
    }

    public static function add($name, $value, $index=NULL) {
        if ($index === NULL) {
            self::$config[$name][] = $value;
        } else {
            self::$config[$name][$index] = $value;
        }
    }
}

final class SharesConfig {
    private static $shares_config;

    private static function _getConfig($share) {
        if (!self::exists($share)) {
            self::$shares_config[$share] = array();
        }
        return self::$shares_config[$share];
    }

    public static function exists($share) {
        return isset(self::$shares_config[$share]);
    }

    public static function getShares() {
        $result = array();
        foreach (self::$shares_config as $share_name => $share_config) {
            if ($share_name != CONFIG_TRASH_SHARE) {
                $result[$share_name] = $share_config;
            }
        }
        return $result;
    }

    public static function getConfigForShare($share) {
        if (!self::exists($share)) {
            return FALSE;
        }
        return self::$shares_config[$share];
    }

    public static function removeShare($share) {
        unset(self::$shares_config[$share]);
    }

    public static function remove($share, $name) {
        unset(self::$shares_config[$share][$name]);
    }

    public static function get($share, $name, $index=NULL) {
        if (!self::exists($share)) {
            return FALSE;
        }
        $config = self::$shares_config[$share];
        if ($index === NULL) {
            return isset($config[$name]) ? $config[$name] : FALSE;
        } else {
            return isset($config[$name][$index]) ? $config[$name][$index] : FALSE;
        }
    }

    public static function set($share, $name, $value) {
        $config = self::_getConfig($share);
        $config[$name] = $value;
        self::$shares_config[$share] = $config;
    }

    public static function add($share, $name, $value, $index=NULL) {
        $config = self::_getConfig($share);
        if ($index === NULL) {
            $config[$name][] = $value;
        } else {
            $config[$name][$index] = $value;
        }
        self::$shares_config[$share] = $config;
    }

    public static function getNumCopies($share) {
        $num_copies = static::get($share, CONFIG_NUM_COPIES);
        if (!$num_copies) {
            Log::warn("Found a task on a share ($share) that disappeared from " . ConfigHelper::$config_file . ". Skipping.", Log::EVENT_CODE_TASK_FOR_UNKNOWN_SHARE);
            return -1;
        }
        if ($num_copies < 1) {
            $num_copies = 1;
        }
        $max_copies = 0;
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (StoragePool::is_pool_drive($sp_drive)) {
                $max_copies++;
            }
        }
        if ($num_copies > $max_copies) {
            $num_copies = $max_copies;
        }
        return $num_copies;
    }

    public static function getShareOptions($full_path) {
        $share = FALSE;
        $landing_zone = '';
        foreach (SharesConfig::getShares() as $share_name => $share_options) {
            $lz = $share_options[CONFIG_LANDING_ZONE];
            if (string_starts_with($full_path, $lz) && mb_strlen($lz) > mb_strlen($landing_zone)) {
                $landing_zone = $lz;
                $share = $share_options;
            }
        }
        return $share;
    }

    public static function getShareOptionsFromDrive($full_path, $sp_drive) {
        $landing_zone = '';
        $share = FALSE;
        foreach (SharesConfig::getShares() as $share_name => $share_options) {
            $lz = $share_options[CONFIG_LANDING_ZONE];
            $metastore = Metastores::get_metastore_from_path($full_path);
            if ($metastore !== FALSE) {
                if (string_starts_with($full_path, "$metastore/$share_name") && mb_strlen($lz) > mb_strlen($landing_zone)) {
                    $landing_zone = $lz;
                    $share = $share_options;
                }
            } else {
                if (string_starts_with($full_path, "$sp_drive/$share_name") && mb_strlen($lz) > mb_strlen($landing_zone)) {
                    $landing_zone = $lz;
                    $share = $share_options;
                }
            }
        }
        return $share;
    }

}

?>
