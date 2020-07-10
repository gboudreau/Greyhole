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

require_once('includes/Tasks/BalanceTask.php');
require_once('includes/Tasks/FsckFileTask.php');
require_once('includes/Tasks/FsckTask.php');
require_once('includes/Tasks/Md5Task.php');
require_once('includes/Tasks/MkdirTask.php');
require_once('includes/Tasks/RenameTask.php');
require_once('includes/Tasks/RemoveTask.php');
require_once('includes/Tasks/RmdirTask.php');
require_once('includes/Tasks/UnlinkTask.php');
require_once('includes/Tasks/WriteTask.php');
require_once('includes/Tasks/LinkTask.php');

abstract class AbstractTask {
    public $id;
    public $action;
    public $share;
    public $full_path;
    public $additional_info;
    public $complete;
    public $event_date;

    /**
     * @param stdClass|array $task
     *
     * @return self
     */
    public static function instantiate($task) {
        $task = to_object($task);
        $class_name = str_replace(' ', '', ucwords(str_replace('_', ' ', $task->action))) . 'Task';
        return new $class_name($task);
    }

    public function __construct($task) {
        $task = to_object($task);
        $this->id              = @$task->id;
        $this->action          = @$task->action;
        $this->share           = @$task->share;
        $this->full_path       = @$task->full_path;
        $this->additional_info = @$task->additional_info;
        $this->complete        = @$task->complete;
        $this->event_date      = @$task->event_date;
    }

    public function shouldBeFrozen() {
        if ($this->complete != 'thawed') {
            $frozen_directories = Config::get(CONFIG_FROZEN_DIRECTORY);
            foreach ($frozen_directories as $frozen_directory) {
                if (string_starts_with("$this->share/$this->full_path", $frozen_directory)) {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public function has_option($option) {
        return string_contains($this->additional_info, $option);
    }

    abstract function execute();

    protected static function _queue($action, $share, $full_path, $additional_info, $complete) {
        $query = "INSERT INTO tasks SET action = :action, share = :share, full_path = :full_path, complete = :complete, additional_info = :additional_info";
        $params = array(
            'action'          => $action,
            'share'           => $share,
            'full_path'       => $full_path,
            'additional_info' => $additional_info,
            'complete'        => $complete,
        );
        DB::insert($query, $params);
    }

    public function postpone() {
        $query = "INSERT INTO tasks (action, share, full_path, additional_info, complete) SELECT action, share, full_path, additional_info, complete FROM tasks WHERE id = :task_id";
        DB::insert($query, array('task_id' => $this->id));
    }

    public function should_ignore_file($share = NULL, $full_path = NULL) {
        if (empty($share)) {
            $share = $this->share;
        }
        if (empty($full_path)) {
            $full_path = $this->full_path;
        }

        list($path, $filename) = explode_full_path($full_path);

        foreach (Config::get(CONFIG_IGNORED_FILES) as $ignored_file_re) {
            if (preg_match(';^' . $ignored_file_re . '$;', $filename)) {
                Log::info("Ignoring task because it matches the following '" . CONFIG_IGNORED_FILES . "' pattern: $ignored_file_re");
                return TRUE;
            }
        }
        foreach (Config::get(CONFIG_IGNORED_FOLDERS) as $ignored_folder_re) {
            $p = clean_dir("$share/$path/");
            if (preg_match(';^' . $ignored_folder_re . '$;', $p)) {
                Log::info("Ignoring task because it matches the following '" . CONFIG_IGNORED_FOLDERS . "' pattern: $ignored_folder_re");
                return TRUE;
            }
        }

        return FALSE;
    }


    protected function is_file_locked($share, $full_path) {
        $locked_by = DBSpool::isFileLocked($share, $full_path);
        if ($locked_by !== FALSE) {
            Log::debug("  File $share/$full_path is locked by another process ($locked_by). Will wait until it's unlocked to work on any file in this share.");
            DBSpool::lockShare($share);
            return TRUE;
        }
        return FALSE;
    }

}

?>
