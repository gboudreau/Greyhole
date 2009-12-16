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

define('DEBUG', 3);
define('INFO',  2);
define('WARN',  1);
define('ERROR', 0);
define('CRITICAL', -1);

set_error_handler("gh_error_handler");
	
$constarray = get_defined_constants(true);
foreach($constarray['user'] as $key => $val) {
    eval(sprintf('$_CONSTANTS[\'%s\'] = ' . (is_int($val) || is_float($val) ? '%s' : "'%s'") . ';', addslashes($key), addslashes($val)));
}

// Cached df results
$last_df_time = 0;
$last_dfs = array();
$is_new_line = TRUE;

function parse_config() {
	global $_CONSTANTS, $storage_pool_directories, $shares_options, $minimum_free_space_pool_directories, $df_command;

	$config_file = '/etc/greyhole.conf';

	$config_text = file_get_contents($config_file);
	foreach (explode("\n", $config_text) as $line) {
		if (preg_match("/[ \t]*([^ \t]+)[ \t]*=[ \t]*([^#]+)/", $line, $regs)) {
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
				case 'files_permissions':
				case 'folders_permissions':
					$shares_options[$name] = (int) base_convert(trim($value), 8, 10);
					break;
				case 'files_owner':
					$shares_options[$name] = explode(':', trim($value));
					break;
				case 'delete_moves_to_attic':
				case 'log_memory_usage':
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
				default:
					if (strpos($name, 'num_copies') === 0) {
						$share = substr($name, 11, strlen($name)-12);
						$shares_options[$share]['num_copies'] = (int) $value;
					} else {
						global ${$name};
						if (is_numeric(trim($regs[2]))) {
							${$name} = (int) trim($value);
						} else {
							${$name} = trim($value);
						}
					}
			}
		}
	}
	$landing_zone = '/' . trim($landing_zone, '/');
	$graveyard = '/' . trim($graveyard, '/');
	
	$df_command = "df -k";
	foreach ($storage_pool_directories as $target_drive) {
		$df_command .= " " . quoted_form($target_drive);
	}
	$df_command .= " | awk '{print \$(NF),\$(NF-2)}'";
}

function quoted_form($path) {
	return "'" . str_replace("'", "'\\''", $path) . "'";
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
	global $greyhole_log_file, $log_level, $is_new_line, $log_memory_usage;
	if ($local_log_level > $log_level) {
		return;
	}

	$log_text = sprintf('%s%s%s%s', 
		$is_new_line ? "[" . date("Y-m-d H:i:s") . "] " : '',
		$is_new_line && $log_memory_usage ? "[" . memory_get_usage() . "] " : '',
		$text,
		$add_line_feed ? "\n": ''
	);
	$is_new_line = $add_line_feed;

	$fp = fopen($greyhole_log_file, 'a') or die("Can't open log file '$greyhole_log_file' for writing.");
	fwrite($fp, $log_text);
	fclose($fp);
	
	if ($local_log_level === CRITICAL) {
		exit(1);
	}
}

function gh_error_handler($errno, $errstr, $errfile, $errline) {
	global $ignored_warnings;
	if (!isset($ignored_warnings)) {
		$ignored_warnings = array();
		$source = explode("\n", file_get_contents(substr(__FILE__, 0, strrpos(__FILE__, '/')-8).'greyhole-executer'));
		for ($i=0; $i<count($source); $i++) {
			if (preg_match("/@([^\(]+)/", $source[$i], $regs)) {
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
		if (isset($ignored_warnings[$errline]) && preg_match("/([^(]+)\(/", $errstr, $regs)) {
			if (array_search($regs[1], $ignored_warnings[$errline]) !== FALSE) {
				// We want to ignore this warning.
				return TRUE;
			}
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
?>