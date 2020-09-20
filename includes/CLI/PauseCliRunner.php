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
    public static function isPaused() {
        $flags = exec('ps ax -o pid,stat,comm,args | grep "greyhole --daemon\|greyhole -D" | grep -v grep | grep -v bash | awk \'{print $2}\'');
        return string_contains($flags, 'T');
    }

    public function run() {
        $pid = (int) exec('ps ax -o pid,stat,comm,args | grep "greyhole --daemon\|greyhole -D" | grep -v grep | grep -v bash | awk \'{print $1}\'');
        if ($pid) {
            if ($this instanceof ResumeCliRunner) {
                exec('kill -CONT ' . $pid);
                $this->log("The Greyhole daemon (PID $pid) has resumed.");
            } else {
                exec('kill -STOP ' . $pid);
                $this->log("The Greyhole daemon (PID $pid) has been paused. Use `greyhole --resume` to restart it.");
            }
            if (isset($_POST)) {
                return TRUE;
            }
            exit(0);
        } else {
            $this->log("Couldn't find a Greyhole daemon running.");
            if (isset($_POST)) {
                return FALSE;
            }
            exit(1);
        }
    }

    protected function log($what='') {
        if (isset($_POST)) {
            error_log($what);
        } else {
            echo "$what\n";
        }
    }

    protected function logn($what) {
        if (isset($_POST)) {
            error_log($what);
        } else {
            echo $what;
        }
    }
}

?>
