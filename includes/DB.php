<?php
/*
Copyright 2014 Guillaume Boudreau

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

// Usage: $DB = new DatabaseHelper($options);

class DB {

	protected static $options; // connection options
	protected static $handle; // database handle

	public static function setOptions($options) {
        if (is_array($options)) {
            $options = (object) $options;
        }
		static::$options = $options;
	}

	public static function connect() {
        $connect_string = 'mysql:host=' . static::$options->host . ';dbname=' . static::$options->name;

        try {
            static::$handle = @new PDO($connect_string, static::$options->user, static::$options->pass, array(PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        } catch (PDOException $ex) {
            echo "ERROR: Can't connect to database: " . $ex->getMessage() . "\n";
            gh_log(CRITICAL, "Can't connect to database: " . $ex->getMessage());
        }

        if (static::$handle) {
            DB::execute("SET SESSION group_concat_max_len = 1048576");
            DB::execute("SET SESSION wait_timeout = 86400"); # Allow 24h fsck!
            DB::migrate();
        }

        return static::$handle;
    }

    public static function execute($q, $args = array(), $attempt_repair=TRUE) {
        $stmt = static::$handle->prepare($q);
        foreach ($args as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        try {
            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            $error = static::$handle->errorInfo();
            if (($error[1] == 144 || $error[1] == 145) && $attempt_repair) {
                gh_log(INFO, "Error during MySQL query: " . $e->getMessage() . '. Will now try to repair the MySQL tables.');
                DB::repairTables();
                return DB::execute($q, $args, FALSE); // $attempt_repair = FALSE, to not go into an infinite loop, if the repair doesn't work.
            }
            throw new Exception($e->getMessage(), $error[1]);
        }
    }

    public static function insert($q, $args = array()) {
        if (DB::execute($q, $args) === FALSE) {
            return FALSE;
        }
        return DB::lastInsertedId();
    }

    public static function getFirst($q, $args = array()) {
        $stmt = DB::execute($q, $args);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result === FALSE) {
            return FALSE;
        }
        return (object) $result;
    }

    public static function getFirstValue($q, $args = array()) {
        $stmt = DB::execute($q, $args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return FALSE;
        }
        return array_shift($row);
    }

    public static function getAll($q, $args = array(), $index_field=NULL) {
        $stmt = DB::execute($q, $args);
        $rows = array();
        $i = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $index = $i++;
            if (!empty($index_field)) {
                $index = $row[$index_field];
            }
            $rows[$index] = (object) $row;
        }
        return $rows;
    }

    public static function getAllValues($q, $args = array(), $data_type=null) {
        $stmt = DB::execute($q, $args);
        $values = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                return FALSE;
            }

            $value = array_shift($row);
            if (!empty($data_type)) {
                settype($value, $data_type);
            }
            $values[] = $value;
        }
        return $values;
    }

    public static function lastInsertedId() {
        $q = "SELECT LAST_INSERT_ID()";
        $lastInsertedId = (int) DB::getFirstValue($q);
        if ($lastInsertedId === 0) {
            return TRUE;
        }
        return $lastInsertedId;
    }

    public static function quote($string) {
        $escaped_string = static::$handle->quote($string);
        return substr($escaped_string, 1, -1);
    }

    public static function error() {
        return static::$options->error;
    }

	private static function migrate() {
        // Migration #1 (complete = frozen|thawed)
        {
            $query = "DESCRIBE tasks";
            $rows = DB::getAll($query);
            foreach ($rows as $row) {
                if ($row->Field == 'complete') {
                    if ($row->Type == "enum('yes','no')") {
                        // migrate
                        DB::execute("ALTER TABLE tasks CHANGE complete complete ENUM('yes','no','frozen','thawed') NOT NULL");
                        DB::execute("ALTER TABLE tasks_completed CHANGE complete complete ENUM('yes','no','frozen','thawed') NOT NULL");
                    }
                    break;
                }
            }
        }

        // Migration #2 (complete = idle)
        {
            $query = "DESCRIBE tasks";
            $rows = DB::getAll($query);
            foreach ($rows as $row) {
                if ($row->Field == 'complete') {
                    if ($row->Type == "enum('yes','no','frozen','thawed')") {
                        // migrate
                        DB::execute("ALTER TABLE tasks CHANGE complete complete ENUM('yes','no','frozen','thawed','idle') NOT NULL");
                        DB::execute("ALTER TABLE tasks_completed CHANGE complete complete ENUM('yes','no','frozen','thawed','idle') NOT NULL");
                    }
                    break;
                }
            }
        }

        // Migration #3 (larger settings.value: tinytext > text)
        {
            $query = "DESCRIBE settings";
            $rows = DB::getAll($query);
            foreach ($rows as $row) {
                if ($row->Field == 'value') {
                    if ($row->Type == "tinytext") {
                        // migrate
                        DB::execute("ALTER TABLE settings CHANGE value value TEXT CHARACTER SET utf8 NOT NULL");
                    }
                    break;
                }
            }
        }

        // Migration #4 (new index for find_next_task function, used by simplify_task, and also for execute_next_task function; also remove deprecated indexes)
        {
            $query = "SHOW INDEX FROM tasks WHERE Key_name = 'find_next_task'";
            $row = DB::getFirst($query);
            if ($row === FALSE) {
                // migrate
                DB::execute("ALTER TABLE tasks ADD INDEX find_next_task (complete, share(64), id)");
            }

            $query = "SHOW INDEX FROM tasks WHERE Key_name = 'incomplete_open'";
            $row = DB::getFirst($query);
            if ($row) {
                // migrate
                DB::execute("ALTER TABLE tasks DROP INDEX incomplete_open");
            }

            $query = "SHOW INDEX FROM tasks WHERE Key_name = 'subsequent_writes'";
            $row = DB::getFirst($query);
            if ($row) {
                // migrate
                DB::execute("ALTER TABLE tasks DROP INDEX subsequent_writes");
            }

            $query = "SHOW INDEX FROM tasks WHERE Key_name = 'unneeded_unlinks'";
            $row = DB::getFirst($query);
            if ($row) {
                // migrate
                DB::execute("ALTER TABLE tasks DROP INDEX unneeded_unlinks");
            }
        }

        // Migration #5 (fix find_next_task index)
        {
            $query = "SHOW INDEX FROM tasks WHERE Key_name = 'find_next_task' and Column_name = 'share'";
            $row = DB::getFirst($query);
            if ($row !== FALSE) {
                // migrate
                DB::execute("ALTER TABLE tasks DROP INDEX find_next_task");
                DB::execute("ALTER TABLE tasks ADD INDEX find_next_task (complete, id)");
            }
        }

        // Migration #6 (new indexes for md5_worker_thread/gh_check_md5 functions)
        {
            $query = "SHOW INDEX FROM tasks WHERE Key_name = 'md5_worker'";
            $row = DB::getFirst($query);
            if ($row === FALSE) {
                // migrate
                DB::execute("ALTER TABLE tasks ADD INDEX md5_worker (action, complete, additional_info(100), id)");
            }

            $query = "SHOW INDEX FROM tasks WHERE Key_name = 'md5_checker'";
            $row = DB::getFirst($query);
            if ($row === FALSE) {
                // migrate
                DB::execute("ALTER TABLE tasks ADD INDEX md5_checker (action, share(64), full_path(265), complete)");
            }

            $query = "DESCRIBE tasks";
            $rows = DB::getAll($query);
            foreach ($rows as $row) {
                if ($row->Field == 'additional_info') {
                    if ($row->Type == "tinytext") {
                        // migrate
                        DB::execute("ALTER TABLE tasks CHANGE additional_info additional_info TEXT CHARACTER SET utf8 NULL");
                    }
                    break;
                }
            }
        }

        // Migration #7 (full_path new size: 4096)
        {
            $query = "DESCRIBE tasks";
            $rows = DB::getAll($query);
            foreach ($rows as $row) {
                if ($row->Field == 'full_path') {
                    if ($row->Type == "tinytext") {
                        // migrate
                        DB::execute("ALTER TABLE tasks CHANGE full_path full_path TEXT CHARACTER SET utf8 NULL");
                        DB::execute("ALTER TABLE tasks_completed CHANGE full_path full_path TEXT CHARACTER SET utf8 NULL");
                    }
                    break;
                }
            }
        }

        // Migration #8 (new du_stats table)
        {
            $query = "CREATE TABLE IF NOT EXISTS `du_stats` (`share` TINYTEXT NOT NULL, `full_path` TEXT NOT NULL, `depth` TINYINT(3) UNSIGNED NOT NULL, `size` BIGINT(20) UNSIGNED NOT NULL, UNIQUE KEY `uniqness` (`share`(64),`full_path`(269))) ENGINE = MYISAM DEFAULT CHARSET=utf8";
            DB::execute($query);
            $query = "SHOW INDEX FROM `du_stats` WHERE Key_name = 'uniqness'";
            $row = DB::getFirst($query);
            if ($row === FALSE) {
                // migrate
                DB::execute("ALTER TABLE `du_stats` ADD UNIQUE `uniqness` (`share` (64), `full_path` (269))");
            }
        }

        // Migration #9 (complete = written)
        {
            $query = "DESCRIBE tasks";
            $rows = DB::getAll($query);
            foreach ($rows as $row) {
                if ($row->Field == 'complete') {
                    if ($row->Type == "enum('yes','no','frozen','thawed','idle')") {
                        // migrate
                        DB::execute("ALTER TABLE tasks CHANGE complete complete ENUM('yes','no','frozen','thawed','idle','written') NOT NULL");
                        DB::execute("ALTER TABLE tasks_completed CHANGE complete complete ENUM('yes','no','frozen','thawed','idle','written') NOT NULL");
                    }
                    break;
                }
            }
        }
    }

    public static function repairTables() {
        global $action;
        if ($action == 'daemon') {
            gh_log(INFO, "Optimizing MySQL tables...");
        }
        // Let's repair tables only if they need to!
        foreach (array('tasks', 'settings', 'du_stats', 'tasks_completed') as $table_name) {
            try {
                DB::getFirst("SELECT * FROM $table_name LIMIT 1");
            } catch (Exception $e) {
                gh_log(INFO, "Repairing $table_name MySQL table...");
                DB::execute("REPAIR TABLE $table_name", array(), FALSE);
            }
        }
    }

    public static function deleteExecutedTasks() {
        $executed_tasks_retention = Config::get(CONFIG_EXECUTED_TASKS_RETENTION);
        if ($executed_tasks_retention == 'forever') {
            return;
        }
        if (!is_int($executed_tasks_retention)) {
            gh_log(CRITICAL, "Error: Invalid value for 'executed_tasks_retention' in greyhole.conf: '$executed_tasks_retention'. You need to use either 'forever' (no quotes), or a number of days.");
        }
        gh_log(INFO, "Cleaning executed tasks: keeping the last $executed_tasks_retention days of logs.");
        $query = sprintf("DELETE FROM tasks_completed WHERE event_date < NOW() - INTERVAL %d DAY", (int) $executed_tasks_retention);
        DB::execute($query);
    }
}
?>
