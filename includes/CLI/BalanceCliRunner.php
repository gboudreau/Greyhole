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

class BalanceCliRunner extends AbstractCliRunner {
    public function run() {
        $query = "INSERT INTO tasks (action, share, complete) VALUES ('balance', '', 'yes')";
        DB::insert($query);
        $this->log("A balance has been scheduled. It will start after all currently pending tasks have been completed.");
        $this->log("This operation will try to even the available space on all drives included in your storage pool.");
    }
}

?>
