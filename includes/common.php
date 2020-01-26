<?php
/*
Copyright 2009-2020 Guillaume Boudreau, Andrew Hopkinson

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

// Other helpers
require_once('includes/ConfigHelper.php');
require_once('includes/DB.php');
require_once('includes/DBSpool.php');
require_once('includes/Hook.php');
require_once('includes/Log.php');
require_once('includes/Metastores.php');
require_once('includes/MigrationHelper.php');
require_once('includes/PoolDriveSelector.php');
require_once('includes/SambaSpool.php');
require_once('includes/SambaUtils.php');
require_once('includes/Settings.php');
require_once('includes/StoragePool.php');
require_once('includes/SystemHelper.php');

$constarray = get_defined_constants(true);
foreach($constarray['user'] as $key => $val) {
    eval(sprintf('$_CONSTANTS[\'%s\'] = ' . (is_int($val) || is_float($val) ? '%s' : "'%s'") . ';', addslashes($key), addslashes($val)));
}

define('FSCK_TYPE_SHARE', 1);
define('FSCK_TYPE_STORAGE_POOL_DRIVE', 2);
define('FSCK_TYPE_METASTORE', 3);

set_error_handler("gh_error_handler");
register_shutdown_function("gh_shutdown");

umask(0);

setlocale(LC_COLLATE, "en_US.UTF-8");
setlocale(LC_CTYPE, "en_US.UTF-8");

if (!defined('PHP_VERSION_ID')) {
    $version = explode('.', PHP_VERSION);
    define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));
}

DBSpool::resetSleepingTasks();

function clean_dir($dir) {
    if (empty($dir)) {
        return $dir;
    }
    if ($dir[0] == '.' && $dir[1] == '/') {
        $dir = mb_substr($dir, 2);
    }
    while (string_contains($dir, '//')) {
        $dir = str_replace("//", "/", $dir);
    }
    $l = strlen($dir);
    if ($l >= 2 && $dir[$l-2] == '/' && $dir[$l-1] == '.') {
        $dir = mb_substr($dir, 0, $l-2);
    }
    $dir = str_replace("/./", "/", $dir);
    return $dir;
}

function explode_full_path($full_path) {
    return array(dirname($full_path), basename($full_path));
}

function gh_shutdown() {
    if ($err = error_get_last()) {
        Log::error("PHP Fatal Error: " . $err['message'] . "; BT: " . basename($err['file']) . '[L' . $err['line'] . '] ', Log::EVENT_CODE_PHP_CRITICAL);
    }
}

function gh_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
    if(!($errno & error_reporting())) {
        // Ignored (@) warning
        return TRUE;
    }

    switch ($errno) {
    case E_ERROR:
    case E_PARSE:
    case E_CORE_ERROR:
    case E_COMPILE_ERROR:
        Log::critical("PHP Error [$errno]: $errstr in $errfile on line $errline", Log::EVENT_CODE_PHP_CRITICAL);
        break;

    case E_WARNING:
    case E_COMPILE_WARNING:
    case E_CORE_WARNING:
    case E_NOTICE:
        $greyhole_log_file = Config::get(CONFIG_GREYHOLE_LOG_FILE);
        if ($errstr == "fopen($greyhole_log_file): failed to open stream: Permission denied") {
            // We want to ignore this warning. Happens when regular users try to use greyhole, and greyhole tries to log something.
            // What would have been logged will be echoed instead.
            return TRUE;
        }
        Log::warn("PHP Warning [$errno]: $errstr in $errfile on line $errline; BT: " . get_debug_bt(), Log::EVENT_CODE_PHP_WARNING);
        break;

    default:
        Log::error("PHP Unknown Error [$errno]: $errstr in $errfile on line $errline", Log::EVENT_CODE_PHP_ERROR);
        break;
    }

    // Don't execute PHP internal error handler
    return TRUE;
}

function get_debug_bt() {
    $bt = '';
    foreach (debug_backtrace() as $d) {
        if ($d['function'] == 'gh_error_handler' || $d['function'] == 'get_debug_bt') { continue; }
        if ($bt != '') {
            $bt = " => $bt";
        }
        $prefix = '';
        if (isset($d['file'])) {
            $prefix = basename($d['file']) . '[L' . $d['line'] . '] ';
        }
        if (!isset($d['args'])) {
            $d['args'] = array();
        }
        foreach ($d['args'] as $k => $v) {
            if (is_object($v)) {
                $d['args'][$k] = 'stdClass';
            }
            if (is_array($v)) {
                $d['args'][$k] = str_replace("\n", "", var_export($v, TRUE));
            }
        }
        $bt = $prefix . $d['function'] .'(' . implode(',', @$d['args']) . ')' . $bt;
    }
    return $bt;
}

function bytes_to_human($bytes, $html=TRUE) {
    $units = 'B';
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = 'KB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = 'MB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = 'GB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = 'TB';
    }
    $decimals = (abs($bytes) > 100 ? 0 : (abs($bytes) > 10 ? 1 : 2));
    if ($html) {
        return number_format($bytes, $decimals) . " <span class=\"i18n-$units\">$units</span>";
    } else {
        return number_format($bytes, $decimals) . $units;
    }
}

function duration_to_human($seconds) {
    $displayable_duration = '';
    if ($seconds > 60*60) {
        $hours = floor($seconds / (60*60));
        $displayable_duration .= $hours . 'h ';
        $seconds -= $hours * (60*60);
    }
    if ($seconds > 60) {
        $minutes = floor($seconds / 60);
        $displayable_duration .= $minutes . 'm ';
        $seconds -= $minutes * 60;
    }
    $displayable_duration .= $seconds . 's';
    return $displayable_duration;
}

function get_share_landing_zone($share) {
    $lz = SharesConfig::get($share, CONFIG_LANDING_ZONE);
    if ($lz !== FALSE) {
        return $lz;
    } else if (array_contains(ConfigHelper::$trash_share_names, $share)) {
        return SharesConfig::get(CONFIG_TRASH_SHARE, CONFIG_LANDING_ZONE);
    } else {
        Log::warn("  Found a share ($share) with no path in " . ConfigHelper::$smb_config_file . ", or missing it's num_copies[$share] config in " . ConfigHelper::$config_file . ". Skipping.", Log::EVENT_CODE_SHARE_MISSING_FROM_GREYHOLE_CONF);
        return FALSE;
    }
}

function memory_check() {
    $usage = memory_get_usage();
    $used = $usage / Config::get(CONFIG_MEMORY_LIMIT);
    $used = $used * 100;
    if ($used > 95) {
        Log::critical("$used% memory usage, exiting. Please increase '" . CONFIG_MEMORY_LIMIT . "' in /etc/greyhole.conf", Log::EVENT_CODE_MEMORY_LIMIT_REACHED);
    }
}

class FSCKWorkLog {
    const FILE = '/usr/share/greyhole/fsck_work_log.dat';

    CONST STATUS_PENDING  = 'pending';
    CONST STATUS_ONGOING  = 'ongoing';
    CONST STATUS_COMPLETE = 'complete';

    public static function initiate($dir, $options, $task_ids) {
        $fsck_work_log = (object) [
            'dir'     => $dir,
            'options' => $options,
            'tasks'   => array(),
        ];
        foreach ($task_ids as $task_id) {
            $fsck_work_log->tasks[] = (object) ['id' => $task_id, 'status' => static::STATUS_PENDING];
        }
        static::saveToDisk($fsck_work_log);
    }

    public static function startTask($task_id) {
        $fsck_work_log = static::getFromDisk();
        foreach ($fsck_work_log->tasks as $task) {
            if ($task->id == $task_id) {
                $task->status = static::STATUS_ONGOING;
                static::saveToDisk($fsck_work_log);
                break;
            }
        }
    }

    public static function taskCompleted($task_id, $send_email) {
        $fsck_work_log = static::getFromDisk();
        foreach ($fsck_work_log->tasks as $task) {
            if ($task->id == $task_id) {
                $task->status = static::STATUS_COMPLETE;
                $fsck_task = FsckTask::getCurrentTask();
                $fsck_task->end_report();
                $task->report = $fsck_task->get_fsck_report();
                static::saveToDisk($fsck_work_log);
                break;
            }
        }
        $completed_tasks = static::getNumCompletedTasks();
        $total_tasks = count($fsck_work_log->tasks);
        if ($total_tasks > 1) {
            Log::info("Completed $completed_tasks/$total_tasks fsck tasks.");
        }
        if ($completed_tasks == $total_tasks) {
            // All tasks completed; email report
            if ($send_email || Hook::hasHookForEvent(LogHook::EVENT_TYPE_FSCK)) {
                // Email report for fsck
                $subject = "[Greyhole] fsck of $fsck_work_log->dir completed on " . exec('hostname');

                $fsck_report_body = "==========\n\n";
                foreach ($fsck_work_log->tasks as $task) {
                    $is_last = array_search($task, $fsck_work_log->tasks) == count($fsck_work_log->tasks)-1;
                    $fsck_report_body .= FsckTask::get_fsck_report_email_body($task->report, $is_last);
                    $fsck_report_body .= "==========\n\n";
                }

                if ($send_email) {
                    $email_to = Config::get(CONFIG_EMAIL_TO);
                    Log::debug("Sending fsck report to $email_to");
                    mail($email_to, $subject, $fsck_report_body);
                }

                LogHook::trigger(LogHook::EVENT_TYPE_FSCK, Log::EVENT_CODE_FSCK_REPORT, $subject . "\n" . $fsck_report_body);
            }
        }
    }

    private static function saveToDisk($fsck_work_log) {
        file_put_contents(static::FILE, serialize($fsck_work_log));
    }

    private static function getFromDisk() {
        return unserialize(file_get_contents(static::FILE));
    }

    private static function getNumCompletedTasks() {
        $completed_tasks = 0;
        $fsck_work_log = static::getFromDisk();
        foreach ($fsck_work_log->tasks as $task) {
            if ($task->status == static::STATUS_COMPLETE) {
                $completed_tasks++;
            }
        }
        return $completed_tasks;
    }
}

class FSCKLogFile {
    const PATH = '/usr/share/greyhole';

    private $path;
    private $filename;
    private $lastEmailSentTime = 0;
    
    public function __construct($filename, $path=self::PATH) {
        $this->filename = $filename;
        $this->path = $path;
    }
    
    public function emailAsRequired() {
        $logfile = "$this->path/$this->filename";
        if (!file_exists($logfile)) { return; }

        $last_mod_date = filemtime($logfile);
        if ($last_mod_date > $this->getLastEmailSentTime()) {
            if ($this->shouldSendViaEmail()) {
                $email_to = Config::get(CONFIG_EMAIL_TO);
                Log::info("Sending $logfile by email to $email_to");
                mail($email_to, $this->getSubject(), $this->getBody());
            }

            LogHook::trigger(LogHook::EVENT_TYPE_FSCK, Log::EVENT_CODE_FSCK_REPORT, $this->getSubject() . "\n" . $this->getBody());

            $this->lastEmailSentTime = $last_mod_date;
            Settings::set("last_email_$this->filename", $this->lastEmailSentTime);
        }
    }

    private function shouldSendViaEmail() {
        $this->getBody();
        $fsck_report = FsckTask::getCurrentTask()->get_fsck_report();
        return (@$fsck_report['send_via_email'] === TRUE);
    }

    private function getBody() {
        $logfile = "$this->path/$this->filename";
        if ($this->filename == 'fsck_checksums.log') {
            return file_get_contents($logfile) . "\nNote: You should manually delete the $logfile file once you're done with it.";
        } else if ($this->filename == 'fsck_files.log') {
            $fsck_report = unserialize(file_get_contents($logfile));
            unlink($logfile);
            return FsckTask::get_fsck_report_email_body($fsck_report, FALSE) . "\nNote: This report is a complement to the last report you've received. It details possible errors with files for which the fsck was postponed.";
        } else {
            return '[empty]';
        }
    }
    
    private function getSubject() {
        if ($this->filename == 'fsck_checksums.log') {
            return '[Greyhole] Mismatched checksums in file copies on ' . exec('hostname');
        } else if ($this->filename == 'fsck_files.log') {
            return '[Greyhole] fsck_files of Greyhole shares on ' . exec('hostname');
        } else {
            return '[Greyhole] Unknown fsck report on ' . exec('hostname');
        }
    }
    
    private function getLastEmailSentTime() {
        if ($this->lastEmailSentTime == 0) {
            $setting = Settings::get("last_email_$this->filename");
            if ($setting) {
                $this->lastEmailSentTime = (int) $setting;
            }
        }
        return $this->lastEmailSentTime;
    }

    /**
     * @param string   $what
     * @param FsckTask $task
     */
    public static function loadFSCKReport($what, $task) {
        $logfile = self::PATH . '/fsck_files.log';
        if (file_exists($logfile)) {
            $fsck_report = unserialize(file_get_contents($logfile));
            $task->set_fsck_report($fsck_report);
            if ($fsck_report !== FALSE) {
                return;
            }
            rename($logfile, "$logfile.broken");
            // $fsck_report === FALSE; let's re-initialize it!
        }
        $task->initialize_fsck_report($what);
    }

    /**
     * @param bool     $send_via_email
     * @param FsckTask $task
     */
    public static function saveFSCKReport($send_via_email, $task) {
        $fsck_report = $task->get_fsck_report();
        $fsck_report['send_via_email'] = $send_via_email;
        $logfile = self::PATH . '/fsck_files.log';
        file_put_contents($logfile, serialize($fsck_report));
    }
}

