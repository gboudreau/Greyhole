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
    <?php if ($is_dark_mode) : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootswatch/4.5.2/darkly/bootstrap.min.css" integrity="sha384-nNK9n28pDUDDgIiIqZ/MiyO3F4/9vsMtReZK39klb/MtkZI3/LtjSjlmyVPS3KdN" crossorigin="anonymous">
    <?php else : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js" integrity="sha256-R4pqcOYV8lt7snxMQO/HSbVCFRPMdrhAFMH+vr9giYI=" crossorigin="anonymous"></script>
    <script src="scripts.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" type="image/png" href="favicon.png" sizes="64x64">
    <title>Greyhole Admin Web UI</title>
</head>
<body class="<?php if ($is_dark_mode) echo "dark"; ?>">

<div class="container-fluid">

<nav class="navbar navbar-dark bg-primary">
    <h1 class="navbar-brand">
        Greyhole Admin Web UI
    </h1>
    <button class="btn btn-secondary btn-<?php echo ($is_dark_mode) ? 'light' : 'dark' ?>" onclick="toggleDarkMode()"><?php echo ($is_dark_mode) ? 'Light' : 'Dark' ?> mode</button>
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

<h2 class="mt-8">Storage Pool</h2>

<?php $stats = StatsCliRunner::get_stats() ?>
<div class="row">
    <div class="col col-sm-12 col-lg-6">
        <table id="table-sp-drives">
            <thead>
                <tr>
                <th>Path</th>
                <th>Min. free space</th>
                    <th>Size</th>
                <th>Usage</th>
            </tr>
            </thead>
            <tbody>
                <?php
                $max = 0;
                foreach ($stats as $sp_drive => $stat) {
                    if ($sp_drive == 'Total') continue;
                    if ($stat->total_space > $max) {
                        $max = $stat->total_space;
                    }
                }
                ?>
                <?php foreach ($stats as $sp_drive => $stat) : ?>
                    <?php if ($sp_drive == 'Total') continue; ?>
                    <tr>
                        <td>
                            <?php phe($sp_drive) ?>
                        </td>
                        <td>
                            <?php echo get_config_html(['name' => CONFIG_MIN_FREE_SPACE_POOL_DRIVE . "[$sp_drive]", 'type' => 'kbytes'], Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive)) ?>
                        </td>
                        <td>
                            <?php if (empty($stat->total_space)) : ?>
                                Offline
                            <?php else : ?>
                                <?php echo bytes_to_human($stat->total_space*1024, TRUE, TRUE) ?>
                            <?php endif; ?>
                        </td>
                        <td class="sp-bar-td">
                            <?php if (!empty($stat->total_space)) : ?>
                                <div class="sp-bar used" data-width="<?php echo (($stat->used_space - $stat->trash_size)/$max) ?>" data-toggle="tooltip" data-placement="bottom" title="<?php phe("Used: " . bytes_to_human(($stat->used_space - $stat->trash_size)*1024, FALSE, TRUE)) ?>">
                                </div><div class="sp-bar trash" data-width="<?php echo ($stat->trash_size/$max) ?>" data-toggle="tooltip" data-placement="bottom" title="<?php phe("Trash: " . bytes_to_human($stat->trash_size*1024, FALSE, TRUE)) ?>">
                                </div><div class="sp-bar free" data-width="<?php echo ($stat->free_space/$max) ?>" data-toggle="tooltip" data-placement="bottom" title="<?php phe("Free: " . bytes_to_human($stat->free_space*1024, FALSE, TRUE)) ?>"></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-storage-pool-drive">
            Add Drive to Storage Pool
        </button>
    </div>
    <div class="col col-sm-12 col-lg-6">
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
                <?php echo get_config_html(['name' => CONFIG_STORAGE_POOL_DRIVE, 'type' => 'string', 'help' => "Specify the absolute path to an empty folder on a new drive.", 'placeholder' => "ex. /mnt/hdd3/gh", 'onchange' => FALSE], '') ?>
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
$max = count(Config::storagePoolDrives());
foreach (SharesConfig::getShares() as $share_name => $share_options) {
    if ($share_options[CONFIG_NUM_COPIES] > $max) {
        $max = $share_options[CONFIG_NUM_COPIES];
    }
    if (is_numeric($share_options[CONFIG_NUM_COPIES . '_raw']) && $share_options[CONFIG_NUM_COPIES . '_raw'] > $max) {
        $max = $share_options[CONFIG_NUM_COPIES . '_raw'];
    }
}

$possible_values_num_copies = ['0' => 'Disabled'];
for ($i=1; $i<=$max; $i++) {
    $possible_values_num_copies[(string) $i] = $i;
}
$possible_values_num_copies['max'] = 'Max';

