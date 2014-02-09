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

class WaitForCliRunner extends AbstractCliRunner {
    private $drive;

    function __construct($options) {
        parent::__construct($options);

        if (isset($this->options['cmd_param'])) {
            $this->drive = $this->options['cmd_param'];
            if (!array_contains(Config::storagePoolDrives(), $this->drive)) {
                $this->drive = '/' . trim($this->drive, '/');
            }
        }

        if (empty($this->drive) || !array_contains(Config::storagePoolDrives(), $this->drive)) {
            if (!empty($this->drive)) {
                $this->log("Directory $this->drive is not one of your defined storage pool drives.");
            }
            $this->log("Please use one of the following with the --wait-for option:");
            $this->log("  " . implode("\n  ", Config::storagePoolDrives()));
            $this->log("Note that the correct syntax for this command is:");
            $this->log("  greyhole --wait-for=<drive>");
            $this->log("The '=' character is mandatory.");
            $this->finish(1);
        }
    }

    public function run() {
        mark_gone_ok($this->drive);
        Log::info("Storage pool drive $this->drive has been marked Temporarily-Gone");
        $this->log("Storage pool drive $this->drive has been marked Temporarily-Gone, which means the missing file copies that are in this drive will not be re-created until it reappears.");
    }
}

?>
