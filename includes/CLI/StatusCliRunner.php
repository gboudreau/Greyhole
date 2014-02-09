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

require_once('includes/CLI/AbstractAnonymousCliRunner.php');

class StatusCliRunner extends AbstractAnonymousCliRunner {
    public function run() {
        $num_dproc = (int) exec('ps ax | grep "greyhole --daemon\|greyhole -D" | grep -v grep | wc -l');
        if ($num_dproc == 0) {
            $this->log("");
            $this->log("Greyhole daemon is currently stopped.");
            $this->log("");
            $this->finish(1);
        }

        $tasks = get_next_tasks(TRUE);
        if (empty($tasks)) {
            $this->log("");
            $this->log("Currently idle.");
        } else {
            $task = array_shift($tasks);
            $this->log("");
            $this->log("Currently working on task ID $task->id: $task->action " . clean_dir("$task->share/$task->full_path") . ($task->action == 'rename' ? " -> " . clean_dir("$task->share/$task->additional_info") : ''));
        }

        exec("tail -10 " . escapeshellarg(Config::get(CONFIG_GREYHOLE_LOG_FILE)), $last_log_lines);
        $this->log("");
        $this->log("Recent log entries:");
        $this->log("  " . implode("\n  ", $last_log_lines));

        $last_log_line = $last_log_lines[count($last_log_lines)-1];
        $last_action_time = strtotime(mb_substr($last_log_line, 0, 15));
        $raw_last_log_line = mb_substr($last_log_line, 16);
        $last_log_line = explode(' ', $raw_last_log_line);
        $last_action = str_replace(':', '', $last_log_line[1]);

        $this->log("");
        $this->log("Last logged action: $last_action");
        $this->log("  on " . date('Y-m-d H:i:s', $last_action_time) . " (" . how_long_ago($last_action_time) . ")");
        $this->log("");
    }
}

?>
