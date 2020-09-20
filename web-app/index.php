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

$tabs = [
    'Status'          => 'status.php',
    'Storage pool'    => 'storage_pool.php',
    'Samba Shares'    => 'samba_shares.php',
    'Samba Config'    => 'samba_config.php',
    'Greyhole Config' => 'greyhole_config.php',
    'Actions'         => 'actions.php',
];

$last_known_config_hash = DB::isConnected() ? Settings::get('last_known_config_hash') : get_config_hash();

if (DB::isConnected()) {
    $last_known_config_hash_samba = Settings::get('last_known_config_hash_samba=' . SambaUtils::get_smbd_pid());
}
if (empty($last_known_config_hash_samba)) {
    $last_known_config_hash_samba = get_config_hash_samba();
    if (DB::isConnected()) {
        // First time we see this PID for Samba; remember the config hash to know when a restart is needed
        $q = "DELETE FROM settings WHERE name LIKE 'last_known_config_hash_samba=%'";
        DB::execute($q);
        Settings::set('last_known_config_hash_samba=' . SambaUtils::get_smbd_pid(), $last_known_config_hash_samba);
    }
}

$licensed = FALSE;
if (DB::isConnected()) {
    $guid = GetGUIDCliRunner::setUniqID();
    $licensed = trim(file_get_contents("https://www.greyhole.net/license/check/?guid=" . urlencode($guid))) === '1';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php if ($is_dark_mode) : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootswatch/4.5.2/slate/bootstrap.min.css" integrity="sha384-8iuq0iaMHpnH2vSyvZMSIqQuUnQA7QM+f6srIdlgBrTSEyd//AWNMyEaSF2yPzNQ" crossorigin="anonymous">
    <?php else : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js" integrity="sha256-R4pqcOYV8lt7snxMQO/HSbVCFRPMdrhAFMH+vr9giYI=" crossorigin="anonymous"></script>
    <script src="scripts.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" type="image/png" href="favicon.png" sizes="64x64">
    <title>Greyhole Admin</title>
</head>
<body class="<?php if ($is_dark_mode) echo "dark" ?>">

<div class="container-fluid">
    <nav class="navbar navbar-expand-md navbar-dark bg-dark">
        <div class="navbar-brand">
            <img src="favicon.png" width="30" height="30" class="d-inline-block align-top" alt="">
            Greyhole
        </div>

        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="nav navbar-nav mr-auto" data-name="page">
                <?php $first = empty($_GET['page']); foreach ($tabs as $name => $view) : $active = $first || @$_GET['page'] == 'id_' . md5($name) . '_tab'; if ($active) $selected_page_tab = $name; ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active ? 'active' : '' ?>"
                           id="id_<?php echo md5($name) ?>_tab"
                           data-toggle="tab"
                           href="#id_<?php echo md5($name) ?>"
                           role="tab"
                           aria-controls="id_<?php echo md5($name) ?>"
                           aria-selected="<?php echo $first ? 'true' : 'false' ?>"><?php phe($name) ?></a>
                    </li>
                <?php $first = FALSE; endforeach; ?>
            </ul>

            <div class="custom-control custom-switch navbar-text">
                <input type="checkbox" class="custom-control-input" id="darkSwitch" onchange="toggleDarkMode()">
                <label class="custom-control-label" for="darkSwitch"><?php echo ($is_dark_mode) ? 'Light' : 'Dark' ?> Mode</label>
            </div>

            <?php if (!$licensed) : ?>
                <a id="donate_btn" type="button" class="btn btn-danger ml-3" title="Donate!" onclick="donate()">
                    <svg width="1em" height="1em" viewBox="0 0 16 16" class="bi bi-heart" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" d="M8 2.748l-.717-.737C5.6.281 2.514.878 1.4 3.053c-.523 1.023-.641 2.5.314 4.385.92 1.815 2.834 3.989 6.286 6.357 3.452-2.368 5.365-4.542 6.286-6.357.955-1.886.838-3.362.314-4.385C13.486.878 10.4.28 8.717 2.01L8 2.748zM8 15C-7.333 4.868 3.279-3.04 7.824 1.143c.06.055.119.112.176.171a3.12 3.12 0 0 1 .176-.17C12.72-3.042 23.333 4.867 8 15z"></path>
                    </svg>
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="tab-content">
        <?php foreach ($tabs as $name => $view) : ?>
            <div class="tab-pane fade <?php echo $name == $selected_page_tab ? 'show active' : '' ?>" id="id_<?php echo md5($name) ?>" role="tabpanel" aria-labelledby="id_<?php echo md5($name) ?>_tab">
                <?php include "web-app/views/$view" ?>
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

    <!--suppress UnreachableCodeJS, BadExpressionStatementJS -->
    <script>
        let dark_mode_enabled = <?php echo json_encode($is_dark_mode) ?>;
        let last_known_config_hash = <?php echo json_encode($last_known_config_hash) ?>;
        let last_known_config_hash_samba = <?php echo json_encode($last_known_config_hash_samba) ?>;
        defer(function() {
            if (<?php echo json_encode(get_config_hash()) ?> !== last_known_config_hash) {
                $('#needs-daemon-restart').show();
            }
            if (<?php echo json_encode(get_config_hash_samba()) ?> !== last_known_config_hash_samba) {
                $('#needs-samba-restart').show();
            }

            selectInitialTab('page');
            selectInitialTab('pagesmb');
            selectInitialTab('pagegh', true);

            $('.nav .nav-link').on('shown.bs.tab', function (evt) {
                changedTab(evt.target);
            });

            $(window).on('popstate', function (evt) {
                for (let selected_tab of evt.originalEvent.state.selected_tabs) {
                    skip_changed_tab_event = true;
                    $('#' + selected_tab).tab('show');
                }
            });

            $(window).resize(function() {
                resizeSPDrivesUsageGraphs();
            });
        });
    </script>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
</body>
</html>
