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

include(__DIR__ . '/init.inc.php');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php if ($_COOKIE['darkmode'] === '1') : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootswatch/4.5.2/darkly/bootstrap.min.css" integrity="sha384-nNK9n28pDUDDgIiIqZ/MiyO3F4/9vsMtReZK39klb/MtkZI3/LtjSjlmyVPS3KdN" crossorigin="anonymous">
    <?php else : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js" integrity="sha256-R4pqcOYV8lt7snxMQO/HSbVCFRPMdrhAFMH+vr9giYI=" crossorigin="anonymous"></script>
    <script src="scripts.js"></script>
    <link rel="stylesheet" href="styles.css">
    <title>Greyhole Admin Web UI</title>
</head>
<body>

<div class="container-fluid">

<nav class="navbar navbar-dark bg-primary">
    <h1 class="navbar-brand">
        Greyhole Admin Web UI
    </h1>
    <btn class="btn btn-secondary btn-<?php echo ($_COOKIE['darkmode'] === '1') ? 'light' : 'dark' ?>" onclick="toggleDarkMode()"><?php echo ($_COOKIE['darkmode'] === '1') ? 'Light' : 'Dark' ?> mode</btn>
</nav>

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
        $tasks = DBSpool::getInstance()->fetch_next_tasks(TRUE, FALSE);
        if (empty($tasks)) {
            echo "idling.";
        } else {
            $task = array_shift($tasks);
            phe("working on task ID $task->id: $task->action " . clean_dir("$task->share/$task->full_path") . ($task->action == 'rename' ? " -> " . clean_dir("$task->share/$task->additional_info") : ''));
        }
        ?>
    </div>
<?php endif; ?>

<h4>Recent log entries</h4>
<code>
<?php
foreach (StatusCliRunner::get_recent_status_entries() as $log) {
    $date = date("M d H:i:s", strtotime($log->date_time));
    $log_text = sprintf("%s%s",
        "$date $log->action: ",
        $log->log
    );
    echo "  " . he($log_text) . "<br/>";
}
?>
</code>

<div class="alert alert-primary mt-3" role="alert">
    <?php list($last_action, $last_action_time) = StatusCliRunner::get_last_action() ?>
    Last logged action: <strong><?php phe($last_action) ?></strong>,
    on <?php phe(date('Y-m-d H:i:s', $last_action_time) . " (" . how_long_ago($last_action_time) . ")") ?>
</div>

<h2 class="mt-8">Storage Pool Drives</h2>

<?php
$stats = StatsCliRunner::get_stats();
?>

