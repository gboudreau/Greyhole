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

class PauseCliRunner extends AbstractCliRunner {
    public function run() {
        $pid = (int) exec('ps ax | grep "greyhole --daemon\|greyhole -D" | grep -v grep | awk \'{print $1}\'');
        if ($pid) {
            if ($this instanceof ResumeCliRunner) {
                exec('kill -CONT ' . $pid);
                echo "The Greyhole daemon has resumed.\n";
            } else {
                exec('kill -STOP ' . $pid);
                echo "The Greyhole daemon has been paused. Use `greyhole --resume` to restart it.\n";
            }
            exit(0);
        } else {
            echo "Couldn't find a Greyhole daemon running.\n";
            exit(1);
        }
    }
}

?>
