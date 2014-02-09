#!/usr/bin/php
<?php

if ($argc != 2) {
    die("Usage: $argv[0] [php_file]\n");
}

file_put_contents($argv[1], "#!/usr/bin/php\n" . inject_in_file($argv[1]));

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
