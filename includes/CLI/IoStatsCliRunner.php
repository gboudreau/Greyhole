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

class IoStatsCliRunner extends AbstractAnonymousCliRunner {
    public function run() {
        $devices_drives = array();
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $device = exec("df " . escapeshellarg($sp_drive) . " 2>/dev/null | awk '{print \$1}'");
            $device = preg_replace('@/dev/(sd[a-z])[0-9]?@', '\1', $device);
            $devices_drives[$device] = $sp_drive;
        }

        while (TRUE) {
            unset($result);
            exec("iostat -p ALL -k 5 2 | grep '^sd[a-z] ' | awk '{print \$1,\$3,\$4}'", $result);
            $iostat = array();
            foreach ($result as $line) {
                $info = explode(' ', $line);
                $device = $info[0];
                $read_kBps = $info[1];
                $write_kBps = $info[2];
                if (!isset($devices_drives[$device])) {
                    # That device isn't in the storage pool.
                    continue;
                }
                $drive = $devices_drives[$device];
                $iostat[$drive] = (int) round((int) $read_kBps + (int) $write_kBps);
            }
            ksort($iostat);
            $this->log("--- [" . date('H:m:s') . "]");
            foreach ($iostat as $drive => $io_kBps) {
                $this->log(sprintf("$drive: %7s kBps", $io_kBps));
            }
        }
    }
}

?>
