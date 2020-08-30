<?php
/*
Copyright 2009-2020 Guillaume Boudreau

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
        $num_dproc = static::get_num_daemon_proc();
        if ($num_dproc == 0) {
            $this->log();
            $this->log("Greyhole daemon is currently stopped.");
            $this->log();
            $this->finish(1);
        }

        $tasks = DBSpool::getInstance()->fetch_next_tasks(TRUE, FALSE);
        if (empty($tasks)) {
            $this->log();
            $this->log("Currently idle.");
        } else {
            $task = array_shift($tasks);
            $this->log();
            $this->log("Currently working on task ID $task->id: $task->action " . clean_dir("$task->share/$task->full_path") . ($task->action == 'rename' ? " -> " . clean_dir("$task->share/$task->additional_info") : ''));
        }

        $this->log();
        $this->log("Recent log entries:");
        foreach (static::get_recent_status_entries() as $log) {
            $date = date("M d H:i:s", strtotime($log->date_time));
            $log_text = sprintf("%s%s",
                "$date $log->action: ",
                $log->log
            );
            $this->log("  $log_text");
        }

        list($last_action, $last_action_time) = static::get_last_action();

        $this->log();
        $this->log("Last logged action: $last_action");
        $this->log("  on " . date('Y-m-d H:i:s', $last_action_time) . " (" . how_long_ago($last_action_time) . ")");
        $this->log();
    }

    public static function get_recent_status_entries() {
        $q = "SELECT * FROM status ORDER BY id DESC LIMIT 15";
        return array_reverse(DB::getAll($q));
    }

    public static function get_num_daemon_proc() {
        return (int) exec('ps ax | grep "greyhole --daemon\|greyhole -D" | grep -v grep | grep -v bash | wc -l');
    }

    public static function get_last_action() {
        exec("tail -1 " . escapeshellarg(Config::get(CONFIG_GREYHOLE_LOG_FILE)), $last_log_lines);
        $last_log_line = $last_log_lines[count($last_log_lines)-1];
        $last_action_time = strtotime(mb_substr($last_log_line, 0, 15));
        $raw_last_log_line = mb_substr($last_log_line, 16);
        $last_log_line = explode(' ', $raw_last_log_line);
        $last_action = str_replace(':', '', $last_log_line[1]);
        return [$last_action, $last_action_time];
    }
}

?>
