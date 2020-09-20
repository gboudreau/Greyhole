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

class CancelFsckCliRunner extends AbstractCliRunner {
    public function run() {
        DB::execute("DELETE FROM tasks WHERE action IN ('fsck', 'md5')");
        $this->log("All scheduled fsck tasks have now been deleted.");
        $this->log("Specific files checks might have been queued for problematic files, and those (fsck_file) tasks will still be executed, once other tasks have been processed.");
        $this->restart_service();
    }
}

?>
