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

if (DB::isConnected()) {
    $q = "SELECT date_time, action FROM `status` ORDER BY id DESC LIMIT 1";
    $last_status = DB::getFirst($q);
    $current_action = $last_status->action;
} else {
    $current_action = FALSE;
}

$tabs = [];
if ($current_action == 'balance') {
    $balance_tab = new Tab('l2_status_balance', 'Balance Status');
    $tabs[] = $balance_tab;
}

$logs_tab = new Tab('l2_status_logs', 'Logs');
$tabs[] = $logs_tab;

$queue_tab = new Tab('l2_status_queue', 'Queue');
$tabs[] = $queue_tab;

$past_tasks_tab = new Tab('l2_status_past_tasks', 'Past Tasks');
$tabs[] = $past_tasks_tab;

if (FSCKWorkLog::isReportAvailable()) {
    $fsck_tab = new Tab('l2_status_fsck', 'fsck Report');
    $tabs[] = $fsck_tab;
}
?>

<?php $logs_tab->startContent() ?>
<h4 class="mt-4">Recent log entries</h4>
<div>
    <div class="custom-control custom-switch navbar-text">
        <input type="checkbox" class="custom-control-input" id="tail-status-log" onchange="tailStatusLogs(this)">
        <label class="custom-control-label" for="tail-status-log">Follow (tail) status logs</label>
    </div>
</div>
<code id="status_logs">
    <?php
    if (!DB::isConnected()) {
        echo " (Warning: Can't connect to database to load log entries.)";
    } else {
        echo "Loading...";
    }
    ?>
</code>

<div class="alert alert-primary mt-3" role="alert" id="last_action"></div>
<?php $logs_tab->endContent(); ?>

<?php $queue_tab->startContent() ?>
<h4 class="mt-4">Queue</h4>

<div>
    This table gives you the number of pending operations queued for the Greyhole daemon, per share.
</div>

<table id="queue">
    <thead>
        <tr class="header">
            <th>Share</th>
            <th>Write</th>
            <th>Delete</th>
            <th>Rename</th>
            <th>Check</th>
        </tr>
    </thead>
    <tr class="loading">
        <td colspan="5">Loading...</td>
    </tr>
</table>

<div class="mt-4">
    The following is the number of pending operations that the Greyhole daemon still needs to parse.<br/>
    Until it does, the nature of those operations is unknown.<br/>
    Spooled operations that have been parsed will be listed above and disappear from the count below.
    <div class="mt-2" style="font-size: 1.3em; font-weight: bold">
        Spooled operations: <span id="num_spooled_ops"></span>
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
    <div class="mt-4" id="cancel_balance_container" style="display:none">
        <button class="btn btn-danger" onclick="cancelBalance(this)">
            Cancel ongoing balance
        </button>
    </div>

    <h4 class="mt-4">Balance Status</h4>

    <div id="balance_groups"></div>

    <?php $balance_tab->endContent(); ?>
<?php endif; ?>

<?php if (isset($fsck_tab)) : ?>
    <?php $fsck_tab->startContent(); ?>
    <div class="mt-4" id="cancel_fsck_container" style="display: none">
        <button class="btn btn-danger" onclick="cancelFsck(this)">
            Cancel ongoing fsck
        </button>
    </div>

    <h4 class="mt-4">Latest fsck Report</h4>
    <div class="col mt-4">
        <code id="fsck-report-code">Loading...</code>
    </div>
    <?php $fsck_tab->endContent(); ?>
<?php endif; ?>


<h2 class="mt-8">Status</h2>

<div class="alert alert-danger daemon-status" role="alert" id="daemon-status-stopped" style="display: none">
    Greyhole daemon is currently stopped.
</div>

<div class="alert alert-danger daemon-status" role="alert" id="daemon-status-paused" style="display: none">
    Greyhole daemon is currently paused.<br/>
    <button type="button" class="btn btn-primary mt-2" onclick="resumeDaemon(this)">
        Resume Daemon
    </button>
</div>

<div class="alert alert-success daemon-status" role="alert" id="daemon-status-running" style="display: none">
</div>

<?php Tab::printTabs($tabs, 'page_status') ?>
