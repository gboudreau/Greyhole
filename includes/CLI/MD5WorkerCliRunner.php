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

class MD5WorkerCliRunner extends AbstractCliRunner {
    private $drives;

    function __construct($options, $cli_command) {
        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->log("Error spawning child md5-worker!");
            $this->finish(1);
        }
        if ($pid == 0) {
            // Child
            parent::__construct($options, $cli_command);
            if (is_array($this->options['drive'])) {
                $this->drives = $this->options['drive'];
            } else {
                $this->drives = array($this->options['drive']);
            }
        } else {
            // Parent
            echo $pid;
            $this->finish(0);
        }
    }
    
    public function run() {
        Log::setAction(ACTION_MD5_WORKER);

        $drives_clause = array();
        $params = array();
        $i = 1;
        foreach ($this->drives as $drive) {
            $key = 'drive' . ($i++);
            $drives_clause[] = "additional_info LIKE :$key";
            $params[$key] = "$drive%";
        }

        $query = "SELECT id, share, full_path, additional_info FROM tasks WHERE action = 'md5' AND complete = 'no' AND (" . implode(' OR ', $drives_clause) . ") ORDER BY id ASC LIMIT 100";

        $last_check_time = time();
        while (TRUE) {
            $task = FALSE;
            if (!empty($new_tasks)) {
                $task = array_shift($new_tasks);
            }
            if ($task === FALSE) {
                $new_tasks = DB::getAll($query, $params);
                if (!empty($new_tasks)) {
                    $task = array_shift($new_tasks);
                }
            }
            if ($task === FALSE) {
                // Nothing new to process

                // Stop this thread once we have nothing more to do, and we have waited 1 hour for more work.
                if (time() - $last_check_time > 3600) {
                    Log::debug("MD5 worker thread for " . implode(', ', $this->drives) . " will now exit; it has nothing more to do.");
                    break;
                }

                sleep(5);
                continue;
            }
            $last_check_time = time();

            Log::info("Working on MD5 task ID $task->id: $task->additional_info");
            $md5 = md5_file($task->additional_info);
            Log::debug("  MD5 for $task->additional_info = $md5");

            $update_query = "UPDATE tasks SET complete = 'yes', additional_info = :additional_info WHERE id = :task_id";
            $params1 = array(
                'task_id' => $task->id,
                'additional_info' => "$task->additional_info=$md5"
            );
            DB::execute($update_query, $params1);
        }
    }
}

?>
