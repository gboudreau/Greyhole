<?php
/*
Copyright 2010 Guillaume Boudreau, Carlos Puchol (Amahi)

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

/*
   Small abstraction layer for supporting MySQL and SQLite based
   on a user choice. Specify

             db_engine = sqlite
             db_path = /var/cache/greyhole.sqlite

   in /etc/greyhole.conf to enable sqlite support, otherwise the
   standard Greyhole MySQL support will be used.

   Carlos Puchol, Amahi
   cpg+git@amahi.org
*/

if ($db_options->engine == 'sqlite') {
	function db_connect() {
		global $db_options;
		if (!file_exists($db_options->db_path)) {
			// create the db automatically if it does not exist
			system("sqlite3 $db_options->db_path < $db_options->schema");
		}
		$db_options->dbh = new PDO("sqlite:" . $db_options->db_path);
		return $db_options->dbh;
	}

	function db_query($query) {
		global $db_options;
		return $db_options->dbh->query($query);
	}

	function db_escape_string($string) {
		global $db_options;
		$escaped_string = $db_options->dbh->quote($string);
		return substr($escaped_string, 1, strlen($escaped_string)-2);
	}

	function db_fetch_object($result) {
		return $result->fetchObject();
	}

	function db_free_result($result) {
		return TRUE;
	}

	function db_insert_id() {
		global $db_options;
		return $db_options->dbh->lastInsertId();
	}

	function db_error() {
		global $db_options;
		$error = $db_options->dbh->errorInfo();
		return $error[2];
	}
} else {
	// MySQL
	function db_connect() {
		global $db_options;
		$connected = mysql_connect($db_options->host, $db_options->user, $db_options->pass);
		if ($connected) {
			$connected = mysql_select_db($db_options->name);
			if ($connected) {
				db_query("SET SESSION group_concat_max_len = 1048576");
				db_query("SET SESSION wait_timeout = 86400"); # Allow 24h fsck!
			}
		}
		return $connected;
	}
	
	function db_query($query) {
		return mysql_query($query);
	}

	function db_escape_string($string) {
		return mysql_real_escape_string($string);
	}

	function db_fetch_object($result) {
		return mysql_fetch_object($result);
	}

	function db_free_result($result) {
		return mysql_free_result($result);
	}

	function db_insert_id() {
		return mysql_insert_id();
	}

	function db_error() {
		return mysql_error();
	}
}

