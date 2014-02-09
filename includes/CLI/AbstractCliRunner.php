<?php
/*
Copyright 2009-2012 Guillaume Boudreau, Andrew Hopkinson

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

require_once('includes/AbstractRunner.php');

abstract class AbstractCliRunner extends AbstractRunner {
    
    protected $options;

    function __construct($options) {
        parent::__construct();
        $this->options = $options;
    }

    protected function log($what) {
        echo "$what\n";
    }
    
    protected function logn($what) {
        echo "$what";
    }

    protected function restart_service() {
        if (is_file('/etc/init.d/greyhole')) {
            exec("/etc/init.d/greyhole condrestart");
        } else if (is_file('/etc/init/greyhole.conf')) {
            exec("/sbin/restart greyhole");
        } else {
            $this->log("You should now restart the Greyhole daemon.");
        }
    }
}

?>
