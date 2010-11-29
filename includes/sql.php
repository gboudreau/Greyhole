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

function db_connect() {
	global $db_options;
	if ($db_options->engine == 'sqlite') {
		if (!file_exists($db_options->db_path)) {
			// create the db automatically if it does not exist
			system("sqlite3 $db_options->db_path < $db_options->schema");
		}
		$db_options->dbh = new PDO("sqlite:" . $db_options->db_path);
		return $db_options->dbh;
	} else {
		return mysql_connect($db_options->host, $db_options->user, $db_options->pass);
	}
}

function db_select_db() {
	global $db_options;
	if ($db_options->engine == 'sqlite') {
		// do nothing - sqlite does not need to select a db; there's only one db per file
	} else {
		mysql_select_db($db_options->name);
	}
}

function db_query($query) {
	global $db_options;
	if ($db_options->engine == 'sqlite') {
		return $db_options->dbh->query($query);
	} else {
		return mysql_query($query);
	}
}

function db_escape_string($string) {
	global $db_options;
	if ($db_options->engine == 'sqlite') {
		$escaped_string = $db_options->dbh->quote($string);
		return substr($escaped_string, 1, strlen($escaped_string)-2);
	} else {
		return mysql_real_escape_string($string);
	}
}

function db_fetch_object($result) {
	global $db_options;
	if ($db_options->engine == 'sqlite') {
		return $result->fetchObject();
	} else {
		return mysql_fetch_object($result);
	}
}

function db_free_result($result) {
	global $db_options;
	if ($db_options->engine == 'sqlite') {
		return TRUE;
	} else {
		return mysql_free_result($result);
	}
}

function db_insert_id() {
	global $db_options;
	if ($db_options->engine == 'sqlite') {
		return $db_options->dbh->lastInsertId();
	} else {
		return mysql_insert_id();
	}
}

function db_error() {
	global $db_options;
	if ($db_options->engine == 'sqlite') {
		$error = $db_options->dbh->errorInfo();
		return $error[2];
	} else {
		return mysql_error();
	}
}

function db_migrate() {
	global $db_options, $db_use_mysql, $db_use_sqlite;
	// Migration #1 (complete = frozen|thawed)
	if (@$db_use_mysql) {
		$query = "DESCRIBE tasks";
		$result = db_query($query) or die("Can't describe tasks with query: $query - Error: " . db_error());
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
}
?>
