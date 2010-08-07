<?php
/*
Copyright 2009 Guillaume Boudreau

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

define('TEST', 8);
define('DEBUG', 7);
define('INFO',  6);
define('WARN',  4);
define('ERROR', 3);
define('CRITICAL', 2);

$log_level_names = array(
	DEBUG => 'debug',
	INFO => 'info',
	WARN => 'warning',
	ERROR => 'err',
	CRITICAL => 'crit'
);

$action = 'initialize';

date_default_timezone_set(date_default_timezone_get());

set_error_handler("gh_error_handler");

$constarray = get_defined_constants(true);
foreach($constarray['user'] as $key => $val) {
    eval(sprintf('$_CONSTANTS[\'%s\'] = ' . (is_int($val) || is_float($val) ? '%s' : "'%s'") . ';', addslashes($key), addslashes($val)));
}

// Cached df results
$last_df_time = 0;
$last_dfs = array();
$is_new_line = TRUE;
$sleep_before_task = array();
$arch = exec('uname -i');

if (!isset($config_file)) {
	$config_file = '/etc/greyhole.conf';
}

if (!isset($smb_config_file)) {
	$smb_config_file = '/etc/samba/smb.conf';
}

function parse_config() {
	global $_CONSTANTS, $storage_pool_directories, $shares_options, $minimum_free_space_pool_directories, $df_command, $config_file, $smb_config_file, $sticky_files, $db_options, $frozen_directories;

	$shares_options = array();
	$storage_pool_directories = array();
	$frozen_directories = array();
	$config_text = file_get_contents($config_file);
	foreach (explode("\n", $config_text) as $line) {
		if (preg_match("/^[ \t]*([^=\t]+)[ \t]*=[ \t]*([^#]+)/", $line, $regs)) {
			$name = trim($regs[1]);
			$value = trim($regs[2]);
			if ($name[0] == '#') {
				continue;
			}
			switch($name) {
				case 'log_level':
					global ${$name};
					${$name} = $_CONSTANTS[$value];
					break;
				case 'delete_moves_to_attic':
				case 'log_memory_usage':
				case 'balance_modified_files':
					global ${$name};
					${$name} = trim($value) === '1' || stripos(trim($value), 'yes') !== FALSE || stripos(trim($value), 'true') !== FALSE;
					break;
				case 'storage_pool_directory':
					if (preg_match("/(.*) ?, ?min_free ?: ?([0-9]+) ?gb?/i", $value, $regs)) {
						$storage_pool_directories[] = trim($regs[1]);
						$minimum_free_space_pool_directories[trim($regs[1])] = (float) trim($regs[2]);
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
				default:
					if (strpos($name, 'num_copies') === 0) {
						$share = substr($name, 11, strlen($name)-12);
						$shares_options[$share]['num_copies'] = (int) $value;
					} else if (strpos($name, 'delete_moves_to_attic') === 0) {
						$share = substr($name, 22, strlen($name)-23);
						$shares_options[$share]['delete_moves_to_attic'] = trim($value) === '1' || stripos(trim($value), 'yes') !== FALSE || stripos(trim($value), 'true') !== FALSE;
					} else {
						global ${$name};
						if (is_numeric($value)) {
							${$name} = (int) $value;
						} else {
							${$name} = $value;
						}
					}
			}
		}
	}
	
	if (is_array($storage_pool_directories) && count($storage_pool_directories) > 0) {
		$df_command = "df -k";
		foreach ($storage_pool_directories as $key => $target_drive) {
			$df_command .= " " . quoted_form($target_drive);
			$storage_pool_directories[$key] = '/' . trim($target_drive, '/');
		}
		$df_command .= " 2>&1 | grep '%' | grep -v \"^df: .*: No such file or directory$\" | awk '{print \$(NF),\$(NF-2)}'";
	} else {
		gh_log(WARN, "You have no storage_pool_directory defined. Greyhole can't run.");
		return FALSE;
	}

	$config_text = file_get_contents($smb_config_file);
	foreach (explode("\n", $config_text) as $line) {
		$line = trim($line);
		if (strlen($line) == 0) { continue; }
		if ($line[0] == '[' && preg_match('/\[([^\]]+)\]/', $line, $regs)) {
			$share_name = $regs[1];
		}
		if (isset($share_name) && !isset($shares_options[$share_name])) { continue; }
		if (isset($share_name) && preg_match('/path[ \t]*=[ \t]*(.+)$/', $line, $regs)) {
			$shares_options[$share_name]['landing_zone'] = '/' . trim($regs[1], '/');
			$shares_options[$share_name]['name'] = $share_name;
		}
	}
	
	foreach ($shares_options as $share_name => $share_options) {
		if ($share_options['num_copies'] > count($storage_pool_directories)) {
			$share_options['num_copies'] = count($storage_pool_directories);
			$shares_options[$share_name] = $share_options;
		}
		if (!isset($share_options['landing_zone'])) {
			global $config_file, $smb_config_file;
			gh_log(WARN, "Found a share ($share_name) defined in $config_file with no path in $smb_config_file. Either add this share in $smb_config_file, or remove it from $config_file, then restart Greyhole.");
			return FALSE;
		}
		
		// Validate that the landing zone is NOT a subdirectory of a storage pool directory!
		foreach ($storage_pool_directories as $key => $target_drive) {
			if (strpos($share_options['landing_zone'], $target_drive) === 0) {
				gh_log(CRITICAL, "Found a share ($share_name), with path " . $share_options['landing_zone'] . ", which is INSIDE a storage pooled directory ($target_drive). Share directories should never be inside a directory that you have in your storage pool.\nFor your shares to use your storage pool, you just need them to have 'vfs objects = greyhole' in their (smb.conf) config; their location on your file system is irrelevant.");
			}
		}
	}
	
	if (!isset($db_engine)) {
		$db_engine = 'mysql';
	} else {
		$db_engine = strtolower($db_engine);
	}

	global ${"db_use_$db_engine"};
	${"db_use_$db_engine"} = TRUE;

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
	
	if (!isset($balance_modified_files)) {
		global $balance_modified_files;
		$balance_modified_files = FALSE;
	}
	return TRUE;
}

function quoted_form($path) {
	return escapeshellarg($path);
}

function clean_dir($dir) {
	if ($dir[0] == '.' && $dir[1] == '/') {
		$dir = substr($dir, 2);
	}
	if (strpos($dir, '//') !== FALSE) {
		$dir = str_replace("//", "/", $dir);
	}
	return $dir;
}

function explode_full_path($full_path) {
	if (strpos($full_path, '/') === FALSE) {
		return array('', $full_path);
	}
	$filename = substr($full_path, strrpos($full_path, '/')+1);
	$path = substr($full_path, 0, strrpos($full_path, '/'));
	return array($path, $filename);
}

function gh_log($local_log_level, $text, $add_line_feed=TRUE) {
	global $greyhole_log_file, $log_level, $is_new_line, $log_memory_usage, $log_level_names, $action, $log_to_stdout;
	if ($local_log_level > $log_level) {
		return;
	}

	$date = explode(' ', date("M d H:i:s"));
	$log_text = sprintf('%s%s%s%s', 
		$is_new_line ? $date[0] . sprintf(' %2d ', (int) $date[1]) . $date[2] . " $local_log_level $action: " : '',
		$text,
		$add_line_feed && $log_memory_usage ? " [" . memory_get_usage() . "]" : '',
		$add_line_feed ? "\n": ''
	);
	$is_new_line = $add_line_feed;

	if (isset($log_to_stdout)) {
		echo $log_text;
	} else {
		@$fp = fopen($greyhole_log_file, 'a');
		if ($fp) {
			fwrite($fp, $log_text);
			fclose($fp);
		} else {
			error_log(trim($log_text));
		}
	}
	
	if ($local_log_level === CRITICAL) {
		exit(1);
	}
}

function gh_error_handler($errno, $errstr, $errfile, $errline) {
	global $ignored_warnings;
	if (!isset($ignored_warnings)) {
		$ignored_warnings = array();
		$greyhole_bin = exec("which greyhole");
		$source = explode("\n", file_get_contents($greyhole_bin));
		for ($i=0; $i<count($source); $i++) {
			if (preg_match("/@([^\(\) ]+)/", $source[$i], $regs)) {
				$ignored_warnings[$i+1][] = $regs[1];
			}
		}
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
		if (isset($ignored_warnings[$errline])) {
			if (preg_match("/([^(]+)\(/", $errstr, $regs)) {
				if (array_search($regs[1], $ignored_warnings[$errline]) !== FALSE) {
					// We want to ignore this warning.
					return TRUE;
				}
				return FALSE;
			}
			if (preg_match("/Undefined variable: (.+)/", $errstr, $regs)) {
				if (array_search('$'.$regs[1], $ignored_warnings[$errline]) !== FALSE) {
					// We want to ignore this warning.
					return TRUE;
				}
				return FALSE;
			}
		}
		global $greyhole_log_file;
		if ($errstr == "fopen($greyhole_log_file): failed to open stream: Permission denied") {
			// We want to ignore this warning. Happens when regular users try to use greyhole, and greyhole tries to log something.
			// What would have been logged will be echoed instead.
			return TRUE;
		}
		gh_log(WARN, "PHP Warning [$errno]: $errstr in $errfile on line $errline");
		break;

	default:
		gh_log(WARN, "PHP Unknown Error [$errno]: $errstr in $errfile on line $errline");
		break;
	}

	// Don't execute PHP internal error handler
	return TRUE;
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
	global $shares_options;
	if (isset($shares_options[$share]['landing_zone'])) {
		return $shares_options[$share]['landing_zone'];
	} else {
		global $config_file, $smb_config_file;
		gh_log(WARN, "  Found a share ($share) with no path in $smb_config_file, or missing from your $config_file. Skipping.");
		return FALSE;
	}
}

function gh_filesize($filename) {
	global $arch;
	if ($arch != 'x86_64') {
		$result = exec("stat -c %s ".quoted_form($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (float) $result;
	}
	return filesize($filename);
}

function gh_fileowner($filename) {
	global $arch;
	if ($arch != 'x86_64') {
		$result = exec("stat -c %u ".quoted_form($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (int) $result;
	}
	return fileowner($filename);
}

function gh_filegroup($filename) {
	global $arch;
	if ($arch != 'x86_64') {
		$result = exec("stat -c %g ".quoted_form($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (int) $result;
	}
	return filegroup($filename);
}

function gh_fileperms($filename) {
	global $arch;
	if ($arch != 'x86_64') {
		$result = exec("stat -c %a ".quoted_form($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return "0" . $result;
	}
	return substr(decoct(fileperms($filename)), -4);
}

function gh_is_file($filename) {
	global $arch;
	if ($arch != 'x86_64') {
		exec('[ -f '.quoted_form($filename).' ]', $tmp, $result);
		return $result === 0;
	}
	return is_file($filename);
}

function gh_fileinode($filename) {
	global $arch;
	if ($arch != 'x86_64') {
		$result = exec("stat -c %i ".quoted_form($filename)." 2>/dev/null");
		if (empty($result)) {
			return FALSE;
		}
		return (int) $result;
	}
	return fileinode($filename);
}

?>
