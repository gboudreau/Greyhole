<?php

chdir(__DIR__ . '/..');

if (php_sapi_name() == 'cli-server') {
    // Serving static assets (CSS, JS, etc.), when running using PHP built-in server (`php -S`)
    $static_assets = [
        '/scripts.js' => 'Content-Type: text/javascript; charset=utf8',
        '/styles.css' => 'Content-Type: text/css; charset=utf8',
        '/favicon.png' => 'Content-Type: image/png',
    ];
    if (isset($static_assets[$_SERVER['REQUEST_URI']])) {
        header($static_assets[$_SERVER['REQUEST_URI']]);
        readfile('web-app' . $_SERVER['REQUEST_URI']);
        exit();
    }

    if (strpos($_SERVER['REQUEST_URI'], '/du/') === 0 && !defined('DU')) {
        define('DU', TRUE);
        include 'web-app/du/index.php';
        exit();
    }
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

if (!empty($_GET['ajax'])) {
    header('Content-Type: text/json; charset=utf8');

    switch($_GET['ajax']) {
    case 'config':
        if (string_starts_with($_POST['name'], 'smb.conf')) {
            $_POST['name'] = str_replace('_', ' ', $_POST['name']);
        }
        ConfigCliRunner::change_config($_POST['name'], $_POST['value'], NULL, $error);
        if (!empty($error)) {
            echo json_encode(['result' => 'error', 'message' => "Error: $error"]);
            exit();
        }
        break;
    case 'daemon':
        if ($_POST['action'] == 'restart') {
            if (!DaemonRunner::restart_service()) {
                echo json_encode(['result' => 'error', 'message' => "Error: was not able to identify how to restart daemon. Please do so manually."]);
                exit();
            }
        }
        break;
    case 'samba':
        if ($_POST['action'] == 'restart') {
            if (!SambaUtils::samba_restart()) {
                echo json_encode(['result' => 'error', 'message' => "Error: was not able to identify how to restart Samba. Please do so manually."]);
                exit();
            }
        }
        if ($_POST['action'] == 'add_user') {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            if (empty($username) || empty($password)) {
                echo json_encode(['result' => 'error', 'message' => "Error: Username and password can't be empty."]);
                exit();
            }
            exec("id " . escapeshellarg($username) . " >/dev/null 2>&1", $tmp, $return_var);
            if ($return_var) {
                echo json_encode(['result' => 'error', 'message' => "Error: UNIX user doesn't not exist.\nYou can only create Samba users for existing UNIX users."]);
                exit();
            }
            $cmd = "(echo " . escapeshellarg($password) . "; echo " . escapeshellarg($password) . ") | /usr/bin/smbpasswd -a -s " . escapeshellarg($username) . " 2>&1 | grep -v 'WARNING: '";
            exec($cmd, $output);
            error_log("Result of 'smbpasswd -a $username': " . implode("\n", $output));
            if (!empty($output)) {
                $error = implode("\n", $output);
                echo json_encode(['result' => 'error', 'message' => $error]);
                exit();
            }
        }
        if ($_POST['action'] == 'add_share') {
            $name = trim($_POST['name']);
            $path = $_POST['path'];
            $options = $_POST['options'];
            if (empty($name) || empty($path)) {
                echo json_encode(['result' => 'error', 'message' => "Error: Share name and path can't be empty."]);
                exit();
            }
            ConfigCliRunner::change_config("smb.conf:[$name]path", $path . "\n" . $options, NULL, $error);
            if (preg_match('@dfree command\s*=\s*/usr/bin/greyhole-dfree@', $options)) {
                // Greyhole is enabled; add num_copies to greyhole.conf
                ConfigCliRunner::change_config("num_copies[$name]", 1, NULL, $error);
            }
            if (!is_dir($path)) {
                mkdir($path, 0777, TRUE);
            }
        }
        break;
    }

    echo json_encode(['result' => 'success', 'config_hash' => get_config_hash(), 'config_hash_samba' => get_config_hash_samba()]);
    exit();
}

ConfigHelper::parse();
try {
    DB::connect(FALSE, TRUE, 2);
} catch (Exception $ex) {
    error_log($ex->getMessage());
}

header('Content-Type: text/html; charset=utf8');

$is_dark_mode = ($_COOKIE['darkmode'] === '1');
