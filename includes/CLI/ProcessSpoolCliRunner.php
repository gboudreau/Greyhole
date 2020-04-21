<?php
/*
Copyright 2020 Guillaume Boudreau

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

class ProcessSpoolCliRunner extends AbstractCliRunner {
    public function run() {
        global $argv;

        $start = time();

        Log::setAction(ACTION_INITIALIZE);
        Metastores::choose_metastores_backups();

        Log::cleanStatusTable();

        while (time() - $start < 55) {
            SambaSpool::parse_samba_spool();
            if (!array_contains($argv, '--keepalive')) {
                return;
            }
            if (time() - $start >= 55) {
                break;
            }
            sleep(5);
        }
    }
}

?>
