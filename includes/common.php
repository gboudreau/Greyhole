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
require_once('includes/StorageFile.php');
require_once('includes/StoragePool.php');
require_once('includes/SystemHelper.php');
require_once('includes/Trash.php');

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

function gh_error_handler($errno, $errstr, $errfile, $errline, $errcontext = NULL) {
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
            $d['args'] = [];
        }
        $args = [];
        foreach ($d['args'] as $v) {
            if (is_object($v)) {
                $args[] = 'stdClass';
            } elseif (is_array($v)) {
                $args[] = str_replace("\n", "", var_export($v, TRUE));
            } else {
                $args[] = $v;
            }
        }
        $bt = $prefix . $d['function'] .'(' . implode(',', $args) . ')' . $bt;
    }
    return $bt;
}

function bytes_to_human($bytes, $html=TRUE, $iso_units=FALSE) {
    $units = 'B';
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = $iso_units ? 'KiB' : 'KB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = $iso_units ? 'MiB' : 'MB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = $iso_units ? 'GiB' : 'GB';
    }
    if (abs($bytes) > 1024) {
        $bytes /= 1024;
        $units = $iso_units ? 'TiB' : 'TB';
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
        Log::critical("$used% memory usage, exiting. Please increase '" . CONFIG_MEMORY_LIMIT . "' in " . ConfigHelper::$config_file, Log::EVENT_CODE_MEMORY_LIMIT_REACHED);
    }
}

function email_sysadmin($subject, $body) {
    $email_to = Config::get(CONFIG_EMAIL_TO);
    Log::debug("Sending email to $email_to; subject: $subject");
    mail($email_to, $subject, $body);
    EmailHook::trigger($subject, $body);
}

define('FSCK_COUNT_META_FILES',           1);
define('FSCK_COUNT_META_DIRS',            2);
define('FSCK_COUNT_LZ_FILES',             3);
define('FSCK_COUNT_LZ_DIRS',              4);
define('FSCK_COUNT_ORPHANS',              5);
define('FSCK_COUNT_SYMLINK_TARGET_MOVED', 6);
define('FSCK_COUNT_TOO_MANY_COPIES',      7);
define('FSCK_COUNT_MISSING_COPIES',       8);
define('FSCK_COUNT_GONE_OK',              9);
define('FSCK_PROBLEM_NO_COPIES_FOUND',   10);
define('FSCK_PROBLEM_TOO_MANY_COPIES',   11);
define('FSCK_PROBLEM_WRONG_COPY_SIZE',   12);
define('FSCK_PROBLEM_TEMP_FILE',         13);
define('FSCK_PROBLEM_WRONG_MD5',         14);

class FSCKReport {
    /** @var int */
    public $start;
    /** @var int */
    public $end;
    /** @var string */
    public $what;
    /** @var bool */
    public $send_via_email;
    /** @var int[] */
    public $counts;
    /** @var array */
    public $found_problems;

    /** @var string[] */
    protected static $dirs = [];

    public function __construct($what, $reset_dirs = FALSE) {
        $this->start          = time();
        $this->end            = NULL;
        $this->what           = $what;
        $this->send_via_email = FALSE;

        $this->counts = array();
        foreach (array(FSCK_COUNT_META_FILES, FSCK_COUNT_META_DIRS, FSCK_COUNT_LZ_FILES, FSCK_COUNT_LZ_DIRS, FSCK_COUNT_ORPHANS, FSCK_COUNT_SYMLINK_TARGET_MOVED, FSCK_COUNT_TOO_MANY_COPIES, FSCK_COUNT_MISSING_COPIES, FSCK_COUNT_GONE_OK) as $name) {
            $this->counts[$name] = 0;
        }

        $this->found_problems = array();
        foreach (array(FSCK_PROBLEM_NO_COPIES_FOUND, FSCK_PROBLEM_TOO_MANY_COPIES, FSCK_PROBLEM_WRONG_COPY_SIZE, FSCK_PROBLEM_TEMP_FILE) as $name) {
            $this->found_problems[$name] = array();
        }

        if ($reset_dirs) {
            static::$dirs = [];
        }
    }

