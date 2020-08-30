<?php

chdir(__DIR__ . '/..');

// Static assets (CSS, JS, etc.)
$static_assets = [
    '/scripts.js' => 'Content-Type: text/javascript; charset=utf8',
    '/styles.css' => 'Content-Type: text/css; charset=utf8',
];
if (isset($static_assets[$_SERVER['REQUEST_URI']])) {
    header($static_assets[$_SERVER['REQUEST_URI']]);
    readfile('web-app' . $_SERVER['REQUEST_URI']);
    exit();
}

include('includes/common.php');
include('includes/CLI/CommandLineHelper.php'); // Command line helper (abstract classes, command line definitions & parsing, Runners, etc.)
include('includes/DaemonRunner.php');

include('web-app/functions.inc.php');

// Log all notice/warnings/errors
error_reporting(E_ALL);
// To stderr, not greyhole.log
restore_error_handler();

setlocale(LC_CTYPE, "en_US.UTF-8");

if ($_SERVER['REQUEST_URI'] == '/ajax/config/') {
    header('Content-Type: text/json; charset=utf8');
    ConfigCliRunner::change_config($_POST['name'], $_POST['value'], NULL, $error);
    if (!empty($error)) {
        echo json_encode(['result' => 'error', 'message' => "Error: $error"]);
    } else {
        echo json_encode(['result' => 'success', 'config_hash' => get_config_hash()]);
    }
    exit();
}

if ($_SERVER['REQUEST_URI'] == '/ajax/daemon/' && $_POST['action'] == 'restart') {
    header('Content-Type: text/json; charset=utf8');
    if (DaemonRunner::restart_service()) {
        echo json_encode(['result' => 'success', 'config_hash' => get_config_hash()]);
    } else {
        echo json_encode(['result' => 'error', 'message' => "Error: was not able to identify how to restart daemon. Please do so manually."]);
    }
    exit();
}

ConfigHelper::parse();
DB::connect();

header('Content-Type: text/html; charset=utf8');
