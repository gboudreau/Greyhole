<?php
/*
Copyright 2014 Guillaume Boudreau

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

abstract class AbstractPoolDriveCliRunner extends AbstractCliRunner {
    
    protected $drive;

    function __construct($options, $cli_command) {
        parent::__construct($options, $cli_command);
        $this->assertPoolDriveSpecified();
    }

    protected function assertPoolDriveSpecified() {
        $simple_long_opt = str_replace(':', '', $this->cli_command->getLongOpt());
        $this->drive = $this->parseCmdParamAsDriveAndExpect(Config::storagePoolDrives());
        if ($this->drive === FALSE) {
            if (!empty($this->drive)) {
                $this->log("Drive $this->drive is not one of your defined storage pool drives.");
            }
            $this->log("Please use one of the following with the --$simple_long_opt option:");
            $this->log("  " . implode("\n  ", Config::storagePoolDrives()));
            $this->log("Note that the correct syntax for this command is:");
            $this->log("  greyhole --$simple_long_opt=<drive>");
            $this->log("The '=' character is mandatory.");
            $this->finish(1);
        }
    }
}

?>