function fix_all_symlinks() {
    foreach (SharesConfig::getShares() as $share_name => $share_options) {
        fix_symlinks_on_share($share_name);
    }
}

function fix_symlinks_on_share($share_name) {
    $share_options = SharesConfig::getConfigForShare($share_name);
    echo "Looking for broken symbolic links in the share '$share_name'...";
    chdir($share_options[CONFIG_LANDING_ZONE]);
    exec("find -L . -type l", $result);
    foreach ($result as $file_to_relink) {
        if (is_link($file_to_relink)) {
            $file_to_relink = substr($file_to_relink, 2);
            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (!StoragePool::is_pool_drive($sp_drive)) { continue; }
                $new_link_target = clean_dir("$sp_drive/$share_name/$file_to_relink");
                if (gh_is_file($new_link_target)) {
                    unlink($file_to_relink);
                    gh_symlink($new_link_target, $file_to_relink);
                    break;
                }
            }
        }
    }
    echo " Done.\n";
}

function schedule_fsck_all_shares($fsck_options=array()) {
    $task_ids = array();
    foreach (SharesConfig::getShares() as $share_name => $share_options) {
        $query = "INSERT INTO tasks SET action = 'fsck', share = :full_path, additional_info = :fsck_options, complete = 'yes'";
        $params = array(
            'full_path' => $share_options[CONFIG_LANDING_ZONE],
            'fsck_options' => empty($fsck_options) ? NULL : implode('|', $fsck_options)
        );
        $task_ids[] = DB::insert($query, $params);
    }
    return $task_ids;
}

