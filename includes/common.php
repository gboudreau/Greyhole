<?php
/*
Copyright 2009-2011 Guillaume Boudreau, Andrew Hopkinson

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

define('PERF', 9);
define('TEST', 8);
define('DEBUG', 7);
define('INFO',  6);
define('WARN',  4);
define('ERROR', 3);
define('CRITICAL', 2);

$action = 'initialize';

date_default_timezone_set(date_default_timezone_get());

set_error_handler("gh_error_handler");
register_shutdown_function("gh_shutdown");

umask(0);

setlocale(LC_COLLATE, "en_US.UTF-8");
setlocale(LC_CTYPE, "en_US.UTF-8");

if (!defined('PHP_VERSION_ID')) {
    $version = explode('.', PHP_VERSION);
    define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));
}

$constarray = get_defined_constants(true);
foreach($constarray['user'] as $key => $val) {
    eval(sprintf('$_CONSTANTS[\'%s\'] = ' . (is_int($val) || is_float($val) ? '%s' : "'%s'") . ';', addslashes($key), addslashes($val)));
}

// Cached df results
$last_df_time = 0;
$last_dfs = array();
$sleep_before_task = array();

if (!isset($config_file)) {
	$config_file = '/etc/greyhole.conf';
}

if (!isset($smb_config_file)) {
	$smb_config_file = '/etc/samba/smb.conf';
}

$trash_share_names = array('Greyhole Attic', 'Greyhole Trash', 'Greyhole Recycle Bin');

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
				gh_log(WARN, "Config file '{$file}' is executable but file permissions are insecure, only the file's contents will be included.");
			}
		}

		$contents = $ok_to_execute ? shell_exec(escapeshellcmd($file)) : file_get_contents($file);
		
		return preg_replace_callback($regex, 'recursive_include_parser', $contents);
	} else {
		return false;
	}
}

function parse_config() {
	global $_CONSTANTS, $log_level, $storage_pool_drives, $shares_options, $minimum_free_space_pool_drives, $df_command, $config_file, $smb_config_file, $sticky_files, $db_options, $frozen_directories, $trash_share_names, $max_queued_tasks, $memory_limit, $delete_moves_to_trash, $greyhole_log_file, $email_to, $log_memory_usage, $check_for_open_files, $allow_multiple_sp_per_device, $df_cache_time;

	$deprecated_options = array(
		'delete_moves_to_attic' => 'delete_moves_to_trash',
		'storage_pool_directory' => 'storage_pool_drive',
		'dir_selection_groups' => 'drive_selection_groups',
		'dir_selection_algorithm' => 'drive_selection_algorithm'
	);

	$parsing_drive_selection_groups = FALSE;
	$shares_options = array();
	$storage_pool_drives = array();
	$frozen_directories = array();
	$config_text = recursive_include_parser($config_file);
	
	// Defaults
	$log_level = DEBUG;
	$greyhole_log_file = '/var/log/greyhole.log';
	$email_to = 'root';
	$log_memory_usage = FALSE;
	$check_for_open_files = TRUE;
	$allow_multiple_sp_per_device = FALSE;
	$df_cache_time = 15;
	$delete_moves_to_trash = TRUE;
	$memory_limit = '128M';
	
	foreach (explode("\n", $config_text) as $line) {
		if (preg_match("/^[ \t]*([^=\t]+)[ \t]*=[ \t]*([^#]+)/", $line, $regs)) {
			$name = trim($regs[1]);
			$value = trim($regs[2]);
			if ($name[0] == '#') {
				continue;
			}
			
			foreach ($deprecated_options as $old_name => $new_name) {
			    if (mb_strpos($name, $old_name) !== FALSE) {
				    $fixed_name = str_replace($old_name, $new_name, $name);
				    #gh_log(WARN, "Deprecated option found in greyhole.conf: $name. You should change that to: $fixed_name");
				    $name = $fixed_name;
				}
			}

			$parsing_drive_selection_groups = FALSE;
			switch($name) {
				case 'log_level':
					$log_level = $_CONSTANTS[$value];
					break;
				case 'delete_moves_to_trash': // or delete_moves_to_attic
				case 'log_memory_usage':
				case 'check_for_open_files':
				case 'allow_multiple_sp_per_device':
					global ${$name};
					${$name} = trim($value) === '1' || mb_stripos($value, 'yes') !== FALSE || mb_stripos($value, 'true') !== FALSE;
					break;
				case 'storage_pool_drive': // or storage_pool_directory
					if (preg_match("/(.*) ?, ?min_free ?: ?([0-9]+) ?([gmk])b?/i", $value, $regs)) {
						$storage_pool_drives[] = trim($regs[1]);
						if (strtolower($regs[3]) == 'g') {
							$minimum_free_space_pool_drives[trim($regs[1])] = (float) trim($regs[2]);
						} else if (strtolower($regs[3]) == 'm') {
							$minimum_free_space_pool_drives[trim($regs[1])] = (float) trim($regs[2]) / 1024.0;
						} else if (strtolower($regs[3]) == 'k') {
							$minimum_free_space_pool_drives[trim($regs[1])] = (float) trim($regs[2]) / 1024.0 / 1024.0;
						}
					}
					break;
				case 'wait_for_exclusive_file_access':
					$shares = explode(',', str_replace(' ', '', $value));
					foreach ($shares as $share) {
						$shares_options[$share]['wait_for_exclusive_file_access'] = TRUE;
					}
					break;
				case 'sticky_files':
					$last_sticky_files_dir = trim($value, '/');
					$sticky_files[$last_sticky_files_dir] = array();
					break;
				case 'stick_into':
					$sticky_files[$last_sticky_files_dir][] = '/' . trim($value, '/');
					break;
				case 'frozen_directory':
					$frozen_directories[] = trim($value, '/');
					break;
				case 'memory_limit':
					ini_set('memory_limit',$value);
					$memory_limit = $value;
					break;
				case 'drive_selection_groups': // or dir_selection_groups
				    if (preg_match("/(.+):(.*)/", $value, $regs)) {
    				    global $drive_selection_groups;
				        $group_name = trim($regs[1]);
						$drive_selection_groups[$group_name] = array_map('trim', explode(',', $regs[2]));
						$parsing_drive_selection_groups = TRUE;
					}
					break;
				case 'drive_selection_algorithm': // or dir_selection_algorithm
				    global $drive_selection_algorithm;
				    $drive_selection_algorithm = DriveSelection::parse($value, @$drive_selection_groups);
				    break;
				default:
					if (mb_strpos($name, 'num_copies') === 0) {
						$share = mb_substr($name, 11, mb_strlen($name)-12);
						if (mb_stripos($value, 'max') === 0) {
							$value = 9999;
						}
						$shares_options[$share]['num_copies'] = (int) $value;
					} else if (mb_strpos($name, 'delete_moves_to_trash') === 0) {
						$share = mb_substr($name, 22, mb_strlen($name)-23);
						$shares_options[$share]['delete_moves_to_trash'] = trim($value) === '1' || mb_strpos(strtolower(trim($value)), 'yes') !== FALSE || mb_strpos(strtolower(trim($value)), 'true') !== FALSE;
					} else if (mb_strpos($name, 'drive_selection_groups') === 0) { // or dir_selection_groups
						$share = mb_substr($name, 23, mb_strlen($name)-24);
    				    if (preg_match("/(.+):(.+)/", $value, $regs)) {
    						$group_name = trim($regs[1]);
    						$shares_options[$share]['drive_selection_groups'][$group_name] = array_map('trim', explode(',', $regs[2]));
    						$parsing_drive_selection_groups = $share;
    					}
					} else if (mb_strpos($name, 'drive_selection_algorithm') === 0) { // or dir_selection_algorithm
						$share = mb_substr($name, 26, mb_strlen($name)-27);
						if (!isset($shares_options[$share]['drive_selection_groups'])) {
						    $shares_options[$share]['drive_selection_groups'] = @$drive_selection_groups;
						}
						$shares_options[$share]['drive_selection_algorithm'] = DriveSelection::parse($value, $shares_options[$share]['drive_selection_groups']);
					} else {
						global ${$name};
						if (is_numeric($value)) {
							${$name} = (int) $value;
						} else {
							${$name} = $value;
						}
					}
			}
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
    				$shares_options[$share]['drive_selection_groups'][$group_name] = $drives;
				} else {
    				$drive_selection_groups[$group_name] = $drives;
				}
			}
	    }
	}
	
	if (is_array($storage_pool_drives) && count($storage_pool_drives) > 0) {
		$df_command = "df -k";
		foreach ($storage_pool_drives as $key => $sp_drive) {
			$df_command .= " " . escapeshellarg($sp_drive);
			$storage_pool_drives[$key] = '/' . trim($sp_drive, '/');
		}
		$df_command .= " 2>&1 | grep '%' | grep -v \"^df: .*: No such file or directory$\"";
	} else {
		gh_log(WARN, "You have no storage_pool_drive defined. Greyhole can't run.");
		return FALSE;
	}

	exec('testparm -s ' . escapeshellarg($smb_config_file) . ' 2> /dev/null', $config_text);
	foreach ($config_text as $line) {
		$line = trim($line);
		if (mb_strlen($line) == 0) { continue; }
		if ($line[0] == '[' && preg_match('/\[([^\]]+)\]/', $line, $regs)) {
			$share_name = $regs[1];
		}
		if (isset($share_name) && !isset($shares_options[$share_name]) && array_search($share_name, $trash_share_names) === FALSE) { continue; }
		if (isset($share_name) && preg_match('/^\s*path[ \t]*=[ \t]*(.+)$/i', $line, $regs)) {
			$shares_options[$share_name]['landing_zone'] = '/' . trim($regs[1], '/');
			$shares_options[$share_name]['name'] = $share_name;
		}
	}

    global $drive_selection_algorithm;
    if (isset($drive_selection_algorithm)) {
        foreach ($drive_selection_algorithm as $ds) {
            $ds->update();
        }
    } else {
        // Default drive_selection_algorithm
        $drive_selection_algorithm = DriveSelection::parse('most_available_space', null);
    }
	foreach ($shares_options as $share_name => $share_options) {
		if (array_search($share_name, $trash_share_names) !== FALSE) {
			global $trash_share;
			$trash_share = array('name' => $share_name, 'landing_zone' => $shares_options[$share_name]['landing_zone']);
			unset($shares_options[$share_name]);
			continue;
		}
		if ($share_options['num_copies'] > count($storage_pool_drives)) {
			$share_options['num_copies'] = count($storage_pool_drives);
		}
		if (!isset($share_options['landing_zone'])) {
			global $config_file, $smb_config_file;
			gh_log(WARN, "Found a share ($share_name) defined in $config_file with no path in $smb_config_file. Either add this share in $smb_config_file, or remove it from $config_file, then restart Greyhole.");
			return FALSE;
		}
		if (!isset($share_options['delete_moves_to_trash'])) {
		    $share_options['delete_moves_to_trash'] = $delete_moves_to_trash;
		}
		if (isset($share_options['drive_selection_algorithm'])) {
            foreach ($share_options['drive_selection_algorithm'] as $ds) {
                $ds->update();
            }
		} else {
		    $share_options['drive_selection_algorithm'] = $drive_selection_algorithm;
		}
		if (isset($share_options['drive_selection_groups'])) {
    		unset($share_options['drive_selection_groups']);
		}
		$shares_options[$share_name] = $share_options;
		
		// Validate that the landing zone is NOT a subdirectory of a storage pool drive!
		foreach ($storage_pool_drives as $key => $sp_drive) {
			if (mb_strpos($share_options['landing_zone'], $sp_drive) === 0) {
				gh_log(CRITICAL, "Found a share ($share_name), with path " . $share_options['landing_zone'] . ", which is INSIDE a storage pool drive ($sp_drive). Share directories should never be inside a directory that you have in your storage pool.\nFor your shares to use your storage pool, you just need them to have 'vfs objects = greyhole' in their (smb.conf) config; their location on your file system is irrelevant.");
			}
		}
	}
	
	if (!isset($db_engine)) {
		$db_engine = 'mysql';
	} else {
		$db_engine = mb_strtolower($db_engine);
	}

	global ${"db_use_$db_engine"};
	${"db_use_$db_engine"} = TRUE;
	
	if (!isset($max_queued_tasks)) {
		if ($db_engine == 'sqlite') {
			$max_queued_tasks = 1000;
		} else {
			$max_queued_tasks = 10000000;
		}
	}

	if (!isset($memory_limit)) {
		ini_set('memory_limit', $memory_limit);
	}
	if (isset($memory_limit)) {
		if(preg_match('/M$/',$memory_limit)) {
			$memory_limit = preg_replace('/M$/','',$memory_limit);
			$memory_limit = $memory_limit * 1048576;
		}elseif(preg_match('/K$/',$memory_limit)) {
			$memory_limit = preg_replace('/K$/','',$memory_limit);
			$memory_limit = $memory_limit * 1024;
		}
	}
	$db_options = (object) array(
		'engine' => $db_engine,
		'schema' => "/usr/share/greyhole/schema-$db_engine.sql"
	);
	if ($db_options->engine == 'sqlite') {
		$db_options->db_path = $db_path;
		$db_options->dbh = null; // internal handle to use with sqlite
	} else {
		$db_options->host = $db_host;
		$db_options->user = $db_user;
		$db_options->pass = $db_pass;
		$db_options->name = $db_name;
	}
	
	include('includes/sql.php');
    
	if (strtolower($greyhole_log_file) == 'syslog') {
		openlog("Greyhole", LOG_PID, LOG_USER);
	}
	
	return TRUE;
}

function clean_dir($dir) {
	if ($dir[0] == '.' && $dir[1] == '/') {
		$dir = mb_substr($dir, 2);
	}
	while (mb_strpos($dir, '//') !== FALSE) {
		$dir = str_replace("//", "/", $dir);
	}
	return $dir;
}

function explode_full_path($full_path) {
	return array(dirname($full_path), basename($full_path));
}

function gh_log($local_log_level, $text) {
	global $greyhole_log_file, $log_level, $log_memory_usage, $action, $log_to_stdout;
	if ($local_log_level > $log_level) {
		return;
	}

	$date = date("M d H:i:s");
	if ($log_level >= PERF) {
		$utimestamp = microtime(true);
		$timestamp = floor($utimestamp);
		$date .= '.' . round(($utimestamp - $timestamp) * 1000000);
	}
	$log_text = sprintf("%s%s%s\n", 
		"$date $local_log_level $action: ",
		$text,
		@$log_memory_usage ? " [" . memory_get_usage() . "]" : ''
	);

	if (isset($log_to_stdout)) {
		echo $log_text;
	} else {
		if (strtolower($greyhole_log_file) == 'syslog') {
			$worked = syslog($local_log_level, $log_text);
		} else if (!empty($greyhole_log_file)) {
			$worked = error_log($log_text, 3, $greyhole_log_file);
		} else {
			$worked = FALSE;
		}
		if (!$worked) {
			error_log(trim($log_text));
		}
	}
	
	if ($local_log_level === CRITICAL) {
		exit(1);
	}
}

function gh_shutdown() {
	if ($err = error_get_last()) {
		gh_log(ERROR, "PHP Fatal Error: " . $err['message'] . "; BT: " . basename($err['file']) . '[L' . $err['line'] . '] ');
	}
}

function gh_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
	if (error_reporting() === 0) {
		// Ignored (@) warning
		return TRUE;
	}

	switch ($errno) {
	case E_ERROR:
	case E_PARSE:
	case E_CORE_ERROR:
	case E_COMPILE_ERROR:
		gh_log(CRITICAL, "PHP Error [$errno]: $errstr in $errfile on line $errline");
		break;

	case E_WARNING:
	case E_COMPILE_WARNING:
	case E_CORE_WARNING:
	case E_NOTICE:
		global $greyhole_log_file;
		if ($errstr == "fopen($greyhole_log_file): failed to open stream: Permission denied") {
			// We want to ignore this warning. Happens when regular users try to use greyhole, and greyhole tries to log something.
			// What would have been logged will be echoed instead.
			return TRUE;
		}
		gh_log(WARN, "PHP Warning [$errno]: $errstr in $errfile on line $errline; BT: " . get_debug_bt());
		break;

	default:
		gh_log(WARN, "PHP Unknown Error [$errno]: $errstr in $errfile on line $errline");
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
		foreach ($d['args'] as $k => $v) {
			if (is_object($v)) {
				$d['args'][$k] = 'stdClass';
			}
			if (is_array($v)) {
				$d['args'][$k] = str_replace("\n", "", var_export($v, TRUE));
			}
		}
		$bt = $prefix . $d['function'] .'(' . implode(',', $d['args']) . ')' . $bt;
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
	global $shares_options, $trash_share_names;
	if (isset($shares_options[$share]['landing_zone'])) {
		return $shares_options[$share]['landing_zone'];
	} else if (array_search($share, $trash_share_names) !== FALSE) {
		global $trash_share;
		return $trash_share['landing_zone'];
	} else {
		global $config_file, $smb_config_file;
		gh_log(WARN, "  Found a share ($share) with no path in $smb_config_file, or missing it's num_copies[$share] config in $config_file. Skipping.");
		return FALSE;
	}
}

$arch = exec('uname -m');
if ($arch != 'x86_64') {
	gh_log(DEBUG, "32-bit system detected: Greyhole will NOT use PHP built-in file functions.");

	function gh_filesize($filename) {
		$result = exec("stat -c %s ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (float) $result;
	}
	
	function gh_fileowner($filename) {
		$result = exec("stat -c %u ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (int) $result;
	}
	
	function gh_filegroup($filename) {
		$result = exec("stat -c %g ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (int) $result;
	}

	function gh_fileperms($filename) {
		$result = exec("stat -c %a ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return "0" . $result;
	}

	function gh_is_file($filename) {
		exec('[ -f '.escapeshellarg($filename).' ]', $tmp, $result);
		return $result === 0;
	}

	function gh_fileinode($filename) {
		// This function returns deviceid_inode to make sure this value will be different for files on different devices.
		$result = exec("stat -c '%d_%i' ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (string) $result;
	}

	function gh_file_deviceid($filename) {
		$result = exec("stat -c '%d' ".escapeshellarg($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (string) $result;
	}
	
	function gh_rename($filename, $target_filename) {
		exec("mv ".escapeshellarg($filename)." ".escapeshellarg($target_filename)." 2>/dev/null", $output, $result);
		return $result === 0;
	}
} else {
	gh_log(DEBUG, "64-bit system detected: Greyhole will use PHP built-in file functions.");

	function gh_filesize($filename) {
		return filesize($filename);
	}
	
	function gh_fileowner($filename) {
		return fileowner($filename);
	}

	function gh_filegroup($filename) {
		return filegroup($filename);
	}

	function gh_fileperms($filename) {
		return mb_substr(decoct(fileperms($filename)), -4);
	}

	function gh_is_file($filename) {
		return is_file($filename);
	}

	function gh_fileinode($filename) {
		// This function returns deviceid_inode to make sure this value will be different for files on different devices.
		$stat = @stat($filename);
		if ($stat === FALSE) {
			return FALSE;
		}
		return $stat['dev'] . '_' . $stat['ino'];
	}

	function gh_file_deviceid($filename) {
		$stat = @stat($filename);
		if ($stat === FALSE) {
			return FALSE;
		}
		return $stat['dev'];
	}

	function gh_rename($filename, $target_filename) {
	    return @rename($filename, $target_filename);
	}
}

function memory_check() {
	global $memory_limit;
	$usage = memory_get_usage();
	$used = $usage/$memory_limit;
	$used = $used * 100;
	if ($used > 95) {
		gh_log(CRITICAL, $used . '% memory usage, exiting. Please increase memory_limit in /etc/greyhole.conf');
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
		while(count($this->directory_stack)>0 && $this->directory_stack !== NULL) {
			$this->dir = array_pop($this->directory_stack);
			if (!$this->quiet) {
				gh_log(DEBUG, "Loading metadata files for (dir) " . clean_dir($this->share . (!empty($this->dir) ? "/" . $this->dir : "")) . " ...");
			}
			for( $i = 0; $i < count($this->metastores); $i++ ) {
				$metastore = $this->metastores[$i];
				$this->base = "$metastore/".$this->share."/";
				if(!file_exists($this->base.$this->dir)) {
					continue;
				}	
				if($this->dir_handle = opendir($this->base.$this->dir)) {
					while (false !== ($file = readdir($this->dir_handle))) {
						memory_check();
						if($file=='.' || $file=='..')
							continue;
						if(!empty($this->dir)) {
							$full_filename = $this->dir . '/' . $file;
						}else
							$full_filename = $file;
						if(is_dir($this->base.$full_filename))
							$this->directory_stack[] = $full_filename;
						else{
							$full_filename = str_replace("$this->path/",'',$full_filename);
							if(isset($this->metafiles[$full_filename])) {
								continue;
							}						
							$this->metafiles[$full_filename] = get_metafiles_for_file($this->share, "$this->dir", $file, $this->load_nok_metafiles, $this->quiet, $this->check_symlink);
						}
					}
					closedir($this->dir_handle);
					$this->directory_stack = array_unique($this->directory_stack);
				}
			}
			if(count($this->metafiles) > 0) {
				break;
			}
			
		}
		if (!$this->quiet) {
			gh_log(DEBUG, 'Found ' . count($this->metafiles) . ' metadata files.');
		}
		return $this->metafiles;
	}
	
	public function valid() {
		return count($this->metafiles) > 0;
	}
}

function _getopt ( ) {

/* _getopt(): Ver. 1.3      2009/05/30
   My page: http://www.ntu.beautifulworldco.com/weblog/?p=526

Usage: _getopt ( [$flag,] $short_option [, $long_option] );

Note that another function split_para() is required, which can be found in the same
page.

_getopt() fully simulates getopt() which is described at
http://us.php.net/manual/en/function.getopt.php , including long options for PHP
version under 5.3.0. (Prior to 5.3.0, long options was only available on few systems)

Besides legacy usage of getopt(), I also added a new option to manipulate your own
argument lists instead of those from command lines. This new option can be a string
or an array such as 

$flag = "-f value_f -ab --required 9 --optional=PK --option -v test -k";
or
$flag = array ( "-f", "value_f", "-ab", "--required", "9", "--optional=PK", "--option" );

So there are four ways to work with _getopt(),

1. _getopt ( $short_option );

  it's a legacy usage, same as getopt ( $short_option ).

2. _getopt ( $short_option, $long_option );

  it's a legacy usage, same as getopt ( $short_option, $long_option ).

3. _getopt ( $flag, $short_option );

  use your own argument lists instead of command line arguments.

4. _getopt ( $flag, $short_option, $long_option );

  use your own argument lists instead of command line arguments.

*/

  if ( func_num_args() == 1 ) {
     $flag =  $flag_array = $GLOBALS['argv'];
     $short_option = func_get_arg ( 0 );
     $long_option = array ();
     return getopt($short_option);
  } else if ( func_num_args() == 2 ) {
     if ( is_array ( func_get_arg ( 1 ) ) ) {
        $flag = $GLOBALS['argv'];
        $short_option = func_get_arg ( 0 );
        $long_option = func_get_arg ( 1 );
        if (PHP_VERSION_ID >= 50300) { return getopt($short_option, $long_option); }
     } else {
        $flag = func_get_arg ( 0 );
        $short_option = func_get_arg ( 1 );
        $long_option = array ();
        return getopt($short_option);
     }
  } else if ( func_num_args() == 3 ) {
     $flag = func_get_arg ( 0 );
     $short_option = func_get_arg ( 1 );
     $long_option = func_get_arg ( 2 );
     if (PHP_VERSION_ID >= 50300) { return getopt($short_option, $long_option); }
  } else {
     exit ( "wrong options\n" );
  }

  $short_option = trim ( $short_option );

  $short_no_value = array();
  $short_required_value = array();
  $short_optional_value = array();
  $long_no_value = array();
  $long_required_value = array();
  $long_optional_value = array();
  $options = array();

  for ( $i = 0; $i < strlen ( $short_option ); ) {
     if ( $short_option{$i} != ":" ) {
        if ( $i == strlen ( $short_option ) - 1 ) {
          $short_no_value[] = $short_option{$i};
          break;
        } else if ( $short_option{$i+1} != ":" ) {
          $short_no_value[] = $short_option{$i};
          $i++;
          continue;
        } else if ( $short_option{$i+1} == ":" && $short_option{$i+2} != ":" ) {
          $short_required_value[] = $short_option{$i};
          $i += 2;
          continue;
        } else if ( $short_option{$i+1} == ":" && $short_option{$i+2} == ":" ) {
          $short_optional_value[] = $short_option{$i};
          $i += 3;
          continue;
        }
     } else {
        continue;
     }
  }

  foreach ( $long_option as $a ) {
     if ( substr( $a, -2 ) == "::" ) {
        $long_optional_value[] = substr( $a, 0, -2);
        continue;
     } else if ( substr( $a, -1 ) == ":" ) {
        $long_required_value[] = substr( $a, 0, -1 );
        continue;
     } else {
        $long_no_value[] = $a;
        continue;
     }
  }

  if ( is_array ( $flag ) )
     $flag_array = $flag;
  else {
     $flag = "- $flag";
     $flag_array = split_para( $flag );
  }

  for ( $i = 0; $i < count( $flag_array ); ) {

     if ( $i >= count ( $flag_array ) )
        break;

     if ( ! $flag_array[$i] || $flag_array[$i] == "-" ) {
        $i++;
        continue;
     }

     if ( $flag_array[$i]{0} != "-" ) {
        $i++;
        continue;

     }

     if ( substr( $flag_array[$i], 0, 2 ) == "--" ) {

        if (strpos($flag_array[$i], '=') != false) {
          list($key, $value) = explode('=', substr($flag_array[$i], 2), 2);
          if ( in_array ( $key, $long_required_value ) || in_array ( $key, $long_optional_value ) )
             $options[$key][] = $value;
          $i++;
          continue;
        }

        if (strpos($flag_array[$i], '=') == false) {
          $key = substr( $flag_array[$i], 2 );
          if ( in_array( substr( $flag_array[$i], 2 ), $long_required_value ) ) {
             $options[$key][] = $flag_array[$i+1];
             $i += 2;
             continue;
          } else if ( in_array( substr( $flag_array[$i], 2 ), $long_optional_value ) ) {
             if ( $flag_array[$i+1] != "" && $flag_array[$i+1]{0} != "-" ) {
                $options[$key][] = $flag_array[$i+1];
                $i += 2;
             } else {
                $options[$key][] = FALSE;
                $i ++;
             }
             continue;
          } else if ( in_array( substr( $flag_array[$i], 2 ), $long_no_value ) ) {
             $options[$key][] = FALSE;
             $i++;
             continue;
          } else {
             $i++;
             continue;
          }
        }

     } else if ( $flag_array[$i]{0} == "-" && $flag_array[$i]{1} != "-" ) {

        for ( $j=1; $j < strlen($flag_array[$i]); $j++ ) {
          if ( in_array( $flag_array[$i]{$j}, $short_required_value ) || in_array( $flag_array[$i]{$j}, $short_optional_value )) {

             if ( $j == strlen($flag_array[$i]) - 1  ) {
                if ( in_array( $flag_array[$i]{$j}, $short_required_value ) ) {
                  $options[$flag_array[$i]{$j}][] = $flag_array[$i+1];
                  $i += 2;
                } else if ( in_array( $flag_array[$i]{$j}, $short_optional_value ) && $flag_array[$i+1] != "" && $flag_array[$i+1]{0} != "-" ) {
                  $options[$flag_array[$i]{$j}][] = $flag_array[$i+1];
                  $i += 2;
                } else {
                  $options[$flag_array[$i]{$j}][] = FALSE;
                  $i ++;
                }
                $plus_i = 0;
                break;
             } else {
                $options[$flag_array[$i]{$j}][] = substr ( $flag_array[$i], $j + 1 );
                $i ++;
                $plus_i = 0;
                break;
             }

          } else if ( in_array ( $flag_array[$i]{$j}, $short_no_value ) ) {

             $options[$flag_array[$i]{$j}][] = FALSE;
             $plus_i = 1;
             continue;

          } else {
             $plus_i = 1;
             break;
          }
        }

        $i += $plus_i;
        continue;

     }

     $i++;
     continue;
  }

  foreach ( $options as $key => $value ) {
     if ( count ( $value ) == 1 ) {
        $options[ $key ] = $value[0];

     }

  }

  return $options;

}

