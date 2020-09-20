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

$tabs = [];
if (@$task->action == 'balance') {
    $balance_tab = new Tab('balance', 'Balance Status');
    $tabs[] = $balance_tab;
}

$logs_tab = new Tab('logs', 'Logs');
$tabs[] = $logs_tab;

$queue_tab = new Tab('queue', 'Queue');
$tabs[] = $queue_tab;

$past_tasks_tab = new Tab('past_tasks', 'Past Tasks');
$tabs[] = $past_tasks_tab;

if (FSCKWorkLog::isReportAvailable()) {
    $fsck_tab = new Tab('fsck', 'fsck Report');
    $tabs[] = $fsck_tab;
}
?>

<?php $logs_tab->startContent() ?>
<h4 class="mt-4">Recent log entries</h4>
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
    Last logged action: <strong><?php phe($last_action) ?></strong>
    <?php if (!empty($last_action_time)) : ?>
        , on <?php phe(date('Y-m-d H:i:s', $last_action_time) . " (" . how_long_ago($last_action_time) . ")") ?>
    <?php endif; ?>
</div>
<?php $logs_tab->endContent(); ?>

<?php $queue_tab->startContent() ?>
<h4 class="mt-4">Queue</h4>

<div>
    This table gives you the number of pending operations queued for the Greyhole daemon, per share.
</div>

<table id="queue">
    <thead>
        <tr>
            <th>Share</th>
            <th>Write</th>
            <th>Delete</th>
            <th>Rename</th>
            <th>Check</th>
        </tr>
    </thead>
<?php
$queues = ViewQueueCliRunner::getData();
$num_rows = 0;
foreach ($queues as $share_name => $queue) {
    if ($share_name == 'Spooled') {
        // Will be shown below table
        continue;
    }
    if ($share_name != 'Total' && $queue->num_writes_pending + $queue->num_delete_pending + $queue->num_rename_pending + $queue->num_fsck_pending == 0) {
        // Don't show the rows with no data, except Total
        continue;
    }
    if ($share_name == 'Total' && $num_rows == 1) {
        // Skip Total row if there was only 1 row above it!
        continue;
    }

    $num_rows++;

    $tr_class = $share_name == 'Total' ? 'total' : '';
    echo "<tr class='$tr_class'>";
    echo "<td>" . he($share_name) . "</td>";
    foreach (['num_writes_pending', 'num_delete_pending', 'num_rename_pending', 'num_fsck_pending'] as $prop) {
        $class = $queue->{$prop} > 0 ? 'nonzero' : '';
        echo "<td class='num $class'>{$queue->{$prop}}</td>";
    }
    echo "</tr>";
}
?>
</table>

<div class="mt-4">
    The following is the number of pending operations that the Greyhole daemon still needs to parse.<br/>
    Until it does, the nature of those operations is unknown.<br/>
    Spooled operations that have been parsed will be listed above and disappear from the count below.
    <div class="mt-2" style="font-size: 1.3em; font-weight: bold">
        Spooled operations: <?php echo number_format($queues['Spooled'], 0) ?>
    </div>
</div>
<?php $queue_tab->endContent(); ?>

<?php $past_tasks_tab->startContent() ?>
<h4 class="mt-4">Past Tasks</h4>
<div class="col mt-4">
    <table id="past-tasks-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Queued When</th>
                <th>Action</th>
                <th>Share</th>
                <th>Path</th>
            </tr>
        </thead>
    </table>
</div>
<?php $past_tasks_tab->endContent(); ?>

<?php if (isset($balance_tab)) : ?>
    <?php $balance_tab->startContent() ?>
    <?php if (@$task->action == 'balance') : ?>
        <div class="mt-4">
            <button class="btn btn-danger" onclick="cancelBalance(this)">
                Cancel ongoing balance
            </button>
        </div>
    <?php endif; ?>

    <h4 class="mt-4">Balance Status</h4>
    <?php $groups = BalanceStatusCliRunner::getData() ?>
    <?php foreach ($groups as $group) : ?>
        <div class="alert alert-success" role="alert">
            Target free space in <?php phe($group->name) ?> storage pool drives: <strong><?php echo bytes_to_human($group->target_avail_space*1024, TRUE, TRUE) ?></strong>
        </div>
        <div class="col">
            <table id="table-sp-drives">
                <thead>
                <tr>
                    <th>Path</th>
                    <th>Needs</th>
                    <th>Usage</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $max = 0;
                foreach ($group->drives as $sp_drive => $drive_infos) {
                    if ($drive_infos->df['used'] > $max) {
                        $max = $drive_infos->df['used'];
                    }
                }
                ?>
                <?php foreach ($group->drives as $sp_drive => $drive_infos) : ?>
                    <?php
                    $target_used_space = $drive_infos->df['used'] + ($drive_infos->direction ? -1 : 1) * $drive_infos->diff;
                    ?>
                    <tr>
                        <td>
                            <?php phe($sp_drive) ?>
                        </td>
                        <td>
                            <?php echo $drive_infos->direction . ' ' . bytes_to_human($drive_infos->diff*1024, TRUE, TRUE) ?>
                        </td>
                        <td class="sp-bar-td">
                            <div class="sp-bar target" data-width="<?php echo ($target_used_space/$max) ?>" data-toggle="tooltip" data-placement="bottom" title="<?php phe("Target: " . bytes_to_human($target_used_space*1024, FALSE, TRUE)) ?>">
                            </div><div class="sp-bar <?php echo ($drive_infos->direction == '-' ? 'used' : 'free') ?>" data-width="<?php echo ($drive_infos->diff/$max) ?>" data-toggle="tooltip" data-placement="bottom" title="<?php phe("Diff: " . $drive_infos->direction . ' ' . bytes_to_human($drive_infos->diff*1024, FALSE, TRUE)) ?>"></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <hr/>
    <?php endforeach; ?>
    <?php $balance_tab->endContent(); ?>
<?php endif; ?>

<?php if (isset($fsck_tab)) : ?>
    <?php $fsck_tab->startContent(); ?>
    <?php if (@$task->action == 'fsck') : ?>
        <div class="mt-4">
            <button class="btn btn-danger" onclick="cancelFsck(this)">
                Cancel ongoing fsck
            </button>
        </div>
    <?php endif; ?>

    <h4 class="mt-4">Latest fsck Report</h4>
    <div class="col mt-4">
        <code><?php echo nl2br(he(FSCKWorkLog::getHumanReadableReport())) ?></code>
    </div>
    <?php $fsck_tab->endContent(); ?>
<?php endif; ?>


<h2 class="mt-8">Status</h2>

<?php $num_dproc = StatusCliRunner::get_num_daemon_proc() ?>
<?php if ($num_dproc == 0) : ?>
    <div class="alert alert-danger" role="alert">
        Greyhole daemon is currently stopped.
    </div>
<?php elseif (PauseCliRunner::isPaused()) : ?>
    <div class="alert alert-danger" role="alert">
        Greyhole daemon is currently paused.<br/>
        <button type="button" class="btn btn-primary mt-2" onclick="resumeDaemon(this)">
            Resume Daemon
        </button>
    </div>
<?php else : ?>
    <div class="alert alert-success" role="alert">
        Greyhole daemon is currently running:
        <?php
        if (DB::isConnected()) {
            $tasks = DBSpool::getInstance()->fetch_next_tasks(TRUE, FALSE, FALSE);
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

<?php Tab::printTabs($tabs, 'page_status') ?>
