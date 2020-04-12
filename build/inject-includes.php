#!/usr/bin/php
<?php
/*
Copyright 2013-2014 Guillaume Boudreau

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

if ($argc != 2) {
    die("Usage: $argv[0] [php_file]\n");
}

$prefix = "#!/usr/bin/greyhole-php\n";
if ($argv[1] == 'web-app/index.php') {
    $prefix = '';
}
file_put_contents($argv[1], $prefix . inject_in_file($argv[1]));

$require_once_already_included = array();

function inject_in_file($file, $level=0) {
    global $require_once_already_included;

    $file_content = file_get_contents($file);
    $new_content = '';
    $is_php = TRUE;
    foreach (explode("\n", $file_content) as $line) {
        if (preg_match("@(include)\(['\"](.+)['\"]\);@", $line, $matches) || preg_match("@(require)\(['\"](.+)['\"]\);@", $line, $matches) || preg_match("@(require_once)\(['\"](.+)['\"]\);@", $line, $matches)) {
            // Don't inject again a require_once() we already included
            if ($matches[1] == 'require_once') {
                if (isset($require_once_already_included[$matches[2]])) {
                    continue;
                }
                $require_once_already_included[$matches[2]] = TRUE;
            }

            // Inject other PHP file
            $new_content .= inject_in_file($matches[2], $level+1) . "\n";
        } else if (preg_match("@^\s*\/\*@", $line) && $level > 0) {
            $is_comment = TRUE;
            if (preg_match("@\s*\*\/$@", $line)) {
                $is_comment = FALSE;
            }
        } else if (preg_match("@\s*\*\/$@", $line) && $level > 0) {
            $is_comment = FALSE;
        } else if (@$is_comment) {
            // Remove block comments
        } else if (($line == "<?php" || $line == "?>") && $level > 0) {
            // Remove PHP tags from all files except the first
        } else if (preg_match("@^\\s*\/\/@", $line) || preg_match("@^\\s*#@", $line)) {
            // Remove whole-line comments
        } else if (preg_match("@^\\s*$@", $line)) {
            // Remove empty lines
        } else {
            $new_content .= "$line\n";
        }
    }
    return $new_content;
}
