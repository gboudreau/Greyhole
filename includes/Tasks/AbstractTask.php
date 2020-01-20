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
require_once('includes/Tasks/RmdirTask.php');
require_once('includes/Tasks/UnlinkTask.php');
require_once('includes/Tasks/WriteTask.php');

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

    protected function has_option($option) {
        return string_contains($this->additional_info, $option);
    }

    abstract function execute();

}

?>