function split_para ( $pattern ) {

/* split_para() version 1.0      2008/08/19
   My page: http://www.ntu.beautifulworldco.com/weblog/?p=526

This function is to parse parameters and split them into smaller pieces.
preg_split() does similar thing but in our function, besides "space", we
also take the three symbols " (double quote), '(single quote),
and \ (backslash) into consideration because things in a pair of " or '
should be grouped together.

As an example, this parameter list

-f "test 2" -ab --required "t\"est 1" --optional="te'st 3" --option -v 'test 4'

will be splited into

-f
t"est 2
-ab
--required
test 1
--optional=te'st 3
--option
-v
test 4

see the code below,

$pattern = "-f \"test 2\" -ab --required \"t\\\"est 1\" --optional=\"te'st 3\" --option -v 'test 4'";

$result = split_para( $pattern );

echo "ORIGINAL PATTERN: $pattern\n\n";

var_dump( $result );

*/

  $begin=0;
  $backslash = 0;
  $quote = "";
  $quote_mark = array();
  $result = array();

  $pattern = trim ( $pattern );

  for ( $end = 0; $end < strlen ( $pattern ) ; ) {

     if ( ! in_array ( $pattern{$end}, array ( " ", "\"", "'", "\\" ) ) ) {
        $backslash = 0;
        $end ++;
        continue;
     }

     if ( $pattern{$end} == "\\" ) {
        $backslash++;
        $end ++;
        continue;
     } else if ( $pattern{$end} == "\"" ) {
        if ( $backslash % 2 == 1 || $quote == "'" ) {
          $backslash = 0;
          $end ++;
          continue;
        }

        if ( $quote == "" ) {
          $quote_mark[] = $end - $begin;
          $quote = "\"";
        } else if ( $quote == "\"" ) {
          $quote_mark[] = $end - $begin;
          $quote = "";
        }

        $backslash = 0;
        $end ++;
        continue;
     } else if ( $pattern{$end} == "'" ) {
        if ( $backslash % 2 == 1 || $quote == "\"" ) {
          $backslash = 0;
          $end ++;
          continue;
        }

        if ( $quote == "" ) {
          $quote_mark[] = $end - $begin;
          $quote = "'";
        } else if ( $quote == "'" ) {
          $quote_mark[] = $end - $begin;
          $quote = "";
        }

        $backslash = 0;
        $end ++;
        continue;
     } else if ( $pattern{$end} == " " ) {
        if ( $quote != "" ) {
          $backslash = 0;
          $end ++;
          continue;
        } else {
          $backslash = 0;
          $cand = substr( $pattern, $begin, $end-$begin );
          for ( $j = 0; $j < strlen ( $cand ); $j ++ ) {
             if ( in_array ( $j, $quote_mark ) )
                continue;

             $cand1 .= $cand{$j};
          }
          if ( $cand1 ) {
             eval( "\$cand1 = \"$cand1\";" );
             $result[] = $cand1;
          }
          $quote_mark = array();
          $cand1 = "";
          $end ++;
          $begin = $end;
          continue;
       }
     }
  }

  $cand = substr( $pattern, $begin, $end-$begin );
  for ( $j = 0; $j < strlen ( $cand ); $j ++ ) {
     if ( in_array ( $j, $quote_mark ) )
        continue;

     $cand1 .= $cand{$j};
  }

  eval( "\$cand1 = \"$cand1\";" );

  if ( $cand1 )
     $result[] = $cand1;

  return $result;
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

function kshuffle(&$array) {
    if (!is_array($array)) { return $array; }
    $keys = array_keys($array);
    shuffle($keys);
    $random = array();
    foreach ($keys as $key) {
        $random[$key] = $array[$key];
    }
    $array = $random;
}

class DriveSelection {
    var $num_drives_per_draft;
    var $selection_algorithm;
    var $drives;
    var $is_custom;
    
    var $sorted_target_drives;
    var $last_resort_sorted_target_drives;
    
    function __construct($num_drives_per_draft, $selection_algorithm, $drives, $is_custom) {
        $this->num_drives_per_draft = $num_drives_per_draft;
        $this->selection_algorithm = $selection_algorithm;
        $this->drives = $drives;
        $this->is_custom = $is_custom;
    }
    
    function init(&$sorted_target_drives, &$last_resort_sorted_target_drives) {
        // Sort by used space (asc) for least_used_space, or by available space (desc) for most_available_space
        if ($this->selection_algorithm == 'least_used_space') {
			$sorted_target_drives = $sorted_target_drives['used_space'];
			$last_resort_sorted_target_drives = $last_resort_sorted_target_drives['used_space'];
        	asort($sorted_target_drives);
    		asort($last_resort_sorted_target_drives);
        } else if ($this->selection_algorithm == 'most_available_space') {
			$sorted_target_drives = $sorted_target_drives['available_space'];
			$last_resort_sorted_target_drives = $last_resort_sorted_target_drives['available_space'];
        	arsort($sorted_target_drives);
    		arsort($last_resort_sorted_target_drives);
		} else {
			gh_log(CRITICAL, "Unknown drive_selection_algorithm found: " . $this->selection_algorithm);
		}
		// Only keep drives that are in $this->drives
        $this->sorted_target_drives = array();
		foreach ($sorted_target_drives as $sp_drive => $space) {
		    if (array_search($sp_drive, $this->drives) !== FALSE) {
		        $this->sorted_target_drives[$sp_drive] = $space;
		    }
		}
        $this->last_resort_sorted_target_drives = array();
		foreach ($last_resort_sorted_target_drives as $sp_drive => $space) {
		    if (array_search($sp_drive, $this->drives) !== FALSE) {
		        $this->last_resort_sorted_target_drives[$sp_drive] = $space;
		    }
		}
    }
    
    function draft() {
        $drives = array();
        $drives_last_resort = array();
        
        while (count($drives)<$this->num_drives_per_draft) {
            $arr = kshift($this->sorted_target_drives);
            if ($arr === FALSE) {
                break;
            }
            list($sp_drive, $space) = $arr;
			if (!is_greyhole_owned_drive($sp_drive)) { continue; }
            $drives[$sp_drive] = $space;
        }
        while (count($drives)+count($drives_last_resort)<$this->num_drives_per_draft) {
            $arr = kshift($this->last_resort_sorted_target_drives);
            if ($arr === FALSE) {
                break;
            }
            list($sp_drive, $space) = $arr;
			if (!is_greyhole_owned_drive($sp_drive)) { continue; }
            $drives_last_resort[$sp_drive] = $space;
        }
        
        return array($drives, $drives_last_resort);
    }
    
    static function parse($config_string, $drive_selection_groups) {
        $ds = array();
        if ($config_string == 'least_used_space' || $config_string == 'most_available_space') {
            global $storage_pool_drives;
            $ds[] = new DriveSelection(count($storage_pool_drives), $config_string, $storage_pool_drives, FALSE);
            return $ds;
        }
        if (!preg_match('/forced ?\((.+)\) ?(least_used_space|most_available_space)/i', $config_string, $regs)) {
            gh_log(CRITICAL, "Can't understand the drive_selection_algorithm value: $config_string");
        }
        $selection_algorithm = $regs[2];
        $groups = array_map('trim', explode(',', $regs[1]));
        foreach ($groups as $group) {
            $group = explode(' ', preg_replace('/^([0-9]+)x/', '\\1 ', $group));
            $num_drives = trim($group[0]);
            $group_name = trim($group[1]);
			if (!isset($drive_selection_groups[$group_name])) {
				//gh_log(WARN, "Warning: drive selection group named '$group_name' is undefined.");
				continue;
			}
            if ($num_drives == 'all' || $num_drives > count($drive_selection_groups[$group_name])) {
                $num_drives = count($drive_selection_groups[$group_name]);
            }
            $ds[] = new DriveSelection($num_drives, $selection_algorithm, $drive_selection_groups[$group_name], TRUE);
        }
        return $ds;
    }

    function update() {
        // Make sure num_drives_per_draft and drives have been set, in case storage_pool_drive lines appear after drive_selection_algorithm line(s) in the config file
        if (!$this->is_custom && ($this->selection_algorithm == 'least_used_space' || $this->selection_algorithm == 'most_available_space')) {
            global $storage_pool_drives;
            $this->num_drives_per_draft = count($storage_pool_drives);
            $this->drives = $storage_pool_drives;
        }
    }
}

$greyhole_owned_drives = array();
function is_greyhole_owned_drive($sp_drive) {
	global $going_drive, $df_cache_time, $greyhole_owned_drives;
	if (isset($going_drive) && $sp_drive == $going_drive) {
		return FALSE;
	}
	if (isset($greyhole_owned_drives[$sp_drive]) && $greyhole_owned_drives[$sp_drive] < time() - $df_cache_time) {
		unset($greyhole_owned_drives[$sp_drive]);
	}
	if (!isset($greyhole_owned_drives[$sp_drive])) {
		$drives_definitions = Settings::get('sp_drives_definitions', TRUE);
		if (!$drives_definitions) {
			$drives_definitions = convert_sp_drives_tag_files();
		}
		if (@$drives_definitions[$sp_drive] === gh_dir_uuid($sp_drive)) {
			$greyhole_owned_drives[$sp_drive] = time();
		}
	}
	return isset($greyhole_owned_drives[$sp_drive]);
}

// Is it OK for a drive to be gone?
function gone_ok($sp_drive, $refresh=FALSE) {
	global $gone_ok_drives;
	if ($refresh || !isset($gone_ok_drives)) {
		$gone_ok_drives = get_gone_ok_drives();
	}
	if (isset($gone_ok_drives[$sp_drive])) {
		return TRUE;
	}
	return FALSE;
}

function get_gone_ok_drives() {
	global $gone_ok_drives;
	$gone_ok_drives = Settings::get('Gone-OK-Drives', TRUE);
	if (!$gone_ok_drives) {
		$gone_ok_drives = array();
		Settings::set('Gone-OK-Drives', $gone_ok_drives);
	}
	return $gone_ok_drives;
}

function mark_gone_ok($sp_drive, $action='add') {
	global $storage_pool_drives;
	if (array_search($sp_drive, $storage_pool_drives) === FALSE) {
		$sp_drive = '/' . trim($sp_drive, '/');
	}
	if (array_search($sp_drive, $storage_pool_drives) === FALSE) {
		return FALSE;
	}

	global $gone_ok_drives;
	$gone_ok_drives = get_gone_ok_drives();
	if ($action == 'add') {
		$gone_ok_drives[$sp_drive] = TRUE;
	} else {
		unset($gone_ok_drives[$sp_drive]);
	}

	Settings::set('Gone-OK-Drives', $gone_ok_drives);
	return TRUE;
}

function gone_fscked($sp_drive, $refresh=FALSE) {
	global $fscked_gone_drives;
	if ($refresh || !isset($fscked_gone_drives)) {
		$fscked_gone_drives = get_fsck_gone_drives();
	}
	if (isset($fscked_gone_drives[$sp_drive])) {
		return TRUE;
	}
	return FALSE;
}

function get_fsck_gone_drives() {
	global $fscked_gone_drives;
	$fscked_gone_drives = Settings::get('Gone-FSCKed-Drives', TRUE);
	if (!$fscked_gone_drives) {
		$fscked_gone_drives = array();
		Settings::set('Gone-FSCKed-Drives', $fscked_gone_drives);
	}
	return $fscked_gone_drives;
}

function mark_gone_drive_fscked($sp_drive, $action='add') {
	global $fscked_gone_drives;
	$fscked_gone_drives = get_fsck_gone_drives();
	if ($action == 'add') {
		$fscked_gone_drives[$sp_drive] = TRUE;
	} else {
		unset($fscked_gone_drives[$sp_drive]);
	}

	Settings::set('Gone-FSCKed-Drives', $fscked_gone_drives);
}

function check_storage_pool_drives($skip_fsck=FALSE) {
	global $storage_pool_drives, $email_to, $gone_ok_drives;
	$needs_fsck = FALSE;
	$returned_drives = array();
	$missing_drives = array();
	$i = 0; $j = 0;
	foreach ($storage_pool_drives as $sp_drive) {
		if (!is_greyhole_owned_drive($sp_drive) && !gone_fscked($sp_drive, $i++ == 0) && !file_exists("$sp_drive/.greyhole_used_this")) {
			if($needs_fsck !== 2){	
				$needs_fsck = 1;
			}
			mark_gone_drive_fscked($sp_drive);
			$missing_drives[] = $sp_drive;
			gh_log(WARN, "Warning! It seems the partition UUID of $sp_drive changed. This probably means this mount is currently unmounted, or that you replaced this drive and didn't use 'greyhole --replace'. Because of that, Greyhole will NOT use this drive at this time.");
			gh_log(DEBUG, "Email sent for gone drive: $sp_drive");
			$gone_ok_drives[$sp_drive] = TRUE; // The upcoming fsck should not recreate missing copies just yet
		} else if ((gone_ok($sp_drive, $j++ == 0) || gone_fscked($sp_drive, $i++ == 0)) && is_greyhole_owned_drive($sp_drive)) {
			// $sp_drive is now back
			$needs_fsck = 2;
			$returned_drives[] = $sp_drive;
			gh_log(DEBUG, "Email sent for revived drive: $sp_drive");

			mark_gone_ok($sp_drive, 'remove');
			mark_gone_drive_fscked($sp_drive, 'remove');
			$i = 0; $j = 0;
		}
	}
	if(count($returned_drives) > 0) {
		$body = "This is an automated email from Greyhole.\n\nOne (or more) of your storage pool drives came back:\n";
		foreach ($returned_drives as $sp_drive) {
  			$body .= "$sp_drive was missing; it's now available again.\n";
		}
		if (!$skip_fsck) {
			$body .= "\nA fsck will now start, to fix the symlinks found in your shares, when possible.\nYou'll receive a report email once that fsck run completes.\n";
		}
		$drive_string = join(", ", $returned_drives);
		$subject = "Storage pool drive now online on " . exec ('hostname') . ": ";
		$subject = $subject . $drive_string;
		if (strlen($subject) > 255) {
			$subject = substr($subject, 0, 255);
		}
		mail($email_to, $subject, $body);
	}
	if(count($missing_drives) > 0) {
		$body = "This is an automated email from Greyhole.\n\nOne (or more) of your storage pool drives has disappeared:\n";

		$drives_definitions = Settings::get('sp_drives_definitions', TRUE);
		foreach ($missing_drives as $sp_drive) {
			if (!is_dir($sp_drive)) {
	  			$body .= "$sp_drive: directory doesn't exists\n";
			} else {
				$current_uuid = gh_dir_uuid($sp_drive);
				if (empty($current_uuid)) {
					$current_uuid = 'N/A';
				}
				$body .= "$sp_drive: expected partition UUID: " . $drives_definitions[$sp_drive] . "; current partition UUID: $current_uuid\n";
			}
		}
		$sp_drive = $missing_drives[0];
		$body .= "\nThis either means this mount is currently unmounted, or you forgot to use 'greyhole --replace' when you changed this drive.\n\n";
		$body .= "Here are your options:\n\n";
		$body .= "- If you forgot to use 'greyhole --replace', you should do so now. Until you do, this drive will not be part of your storage pool.\n\n";
		$body .= "- If the drive is gone, you should either re-mount it manually (if possible), or remove it from your storage pool. To do so, use the following command:\n  greyhole --gone=" . escapeshellarg($sp_drive) . "\n  Note that the above command is REQUIRED for Greyhole to re-create missing file copies before the next fsck runs. Until either happens, missing file copies WILL NOT be re-created on other drives.\n\n";
		$body .= "- If you know this drive will come back soon, and do NOT want Greyhole to re-create missing file copies for this drive until it reappears, you should execute this command:\n  greyhole --wait-for=" . escapeshellarg($sp_drive) . "\n\n";
		if (!$skip_fsck) {
			$body .= "A fsck will now start, to fix the symlinks found in your shares, when possible.\nYou'll receive a report email once that fsck run completes.\n";
		}
		$subject = "Missing storage pool drives on " . exec('hostname') . ": ";
		$drive_string = join(",",$missing_drives);
		$subject = $subject . $drive_string;
		if (strlen($subject) > 255) {
			$subject = substr($subject, 0, 255);
		}
		mail($email_to, $subject, $body);
	}
	if ($needs_fsck !== FALSE) {
		set_metastore_backup();
		get_metastores(FALSE); // FALSE => Resets the metastores cache
		clearstatcache();

		if (!$skip_fsck) {
			global $shares_options;
			initialize_fsck_report('All shares');
			if($needs_fsck === 2) {
				foreach ($returned_drives as $drive) {
					$metastores = get_metastores_from_storage_volume($drive);
					gh_log(INFO, "Starting fsck for metadata store on $drive which came back online.");
					foreach($metastores as $metastore) {
						foreach($shares_options as $share_name => $share_options) {
							gh_fsck_metastore($metastore,"/$share_name", $share_name);
						}
					}
					gh_log(INFO, "fsck for returning drive $drive's metadata store completed.");
				}
				gh_log(INFO, "Starting fsck for all shares - caused by missing drive that came back online.");
			}else{
				gh_log(INFO, "Starting fsck for all shares - caused by missing drive. Will just recreate symlinks to existing copies when possible; won't create new copies just yet.");
				fix_all_symlinks();
			}
			schedule_fsck_all_shares(array('email'));
			gh_log(INFO, "  fsck for all shares scheduled.");
		}

		// Refresh $gone_ok_drives to it's real value (from the DB)
		get_gone_ok_drives();
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
			global $email_to;
			gh_log(WARN, "Sending $logfile by email to $email_to");
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
		} else {
			initialize_fsck_report($what);
		}
	}

	public static function saveFSCKReport() {
		global $fsck_report;
		$logfile = self::PATH . '/fsck_files.log';
		file_put_contents($logfile, serialize($fsck_report));
	}
}

