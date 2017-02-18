CREATE TABLE `settings` (
`name` VARCHAR(255) NOT NULL,
`value` TEXT NOT NULL,
PRIMARY KEY (`name`)
) ENGINE = MYISAM DEFAULT CHARSET=utf8;

INSERT INTO `settings` (`name`, `value`) VALUES ('last_read_log_smbd_line', '0');
INSERT INTO `settings` (`name`, `value`) VALUES ('last_OOS_notification', '0');
INSERT INTO `settings` (`name`, `value`) VALUES ('db_version', '11');

CREATE TABLE `tasks` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`action` VARCHAR(10) CHARACTER SET ascii NOT NULL,
`share` VARCHAR(255) NOT NULL,
`full_path` VARCHAR(255) NULL,
`additional_info` VARCHAR(255) NULL,
`complete` ENUM('yes', 'no', 'frozen', 'thawed', 'idle', 'written') CHARACTER SET ascii NOT NULL,
`event_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `md5_worker` (`action`,`complete`,`additional_info`(100),`id`),
KEY `find_next_task` (`complete`,`id`),
KEY `md5_checker` (`action`,`share`(64),`full_path`,`complete`)
) ENGINE = MYISAM DEFAULT CHARSET=utf8;

CREATE TABLE `tasks_completed` (
`id` BIGINT UNSIGNED NOT NULL,
`action` VARCHAR(10) CHARACTER SET ascii NOT NULL,
`share` VARCHAR(255) NOT NULL,
`full_path` VARCHAR(255) NULL,
`additional_info` VARCHAR(255) NULL,
`complete` ENUM('yes', 'no', 'frozen', 'thawed', 'idle', 'written') CHARACTER SET ascii NOT NULL,
`event_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = MYISAM DEFAULT CHARSET=utf8;

CREATE TABLE `du_stats` (
`share` VARCHAR(255) NOT NULL,
`full_path` VARCHAR(255) NOT NULL,
`depth` TINYINT(3) UNSIGNED NOT NULL,
`size` BIGINT(20) UNSIGNED NOT NULL,
UNIQUE KEY `uniqness` (`share`(64),`full_path`)
) ENGINE = MYISAM DEFAULT CHARSET=utf8;
