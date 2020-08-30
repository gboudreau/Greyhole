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
                <th>Total</th>
                <th>Used</th>
                <th>Free</th>
                <th>Trash</th>
                <th>Possible</th>
                <th>Min. free space</th>
            </tr>
            </thead>
            <tbody>
                <?php foreach ($stats as $sp_drive => $stat) : ?>
                    <?php if ($sp_drive == 'Total') continue; ?>
                    <tr>
                        <td><?php phe($sp_drive) ?></td>
                        <?php if (empty($stat->total_space)) : ?>
                            <td colspan="5">Offline</td>
                        <?php else : ?>
                            <td><?php echo bytes_to_human($stat->total_space*1024) ?></td>
                            <td><?php echo bytes_to_human($stat->used_space*1024) ?></td>
                            <td><?php echo bytes_to_human($stat->free_space*1024) ?></td>
                            <td><?php echo bytes_to_human($stat->trash_size*1024) ?></td>
                            <td><?php echo bytes_to_human($stat->potential_available_space*1024) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php echo get_config_html(['name' => CONFIG_MIN_FREE_SPACE_POOL_DRIVE . "[$sp_drive]", 'type' => 'kbytes'], Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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

<h2>Samba Shares</h2>
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
    let last_known_config_hash = <?php echo json_encode(get_config_hash()) ?>;
    let dark_mode_enabled = <?php echo json_encode($_COOKIE['darkmode'] === '1') ?>;
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
