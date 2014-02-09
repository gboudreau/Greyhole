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

require_once('includes/CLI/AbstractAnonymousCliRunner.php');

class LogsCliRunner extends AbstractAnonymousCliRunner {
    public function run() {
        $greyhole_log_file = Config::get(CONFIG_GREYHOLE_LOG_FILE);
        $greyhole_error_log_file = Config::get(CONFIG_GREYHOLE_ERROR_LOG_FILE);
        if (strtolower($greyhole_log_file) == 'syslog') {
            if (gh_is_file('/var/log/syslog')) {
                passthru("tail -F -n 1 /var/log/syslog | grep --line-buffered Greyhole");
            } else {
                passthru("tail -F -n 1 /var/log/messages | grep --line-buffered Greyhole");
            }
        } else {
            $files = escapeshellarg($greyhole_log_file);
            passthru("tail -n 1 $files");
            if (!empty($greyhole_error_log_file)) {
                $files = escapeshellarg($greyhole_error_log_file) . " " . $files;
            }
            passthru("tail -qF -n 0 $files");
        }
    }
}

?>