unset($output);
exec("/usr/bin/testparm -sl 2>/dev/null | grep '\[' | grep -vi '\[global]'", $output);
$all_samba_shares = [];
foreach ($output as $line) {
    if (preg_match('/\s*\[(.+)]\s*$/', $line, $re)) {
        $share_name = $re[1];
        if (array_contains(ConfigHelper::$trash_share_names, $share_name)) {
            $share_options = SharesConfig::getConfigForShare(CONFIG_TRASH_SHARE);
            $share_options['is_trash'] = TRUE;
        } else {
            $share_options = SharesConfig::getConfigForShare($share_name);
        }
        if (empty($share_options)) {
            $share_options['landing_zone'] = exec("/usr/bin/testparm -sl --parameter-name='path' --section-name=" . escapeshellarg($share_name) . " 2>/dev/null");
            $share_options[CONFIG_NUM_COPIES . '_raw'] = '0';
        }
        $share_options['vfs_objects'] = exec("/usr/bin/testparm -sl --parameter-name='vfs objects' --section-name=" . escapeshellarg($share_name) . " 2>/dev/null");
        if (empty($share_options['landing_zone'])) {
            continue;
        }
        $all_samba_shares[$share_name] = $share_options;
    }
}
natksort($all_samba_shares);
?>
<div class="row">
    <div class="col col-sm-12 col-lg-6">
        <table id="table-shares">
            <thead>
            <tr>
                <th>Share</th>
                <th>Landing zone</th>
                <th>Greyhole-enabled?</th>
                <th>Number of file copies</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($all_samba_shares as $share_name => $share_options) : ?>
                <tr>
                    <td><?php phe($share_name) ?></td>
                    <td><code><?php phe($share_options['landing_zone']) ?></code></td>
                    <td class="centered">
                        <?php
                        if (@$share_options['is_trash']) {
                            echo '<a href="https://github.com/gboudreau/Greyhole/wiki/AboutTrash" target="_blank">Greyhole Trash</a>';
                        } else {
                            echo get_config_html(['name' => "gh_enabled[$share_name]", 'type' => 'bool', 'onchange' => 'toggleSambaShareGreyholeEnabled(this)', 'data' => ['sharename' => $share_name]], @$share_options[CONFIG_NUM_COPIES . '_raw'] !== '0', FALSE);
                        }
                        ?>
                    </td>
                    <td class="centered">
                        <?php
                        if (@$share_options['is_trash']) {
                            echo 'N/A';
                        } else {
                            echo get_config_html(['name' => CONFIG_NUM_COPIES . "[$share_name]", 'type' => 'select', 'possible_values' => $possible_values_num_copies], $share_options[CONFIG_NUM_COPIES . '_raw'], FALSE);
                        }
                        ?>
                        <input type="hidden" name="vfs_objects[<?php phe($share_name) ?>]" value="<?php phe($share_options['vfs_objects']) ?>" />
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-add-samba-share">
            Add Samba Share
        </button>
    </div>
    <div class="col col-sm-12 col-lg-6">
        <?php if (DB::isConnected()) : ?>
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
        <?php else : ?>
            (Warning: Can't connect to database to load disk usage statistics.)
        <?php endif; ?>
    </div>
</div>
<div id="modal-add-samba-share" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Samba Share</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <?php list($default_path, $options) = get_new_share_defaults($all_samba_shares); ?>
                <div class="mb-1">Share Name</div>
                <?php echo get_config_html(['name' => 'samba_share_name', 'type' => 'string', 'onchange' => 'updateSambaSharePath(this)', 'placeholder' => "eg. Videos", 'width' => 460], '', FALSE) ?>
                <div class="mb-1">Path (Landing zone)</div>
                <?php echo get_config_html(['name' => 'samba_share_path', 'type' => 'string', 'onchange' => FALSE, 'width' => 460, 'placeholder' => '/path/to/your/share'], $default_path, FALSE) ?>
                <div class="mb-1">Additional Options</div>
                <?php echo get_config_html(['name' => 'samba_share_options', 'type' => 'multi-string', 'onchange' => FALSE, 'width' => 460], $options, FALSE) ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="addSambaShare(this)">Create Share</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<h2 class="mt-8">Samba Config</h2>
<ul class="nav nav-tabs" id="myTabsSamba" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" id="id-smb-general-tab" data-toggle="tab" href="#id-smb-general" role="tab" aria-controls="id-smb-general" aria-selected="true">Greyhole-required options</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="id-smb-users-tab" data-toggle="tab" href="#id-smb-users" role="tab" aria-controls="id-smb-users" aria-selected="true">Users</a>
    </li>
</ul>
<div class="tab-content" id="myTabContentSamba">
    <div class="tab-pane fade show active" id="id-smb-general" role="tabpanel" aria-labelledby="id-smb-general-tab">
        <div class='input_group mt-4'>
            <?php
            $wide_links = exec("/usr/bin/testparm -sl --parameter-name='wide links' 2>/dev/null");
            $unix_extensions = exec("/usr/bin/testparm -sl --parameter-name='unix extensions' 2>/dev/null");
            $allow_insecure_wide_links = exec("/usr/bin/testparm -sl --parameter-name='allow insecure wide links' 2>/dev/null");
            ?>
            <?php echo get_config_html(['name' => 'smb.conf:[global]wide_links', 'display_name' => 'Wide links', 'type' => 'bool', 'help' => "Wide links needs to be enabled, or you won't be able to access your files on your Greyhole-enabled Samba shares."], $wide_links == 'Yes') ?>
            <?php echo get_config_html(['name' => 'smb.conf:[global]unix_extensions', 'display_name' => 'Unix Extensions', 'type' => 'bool', 'help' => "Either you disable Unix Extensions, or enable Allow Insecure Wide Links below."], $unix_extensions == 'Yes') ?>
            <?php echo get_config_html(['name' => 'smb.conf:[global]allow_insecure_wide_links', 'display_name' => 'Allow Insecure Wide Links', 'type' => 'bool'], $allow_insecure_wide_links == 'Yes') ?>
        </div>
    </div>
    <div class="tab-pane fade show" id="id-smb-users" role="tabpanel" aria-labelledby="id-smb-users-tab">
        <div class='input_group mt-4'>
            <?php
            exec("/usr/bin/pdbedit -L | grep -v WARNING | grep -v 4294967295", $samba_users);
            ?>
            <?php echo get_config_html(['name' => 'samba_users', 'display_name' => 'Existing Samba users', 'type' => 'multi-string', 'onchange' => FALSE], $samba_users) ?>
            <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-add-samba-user">
                Add Samba User
            </button>
        </div>
    </div>
</div>
<div id="modal-add-samba-user" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Samba User</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="mb-1">Username</div>
                <?php echo get_config_html(['name' => 'samba_username', 'type' => 'string', 'onchange' => FALSE], '') ?>
                <div class="mb-1">Password</div>
                <?php echo get_config_html(['name' => 'samba_password', 'type' => 'string', 'onchange' => FALSE], '') ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="addSambaUser(this)">Create User</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<h2 class="mt-8">Greyhole Config</h2>

<!--suppress UnreachableCodeJS, BadExpressionStatementJS -->
<script>
    let dark_mode_enabled = <?php echo json_encode($is_dark_mode) ?>;
    let last_known_config_hash = <?php echo json_encode(DB::isConnected() ? Settings::get('last_known_config_hash') : get_config_hash()) ?>;
    <?php
    if (DB::isConnected()) {
        $hash = Settings::get('last_known_config_hash_samba=' . SambaUtils::get_smbd_pid());
    }
    if (empty($hash)) {
        $hash = get_config_hash_samba();
        if (DB::isConnected()) {
            // First time we see this PID for Samba; remember the config hash to know when a restart is needed
            $q = "DELETE FROM settings WHERE name LIKE 'last_known_config_hash_samba=%'";
            DB::execute($q);
            Settings::set('last_known_config_hash_samba=' . SambaUtils::get_smbd_pid(), $hash);
        }
    }
    ?>
    let last_known_config_hash_samba = <?php echo json_encode($hash) ?>;
    defer(function() {
        if (<?php echo json_encode(get_config_hash()) ?> !== last_known_config_hash) {
            $('#needs-daemon-restart').show();
        }
        if (<?php echo json_encode(get_config_hash_samba()) ?> !== last_known_config_hash_samba) {
            $('#needs-samba-restart').show();
        }
    });
</script>

<?php
global $configs;
include 'web-app/config_definitions.inc.php';
?>
<ul class="nav nav-tabs" id="myTabGreyhole" role="tablist">
    <?php foreach ($configs as $i => $config) : ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $i == 0 ? 'active' : '' ?>" id="id<?php echo md5($config->name) ?>-tab" data-toggle="tab" href="#id<?php echo md5($config->name) ?>" role="tab" aria-controls="id<?php echo md5($config->name) ?>" aria-selected="true"><?php phe($config->name) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
<div class="tab-content" id="myTabContentGreyhole">
    <?php foreach ($configs as $i => $config) : ?>
        <div class="tab-pane fade show <?php echo $i == 0 ? 'active' : '' ?>" id="id<?php echo md5($config->name) ?>" role="tabpanel" aria-labelledby="id<?php echo md5($config->name) ?>-tab">
            <?php echo get_config_html($config) ?>
        </div>
    <?php endforeach; ?>
</div>
<div id="footer-padding"></div>

<div id="needs-restart-container">
    <div id="needs-samba-restart" class="text-center" style="display:none">
        You will need to restart the Samba daemon for your changes to be effective.<br/>
        <button class="btn btn-primary mt-3 mx-auto" onclick="restartSamba(this)">Restart</button>
    </div>
    <div id="needs-daemon-restart" class="text-center" style="display:none">
        You will need to restart the Greyhole daemon for your changes to be effective.<br/>
        <button class="btn btn-primary mt-3 mx-auto" onclick="restartDaemon(this)">Restart</button>
    </div>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
</body>
</html>
