CREATE TABLE `settings` (
`name` TINYTEXT CHARACTER SET ascii NOT NULL,
`value` TEXT NOT NULL,
PRIMARY KEY ( `name`(255) )
) ENGINE = MYISAM DEFAULT CHARSET=utf8;

INSERT INTO `settings` (`name`, `value`) VALUES ('last_read_log_smbd_line', '0');
INSERT INTO `settings` (`name`, `value`) VALUES ('last_OOS_notification', '0');

CREATE TABLE `tasks` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`action` VARCHAR( 10 ) CHARACTER SET ascii NOT NULL,
`share` TINYTEXT NOT NULL,
`full_path` TEXT NULL,
`additional_info` TEXT NULL,
`complete` ENUM( 'yes',  'no', 'frozen', 'thawed', 'idle') CHARACTER SET ascii NOT NULL,
`event_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `md5_worker` (`action`,`complete`,`additional_info`(100),`id`),
KEY `find_next_task` (`complete`,`id`),
KEY `md5_checker` (`action`,`share`(64),`full_path`(265),`complete`)
) ENGINE = MYISAM DEFAULT CHARSET=utf8;

CREATE TABLE `tasks_completed` (
`id` BIGINT UNSIGNED NOT NULL,
`action` VARCHAR( 10 ) CHARACTER SET ascii NOT NULL,
`share` TINYTEXT NOT NULL,
`full_path` TINYTEXT NULL,
`additional_info` TINYTEXT NULL,
`complete` ENUM( 'yes',  'no', 'frozen', 'thawed', 'idle' ) CHARACTER SET ascii NOT NULL,
`event_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = MYISAM DEFAULT CHARSET=utf8;

CREATE TABLE `du_stats` (
`share` TINYTEXT NOT NULL,
`full_path` TEXT NOT NULL,
`depth` TINYINT(3) UNSIGNED NOT NULL,
`size` BIGINT(20) UNSIGNED NOT NULL,
UNIQUE KEY `uniqness` (`share`(64),`full_path`(269))
) ENGINE = MYISAM DEFAULT CHARSET=utf8;