class Settings {
	public static function get($name, $unserialize=FALSE, $value=FALSE) {
		$query = sprintf("SELECT * FROM settings WHERE name LIKE '%s'", $name);
		if ($value !== FALSE) {
			$query .= sprintf(" AND value LIKE '%s'", $value);
		}
		$result = db_query($query) or gh_log(CRITICAL, "Can't select setting '$name'/'$value' from settings table: " . db_error());
		$setting = db_fetch_object($result);
		if ($setting === FALSE) {
			return FALSE;
		}
		return $unserialize ? unserialize($setting->value) : $setting->value;
	}

	public static function set($name, $value) {
		if (is_array($value)) {
			$value = serialize($value);
		}
		global $db_use_mysql;
		if (@$db_use_mysql) {
			$query = sprintf("INSERT INTO settings (name, value) VALUES ('%s', '%s') ON DUPLICATE KEY UPDATE value = VALUES(value)", $name, $value);
			db_query($query) or gh_log(CRITICAL, "Can't insert/update '$name' setting: " . db_error());
		} else {
			$query = sprintf("DELETE FROM settings WHERE name = '%s'", $name);
			db_query($query) or gh_log(CRITICAL, "Can't delete '$name' setting: " . db_error());
			$query = sprintf("INSERT INTO settings (name, value) VALUES ('%s', '%s')", $name, $value);
			db_query($query) or gh_log(CRITICAL, "Can't insert '$name' setting: " . db_error());
		}
		return (object) array('name' => $name, 'value' => $value);
	}

