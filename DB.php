<?php

class DB {
	private static $handle;

	public static function connect() {
        try {
            // Example connect string: 'mysql:host=localhost;port=9005;dbname=test', 'sqlite:/tmp/foo.db'
            $connect_string = Config::DB_ENGINE . ':';
            if (defined('Config::DB_FILE')) {
                $connect_string .= Config::DB_FILE;
            } else if (defined('Config::DB_HOST')) {
                $connect_string .= 'host=' . Config::DB_HOST;
                if (defined('Config::DB_PORT')) {
                    $connect_string .= ';port=' . Config::DB_PORT;
                }
                if (defined('Config::DB_NAME')) {
                    $connect_string .= ';dbname=' . Config::DB_NAME;
                }
                $connect_string .= ';charset=utf8';
            }
            if (defined('Config::DB_PWD')) {
                static::$handle = new PDO($connect_string, Config::DB_USER, Config::DB_PWD, array(PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            } else {
                static::$handle = new PDO($connect_string, null, null, array(PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            }
        } catch (PDOException $e) {
            throw new Exception("Can't connect to the database. Please try again later. Error: " . $e->getMessage());
        }
	}

	public static function execute($q, $args = array()) {
        $stmt = static::$handle->prepare($q);
		foreach ($args as $key => $value) {
            $stmt->bindValue(":$key", $value);
		}
		if (DEBUGSQL) echo "$q " . preg_replace("/\n/", '', var_export($args, TRUE)) . "<br/>\n";
        try {
            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("Can't execute query: $q; error: " . $e->getMessage(), (int) $stmt->errorCode());
        }
	}

	public static function insert($q, $args = array()) {
		DB::execute($q, $args);
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

	public static function getAll($q, $args = array()) {
		$stmt = DB::execute($q, $args);
		$rows = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$rows[] = (object) $row;
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
        if (Config::DB_ENGINE == 'mysql') {
            $q = "SELECT LAST_INSERT_ID()";
            return DB::getFirstValue($q);
        }
        return TRUE;
	}

    public static function getDomainObjects($table_name, $order_by=null) {
        $q = "SELECT * FROM $table_name";
        if (!empty($order_by)) {
            $q .= " ORDER BY $order_by";
        }
        return DB::getAll($q);
    }

    const GROUP_CONCAT_SEP = 'Ω'; // Something that won't appear in the strings I GROUP_CONCAT(), to be able to explode them.

    public static function groupConcatQuery($field, $as_what) {
        $q = "GROUP_CONCAT(DISTINCT $field ORDER BY $field ASC SEPARATOR '" . DB::GROUP_CONCAT_SEP . "') AS `$as_what`";
        return $q;
    }

    public static function groupConcatParse(&$list) {
        if ($list != null) {
            $list = explode(DB::GROUP_CONCAT_SEP, $list);
        } else {
            $list = array();
        }
    }
}
