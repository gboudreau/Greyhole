<?php
/*
Copyright 2009-2014 Guillaume Boudreau

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

require_once('includes/CLI/AbstractCliRunner.php');

class ConfigCliRunner extends AbstractCliRunner {
    public function run() {
        $argc = $GLOBALS['argc'];
        $argv = $GLOBALS['argv'];

        if ($argc != 3 && $argc != 4) {
            echo "Usage: greyhole --config name [value]\n";
            exit(1);
        }

        $name = $argv[2];
        if ($argc > 3) {
            $value = $argv[3];
            static::change_config($name, $value, [$this, 'log']);
        } else {
            $config_value = Config::get($name);
            $this->log(json_encode($config_value));
        }
    }

    public static function change_config($name, $value, $log_fct, &$error = NULL) {
        if (empty($log_fct)) {
            $log_fct = function($log) { error_log($log); };
        }

        $config_file = ConfigHelper::$config_file;

        if (string_starts_with($name, 'smb.conf:')) {
            $config_file = ConfigHelper::$smb_config_file;
            if (!preg_match('/smb.conf:\[(.+)](.+)$/', $name, $re)) {
                $error = "Invalid format for option name: $name";
                return;
            }
            $section = $re[1];
            $name = $re[2];
        }

        if (string_starts_with($name, 'num_copies') && empty($value)) {
            $value = '___REMOVE___';
        }

        if ($name == 'ignored_folders' || $name == 'ignored_files') {
            // Those require special handling

            // 1. First, comment out all config options in the config file
            $content = file_get_contents(ConfigHelper::$config_file);
            $content = explode("\n", $content);
            foreach ($content as $i => $line) {
                if (preg_match('/\s*(.+)\s*=\s*(.*)$/', $line, $re) && trim($re[1]) == $name) {
                    $content[$i] = "#$line";
                    $log_fct("Commented-out $name = $re[2] at line " . ($i+1) . " in " . ConfigHelper::$config_file . "");
                }
            }

            // 2. Then, de-comment the ones we want to keep
            $ignore_values = explode("\n", $value);
            foreach ($content as $i => $line) {
                if (preg_match('/#\s*(.+)\s*=\s*(.*)$/', $line, $re) && trim($re[1]) == $name) {
                    foreach ($ignore_values as $j => $ignore_value) {
                        if ($re[2] == $ignore_value) {
                            $content[$i] = substr($line, 1);
                            $log_fct("Keeping $name = $ignore_value at line " . ($i+1) . " in " . ConfigHelper::$config_file . "");
                            unset($ignore_values[$j]);
                            break;
                        }
                    }
                }
            }

            // 3. Finally, add the ones that are still missing
            foreach ($ignore_values as $ignore_value) {
                if (empty(trim($ignore_value))) { continue; }
                $content[] = "\t$name = $ignore_value";
                $log_fct("Will append to " . ConfigHelper::$config_file . ": $name = $ignore_value");
            }

            $content = implode("\n", $content) . (!empty($ignore_values) ? "\n" : "");
            file_put_contents(ConfigHelper::$config_file, $content);

            exit(0);
        }

        if (string_starts_with($name, 'min_free_space_pool_drive')) {
            // min_free_space_pool_drive[sp_drive]
            $sp_drive = substr($name, 26, -1);
            $name = "storage_pool_drive";
            $needs_match = "@$sp_drive\s*,\s*min_free\s*:\s*\d+@";
            $value = "$sp_drive, min_free: $value";
        }

        if (string_starts_with($name, 'drive_selection_groups')) {
            // drive_selection_groups[group_name]
            $group_name = substr($name, 23, -1);
            $name = ['drive_selection_groups', ''];
            $needs_match = "@[=\s]$group_name:\s*@";
            $value = "$group_name: " . str_replace(',/', ', /', $value);
        }

        // Find the correct line to replace
        exec("cat " . escapeshellarg($config_file) . " | grep -n -v '^\s*[#;]' | grep -v '^[0-9]*:\s*$'", $output);
        if (!empty($section)) {
            $output = static::filter_lines_for_section($output, $section);
        }
        foreach ($output as $line) {
            $equal_optional = '';
            if (array_contains(to_array($name), '')) {
                $equal_optional = '?';
            }
            if (preg_match('/(\d+):\s*(.+)\s*='.$equal_optional.'\s*(.*)$/U', $line, $re) && array_contains(to_array($name), trim($re[2])) && (empty($needs_match) || preg_match($needs_match, $line))) {
                $line_number = $re[1];
                $log_fct("Will overwrite line $line_number in " . $config_file . ':');
                break;
            }
        }
        if (empty($line_number)) {
            // Maybe a line exists, but is commented out
            unset($output);
            exec("cat " . escapeshellarg($config_file) . " | egrep -n '^\s*[#;]|^\s*\[' | grep -v '^[0-9]*:\s*$'", $output);
            if (!empty($section)) {
                $output = static::filter_lines_for_section($output, $section);
            }
            foreach ($output as $line) {
                if (preg_match('/(\d+):[#;]\s*(.+)\s*=\s*(.*)$/U', $line, $re) && array_contains(to_array($name), trim($re[2])) && (empty($needs_match) || preg_match($needs_match, $line))) {
                    $line_number = $re[1];
                    $log_fct("Will overwrite line $line_number in " . $config_file . ':');
                    break;
                }
            }
        }

        $name = first(to_array($name));
        $content = file_get_contents($config_file);
        if (empty($line_number)) {
            // Couldn't find a line to replace; will append to the file

            if ($name == "storage_pool_drive") {
                // Do some basic checks, before allowing a new Storage Pool drive to be added
                if (!Config::get(CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE)) {
                    // @TODO Add more checks, like making sure this new folder is not on the same drive as another existing sp_drive
                }
                /** @noinspection PhpUndefinedVariableInspection */
                if (!is_dir($sp_drive) && is_dir(dirname($sp_drive))) {
                    // Create the folder ourselves, if the parent exists
                    mkdir($sp_drive, 0777);
                }
                if (!is_dir($sp_drive)) {
                    $error = "Specified path '$sp_drive' does not exist.";
                    return;
                }
            }

            $log_fct("Will append to " . $config_file . ':');
            if (!empty($section)) {
                $content .= "\n[$section]";
            }
            $prefix = '';
            if ($value === '___REMOVE___') {
                $prefix = "#";
            }
            $content .= "\n$prefix$name = $value\n";
        } else {
            // Replacing the identified line with the new line
            $content = explode("\n", $content);
            $before = $content[$line_number-1];
            // Remove comment, if present
            if ($value === '___REMOVE___') {
                if ($before[0] != '#' && $before[0] != ';') {
                    $content[$line_number - 1] = "#$before";
                }
            } else {
                if ($before[0] == '#' || $before[0] == ';') {
                    $before[0] = ' ';
                }
                // Keep prefix (white space)
                $prefix = '';
                if (preg_match('/^(\s*)/', $before, $re)) {
                    $prefix = $re[1];
                }
                $after = "$prefix$name = $value";
                $content[$line_number-1] = $after;
            }
            $content = implode("\n", $content);
        }
        file_put_contents($config_file, $content);

        $log_fct("$name = $value");
    }

    private static function filter_lines_for_section($lines, $section) {
        // Filter to keep only the correct [section]
        $found_section = FALSE;
        foreach ($lines as $i => $line) {
            if (preg_match('/\[(.+)]/', $line, $re)) {
                $found_section = ( trim($re[1]) == $section );
                unset($lines[$i]);
                continue;
            }
            if (!$found_section) {
                unset($lines[$i]);
            }
        }
        return array_values($lines);
    }
}

?>
