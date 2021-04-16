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
    case 'get_status':
        if (DB::isConnected()) {
            $tasks = DBSpool::getInstance()->fetch_next_tasks(TRUE, FALSE, FALSE);
            if (!empty($tasks)) {
                $task = array_shift($tasks);

                $q = "SELECT date_time, action FROM `status` WHERE date_time > DATE_SUB(NOW(), INTERVAL 1 MINUTE) ORDER BY id DESC LIMIT 1";
                $last_status = DB::getFirst($q);
                $current_action = $last_status->action;
            }

            $status_text = "Greyhole daemon is currently running: ";
            if (empty($tasks) && empty($current_action)) {
                $status_text .= "idling";
            } else {
                if ($current_action == $task->action) {
                    $status_text .= he("working on task ID $task->id: $task->action " . clean_dir("$task->share/$task->full_path") . ($task->action == 'rename' ? " -> " . clean_dir("$task->share/$task->additional_info") : ''));
                } else {
                    $status_text .= he("working on '$current_action' task");
                }
            }
        } else {
            $status_text = "Can't connect to database to load current task.";
        }

        $num_dproc = StatusCliRunner::get_num_daemon_proc();

        echo json_encode(['result' => 'success', 'daemon_status' => $num_dproc == 0 ? 'stopped' : (PauseCliRunner::isPaused() ? 'paused' : 'running'), 'status_text' => $status_text, 'current_action' => @$current_action]);
        exit();
    case 'get_status_logs':
        $logs = get_status_logs();
        list($last_action, $last_action_time) = StatusCliRunner::get_last_action();
        echo json_encode(['result' => 'success', 'logs' => $logs, 'last_action' => $last_action, 'last_action_time' => empty($last_action_time) ? NULL : date('Y-m-d H:i:s', $last_action_time), 'last_action_time_relative' => empty($last_action_time) ? NULL : how_long_ago($last_action_time)]);
        exit();
    case 'get_status_queue_content':
        $queues = ViewQueueCliRunner::getData();
        $num_rows = 0;
        $rows = [];
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

            $rows[] = ['share_name' => $share_name, 'queue' => $queue];
        }

        echo json_encode(['result' => 'success', 'rows' => $rows, 'num_spooled_ops' => number_format($queues['Spooled'], 0)]);
        exit();
    case 'get_status_past_tasks':
        $q = "SELECT COUNT(*) FROM tasks_completed";
        $num_rows = DB::getFirstValue($q);

        $columns = ['id', 'event_date', 'action', 'share', 'full_path'];
        $order_by = [];
        foreach ($_GET['order'] as $ob) {
            $order_by[] = $columns[$ob['column']] . ($ob['dir'] == 'desc' ? ' DESC' : '');
        }

        $where = "1";
        $search = NULL;
        $params = [];
        if (!empty($_GET['search']['value'])) {
            $where = "full_path LIKE :search OR share LIKE :search OR event_date LIKE :search OR action LIKE :search";
            $search = "%" . str_replace(' ', '%', $_GET['search']['value']) . "%";
            $params['search'] = $search;
        }

        $start = (int) $_GET['start'];
        $length = (int) $_GET['length'];

        $q = "SELECT * FROM tasks_completed WHERE $where ORDER BY " . implode(', ', $order_by) . " LIMIT $start,$length";
        $tasks = DB::getAll($q, $params);

        foreach ($tasks as $task) {
            if ($task->action == 'rename') {
                $task->full_path .= "<br/>=> $task->additional_info";
            }
        }

        $q = "SELECT COUNT(*) FROM tasks_completed WHERE $where";
        $num_rows_filtered = DB::getFirstValue($q, $params);

        echo json_encode(['draw' => $_GET['draw'], 'recordsTotal' => $num_rows, 'recordsFiltered' => $num_rows_filtered, 'data' => $tasks]);
        exit();
    case 'get_status_fsck_report':
        $q = "SELECT date_time, action FROM `status` ORDER BY id DESC LIMIT 1";
        $last_status = DB::getFirst($q);

        $report_html = nl2br(he(FSCKWorkLog::getHumanReadableReport()));

        echo json_encode(['result' => 'success', 'show_stop_button' => ($last_status->action == 'fsck'), 'report_html' => $report_html]);
        exit();
    case 'get_status_balance_report':
        $q = "SELECT date_time, action FROM `status` ORDER BY id DESC LIMIT 1";
        $last_status = DB::getFirst($q);

        $groups = BalanceStatusCliRunner::getData();
        foreach ($groups as $group) {
            $group->target_avail_space_html = bytes_to_human($group->target_avail_space*1024, TRUE, TRUE);

            $max = 0;
            foreach ($group->drives as $sp_drive => $drive_infos) {
                if ($drive_infos->df['used'] > $max) {
                    $max = $drive_infos->df['used'];
                }
                if ($drive_infos->df['used'] + abs($drive_infos->diff) > $max) {
                    $max = $drive_infos->df['used'] + abs($drive_infos->diff);
                }
            }

            foreach ($group->drives as $sp_drive => $drive_infos) {
                $target_used_space = $drive_infos->df['used'] - ($drive_infos->direction == '+' ? 0 : $drive_infos->diff);
                $drive_infos->target_width = $target_used_space / $max;
                $drive_infos->diff_width = $drive_infos->diff / $max;
                $drive_infos->target_used_space = bytes_to_human($target_used_space*1024, FALSE, TRUE);
                $drive_infos->diff_html = bytes_to_human($drive_infos->diff*1024, TRUE, TRUE);
                $drive_infos->diff = bytes_to_human($drive_infos->diff*1024, FALSE, TRUE);
                if ($drive_infos->direction == '+') {
                    $drive_infos->tooltip = bytes_to_human($drive_infos->df['used']*1024, FALSE, TRUE) . " (used) + " . $drive_infos->diff . " (to be added)";
                } else {
                    $drive_infos->tooltip = bytes_to_human($drive_infos->df['used']*1024, FALSE, TRUE) . " (used) - " . $drive_infos->diff . " (to be removed) = " . $drive_infos->target_used_space = bytes_to_human($target_used_space*1024, FALSE, TRUE) . " (target)";
                }
            }
        }

        echo json_encode(['result' => 'success', 'show_stop_button' => ($last_status->action == 'balance'), 'groups' => $groups]);
        exit();
    case 'get_storage_pool':
        $sp_stats = StatsCliRunner::get_stats();

        $max = 0;
        foreach ($sp_stats as $sp_drive => $stat) {
            if ($sp_drive == 'Total') continue;
            if ($stat->total_space > $max) {
                $max = $stat->total_space;
            }
        }

        foreach ($sp_stats as $sp_drive => $stat) {
            $stat->min_free_html = get_config_html(['name' => CONFIG_MIN_FREE_SPACE_POOL_DRIVE . "[$sp_drive]", 'type' => 'kbytes'], Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive));
            $stat->size_html = empty($stat->total_space) ? 'Offline' : bytes_to_human($stat->total_space*1024, TRUE, TRUE);
            $stat->used_width = ($stat->used_space - $stat->trash_size) / $max;
            $stat->used_tooltip = 'Used: ' . bytes_to_human(($stat->used_space - $stat->trash_size)*1024, FALSE, TRUE);
            $stat->trash_width = $stat->trash_size / $max;
            $stat->trash_tooltip = 'Trash: ' . bytes_to_human(($stat->trash_size)*1024, FALSE, TRUE);
            $stat->free_width = $stat->free_space / $max;
            $stat->free_tooltip = 'Free: ' . bytes_to_human(($stat->free_space)*1024, FALSE, TRUE);
        }

        echo json_encode(['result' => 'success', 'sp_stats' => $sp_stats]);
        exit();
    case 'get_trashman_content':
        // Find all files in specified directory, with their size (%s) and last modified date (%T@)
        $dir = $_REQUEST['dir'];
        $output = [];
        foreach (Config::storagePoolDrives() as $sp_drive) {
            // Ensure the specified dir doesn't contains .. to try to go outside the trash folder!
            $base_dir = "$sp_drive/.gh_trash";
            if (substr("$base_dir/$dir",0, strlen($base_dir)) !== $base_dir) {
                continue;
            }
            if (is_dir("$sp_drive/.gh_trash/$dir")) {
                chdir("$sp_drive/.gh_trash/$dir");
                exec("find . -type f -printf \"%s %T@ %p\\n\"", $output);
            }
        }
        chdir(__DIR__);

        // Build $trash_content array that counts and sums the filesize of all files contained in the current directory
        $trash_content = [];
        $restore_content = [];
        foreach ($output as $line) {
            $line = explode(" ", trim($line));
            $size = array_shift($line);
            $last_modified = date('Y-m-d H:i:s', explode('.', array_shift($line))[0]);
            $line = implode(" ", $line);
            $file_path = explode("/", $line);
            array_shift($file_path);
            $dir_path = ['.'];
            foreach ($file_path as $part) {
                $key = implode("/", $dir_path);
                if (!isset($trash_content[$key])) {
                    $trash_content[$key] = [];
                }
                if (count($dir_path) > 1) {
                    // No need to calculate stats for subfolders
                    break;
                }
                if (!isset($trash_content[$key][$part])) {
                    $trash_content[$key][$part] = ['size' => 0, 'num_copies' => 0, 'modified' => $last_modified];
                }
                $trash_content[$key][$part]['size'] += $size;
                $trash_content[$key][$part]['num_copies']++;
                $dir_path[] = $part;

                $restore_content[$part][implode("/", $file_path)] = $size;
            }
        }

        if (!isset($trash_content['.'])) {
            // Can happen after someone deleted the last entry using trashman
            $data[] = ['path' => 'Empty', 'size' => '', 'copies' => '', 'modified' => '', 'actions' => ''];
        } else {
            // Display the files/folders found in the current dir (.)
            $result = $trash_content['.'];
            $num_rows = count($result);
            foreach ($result as $path => $stat) {
                $files_to_restore = $restore_content[$path];
                $data[] = ['path' => $path, 'size' => $stat['size'], 'copies' => $stat['num_copies'], 'modified' => $stat['modified'], 'copies_restore' => count($files_to_restore), 'size_restore' => bytes_to_human(array_sum($files_to_restore), TRUE, TRUE)];
            }

            // Sort (using user-specified column & direction)
            $columns = ['path', 'size', 'copies', 'modified'];
            $order_by = [];
            foreach ($_GET['order'] as $ob) {
                $order_by[] = [$columns[$ob['column']], $ob['dir']];
            }
            usort($data, function ($d1, $d2) use ($order_by) {
                foreach ($order_by as $ob) {
                    $col = $ob[0];
                    $dir = $ob[1];
                    if ($d1[$col] < $d2[$col]) {
                        if ($dir == 'asc') {
                            return -1;
                        }
                        return 1;
                    }
                    if ($d1[$col] > $d2[$col]) {
                        if ($dir == 'asc') {
                            return 1;
                        }
                        return -1;
                    }
                }
                return 0;
            });

            // Format size values; add link (navigation) for directories, add action buttons (delete, restore)
            foreach ($data as &$d) {
                $d['raw_path'] = $d['path'];
                $key = './' . $d['path'];
                $d['size_delete'] = bytes_to_human($d['size'], TRUE, TRUE);
                if (isset($trash_content[$key])) {
                    // Is a folder
                    $d['path'] = '<a href="#enter" onclick="trashmanEnterFolder(this); return false">' . he($d['path']) . '</a>';
                    $d['size'] = number_format($d['size']);
                } else {
                    // Is a file
                    $d['path'] = $key;
                    if ($d['copies'] > 1) {
                        $d['size'] = $d['copies'] . ' x ' . bytes_to_human($d['size'] / $d['copies'], TRUE, TRUE) . ' = ' . number_format($d['size']);
                    } else {
                        $d['size'] = number_format($d['size']);
                    }
                }
                $d['actions'] = '<a class="btn btn-danger" href="#delete" onclick="trashmanDelete(this); return false">Delete forever...</a> <a class="btn btn-success" href="restore" onclick="trashmanRestore(this); return false">Restore...</a>';
            }
        }

        // If we're in a sub-directory, add a row at the top to allow navigating back to the parent directory
        if ($dir != '.') {
            array_unshift($data, ['path' => '<a href="#parent" onclick="trashmanGoToParent(); return false">&lt; Parent directory</a>', 'size' => '', 'modified' => '', 'copies' => '', 'actions' => '']);
        }

        if (!isset($num_rows)) {
            $num_rows = 0;
        }
        echo json_encode(['draw' => $_GET['draw'], 'recordsTotal' => $num_rows, 'recordsFiltered' => $num_rows, 'data' => $data]);
        exit();
    case 'restore_from_trash':
        $folder = $_REQUEST['folder'];
        $restore_content = [];
        foreach (Config::storagePoolDrives() as $sp_drive) {
            // Ensure the specified dir doesn't contains .. to try to go outside the trash folder!
            $base_dir = "$sp_drive/.gh_trash";
            if (substr("$base_dir/$folder",0, strlen($base_dir)) !== $base_dir) {
                continue;
            }
            if (is_dir("$sp_drive/.gh_trash/$folder")) {
                chdir("$sp_drive/.gh_trash/$folder");
                $dir = $folder;
                unset($output);
                exec("find . -type f -printf \"%s %T@ %p\\n\"", $output);
                chdir(__DIR__);
            } elseif (is_file("$sp_drive/.gh_trash/$folder")) {
                $file = "$sp_drive/.gh_trash/$folder";
                $size = gh_filesize($file);
                $last_modified = filemtime($file);
                $file = substr($file, strlen("$sp_drive/.gh_trash/"));
                $dir = dirname($file);
                $output = ["$size $last_modified ./" . basename($file)];
            } else {
                continue;
            }
            foreach ($output as $line) {
                $line = explode(" ", trim($line));
                $size = array_shift($line);
                $last_modified = date('Y-m-d H:i:s', explode('.', array_shift($line))[0]);
                $line = implode(" ", $line);
                $file_path = explode("/", $line);
                array_shift($file_path);
                $file_path = implode("/", $file_path);
                $restore_content[$file_path][] = ['path' => "$sp_drive/.gh_trash/$dir/$file_path", 'size' => $size, 'last_modified' => $last_modified, 'sp_drive' => $sp_drive];
            }
        }

        $total_size = 0;
        $file_copies_to_restore = [];
        foreach ($restore_content as $trashed_files) {
            $max_last_modified = 0;
            $file_to_restore = ['last_modified' => 0, 'size' => 0];
            foreach ($trashed_files as $trashed_file) {
                if (strtotime($trashed_file['last_modified']) >= $file_to_restore['last_modified']) {
                    if (strtotime($trashed_file['last_modified']) > $file_to_restore['last_modified'] || $trashed_file['size'] > $file_to_restore['size']) {
                        $file_to_restore = $trashed_file;
                    }
                }
            }
            $file_copies_to_restore[] = $file_to_restore;
            $total_size += $file_to_restore['size'];
        }

        error_log("Restoring " . count($file_copies_to_restore) . " files totaling " . bytes_to_human($total_size, FALSE, TRUE) . " from trash into $dir");

        foreach ($file_copies_to_restore as $file_copy_to_restore) {
            $source = $file_copy_to_restore['path'];
            $target = str_replace($file_copy_to_restore['sp_drive'] . "/.gh_trash/", $file_copy_to_restore['sp_drive'] . "/", $file_copy_to_restore['path']);

            $link = substr($target, strlen($file_copy_to_restore['sp_drive'])+1);
            $link = explode("/", $link);
            $share = array_shift($link);
            $full_path = implode("/", $link);
            $link = get_share_landing_zone($share) . "/$full_path";

            $tentative_link = $link;
            $i = 0;
            while (file_exists($tentative_link)) {
                $i++;
                $tentative_link = $link . " (restored $i)";
            }
            $link = $tentative_link;

            if ($i > 0) {
                $target .= " (restored $i)";
            }
            error_log("- Moving $source into $target");
            gh_mkdir(dirname($target), dirname($source));
            rename($source, $target);

            error_log("- Creating symlink at $link");
            gh_mkdir(dirname($link), dirname($source));
            gh_symlink($target, $link);
            FsckFileTask::queue($share, dirname($full_path) . '/' . basename($link));
        }

        echo json_encode(['result' => 'success']);
        exit();
    case 'delete_from_trash':
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $base_dir = "$sp_drive/.gh_trash";
            $path_to_delete_tr = realpath("$base_dir/" . $_REQUEST['folder']);
            if ($path_to_delete_tr && substr($path_to_delete_tr,0, strlen($base_dir)) === $base_dir) {
                // First, delete the folders & symlinks from the Trash share, if it exists
                $trash_share = SharesConfig::getConfigForShare(CONFIG_TRASH_SHARE);
                if ($trash_share) {
                    $base_dir = $trash_share[CONFIG_LANDING_ZONE];
                    $path_to_delete = "$base_dir/" . $_REQUEST['folder'];
                    if (file_exists($path_to_delete)) {
                        $rm_command = "rm -rf " . escapeshellarg($path_to_delete);
                        error_log($rm_command);
                        exec($rm_command);

                        // Also delete other copies symlinks from the Trash share, if any (those are named "FILENAME copy #")
                        $i = 1;
                        while (TRUE) {
                            $path_to_delete = "$base_dir/" . $_REQUEST['folder'] . " copy $i";
                            if (file_exists($path_to_delete) && !is_dir($path_to_delete)) {
                                $rm_command = "rm -rf " . escapeshellarg($path_to_delete);
                                error_log($rm_command);
                                exec($rm_command);
                                $i++;
                            } else {
                                break;
                            }
                        }
                    }
                }

                // Then delete the trashed files from the .gh_trash
                $rm_command = "rm -rf " . escapeshellarg($path_to_delete_tr);
                error_log($rm_command);
                exec($rm_command);
            }
        }
        echo json_encode(['result' => 'success']);
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
    case 'pre-remove-drive':
        $drive = $_REQUEST['drive'];
        if (is_dir("$drive/.gh_metastore/")) {
            echo json_encode(['result' => 'success', 'drive_is_available' => TRUE]);
        } else {
            echo json_encode(['result' => 'success', 'drive_is_available' => FALSE]);
        }
        exit();
    case 'remove_drive':
        $drive = $_REQUEST['drive'];
        $drive_still_available = ($_REQUEST['drive_is_available'] == 'yes');

        $query = "INSERT INTO tasks SET action = :action, share = :share, full_path = :full_path, additional_info = :options, complete = 'yes'";
        $params = array(
            'action'    => ACTION_REMOVE,
            'share'     => 'pool drive ',
            'full_path' => $drive,
            'options'   => ( $drive_still_available ? OPTION_DRIVE_IS_AVAILABLE : '' ),
        );
        DB::insert($query, $params);
        sleep(1);

        echo json_encode(['result' => 'success', 'text' => "Removal of $drive has been scheduled. It will start after all currently pending tasks have been completed.\nYou will receive an email notification once it completes.\nYou can also tail the Greyhole log to follow this operation."]);
        exit();
    case 'trash_content':
        $sp_stats = [];
        foreach (StatsCliRunner::get_stats() as $sp_drive => $stat) {
            if ($sp_drive == 'Total') {
                continue;
            }
            $sp_stats[] = ['drive' => $sp_drive, 'trash_size' => $stat->trash_size, 'trash_size_human' => bytes_to_human($stat->trash_size*1024, TRUE, TRUE)];
        }
        echo json_encode(['result' => 'success', 'sp_stats' => $sp_stats]);
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