function kshift(&$arr) {
    if (count($arr) == 0) {
        return FALSE;
    }
    foreach ($arr as $k => $v) {
        unset($arr[$k]);
        break;
    }
    return array($k, $v);
}

function array_contains($haystack, $needle) {
    return array_search($needle, $haystack) !== FALSE;
}

function string_contains($haystack, $needle) {
    if (!is_string($haystack)) {
        return FALSE;
    }
    return mb_strpos($haystack, $needle) !== FALSE;
}

function string_starts_with($haystack, $needle) {
    if (is_array($needle)) {
        foreach ($needle as $n) {
            if (mb_strpos($haystack, $n) === 0) {
                return TRUE;
            }
        }
        return FALSE;
    }
    return mb_strpos($haystack, $needle) === 0;
}

function json_pretty_print($json) {
    if (defined('JSON_PRETTY_PRINT')) {
        if (is_string($json)) {
            $json = json_decode($json);
        }
        return json_encode($json, JSON_PRETTY_PRINT);
    }
    if (!is_string($json)) {
        $json = json_encode($json);
    }
    $result = '';
    $level = 0;
    $in_quotes = FALSE;
    $in_escape = FALSE;
    $ends_line_level = NULL;
    $json_length = strlen( $json );
    for ($i = 0; $i < $json_length; $i++) {
        $char = $json[$i];
        $new_line_level = NULL;
        $post = "";
        if ($ends_line_level !== NULL) {
            $new_line_level = $ends_line_level;
            $ends_line_level = NULL;
        }
        if ($in_escape) {
            $in_escape = FALSE;
        } else if ($char === '"') {
            $in_quotes = !$in_quotes;
        } else if (!$in_quotes) {
            switch($char) {
                case '}':
                case ']':
                    $level--;
                    $ends_line_level = NULL;
                    $new_line_level = $level;
                    break;
                case '{':
                case '[':
                    $level++;
                case ',':
                    $ends_line_level = $level;
                    break;
                case ':':
                    $post = " ";
                    break;

                case " ":
                case "\t":
                case "\n":
                case "\r":
                    $char = "";
                    $ends_line_level = $new_line_level;
                    $new_line_level = NULL;
                    break;
            }
        } else if ($char === '\\') {
            $in_escape = true;
        }
        if ($new_line_level !== NULL) {
            $result .= "\n" . str_repeat("  ", $new_line_level);
        }
        $result .= $char . $post;
    }
    return $result;
}

