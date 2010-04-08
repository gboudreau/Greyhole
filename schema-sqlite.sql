CREATE TABLE settings ( name CHAR(255) PRIMARY KEY, value CHAR(255));

INSERT INTO settings (name, value) VALUES ('last_read_log_smbd_line', '0');
INSERT INTO settings (name, value) VALUES ('last_OOS_notification', '0');

CREATE TABLE tasks (
id INTEGER PRIMARY KEY AUTOINCREMENT,
action VARCHAR( 10 ) NOT NULL,
share TINYTEXT NOT NULL,
full_path TINYTEXT NULL,
additional_info TINYTEXT NULL,
complete TINYTEXT NOT NULL,
event_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tasks_completed (
id INTEGER PRIMARY KEY AUTOINCREMENT,
action VARCHAR( 10 ) NOT NULL,
share TINYTEXT NOT NULL,
full_path TINYTEXT NULL,
additional_info TINYTEXT NULL,
complete TINYTEXT NOT NULL,
event_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
