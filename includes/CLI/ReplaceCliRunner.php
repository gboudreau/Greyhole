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

require_once('includes/CLI/AbstractPoolDriveCliRunner.php');

class ReplaceCliRunner extends AbstractPoolDriveCliRunner {
    public function run() {
        remove_drive_definition($this->drive);

        Log::info("Storage pool drive $this->drive has been marked replaced. The Greyhole daemon will now be restarted to allow it to use this new drive.");
        $this->log("Storage pool drive $this->drive has been marked replaced. The Greyhole daemon will now be restarted to allow it to use this new drive.");
        $this->restart_service();
    }
}

?>