    public function count($what) {
        $this->counts[$what]++;
    }

    public function found_problem($what, $value = NULL, $key = NULL) {
        if (isset($this->counts[$what])) {
            $this->count($what);
        } elseif (!empty($key)) {
            $this->found_problems[$what][$key] = $value;
        } else {
            $this->found_problems[$what][] = $value;
        }
    }

    /**
     * @param bool $include_trash_size
     *
     * @return string
     */
    public function get_email_body($include_trash_size) {
        if (empty($this->end)) {
            $this->end = time();
        }

        $displayable_duration = duration_to_human($this->end - $this->start);

        $report  = "Scanned directory: " . $this->what . "\n\n";
        $report .= "Started:  " . date('Y-m-d H:i:s', $this->start) . "\n";
        $report .= "Ended:    " . date('Y-m-d H:i:s', $this->end) . "\n";
        $report .= "Duration: $displayable_duration\n\n";
        $report .= "Landing Zone (shares):\n";
        $report .= "  Scanned " . number_format($this->counts[FSCK_COUNT_LZ_DIRS]) . " directories\n";
        $report .= "  Found " . number_format($this->counts[FSCK_COUNT_LZ_FILES]) . " files\n\n";

        if ($this->counts[FSCK_COUNT_META_DIRS] > 0) {
            $report .= "Metadata Store:\n";
            $report .= "  Scanned " . number_format($this->counts[FSCK_COUNT_META_DIRS]) . " directories\n";
            $report .= "  Found " . number_format($this->counts[FSCK_COUNT_META_FILES]) . " files\n";
            $report .= "  Found " . number_format($this->counts[FSCK_COUNT_ORPHANS]) . " orphans\n\n";
        }

        // Errors
        if (empty($this->found_problems[FSCK_PROBLEM_NO_COPIES_FOUND]) && empty($this->found_problems[FSCK_PROBLEM_WRONG_COPY_SIZE])) {
            $report .= "No problems found.\n\n";
        } else {
            $report .= "Problems:\n";

            $problems = $this->found_problems[FSCK_PROBLEM_NO_COPIES_FOUND];
            if (!empty($problems)) {
                $problems = array_unique($problems);
                sort($problems);
                $report .= "  Found " . count($problems) . " files in the metadata store for which no file copies were found.\n";
                if (FsckTask::getCurrentTask()->has_option(OPTION_DEL_ORPHANED_METADATA)) {
                    $report .= "    Those metadata files have been deleted, since you used the --delete-orphaned-metadata option. They will not re-appear in the future.\n";
                } else {
                    $report .= "    Those files were removed from the Landing Zone. (i.e. those files are now gone!) They will re-appear in your shares if a copy re-appear and fsck is run.\n";
                    /** @noinspection RequiredAttributes */
                    $report .= "    If you don't want to see those files listed here each time fsck runs, delete the corresponding files from the metadata store using \"greyhole --delete-metadata='<path>'\", where <path> is one of the value listed below.\n";
                }
                $report .= "  Files with no copies:\n";
                $report .= "    " . implode("\n    ", $problems) . "\n\n";
            }

            $problems = $this->found_problems[FSCK_PROBLEM_WRONG_COPY_SIZE];
            if (!empty($problems)) {
                $report .= "  Found " . count($problems) . " file copies with the wrong file size. Those files don't have the same file size as the original files available on your shares. The invalid copies have been moved into the trash.\n";
                foreach ($problems as $real_file_path => $info_array) {
                    $report .= "    $real_file_path is " . number_format($info_array[0]) . " bytes; should be " . number_format($info_array[1]) . " bytes.\n";
                }
                $report .= "\n\n";
            }
        }

        // Warnings
        /** @noinspection PhpStatementHasEmptyBodyInspection */
        if ($this->counts[FSCK_COUNT_TOO_MANY_COPIES] == 0 && $this->counts[FSCK_COUNT_SYMLINK_TARGET_MOVED] == 0 && count($this->found_problems[FSCK_PROBLEM_TEMP_FILE]) == 0 && $this->counts[FSCK_COUNT_GONE_OK] == 0) {
            // Nothing to say...
        } else {
            $report .= "Notices:\n";

            if ($this->counts[FSCK_COUNT_TOO_MANY_COPIES] > 0) {
                $problems = array_unique($this->found_problems[FSCK_PROBLEM_TOO_MANY_COPIES]);
                $report .= "  Found " . $this->counts[FSCK_COUNT_TOO_MANY_COPIES] . " files for which there was too many file copies. Deleted (or moved in trash) files:\n";
                $report .= "    " . implode("\n    ", $problems) . "\n\n";
            }

            if ($this->counts[FSCK_COUNT_SYMLINK_TARGET_MOVED] > 0) {
                $report .= "  Found " . $this->counts[FSCK_COUNT_SYMLINK_TARGET_MOVED] . " files in the Landing Zone that were pointing to a now gone copy.
    Those symlinks were updated to point to the new location of those file copies.\n\n";
            }

            if (count($this->found_problems[FSCK_PROBLEM_TEMP_FILE]) > 0) {
                $problems = $this->found_problems[FSCK_PROBLEM_TEMP_FILE];
                $report .= "  Found " . count($problems) . " temporary files, which are leftovers of interrupted Greyhole executions. The following temporary files were deleted (or moved into the trash):\n";
                $report .= "    " . implode("\n    ", $problems) . "\n\n";
            }

            if ($this->counts[FSCK_COUNT_GONE_OK] > 0) {
                $report .= "  Found " . $this->counts[FSCK_COUNT_GONE_OK] . " missing files that are in a storage pool drive marked Temporarily-Gone.
  If this drive is gone for good, you should execute the following command, and remove the drive from your configuration file:
    greyhole --remove=path
  where path is one of:\n";
                $report .= "    " . implode("\n    ", array_keys(StoragePool::get_gone_ok_drives())) . "\n\n";
            }
        }

        if ($include_trash_size) {
            $report .= "==========\n\n";
            $report .= static::get_trash_size_report();
        }

        return $report;
    }

