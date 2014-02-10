<?php
/*
Copyright 2009-2014 Guillaume Boudreau, Andrew Hopkinson

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

class MigrationHelper {

    public static function terminologyConversion() {
        static::convertFolders('.gh_graveyard', '.gh_metastore');
        static::convertFolders('.gh_graveyard_backup', '.gh_metastore_backup');
        static::convertFolders('.gh_attic', '.gh_trash');
        static::convertDatabase();
        static::convertStoragePoolDrivesTagFiles();
    }

	private static function convertFolders($old, $new) {
        foreach (Config::storagePoolDrives() as $sp_drive) {
			$old_term = clean_dir("$sp_drive/$old");
			$new_term = clean_dir("$sp_drive/$new");
			if (file_exists($old_term)) {
				Log::info("Moving $old_term to $new_term...");
				gh_rename($old_term, $new_term);
			}
		}
	}

	private static function convertDatabase() {
        Settings::rename('graveyard_backup_directory', 'metastore_backup_directory');
        $setting = Settings::get('metastore_backup_directory', FALSE, '%graveyard%');
        if ($setting) {
            $new_value = str_replace('/.gh_graveyard_backup', '/.gh_metastore_backup', $setting);
            Settings::set('metastore_backup_directory', $new_value);
        }
	}

    public static function convertStoragePoolDrivesTagFiles() {
        global $going_drive;

        $drives_definitions = Settings::get('sp_drives_definitions', TRUE);
        if (!$drives_definitions) {
            $drives_definitions = array();
        }
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (isset($going_drive) && $sp_drive == $going_drive) { continue; }
            $drive_uuid = gh_dir_uuid($sp_drive);
            if (!isset($drives_definitions[$sp_drive])) {
                if (is_dir($sp_drive)) {
                    $drives_definitions[$sp_drive] = $drive_uuid;
                }
            }
            if (!isset($drives_definitions[$sp_drive])) {
                continue;
            }
            if ($drives_definitions[$sp_drive] === FALSE) {
                unset($drives_definitions[$sp_drive]);
                continue;
            }
            if (file_exists("$sp_drive/.greyhole_uses_this") && $drive_uuid != 'remote') {
                unlink("$sp_drive/.greyhole_uses_this");
            }
            if ($drives_definitions[$sp_drive] != $drive_uuid) {
                Log::warn("Warning! It seems the partition UUID of $sp_drive changed. This probably means this mount is currently unmounted, or that you replaced this drive and didn't use 'greyhole --replace'. Because of that, Greyhole will NOT use this drive at this time.");
            }
        }
        foreach ($drives_definitions as $sp_drive => $uuid) {
            if (!array_contains(Config::storagePoolDrives(), $sp_drive)) {
                unset($drives_definitions[$sp_drive]);
            }
        }

        // Check that the user is not using two sp drives on the same device
        $devices = array();
        foreach ($drives_definitions as $sp_drive => $device_id) {
            $devices[$device_id][] = $sp_drive;
        }
        foreach ($devices as $device_id => $sp_drives) {
            if (count($sp_drives) > 1 && $device_id !== 0 && $device_id != 'remote') {
                if (Config::get(CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE)) {
                    Log::info("The following storage pool drives are on the same partition: " . implode(", ", $sp_drives) . ", but per greyhole.conf '" . CONFIG_ALLOW_MULTIPLE_SP_PER_DRIVE . "' options, you chose to ignore this normally critical error.");
                } else {
                    Log::critical("ERROR: The following storage pool drives are on the same partition: " . implode(", ", $sp_drives) . ". The Greyhole daemon will now stop.");
                }
            }
        }

        Settings::set('sp_drives_definitions', $drives_definitions);
        return $drives_definitions;
	}
}
?>
