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

class DebugCliRunner extends AbstractAnonymousCliRunner {
    public function run() {
        if (!isset($this->options['cmd_param'])) {
            $this->log("Please specify a file to debug.");
            $this->finish(1);
        }
        $filename = $this->options['cmd_param'];

        if (!string_contains($filename, '/')) {
            $filename = "/$filename";
        }

        $this->log("Debugging file operations for file named \"$filename\"");
        $this->log();

        list($to_grep, $debug_tasks) = $this->getDBLogs($filename);
        $this->getAppLogs($to_grep);
        $this->getFilesystemDetails($debug_tasks);
    }

    private function getDBLogs($filename) {
        $this->log("From DB");
        $this->log("=======");

        $query = "SELECT id, action, share, full_path, additional_info, event_date FROM tasks_completed WHERE full_path LIKE :filename ORDER BY id ASC";
        $debug_tasks = DB::getAll($query, array('filename' => "%$filename%"), 'id');

        // Renames
        $query = "SELECT id, action, share, full_path, additional_info, event_date FROM tasks_completed WHERE additional_info LIKE :filename ORDER BY id ASC";
        $params = array('filename' => "%$filename%");
        while (TRUE) {
            $rows = DB::getAll($query, $params);
            foreach ($rows as $row) {
                $debug_tasks[$row->id] = $row;
                $query = "SELECT id, action, share, full_path, additional_info, event_date FROM tasks_completed WHERE additional_info = :full_path ORDER BY id ASC";
                $params = array('full_path' => $row->full_path);
            }

            # Is there more?
            $new_query = preg_replace('/SELECT .* FROM/i', 'SELECT COUNT(*) FROM', $query);
            $count = DB::getFirstValue($new_query, $params);
            if ($count == 0) {
                break;
            }
        }

        ksort($debug_tasks);
        $to_grep = array();
        foreach ($debug_tasks as $task) {
            $this->log("[$task->event_date] Task ID $task->id: $task->action $task->share/$task->full_path" . ($task->action == 'rename' ? " -> $task->share/$task->additional_info" : ''));
            $to_grep["$task->share/$task->full_path"] = 1;
            if ($task->action == 'rename') {
                $to_grep["$task->share/$task->additional_info"] = 1;
            }
        }
        if (empty($to_grep)) {
            $to_grep[$filename] = 1;
            if (string_contains($filename, '/')) {
                $share = trim(mb_substr($filename, 0, mb_strpos(mb_substr($filename, 1), '/')+1), '/');
                $full_path = trim(mb_substr($filename, mb_strpos(mb_substr($filename, 1), '/')+1), '/');
                $debug_tasks[] = (object) array('share' => $share, 'full_path' => $full_path);
            }
        }

        return array($to_grep, $debug_tasks);
    }

    private function getAppLogs($to_grep) {
        $this->log();
        $this->log("From logs");
        $this->log("=========");
        $to_grep = array_keys($to_grep);
        $to_grep = implode("|", $to_grep);
        $commands = array();
        $commands[] = "zgrep -h -E -B 1 -A 2 -h " . escapeshellarg($to_grep) . " " . Config::get(CONFIG_GREYHOLE_LOG_FILE) . "*.gz";
        $commands[] = "grep -h -E -B 1 -A 2 -h " . escapeshellarg($to_grep) . " " . escapeshellarg(Config::get(CONFIG_GREYHOLE_LOG_FILE));
        foreach ($commands as $command) {
            exec($command, $result);
        }

        $result2 = array();
        $i = 0;
        foreach ($result as $rline) {
            if ($rline == '--') { continue; }
            $date_time = substr($rline, 0, 15);
            $timestamp = strtotime($date_time);
            $result2[$timestamp.sprintf("%04d", $i++)] = $rline;
        }
        ksort($result2);
        $this->log(implode("\n", $result2));
    }

    private function getFilesystemDetails($debug_tasks) {
        $this->log();
        $this->log("From filesystem");
        $this->log("===============");

        $last_task = array_pop($debug_tasks);
        $share = $last_task->share;
        $full_path = $last_task->full_path;
        $this->log("Landing Zone:");
        passthru("ls -l " . escapeshellarg(get_share_landing_zone($share) . "/" . $full_path));

        $this->log();
        $this->log("Metadata store:");
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $metastore = clean_dir("$sp_drive/.gh_metastore");
            if (file_exists("$metastore/$share/$full_path")) {
                passthru("ls -l " . escapeshellarg("$metastore/$share/$full_path"));
                $data = unserialize(file_get_contents("$metastore/$share/$full_path"));
                $this->log(json_pretty_print($data));
            }
        }

        $this->log();
        $this->log("File copies:");
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (file_exists("$sp_drive/$share/$full_path")) {
                $this->logn("  "); passthru("ls -l " . escapeshellarg("$sp_drive/$share/$full_path"));
            }
        }
    }
}

?>