	public static function rename($from, $to) {
		$query = sprintf("UPDATE settings SET name = '%s' WHERE name = '%s'", $to, $from);
		db_query($query) or gh_log(CRITICAL, "Can't rename setting '$from' to '$to': " . db_error());
	}

	public static function backup() {
		global $storage_pool_drives;
		$result = db_query("SELECT * FROM settings") or gh_log(CRITICAL, "Can't select settings for backup: " . db_error());
		$settings = array();
		while ($setting = db_fetch_object($result)) {
			$settings[] = $setting;
		}
		foreach ($storage_pool_drives as $sp_drive) {
			if (is_greyhole_owned_drive($sp_drive)) {
				$settings_backup_file = "$sp_drive/.gh_settings.bak";
				file_put_contents($settings_backup_file, serialize($settings));
			}
		}
	}

	public static function restore() {
		global $storage_pool_drives;
		foreach ($storage_pool_drives as $sp_drive) {
			$settings_backup_file = "$sp_drive/.gh_settings.bak";
			$latest_backup_time = 0;
			if (file_exists($settings_backup_file)) {
				$last_mod_date = filemtime($settings_backup_file);
				if ($last_mod_date > $latest_backup_time) {
					$backup_file = $settings_backup_file;
					$latest_backup_time = $last_mod_date;
				}
			}
		}
		if (isset($backup_file)) {
			gh_log(INFO, "Restoring settings from last backup: $backup_file");
			$settings = unserialize(file_get_contents($backup_file));
			foreach ($settings as $setting) {
				Settings::set($setting->name, $setting->value);
			}
			return TRUE;
		}
		return FALSE;
	}
}

