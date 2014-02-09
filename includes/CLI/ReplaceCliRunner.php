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

class ReplaceCliRunner extends AbstractCliRunner {
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
                $this->log("Drive $this->drive is not one of your defined storage pool drives.");
            }
            $this->log("Please use one of the following with the --replace option:");
            $this->log("  " . implode("\n  ", Config::storagePoolDrives()));
            $this->log("Note that the correct syntax for this command is:");
            $this->log("  greyhole --replace=<drive>");
            $this->log("The '=' character is mandatory.");
            $this->finish(1);
        }
    }

    public function run() {
        if (!is_dir($this->drive)) {
            Log::error("The directory $this->drive does not exists. Greyhole can't --replace directories that don't exits.");
            $this->log("The directory $this->drive does not exists. Greyhole can't --replace directories that don't exits.");
            $this->finish(2);
        }

        remove_drive_definition($this->drive);

        Log::info("Storage pool drive $this->drive has been marked replaced. The Greyhole daemon will now be restarted to allow it to use this new drive.");
        $this->log("Storage pool drive $this->drive has been marked replaced. The Greyhole daemon will now be restarted to allow it to use this new drive.");
        $this->restart_service();
    }
}

?>
