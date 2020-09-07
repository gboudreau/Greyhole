<?php
/*
Copyright 2020 Guillaume Boudreau

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
?>

<h2 class="mt-8">Status</h2>

<?php $num_dproc = StatusCliRunner::get_num_daemon_proc() ?>
<?php if ($num_dproc == 0) : ?>
    <div class="alert alert-danger" role="alert">
        Greyhole daemon is currently stopped.
    </div>
<?php else : ?>
    <div class="alert alert-success" role="alert">
        Greyhole daemon is currently running:
        <?php
        if (DB::isConnected()) {
            $tasks = DBSpool::getInstance()->fetch_next_tasks(TRUE, FALSE);
            if (empty($tasks)) {
                echo "idling.";
            } else {
                $task = array_shift($tasks);
                phe("working on task ID $task->id: $task->action " . clean_dir("$task->share/$task->full_path") . ($task->action == 'rename' ? " -> " . clean_dir("$task->share/$task->additional_info") : ''));
            }
        } else {
            echo " (Warning: Can't connect to database to load current task.)";
        }
        ?>
    </div>
<?php endif; ?>

<h4>Recent log entries</h4>
<code>
    <?php
    if (DB::isConnected()) {
        foreach (StatusCliRunner::get_recent_status_entries() as $log) {
            $date = date("M d H:i:s", strtotime($log->date_time));
            $log_text = sprintf("%s%s",
                "$date $log->action: ",
                $log->log
            );
            echo "  " . he($log_text) . "<br/>";
        }
    } else {
        echo " (Warning: Can't connect to database to load log entries.)";
    }
    ?>
</code>

<div class="alert alert-primary mt-3" role="alert">
    <?php list($last_action, $last_action_time) = StatusCliRunner::get_last_action() ?>
    Last logged action: <strong><?php phe($last_action) ?></strong>,
    on <?php phe(date('Y-m-d H:i:s', $last_action_time) . " (" . how_long_ago($last_action_time) . ")") ?>
</div>
