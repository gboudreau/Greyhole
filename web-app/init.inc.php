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

    if (!defined('INSTALL')) {
        if (strpos($_SERVER['REQUEST_URI'], '/install/') === 0) {
            define('INSTALL', TRUE);
            include 'web-app/install/index.php';
            exit();
        }
        if (strpos($_SERVER['REQUEST_URI'], '/install') === 0) {
            header('Location: ' . $_SERVER['REQUEST_URI'] . '/');
            exit();
        }
    }
}

if (!defined('DB')) {
    define('IS_WEB_APP', TRUE);
    include 'web-app/includes.inc.php';
}

// Log all notice/warnings/errors
error_reporting(E_ALL);
// To stderr, not greyhole.log
restore_error_handler();

setlocale(LC_CTYPE, "en_US.UTF-8");

try {
    ConfigHelper::parse();
} catch (Exception $ex) {
    error_log($ex->getMessage());
    echo "Fatal error: " . $ex->getMessage();
    exit();
}

try {
    DB::connect(FALSE, TRUE, 2);
} catch (Exception $ex) {
    error_log($ex->getMessage());
}

if (!empty($_GET['ajax'])) {
    header('Content-Type: text/json; charset=utf8');

    error_log("Received POST ?ajax=" . $_GET['ajax'] . ": " . json_encode($_POST));
    switch($_GET['ajax']) {
    case 'config':
        if (string_starts_with($_POST['name'], 'smb.conf')) {
            $_POST['name'] = str_replace('_', ' ', $_POST['name']);
        }
        ConfigCliRunner::change_config($_POST['name'], $_POST['value'], NULL, $error);
        if (!empty($error)) {
            error_log("Error: $error");
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
    case 'donate':
        $guid = GetGUIDCliRunner::setUniqID();
        $response = trim(file_get_contents("https://www.greyhole.net/license/submit/?guid=" . urlencode($guid) . "&email=" . urlencode($_POST['email'])));
        $success = $response === '1';
        if (!$success) {
            echo json_encode(['result' => 'error', 'message' => "Error: failed to submit donation email. Please email support@greyhole.net for help."]);
            exit();
        }
        Settings::set('registered_email', $_POST['email']);
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
                // Create UNIX user first
                unset($output);
                exec("/usr/sbin/adduser --home /nonexistent --no-create-home --shell /usr/sbin/nologin --disabled-password --gecos " . escapeshellarg($username) . " " . escapeshellarg($username) . " 2>&1", $output, $return_var);
                if ($return_var) {
                    $output = implode(" ", $output);
                    echo json_encode(['result' => 'error', 'message' => "Error: UNIX user doesn't not exist, and failed to create ($output).\nYou can only create Samba users for existing UNIX users."]);
                }
            }
            $cmd = "(echo " . escapeshellarg($password) . "; echo " . escapeshellarg($password) . ") | /usr/bin/smbpasswd -a -s " . escapeshellarg($username) . " 2>&1";
            exec($cmd, $output, $return_var);
            error_log("Result of 'smbpasswd -a $username': " . implode("\n", $output));
            if ($return_var) {
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
    case 'fsck':
        $options = [];
        if (@$_POST['action'] == 'cancel') {
            DB::execute("DELETE FROM tasks WHERE action IN ('fsck', 'md5')");
            if (!DaemonRunner::restart_service()) {
                echo json_encode(['result' => 'error', 'message' => "Error: was not able to identify how to restart daemon. Please do so manually."]);
                exit();
            }
            echo json_encode(['result' => 'success']);
            exit();
        }
        foreach ($_POST as $k => $v) {
            if ($k == 'dir') {
                if ($v != '') {
                    if (!is_dir($v)) {
                        echo json_encode(['result' => 'error', 'message' => "Specified dir not found: " . $v]);
                        exit();
                    }
                    $options['dir'] = $v;
                }
            } elseif ($v == 'yes') {
                $options[$k] = TRUE;
            }
        }
        $runner = new FsckCliRunner($options, 'fsck');
        $runner->run();
        break;
    case 'balance':
        if (@$_POST['action'] == 'cancel') {
            DB::execute("DELETE FROM tasks WHERE action = 'balance'");
            if (!DaemonRunner::restart_service()) {
                echo json_encode(['result' => 'error', 'message' => "Error: was not able to identify how to restart daemon. Please do so manually."]);
                exit();
            }
            echo json_encode(['result' => 'success']);
            exit();
        }
        $query = "INSERT INTO tasks (action, share, complete) VALUES ('balance', '', 'yes')";
        DB::insert($query);
        break;
    case 'pause':
        if (@$_POST['action'] == 'pause') {
            $runner = new PauseCliRunner([], 'pause');
        } else {
            $runner = new ResumeCliRunner([], 'resume');
        }
        $success = $runner->run();
        if (!$success) {
            echo json_encode(['result' => 'error', 'message' => "Error: couldn't find a Greyhole daemon running."]);
            exit();
        }
        break;
    case 'trash':
        $runner = new EmptyTrashCliRunner([], 'empty-trash');
        $runner->run();
        break;
    case 'logs':
        $logs = get_status_logs();
        echo json_encode(['result' => 'success', 'logs' => $logs]);
        exit();
    case 'past_tasks':
        $q = "SELECT COUNT(*) FROM tasks_completed";
        $num_rows = DB::getFirstValue($q);

        $columns = ['id', 'event_date', 'action', 'share', 'full_path'];
        $order_by = [];
        foreach ($_GET['order'] as $ob) {
            $order_by[] = $columns[$ob['column']] . ($ob['dir'] == 'desc' ? ' DESC' : '');
        }

        $where = "1";
        $search = NULL;
        if (!empty($_GET['search']['value'])) {
            $where = "full_path LIKE :search OR share LIKE :search OR event_date LIKE :search OR action LIKE :search";
            $search = "%" . str_replace(' ', '%', $_GET['search']['value']) . "%";
        }

        $start = (int) $_GET['start'];
        $length = (int) $_GET['length'];

        $q = "SELECT * FROM tasks_completed WHERE $where ORDER BY " . implode(', ', $order_by) . " LIMIT $start,$length";
        $tasks = DB::getAll($q, ['search' => $search]);

        foreach ($tasks as $task) {
            if ($task->action == 'rename') {
                $task->full_path .= "<br/>=> $task->additional_info";
            }
        }

        $q = "SELECT COUNT(*) FROM tasks_completed WHERE $where";
        $num_rows_filtered = DB::getFirstValue($q, ['search' => $search]);

        echo json_encode(['draw' => $_GET['draw'], 'recordsTotal' => $num_rows, 'recordsFiltered' => $num_rows_filtered, 'data' => $tasks]);
        exit();
    case 'install':
        $step = $_POST['step'];

        if ($step == 4) {
            $host = $_POST['host'];
            if (empty($host)) {
                echo json_encode(['result' => 'error', 'message' => "Error: MySQL host can't be left empty."]);
                exit();
            }
            try {
                $pwd = $_POST['root_pwd'];
                DB::setOptions(['host' => $host, 'name' => 'mysql', 'user' => 'root', 'pass' => $pwd]);
                DB::connect(FALSE, TRUE, 5);
            } catch (Exception $ex) {
                echo json_encode(['result' => 'error', 'message' => "Error: failed to connect to MySQL host $host using root user: " . $ex->getMessage()]);
                exit();
            }

            try {
                $q = "SELECT user()";
                $user = DB::getFirstValue($q);
                list(, $client_host) = explode('@', $user);

                $username = Config::get(CONFIG_DB_USER);
                $db_name = 'greyhole';

                $q = "CREATE DATABASE IF NOT EXISTS $db_name";
                DB::execute($q);
                $q = "CREATE USER IF NOT EXISTS :username@:client_host IDENTIFIED BY :pwd";
                DB::execute($q, ['username' => $username, 'client_host' => $client_host, 'pwd' => '89y63jdwe']);
                $q = "GRANT ALL ON $db_name.* TO :username@:client_host";
                DB::execute($q, ['username' => $username, 'client_host' => $client_host]);
                $q = "USE $db_name";
                DB::execute($q);
                $q = file_get_contents('schema-mysql.sql');
                DB::execute($q);

                ConfigCliRunner::change_config(CONFIG_DB_HOST, $host, NULL);
                ConfigCliRunner::change_config(CONFIG_DB_NAME, 'greyhole', NULL);
            } catch (Exception $ex) {
                echo json_encode(['result' => 'error', 'message' => "Error: failed to initialize Greyhole database: " . $ex->getMessage() . "; Query: $q"]);
                exit();
            }
        }

        if ($step == 6) {
            if (!SambaUtils::samba_restart()) {
                echo json_encode(['result' => 'error', 'message' => "Error: was not able to identify how to restart Samba. Please do so manually."]);
                exit();
            }
            if (!DaemonRunner::restart_service()) {
                echo json_encode(['result' => 'error', 'message' => "Error: was not able to identify how to restart the Greyhole daemon. Please do so manually."]);
                exit();
            }
        }

        $next_step = $step + 1;

        $url = $_SERVER['REQUEST_URI'];
        $url = preg_replace('/\?.*$/', '', $url);
        $next_url = $url . '?step=' . $next_step;
        echo json_encode(['result' => 'success', 'next_page' => $next_url]);
        exit();
    }

    echo json_encode(['result' => 'success', 'config_hash' => get_config_hash(), 'config_hash_samba' => get_config_hash_samba()]);
    exit();
}

header('Content-Type: text/html; charset=utf8');

$is_dark_mode = (@$_COOKIE['darkmode'] === '1');

if (DB::isConnected()) {
    // Make sure the list of storage pool drives is up to date (eg. after we added a new drive, but didn't restart the daemon yet)
    MigrationHelper::convertStoragePoolDrivesTagFiles();
}
