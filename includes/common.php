<?php
/*
Copyright 2009-2014 Guillaume Boudreau, Andrew Hopkinson

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
require_once('includes/Log.php');
require_once('includes/MigrationHelper.php');
require_once('includes/PoolDriveSelector.php');
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

$sleep_before_task = array();

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
        Log::error("PHP Fatal Error: " . $err['message'] . "; BT: " . basename($err['file']) . '[L' . $err['line'] . '] ');
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
        Log::critical("PHP Error [$errno]: $errstr in $errfile on line $errline");
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
        Log::warn("PHP Warning [$errno]: $errstr in $errfile on line $errline; BT: " . get_debug_bt());
        break;

    default:
        Log::warn("PHP Unknown Error [$errno]: $errstr in $errfile on line $errline");
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
        Log::warn("  Found a share ($share) with no path in " . ConfigHelper::$smb_config_file . ", or missing it's num_copies[$share] config in " . ConfigHelper::$config_file . ". Skipping.");
        return FALSE;
    }
}

function memory_check() {
    $usage = memory_get_usage();
    $used = $usage / Config::get(CONFIG_MEMORY_LIMIT);
    $used = $used * 100;
    if ($used > 95) {
        Log::critical("$used% memory usage, exiting. Please increase '" . CONFIG_MEMORY_LIMIT . "' in /etc/greyhole.conf");
    }
}

class metafile_iterator implements Iterator {
    private $path;
    private $share;
    private $load_nok_metafiles;
    private $quiet;
    private $check_symlink;
    private $metafiles;
    private $metastores;
    private $dir_handle;
    private $directory_stack;

    public function __construct($share, $path, $load_nok_metafiles=FALSE, $quiet=FALSE, $check_symlink=TRUE) {
        $this->quiet = $quiet;
        $this->share = $share;
        $this->path = $path;
        $this->check_symlink = $check_symlink;
        $this->load_nok_metafiles = $load_nok_metafiles;
    }

    public function rewind() {
        $this->metastores = get_metastores();
        $this->directory_stack = array($this->path);
        $this->dir_handle = NULL;
        $this->metafiles = array();
        $this->next();
    }

    public function current() {
        return $this->metafiles;
    }

    public function key() {
        return count($this->metafiles);
    }

    public function next() {
        $this->metafiles = array();
        while (count($this->directory_stack) > 0 && $this->directory_stack !== NULL) {
            $dir = array_pop($this->directory_stack);
            if (!$this->quiet) {
                Log::debug("Loading metadata files for (dir) " . clean_dir($this->share . (!empty($dir) ? "/" . $dir : "")) . " ...");
            }
            for ($i = 0; $i < count($this->metastores); $i++) {
                $metastore = $this->metastores[$i];
                $base = "$metastore/" . $this->share . "/";
                if (!file_exists($base . $dir)) {
                    continue;
                }    
                if ($this->dir_handle = opendir($base . $dir)) {
                    while (false !== ($file = readdir($this->dir_handle))) {
                        memory_check();
                        if ($file=='.' || $file=='..') {
                            continue;
                        }
                        if (!empty($dir)) {
                            $full_filename = $dir . '/' . $file;
                        } else {
                            $full_filename = $file;
                        }
                        if (is_dir($base . $full_filename)) {
                            $this->directory_stack[] = $full_filename;
                        } else {
                            $full_filename = str_replace("$this->path/",'',$full_filename);
                            if (isset($this->metafiles[$full_filename])) {
                                continue;
                            }                        
                            $this->metafiles[$full_filename] = get_metafiles_for_file($this->share, $dir, $file, $this->load_nok_metafiles, $this->quiet, $this->check_symlink);
                        }
                    }
                    closedir($this->dir_handle);
                    $this->directory_stack = array_unique($this->directory_stack);
                }
            }
            if (count($this->metafiles) > 0) {
                break;
            }
            
        }
        if (!$this->quiet) {
            Log::debug('Found ' . count($this->metafiles) . ' metadata files.');
        }
        return $this->metafiles;
    }
    
    public function valid() {
        return count($this->metafiles) > 0;
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
            $email_to = Config::get(CONFIG_EMAIL_TO);
            Log::warn("Sending $logfile by email to $email_to");
            mail($email_to, $this->getSubject(), $this->getBody());

            $this->lastEmailSentTime = $last_mod_date;
            Settings::set("last_email_$this->filename", $this->lastEmailSentTime);
        }
    }

    private function getBody() {
        $logfile = "$this->path/$this->filename";
        if ($this->filename == 'fsck_checksums.log') {
            return file_get_contents($logfile) . "\nNote: You should manually delete the $logfile file once you're done with it.";
        } else if ($this->filename == 'fsck_files.log') {
            global $fsck_report;
            $fsck_report = unserialize(file_get_contents($logfile));
            unlink($logfile);
            return get_fsck_report() . "\nNote: This report is a complement to the last report you've received. It details possible errors with files for which the fsck was postponed.";
        } else {
            return '[empty]';
        }
    }
    
    private function getSubject() {
        if ($this->filename == 'fsck_checksums.log') {
            return 'Mismatched checksums in Greyhole file copies';
        } else if ($this->filename == 'fsck_files.log') {
            return 'fsck_files of Greyhole shares on ' . exec('hostname');
        } else {
            return 'Unknown FSCK report';
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
    
    public static function loadFSCKReport($what) {
        $logfile = self::PATH . '/fsck_files.log';
        if (file_exists($logfile)) {
            global $fsck_report;
            $fsck_report = unserialize(file_get_contents($logfile));
            if ($fsck_report !== FALSE) {
                return;
            }
            rename($logfile, "$logfile.broken");
            // $fsck_report === FALSE; let's re-initialize it!
        }
        initialize_fsck_report($what);
    }

    public static function saveFSCKReport() {
        global $fsck_report;
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
    foreach (SharesConfig::getShares() as $share_name => $share_options) {
        $query = "INSERT INTO tasks SET action = 'fsck', share = :full_path, additional_info = :fsck_options, complete = 'yes'";
        $params = array(
            'full_path' => $share_options[CONFIG_LANDING_ZONE],
            'fsck_options' => empty($fsck_options) ? NULL : implode('|', $fsck_options)
        );
        DB::insert($query, $params);
    }
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
    return mb_strpos($haystack, $needle) !== FALSE;
}

function string_starts_with($haystack, $needle) {
    return mb_strpos($haystack, $needle) === 0;
}

function json_pretty_print($json) {
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

?>
