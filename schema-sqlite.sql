CREATE TABLE settings ( name CHAR(255) PRIMARY KEY, value CHAR(255));

INSERT INTO settings (name, value) VALUES ('last_read_log_smbd_line', '0');
INSERT INTO settings (name, value) VALUES ('last_OOS_notification', '0');

CREATE TABLE tasks (
id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
action VARCHAR( 10 ) NOT NULL,
share TINYTEXT NOT NULL,
full_path TINYTEXT NULL,
additional_info TINYTEXT NULL,
complete BOOL NOT NULL,
event_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tasks_completed (
id BIGINT UNSIGNED NOT NULL,
action VARCHAR( 10 ) NOT NULL,
share TINYTEXT NOT NULL,
full_path TINYTEXT NULL,
additional_info TINYTEXT NULL,
complete BOOL NOT NULL,
event_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