function gh_dir_uuid($dir) {
	$dev = exec('df ' . escapeshellarg($dir) . ' 2> /dev/null | tail -1 | awk \'{print $1}\'');
	if (empty($dev) || strpos($dev, '/dev/') !== 0) {
		return FALSE;
	}
	return trim(exec('blkid '.$dev.' | awk -F\'UUID="\' \'{print $2}\' | awk -F\'"\' \'{print $1}\''));
}

function fix_all_symlinks() {
	global $shares_options;
	foreach ($shares_options as $share_name => $share_options) {
		fix_symlinks_on_share($share_name);
	}
}

function fix_symlinks_on_share($share_name) {
	global $shares_options, $storage_pool_drives;
	$share_options = $shares_options[$share_name];
	echo "Looking for broken symbolic links in the share '$share_name'... Please be patient... ";
	chdir($share_options['landing_zone']);
	exec("find -L . -type l", $result);
	foreach ($result as $file_to_relink) {
		if (is_link($file_to_relink)) {
			$file_to_relink = substr($file_to_relink, 2);
			foreach ($storage_pool_drives as $sp_drive) {
				if (!is_greyhole_owned_drive($sp_drive)) { continue; }
				$new_link_target = clean_dir("$sp_drive/$share_name/$file_to_relink");
				if (gh_is_file($new_link_target)) {
					unlink($file_to_relink);
					symlink($new_link_target, $file_to_relink);
					echo ".";
					break;
				}
			}
		}
	}
	echo "Done.\n";
}

function schedule_fsck_all_shares($fsck_options=array()) {
	global $shares_options;
	foreach ($shares_options as $share_name => $share_options) {
		$full_path = $share_options['landing_zone'];
		$query = sprintf("INSERT INTO tasks (action, share, additional_info, complete) VALUES ('fsck', '%s', %s, 'yes')",
			db_escape_string($full_path),
			(!empty($fsck_options) ? "'" . implode('|', $fsck_options) . "'" : "NULL")
		);
		db_query($query) or gh_log(CRITICAL, "Can't insert fsck task: " . db_error());
	}
}
?>