    /**
     * @param FSCKReport $fsck_report
     */
    public function mergeReport($fsck_report) {
        if (empty(static::$dirs)) {
            $this->start = $fsck_report->start;
        }
        $this->end = @$fsck_report->end;

        static::$dirs[] = $fsck_report->what;
        $this->what = "\n  - " . implode("\n  - ", static::$dirs);

        $this->send_via_email = $fsck_report->send_via_email;

        foreach (array(FSCK_COUNT_META_FILES, FSCK_COUNT_META_DIRS, FSCK_COUNT_LZ_FILES, FSCK_COUNT_LZ_DIRS, FSCK_COUNT_ORPHANS, FSCK_COUNT_SYMLINK_TARGET_MOVED, FSCK_COUNT_TOO_MANY_COPIES, FSCK_COUNT_MISSING_COPIES, FSCK_COUNT_GONE_OK) as $name) {
            $this->counts[$name] += $fsck_report->counts[$name];
        }

        foreach (array(FSCK_PROBLEM_NO_COPIES_FOUND, FSCK_PROBLEM_TOO_MANY_COPIES, FSCK_PROBLEM_WRONG_COPY_SIZE, FSCK_PROBLEM_TEMP_FILE) as $name) {
            if (!empty($fsck_report->found_problems[$name])) {
                $this->found_problems[$name] = array_merge($this->found_problems[$name], $fsck_report->found_problems[$name]);
            }
        }
    }

    public static function get_trash_size_report() {
        $report = "Trash size:\n";
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $trash_path = clean_dir("$sp_drive/.gh_trash");
            if (is_dir($trash_path)) {
                $report .= "  $trash_path = " . trim(exec("du -sh " . escapeshellarg($trash_path) . " | awk '{print $1}'"))."\n";
            } else if (!file_exists($sp_drive)) {
                $report .= "  $sp_drive = N/A\n";
            } else {
                $report .= "  $trash_path = empty\n";
            }
        }
        $report .= "\n";
        return $report;
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

