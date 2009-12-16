CREATE TABLE `greyhole`.`settings` (
`name` TINYTEXT NOT NULL,
`value` TINYTEXT NOT NULL,
PRIMARY KEY ( `name`(255) )
) ENGINE = MYISAM;

INSERT INTO `greyhole`.`settings` (`name`, `value`) VALUES ('last_read_log_smbd_line', '0');
INSERT INTO `greyhole`.`settings` (`name`, `value`) VALUES ('last_OOS_notification', '0');

CREATE TABLE `greyhole`.`tasks` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
`action` VARCHAR( 10 ) NOT NULL,
`share` TINYTEXT NOT NULL,
`full_path` TINYTEXT NULL,
`additional_info` TINYTEXT NULL,
`complete` ENUM( 'yes',  'no' ) NOT NULL,
`event_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = MYISAM;

ALTER TABLE `greyhole`.`tasks` ADD INDEX `incomplete_open` ( `complete` );
ALTER TABLE `greyhole`.`tasks` ADD INDEX `subsequent_writes` ( `action`(10), `share`(64), `full_path`(255) );
ALTER TABLE `greyhole`.`tasks` ADD INDEX `unneeded_unlinks` ( `complete`, `share`(64), `action`(10), `full_path`(255), `additional_info`(255) );

CREATE TABLE `greyhole`.`tasks_completed` (
`id` BIGINT UNSIGNED NOT NULL,
`action` VARCHAR( 10 ) NOT NULL,
`share` TINYTEXT NOT NULL,
`full_path` TINYTEXT NULL,
`additional_info` TINYTEXT NULL,
`complete` ENUM( 'yes',  'no' ) NOT NULL,
`event_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = MYISAM;