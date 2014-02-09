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

class EmptyTrashCliRunner extends AbstractCliRunner {
    public function run() {
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $trash_path = clean_dir("$sp_drive/.gh_trash");
            if (!file_exists($trash_path)) {
                $this->log("Trash in $sp_drive is empty. Nothing to do.");
            } else {
                $trash_size = trim(exec("du -sk " . escapeshellarg($trash_path) . " | awk '{print $1}'"));
                $this->logn("Trash in $sp_drive is " . bytes_to_human($trash_size*1024, FALSE) . ". Emptying... ");
                exec("rm -rf " . escapeshellarg($trash_path));
                $this->log("Done");
            }
        }
        $trash_share = SharesConfig::getConfigForShare(CONFIG_TRASH_SHARE);
        if ($trash_share && mb_strlen(escapeshellarg($trash_share[CONFIG_LANDING_ZONE])) > 8) {
            exec("rm -rf " . escapeshellarg($trash_share[CONFIG_LANDING_ZONE]) . '/*');
        }
    }
}

?>