function gh_wild_mb_strpos($haystack, $needle) {
    $is_wild = string_contains($needle, "*");
    if (!$is_wild) {
        return mb_strpos($haystack, $needle);
    }
    if (str_replace('*', '', $needle) == $haystack) {
        return FALSE;
    }
    $needles = explode("*", $needle);
    if ($needle[0] == '*') {
        $first_index = 0;
    }
    foreach ($needles as $needle_part) {
        if ($needle_part == '') {
            continue;
        }
        $needle_index = mb_strpos($haystack, $needle_part);
        if (!isset($first_index)) {
            $first_index = $needle_index;
        }
        if ($needle_index === FALSE) {
            return FALSE;
        } else {
            $found = TRUE;
            $haystack = mb_substr($haystack, $needle_index + mb_strlen($needle_part));
        }
    }
    if ($found) {
        return $first_index;
    }
    return FALSE;
}

function str_replace_first($search, $replace, $subject) {
    $firstChar = mb_strpos($subject, $search);
    if ($firstChar !== FALSE) {
        $beforeStr = mb_substr($subject, 0, $firstChar);
        $afterStr = mb_substr($subject, $firstChar + mb_strlen($search));
        return $beforeStr . $replace . $afterStr;
    } else {
        return $subject;
    }
}

function normalize_utf8_characters($string) {
    // Requires intl PHP extension (php-intl)
    return normalizer_normalize($string);
}