<div class="row">
    <div class="col-sm-12 col-lg-6">
        <table cellspacing="0" cellpadding="6">
            <thead>
                <tr>
                <th>Path</th>
                <th>Size</th>
                <th>Min. free space</th>
            </tr>
            </thead>
            <tbody>
                <?php foreach ($stats as $sp_drive => $stat) : ?>
                    <?php if ($sp_drive == 'Total') continue; ?>
                    <tr>
                        <td>
                            <?php phe($sp_drive) ?>
                        </td>
                        <td>
                            <?php if (empty($stat->total_space)) : ?>
                                Offline
                            <?php else : ?>
                                <?php echo bytes_to_human($stat->total_space*1024, TRUE, TRUE) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo get_config_html(['name' => CONFIG_MIN_FREE_SPACE_POOL_DRIVE . "[$sp_drive]", 'type' => 'kbytes'], Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#modal-storage-pool-drive">
            Add Drive to Storage Pool
        </button>
    </div>
    <div class="col-sm-12 col-lg-6">
        <div class="chart-container">
            <canvas id="chart_storage_pool" width="200" height="200"></canvas>
        </div>
        <script>
            defer(function(){
                let ctx = document.getElementById('chart_storage_pool').getContext('2d');
                drawPieChartStorage(ctx, <?php echo json_encode($stats) ?>);
            });
        </script>
    </div>
</div>
<div id="modal-storage-pool-drive" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Drive to Storage Pool</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="mb-1">Path</div>
                <?php echo get_config_html(['name' => CONFIG_STORAGE_POOL_DRIVE, 'type' => 'string', 'help' => "Specify the absolute path to an empty folder on a new drive.", 'placeholder' => "ex. /mnt/hdd3/gh", 'onchange' => FALSE]) ?>
                <div class="mb-1">Min. free space</div>
                <?php echo get_config_html(['name' => CONFIG_MIN_FREE_SPACE_POOL_DRIVE . "[__new__]", 'type' => 'kbytes', 'help' => "Specify how much free space you want to reserve on each drive. This is a soft limit that will be ignored if the all the necessary hard drives are below their minimum.", 'onchange' => FALSE], 10*1024*1024) ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="addStoragePoolDrive(this)">Add</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<h2 class="mt-8">Samba Shares</h2>
<?php
$possible_values_num_copies = [];
for ($i=1; $i<count(Config::storagePoolDrives()); $i++) {
    $possible_values_num_copies[(string) $i] = $i;
}
$possible_values_num_copies['max'] = 'Max';
?>
<div class="row">
    <div class="col-sm-12 col-lg-6">
        <ul>
            <?php foreach (SharesConfig::getShares() as $share_name => $share_options) : ?>
                <li>
                    <strong><?php phe($share_name) ?></strong><br/>
                    Landing zone: <code><?php phe($share_options['landing_zone']) ?></code><br/>
                    <?php echo get_config_html(['name' => CONFIG_NUM_COPIES . "[$share_name]", 'display_name' => "Number of file copies", 'type' => 'select', 'possible_values' => $possible_values_num_copies], $share_options['num_copies'] == count(Config::storagePoolDrives()) ? 'max' : $share_options['num_copies'] , FALSE) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="col-sm-12 col-lg-6">
        <?php
        $q = "SELECT size, depth, share AS file_path FROM du_stats WHERE depth = 1 ORDER BY size DESC";
        $rows = DB::getAll($q);
        ?>
        <div class="chart-container">
            <canvas id="chart_shares_usage" width="200" height="200"></canvas>
        </div>
        <script>
            defer(function(){
                let ctx = document.getElementById('chart_shares_usage').getContext('2d');
                drawPieChartDiskUsage(ctx, <?php echo json_encode($rows) ?>);
            });
        </script>
    </div>
</div>

<h2 class="mt-8">Greyhole Config</h2>

<?php
global $configs;
include 'web-app/config_definitions.inc.php';
?>
<script>
    let dark_mode_enabled = <?php echo json_encode($_COOKIE['darkmode'] === '1') ?>;
    let last_known_config_hash = <?php echo json_encode(Settings::get('last_known_config_hash')) ?>;
    defer(function(){
        if (<?php echo json_encode(get_config_hash()) ?> !== last_known_config_hash) {
            $('#needs-daemon-restart').show();
        }
    });
</script>
<ul class="nav nav-tabs" id="myTab" role="tablist">
    <?php foreach ($configs as $i => $config) : ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $i == 0 ? 'active' : '' ?>" id="id<?php echo md5($config->name) ?>-tab" data-toggle="tab" href="#id<?php echo md5($config->name) ?>" role="tab" aria-controls="id<?php echo md5($config->name) ?>" aria-selected="true"><?php phe($config->name) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
<div class="tab-content" id="myTabContent">
    <?php foreach ($configs as $i => $config) : ?>
        <div class="tab-pane fade show <?php echo $i == 0 ? 'active' : '' ?>" id="id<?php echo md5($config->name) ?>" role="tabpanel" aria-labelledby="id<?php echo md5($config->name) ?>-tab">
            <?php echo get_config_html($config) ?>
        </div>
    <?php endforeach; ?>
</div>
<div id="footer-padding"></div>

<div id="needs-daemon-restart" class="text-center" style="display:none">
    You will need to restart the Greyhole daemon for your changes to be effective.<br/>
    <button class="btn btn-primary mt-3 mx-auto" onclick="restartDaemon(this)">Restart</button>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
</body>
</html>