function db_migrate($attempt_repair = TRUE) {
	global $db_options, $db_use_mysql, $db_use_sqlite;
	// Migration #1 (complete = frozen|thawed)
	if (@$db_use_mysql) {
		$query = "DESCRIBE tasks";
		$result = db_query($query);
		if (!$result) {
			if ((mysql_errno() == 144 || mysql_errno() == 145) && $attempt_repair) {
				repair_tables();
				db_migrate(FALSE);
				return;
			} else {
				die("Can't describe tasks with query: $query - Error: " . db_error());
			}
		}
		while ($row = db_fetch_object($result)) {
			if ($row->Field == 'complete') {
				if ($row->Type == "enum('yes','no')") {
					// migrate
					db_query("ALTER TABLE tasks CHANGE complete complete ENUM('yes','no','frozen','thawed') NOT NULL");
					db_query("ALTER TABLE tasks_completed CHANGE complete complete ENUM('yes','no','frozen','thawed') NOT NULL");
				}
				break;
			}
		}
	} else if (@$db_use_sqlite) {
		$query = "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'tasks'";
		$result = db_query($query) or die("Can't describe tasks with query: $query - Error: " . db_error());
		while ($row = db_fetch_object($result)) {
			if (strpos($row->sql, 'complete BOOL NOT NULL') !== FALSE) {
				// migrate; not supported! @see http://sqlite.org/omitted.html
				gh_log(CRITICAL, "Your SQLite database is not up to date. Column tasks.complete needs to be a TINYTEXT. Please fix, then retry.");
			}
		}
	}
	// Migration #2 (complete = idle)
	if (@$db_use_mysql) {
		$query = "DESCRIBE tasks";
		$result = db_query($query) or die("Can't describe tasks with query: $query - Error: " . db_error());
		while ($row = db_fetch_object($result)) {
			if ($row->Field == 'complete') {
				if ($row->Type == "enum('yes','no','frozen','thawed')") {
					// migrate
					db_query("ALTER TABLE tasks CHANGE complete complete ENUM('yes','no','frozen','thawed','idle') NOT NULL");
					db_query("ALTER TABLE tasks_completed CHANGE complete complete ENUM('yes','no','frozen','thawed','idle') NOT NULL");
				}
				break;
			}
		}
	}
	// Migration #3 (larger settings.value: tinytext > text)
	if (@$db_use_mysql) {
		$query = "DESCRIBE settings";
		$result = db_query($query);
		if (!$result) {
			if ((mysql_errno() == 144 || mysql_errno() == 145) && $attempt_repair) {
				repair_tables();
				db_migrate(FALSE);
				return;
			} else {
				die("Can't describe settings with query: $query - Error: " . db_error());
			}
		}
		while ($row = db_fetch_object($result)) {
			if ($row->Field == 'value') {
				if ($row->Type == "tinytext") {
					// migrate
					db_query("ALTER TABLE settings CHANGE value value TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL");
				}
				break;
			}
		}
	}
	// Migration #4 (new index for find_next_task function, used by simplify_task, and also for execute_next_task function; also remove deprecated indexes)
	if (@$db_use_mysql) {
		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'find_next_task'";
		$result = db_query($query) or die("Can't show index with query: $query - Error: " . db_error());
		if (db_fetch_object($result) === FALSE) {
			// migrate
			db_query("ALTER TABLE tasks ADD INDEX find_next_task (complete, share(64), id)");
		}

		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'incomplete_open'";
		$result = db_query($query) or die("Can't show index with query: $query - Error: " . db_error());
		if (db_fetch_object($result)) {
			// migrate
			db_query("ALTER TABLE tasks DROP INDEX incomplete_open");
		}

		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'subsequent_writes'";
		$result = db_query($query) or die("Can't show index with query: $query - Error: " . db_error());
		if (db_fetch_object($result)) {
			// migrate
			db_query("ALTER TABLE tasks DROP INDEX subsequent_writes");
		}

		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'unneeded_unlinks'";
		$result = db_query($query) or die("Can't show index with query: $query - Error: " . db_error());
		if (db_fetch_object($result)) {
			// migrate
			db_query("ALTER TABLE tasks DROP INDEX unneeded_unlinks");
		}
	}

	// Migration #5 (fix find_next_task index)
	if (@$db_use_mysql) {
	    $query = "SHOW INDEX FROM tasks WHERE Key_name = 'find_next_task' and Column_name = 'share'";
		$result = db_query($query) or die("Can't show index with query: $query - Error: " . db_error());
		if (db_fetch_object($result) !== FALSE) {
			// migrate
			db_query("ALTER TABLE tasks DROP INDEX find_next_task ADD INDEX find_next_task (complete, id)");
		}
    }

	// Migration #6 (new indexes for md5_worker_thread/gh_check_md5 functions)
	if (@$db_use_mysql) {
		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'md5_worker'";
		$result = db_query($query) or die("Can't show index with query: $query - Error: " . db_error());
		if (db_fetch_object($result) === FALSE) {
			// migrate
			db_query("ALTER TABLE tasks ADD INDEX md5_worker (action, complete, additional_info(100), id)");
		}

		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'md5_checker'";
		$result = db_query($query) or die("Can't show index with query: $query - Error: " . db_error());
		if (db_fetch_object($result) === FALSE) {
			// migrate
			db_query("ALTER TABLE tasks ADD INDEX md5_checker (action, share(64), full_path(255), complete)");
		}
		
		$query = "DESCRIBE tasks";
		$result = db_query($query) or die("Can't describe tasks with query: $query - Error: " . db_error());
		while ($row = db_fetch_object($result)) {
			if ($row->Field == 'additional_info') {
				if ($row->Type == "tinytext") {
					// migrate
					db_query("ALTER TABLE tasks CHANGE additional_info additional_info TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL");
				}
				break;
			}
		}
	}

	// Migration #7 (full_path new size: 4096)
	if (@$db_use_mysql) {
		$query = "DESCRIBE tasks";
		$result = db_query($query) or die("Can't describe tasks with query: $query - Error: " . db_error());
		while ($row = db_fetch_object($result)) {
			if ($row->Field == 'full_path') {
				if ($row->Type == "tinytext") {
					// migrate
					db_query("ALTER TABLE tasks CHANGE full_path full_path TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL");
					db_query("ALTER TABLE tasks_completed CHANGE full_path full_path TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL");
				}
				break;
			}
		}
	}
}

function repair_tables() {
	global $db_use_mysql, $action;
	if (@$db_use_mysql) {
		if ($action == 'daemon') {
			gh_log(INFO, "Optimizing MySQL tables...");
		}
		db_query("REPAIR TABLE tasks") or gh_log(CRITICAL, "Can't repair tasks table: " . db_error());
		db_query("REPAIR TABLE settings") or gh_log(CRITICAL, "Can't repair settings table: " . db_error());
		// Let's repair tasks_completed only if it's broken!
		$result = db_query("SELECT * FROM tasks_completed LIMIT 1");
		if ($result === FALSE) {
			gh_log(INFO, "Repairing MySQL tables...");
			db_query("REPAIR TABLE tasks_completed") or gh_log(CRITICAL, "Can't repair tasks_completed table: " . db_error());
		}
	}
}
?>