function spawn_thread($action, $arguments) {
    // Don't spawn duplicate threads
    $num_worker_thread = (int) exec('ps ax | grep "/usr/bin/greyhole --' . $action . '" | grep "drive='. implode('" | grep "drive=', $arguments) . '" | grep -v grep | grep -v bash | wc -l');
    if ($num_worker_thread > 0) {
        Log::debug("Won't spawn a duplicate thread; 'greyhole --$action --drive=$arguments[0]' is already running");
        return 1;
    }

    $cmd = "/usr/bin/greyhole --$action --drive=" . implode(' --drive=', array_map('escapeshellarg', $arguments));
    exec("$cmd 1>/var/run/greyhole_thread.pid 2>&1 &");
    usleep(100000); // 1/10s
    return (int) file_get_contents('/var/run/greyhole_thread.pid');
}

function to_object($o) {
    if (is_array($o)) {
        return (object) $o;
    }
    return $o;
}

function first($array, $default=NULL) {
    if (is_iterable($array)) {
        foreach ($array as $el) {
            return $el;
        }
    }
    return $default;
}

function log_file_checksum($share, $full_path, $checksum) {
    $q = "INSERT INTO checksums SET id = :id, share = :share, full_path = :full_path, checksum = :checksum ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)";
    DB::insert(
        $q,
        [
            'id'        => md5(clean_dir("$share/$full_path")),
            'share'     => $share,
            'full_path' => $full_path,
            'checksum'  => $checksum,
        ]
    );
}

function get_share_and_fullpath_from_realpath($real_path) {
    $prefix = get_storage_volume_from_path($real_path);
    if (!$prefix) {
        $share_options = get_share_options_from_full_path($real_path);
        $lz = $share_options['landing_zone'];
        $array = explode('/', $lz);
        array_pop($array);
        $prefix = implode('/', $array);
    }
    $share_and_fullpath = substr($real_path, strlen($prefix)+1);
    $array = explode('/', $share_and_fullpath);
    $share = array_shift($array);
    $full_path = implode('/', $array);
    return array($share, $full_path);
}

/**
 * Return a human-readable string that indicates how long ago the specified time occurred.
 *
 * @param $past_time int Timestamp
 *
 * @return string
 */
function how_long_ago($past_time) {
    $ago = '';
    $s = time() - $past_time;
    $m = floor($s / 60);
    if ($m > 0) {
        $s -= $m * 60;
        $h = floor($m / 60);
        if ($h > 0) {
            $ago = $h . "h ";
            $m -= $h * 60;
        }
        $ago = $ago . $m . "m ";
    }
    $ago = $ago . $s . "s";
    if ($ago == '0s') {
        return 'just now';
    }
    return "$ago ago";
}

?>