    public static function isReportAvailable() {
        return file_exists(static::FILE) && !empty(unserialize(file_get_contents(static::FILE)));
    }

    public static function getHumanReadableReport() {
        $fsck_work_log = static::getFromDisk();

        if (count($fsck_work_log->tasks) == 1) {
            $task = first($fsck_work_log->tasks);
            if (empty($task->report)) {
                $task->report = FsckTask::getCurrentTask()->get_fsck_report();
            }
            if (!empty($task->report)) {
                $fsck_report_body = $task->report->get_email_body(FALSE);
            } else {
                $fsck_report_body = "No fsck report available.\n\n";
            }
        } else {
            //  All shares
            $report = new FSCKReport(NULL, TRUE);
            foreach ($fsck_work_log->tasks as $task) {
                $report->mergeReport($task->report);
            }
            $fsck_report_body = $report->get_email_body(TRUE);
        }

        return $fsck_report_body;
    }

    public static function taskCompleted($task_id, $send_email) {
        $fsck_work_log = static::getFromDisk();
        foreach ($fsck_work_log->tasks as $task) {
            if ($task->id == $task_id) {
                $task->status = static::STATUS_COMPLETE;
                $fsck_task = FsckTask::getCurrentTask();
                $task->report = $fsck_task->get_fsck_report();
                $task->report->end = time();
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

                $fsck_report_body = static::getHumanReadableReport();

                if ($send_email) {
                    email_sysadmin($subject, $fsck_report_body);
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
                email_sysadmin($this->getSubject(), $this->getBody());
            }

            LogHook::trigger(LogHook::EVENT_TYPE_FSCK, Log::EVENT_CODE_FSCK_REPORT, $this->getSubject() . "\n" . $this->getBody());

            $this->lastEmailSentTime = $last_mod_date;
            Settings::set("last_email_$this->filename", $this->lastEmailSentTime);
        }
    }

    private function shouldSendViaEmail() {
        $this->getBody();
        $fsck_report = FsckTask::getCurrentTask()->get_fsck_report();
        return (@$fsck_report->send_via_email === TRUE);
    }

    private function getBody() {
        $logfile = "$this->path/$this->filename";
        if ($this->filename == 'fsck_checksums.log') {
            return file_get_contents($logfile) . "\nNote: You should manually delete the $logfile file once you're done with it.";
        } else if ($this->filename == 'fsck_files.log' && file_exists($logfile)) {
            $fsck_report = unserialize(file_get_contents($logfile));
            /** @var FSCKReport $fsck_report */
            unlink($logfile);
            return $fsck_report->get_email_body(FALSE) . "\nNote: This report is a complement to the last report you've received. It details possible errors with files for which the fsck was postponed.";
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
        $fsck_report->send_via_email = $send_via_email;
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
            if (string_starts_with($haystack, $n)) {
                return TRUE;
            }
        }
        return FALSE;
    }
    return mb_strpos($haystack, $needle) === 0;
}

function string_ends_with($haystack, $needle) {
    if (is_array($needle)) {
        foreach ($needle as $n) {
            if (string_ends_with($haystack, $n)) {
                return TRUE;
            }
        }
        return FALSE;
    }
    return ( substr(strtolower($haystack), -strlen($needle)) == strtolower($needle) );
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

if (!function_exists('is_iterable')) {
    function is_iterable($var) {
        return is_array($var) || $var instanceof Traversable;
    }
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
    $prefix = StoragePool::getDriveFromPath($real_path);
    if (!$prefix) {
        $share_options = SharesConfig::getShareOptions($real_path);
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

function to_array($el) {
    if (is_array($el)) {
        return $el;
    }
    return [$el];
}

function get_config_hash() {
    exec("cat " . escapeshellarg(ConfigHelper::$config_file) . " | grep -v '^\s*#' | grep -v '^\s*$'", $output);
    $output = array_map('trim', $output);
    return md5(implode("\n", $output));
}

function get_config_hash_samba() {
    exec("/usr/bin/testparm -ls 2>/dev/null", $output);
    $output = array_map('trim', $output);
    return md5(implode("\n", $output));
}

?>
