<?php
/*
Copyright 2009-2020 Guillaume Boudreau

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

require_once('includes/Tasks/AbstractTask.php');

final class DBSpool {

    /** @var self */
    private static $_instance;

    /** @var bool */
    private $use_old_vfs = FALSE;
    /** @var self|null */
    private $current_task = NULL;
    /** @var array */
    private $locked_shares = array();
    /** @var array */
    private $sleep_before_task = array();
    /** @var array */
    private $next_tasks = array();
    /** @var array */
    private $locked_files = array();
    /** @var array */
    private $written_files = array();

    /**
     * @return self
     */
    public static function getInstance() {
        if (empty(static::$_instance)) {
            static::$_instance = new self();
        }
        return static::$_instance;
    }

    public function __construct() {
        $arch = exec('uname -m');
        if (stripos($arch, 'armv5') !== FALSE) {
            // See explanation in close_task() about armv5 VFS modules
            $this->use_old_vfs = TRUE;
        }
    }

    /**
     * @param bool $incl_md5        Include or not MD5 tasks?
     * @param bool $update_idle     If no tasks are found, return 'complete=idle' tasks, if any.
     * @param bool $include_written Include tasks with status = 'written', in addition to yes/thawed statuses
     *
     * @return stdClass[]
     * @throws Exception
     */
    public function fetch_next_tasks($incl_md5, $update_idle, $include_written = TRUE) {
        $where_clause = "";
        if (!empty($this->locked_shares)) {
            $where_clause .= " AND share NOT IN ('" . implode("','", array_keys($this->locked_shares)) . "')";
        }
        if (!$incl_md5) {
            $where_clause .= " AND action != 'md5'";
        }

        if ($include_written) {
            $statuses = "'yes', 'thawed', 'written'";
        } else {
            $statuses = "'yes', 'thawed'";
        }
        $query = "SELECT id, action, share, full_path, additional_info, complete FROM tasks WHERE complete IN ($statuses) $where_clause ORDER BY id ASC LIMIT 20";
        $tasks = DB::getAll($query);

        if (empty($tasks) && $update_idle) {
            // No more complete = yes|thawed; let's look for complete = 'idle' tasks.
            $query = "UPDATE tasks SET complete = 'yes' WHERE complete = 'idle'";
            DB::execute($query);
            $tasks = $this->fetch_next_tasks($incl_md5, FALSE);
        }
        return $tasks;
    }

    /**
     * Get the currently active task.
     *
     * @return DBSpool
     */
    public static function getCurrentTask() {
        return static::getInstance()->current_task;
    }

    /**
     * Is the currently active task a retry?
     *
     * @return bool
     */
    public static function isCurrentTaskRetry() {
        $current_task = static::getCurrentTask();
        /** @var $current_task stdClass */
        return !empty($current_task) && $current_task->id === 0;
    }

    public static function lockShare($share) {
        static::getInstance()->locked_shares[$share] = TRUE;
    }

    public static function resetSleepingTasks() {
        static::getInstance()->sleep_before_task = array();
    }

    public static function setNextTask($task) {
        array_unshift(static::getInstance()->next_tasks, $task);
    }

    private function lockFile($idx, $locked_by) {
        $this->locked_files[$idx] = $locked_by;
    }

    public static function isFileLocked($share, $full_path) {
        $db_spool = static::getInstance();
        $idx = clean_dir("$share/$full_path");
        if (isset($db_spool->locked_files[$idx])) {
            return $db_spool->locked_files[$idx];
        }

        if (Config::get(CONFIG_CHECK_FOR_OPEN_FILES) === FALSE) {
            Log::debug("  Skipping open file (lock) check.");
            return FALSE;
        }

        $landing_zone = get_share_landing_zone($share);
        if (!$landing_zone) {
            return FALSE;
        }

        $real_fullpath = "$landing_zone/$full_path";

        $result = gh_is_file_locked($real_fullpath);
        if ($result !== FALSE) {
            $db_spool->lockFile($idx, $result);
            return $result;
        }

        $query = "SELECT * FROM tasks WHERE complete = 'no' AND action = 'write' AND share = :share AND full_path = :full_path LIMIT 1";
        $params = array('share' => $share, 'full_path' => $full_path);
        $row = DB::getFirst($query, $params);
        if ($row === FALSE) {
            return FALSE;
        }

        // Locked, according to DB... But maybe it's not really locked?
        if (!gh_file_exists($real_fullpath)) {
            // File doesn't exists anymore... It can't be really locked... Let's assume this is just Samba that 'forgot' to close the file handle.
            $query = "UPDATE tasks SET complete = 'yes' WHERE complete = 'no' AND action = 'write' AND share = :share AND full_path = :full_path";
            DB::execute($query, $params);
            return FALSE;
        }

        $result = 'samba-vfs-writer';
        $db_spool->lockFile($idx, $result);
        return $result;
    }

    public function execute_next_task() {
        if (!empty($this->next_tasks)) {
            $task = array_shift($this->next_tasks);
        } else {
            $this->next_tasks = $this->fetch_next_tasks(TRUE, TRUE);
            if (!empty($this->next_tasks)) {
                $task = array_shift($this->next_tasks);
            } else {
                Log::setAction(ACTION_SLEEP);
                DB::repairTables();

                Md5Task::check_md5_workers();

                Log::cleanStatusTable();

                // Email any unsent fsck reports found in /usr/share/greyhole/
                foreach (array('fsck_checksums.log', 'fsck_files.log') as $log_file) {
                    $log = new FSCKLogFile($log_file);
                    $log->emailAsRequired();
                }

                $log = "Nothing to do... Sleeping.";
                Log::debug($log);
                if (!DaemonRunner::$was_idle) {
                    LogHook::trigger(LogHook::EVENT_TYPE_IDLE, Log::EVENT_CODE_IDLE, $log);
                    DaemonRunner::$was_idle = TRUE;
                }

                $log_level = Log::getLevel();
                sleep($log_level == Log::DEBUG ? 10 : ($log_level == Log::TEST || $log_level == Log::PERF ? 1 : 600));
                $this->locked_files = array();
                $this->locked_shares = array();

                return;
            }
        }

        $task = AbstractTask::instantiate($task);
        $this->current_task = $task;

        if (array_contains($this->sleep_before_task, $task->id)) {
            Log::setAction(ACTION_SLEEP);
            $log = "Only locked files operations pending... Sleeping.";
            Log::debug($log);
            if (!DaemonRunner::$was_idle) {
                LogHook::trigger(LogHook::EVENT_TYPE_IDLE, Log::EVENT_CODE_IDLE, $log);
                DaemonRunner::$was_idle = TRUE;
            }
            $log_level = Log::getLevel();
            sleep($log_level == Log::DEBUG ? 10 : ($log_level == Log::TEST ? 1 : 600));
            $this->locked_files = array();
            $this->sleep_before_task = array();
        }

        Log::setAction($task->action);
        $log = "Now working on task ID $task->id: $task->action " . clean_dir("$task->share/$task->full_path") . ($task->action == 'rename' ? " -> $task->share/$task->additional_info" : '');
        Log::info($log);

        if ($task->complete == 'written') {
            if ($task->should_ignore_file()) {
                static::archive_task($task->id);
                return;
            }

            // Check if it's been 10 minutes since the file size changed. If so, process this normally.
            $filename = get_share_landing_zone($task->share) . '/' . $task->full_path;
            $filesize = gh_filesize($filename);
            if (empty($this->written_files[clean_dir("$task->share/$task->full_path")])) {
                $this->written_files[clean_dir("$task->share/$task->full_path")] = (object) array('since' => time(), 'filesize' => $filesize);
            } else {
                $infos = $this->written_files[clean_dir("$task->share/$task->full_path")];
                if ($infos->filesize == $filesize) {
                    if (time() - $infos->since > 10*60) {
                        Log::debug("  File is still being written to (" . bytes_to_human($filesize, FALSE) . "). But it's been at least 10 minutes since the file size changed. We can probably assume we should work on this file now. Let do this!");
                        unset($this->written_files[clean_dir("$task->share/$task->full_path")]);
                    }
                } else {
                    $this->written_files[clean_dir("$task->share/$task->full_path")] = (object) array('since' => time(), 'filesize' => $filesize);
                }
            }

            if (!empty($this->written_files[clean_dir("$task->share/$task->full_path")])) {
                Log::debug("  File is still being written to (" . bytes_to_human($filesize, FALSE) . "). Postponing.");
                $this->lockFile(clean_dir("$task->share/$task->full_path"), 'samba-bytes-writer');
                $this->locked_shares[$task->share] = TRUE;
                return;
            }
        }

        if (!empty($this->locked_shares) && array_contains(array_keys($this->locked_shares), $task->share)) {
            Log::info("  Share is locked because another file operation is waiting for a file handle to be released. Skipping.");
            return;
        }

        # Postpone tasks in frozen directories until a --thaw command is received
        if ($task->shouldBeFrozen()) {
            Log::debug("  This directory is frozen. Will postpone this task until it is thawed.");
            $this->postpone_task($task->id, 'frozen');
            static::archive_task($task->id);
            return;
        }

        if (DaemonRunner::$was_idle) {
            LogHook::trigger(LogHook::EVENT_TYPE_NOT_IDLE, Log::EVENT_CODE_IDLE_NOT, $log);
            DaemonRunner::$was_idle = FALSE;
        }

        $result = $task->execute();
        if (!$result) {
            return;
        }

        if ($task->action != 'write' && $task->action != 'rename') {
            $this->sleep_before_task = array();
        }

        static::archive_task($task->id);
    }

    public function insert($action, $share, $full_path, $additional_info, $fd) {
        $query = "INSERT INTO tasks SET action = :action, share = :share, full_path = :full_path, additional_info = :additional_info, complete = :complete";
        $full_path = isset($full_path) ? clean_dir($full_path) : NULL;
        $additional_info = !empty($additional_info) ? clean_dir($additional_info) : (!empty($fd) ? $fd : NULL);
        $params = array(
            'action' => $action,
            'share' => $share,
            'full_path' => $full_path,
            'additional_info' => $additional_info,
            'complete' => ( $action == 'write' ? 'no' : 'yes' ),
        );
        try {
            $id = DB::insert($query, $params);
        } catch (Exception $ex) {
            if ($ex->getCode() == 1366) {
                // Incorrect string value: '\x##\x##\x##'
                Log::warn("File '$full_path' contains non UTF-8 character. Skipping.", Log::EVENT_CODE_FILE_INVALID);
                return FALSE;
            }
        }
        /** @noinspection PhpUndefinedVariableInspection */
        return $id;
    }

    public function close_task($act, $share, $fd, $fullpath, &$tasks) {
        if (!empty($fullpath)) {
            $prop = 'full_path';
            $prop_value = $fullpath;
        } else {
            $prop = 'additional_info';
            $prop_value = $fd;
        }
        if ($act === 'fwrite') {
            if (!empty($fullpath)) {
                $q = "SELECT * FROM tasks WHERE action = 'write' AND complete IN ('written', 'no') AND share = :share AND $prop = :$prop";
                try {
                    $opened_task = DB::getFirst($q, array('share' => $share, $prop => $prop_value));
                } catch (Exception $ex) {
                    if ($ex->getCode() == 1267) {
                        // Illegal mix of collations
                        Log::warn("File '$prop_value' contains non UTF-8 character. Skipping.", Log::EVENT_CODE_FILE_INVALID);
                        return;
                    }
                }
                if (empty($opened_task)) {
                    // Writing to a file that wasn't opened-for-writing... Log this as a write, complete=yes task
                    $id = $this->insert('write', $share, $fullpath, NULL, $fd);
                    $q = "UPDATE tasks SET complete = 'yes' WHERE id = :id";
                    DB::execute($q, array('id' => $id));
                    $q = "SELECT * FROM tasks WHERE id = :id";
                    $opened_task = DB::getFirst($q, array('id' => $id));
                }
            }
            if (empty($fullpath) || $opened_task->complete == 'no') {
                $query = "UPDATE tasks SET complete = 'written' WHERE action = 'write' AND complete = 'no' AND share = :share AND $prop = :$prop";
                DB::execute($query, array('share' => $share, $prop => $prop_value));
            }
        }
        if ($act === 'close') {
            if ($this->use_old_vfs) {
                // armv5 VFS have not been recompiled to create fwrite spooled files; so for those, we process close tasks like we did before.
                $query = "UPDATE tasks SET additional_info = NULL, complete = 'yes' WHERE complete = 'no' AND share = :share AND $prop = :$prop";
                DB::execute($query, array('share' => $share, $prop => $prop_value));
            } else {
                // We will only close tasks at the very end, to make sure all fwrite tasks have been handled.
                // We need to do this because some fwrite spool file might apply to multiple write (open) tasks.
                // For example: writing into two files in the same share within the same second. See Greyhole VFS implementation for writes to see why.
                $last_id = DB::getFirstValue("SELECT MAX(id) FROM tasks");
                if ($last_id) {
                    $task = (object) array(
                        'share' => $share,
                        'fd' => $fd,
                        'full_path' => $fullpath,
                        'last_id' => $last_id,
                    );
                    $tasks[] = $task;
                }
            }
        }
    }

    public function close_all_tasks($tasks) {
        foreach ($tasks as $task) {
            $share = $task->share;
            $fd = $task->fd;
            $full_path = $task->full_path;
            $last_id = $task->last_id;

            // We only want to handle real writes (complete = 'written'); if complete = 'no', that means the file was open for writing, but wasn't written to; we'll ignore those.

            $params = array('share' => $share, 'last_id' => $last_id);
            if (!empty($full_path)) {
                $prop = 'full_path';
                $params[$prop] = $full_path;
            } else {
                $prop = 'additional_info';
                $params[$prop] = $fd;
            }

            $query = "UPDATE tasks SET additional_info = NULL, complete = 'yes' WHERE complete = 'written' AND share = :share AND $prop = :$prop AND id <= :last_id";
            DB::execute($query, $params);

            // Remove write tasks that were not written to. But log them first.
            $query = "SELECT id, full_path FROM tasks WHERE complete = 'no' AND share = :share AND $prop = :$prop AND id <= :last_id";
            $rows = DB::getAll($query, $params);
            foreach ($rows as $row) {
                // Maybe the file is empty?
                $file_fullpath = get_share_landing_zone($share) . '/' . $row->full_path;
                $size = gh_filesize($file_fullpath);
                if ($size == 0) {
                    $query = "UPDATE tasks SET additional_info = NULL, complete = 'yes' WHERE id = :task_id";
                    DB::execute($query, array('task_id' => $row->id));
                } else {
                    // Ignore
                    Log::debug("File pointer to $share/$row->full_path was closed without being written to. Ignoring.");
                }
            }

            $query = "DELETE FROM tasks WHERE complete = 'no' AND share = :share AND $prop = :$prop AND id <= :last_id";
            DB::execute($query, $params);
        }
    }

    public static function archive_task($task_id) {
        $query = "INSERT INTO tasks_completed SELECT * FROM tasks WHERE id = :task_id";
        $worked = DB::insert($query, array('task_id' => $task_id));
        if (!$worked) {
            // Let's try a second time... This is kinda important!
            DB::connect();
            DB::insert($query, array('task_id' => $task_id));
        }

        $query = "DELETE FROM tasks WHERE id = :task_id";
        DB::execute($query, array('task_id' => $task_id));
    }

    public function postpone_task($task_id, $complete='yes') {
        $query = "INSERT INTO tasks (action, share, full_path, additional_info, complete) SELECT action, share, full_path, additional_info, :complete FROM tasks WHERE id = :task_id";
        $params = array(
            'complete' => $complete,
            'task_id' => $task_id
        );
        DB::insert($query, $params);
        $this->sleep_before_task[] = DB::lastInsertedId();
    }

    public function delete_tasks($task_ids) {
        if (empty($task_ids)) {
            return;
        }
        if (is_string($task_ids)) {
            $task_ids = explode(',', $task_ids);
        }
        if (is_array($task_ids)) {
            foreach ($this->next_tasks as $k => $task) {
                if (array_contains($task_ids, $task->id)) {
                    unset($this->next_tasks[$k]);
                }
            }
            $this->next_tasks = array_values($this->next_tasks);

            $task_ids = implode(',', $task_ids);
        }
        DB::execute("DELETE FROM tasks WHERE id IN ($task_ids)");
    }

    public function find_next_rename_task($share, $full_path, $task_id) {
        $full_paths = [$full_path];
        $parent_full_path = $full_path;
        list($parent_full_path, ) = explode_full_path($parent_full_path);
        while (strlen($parent_full_path) > 1) {
            $full_paths[] = $parent_full_path;
            list($parent_full_path, ) = explode_full_path($parent_full_path);
        }
        $params = ['share' => $share, 'task_id' => $task_id];
        $param_names = [];
        foreach ($full_paths as $i => $full_path) {
            $param_name = sprintf("fp_%03d", $i);
            $param_names[] = ":$param_name";
            $params[$param_name] = $full_path;
        }
        $query = "SELECT * FROM tasks WHERE complete = 'yes' AND share = :share AND action = 'rename' AND full_path IN (" . implode(", ", $param_names) . ") AND id > :task_id ORDER BY id LIMIT 1";
        return DB::getFirst($query, $params);
    }

    /**
     * Counts the number of tasks currently in the DB spool.
     *
     * @param string|null $action If specified, count only the tasks for this action.
     *
     * @return int Number of tasks in the DB spool.
     */
    public static function get_num_tasks($action = NULL) {
        $query = "SELECT COUNT(*) FROM tasks";
        $params = [];
        if (!empty($action)) {
            $query .= " WHERE action = :action";
            $params['action'] = $action;
        }
        return (int) DB::getFirstValue($query, $params);
    }

}

?>
