<?php
/*
Copyright 2009-2014 Guillaume Boudreau

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

final class Settings {
    public static function get($name, $unserialize=FALSE, $value=FALSE) {
        if (!DB::isConnected()) {
            $setting = FALSE;
        } else {
            $query = "SELECT * FROM settings WHERE name LIKE :name";
            $params = array('name' => $name);
            if ($value !== FALSE) {
                $query .= " AND value LIKE :value";
                $params['value'] = $value;
            }
            $setting = DB::getFirst($query, $params);
        }
        if ($setting === FALSE) {
            return FALSE;
        }
        return $unserialize ? unserialize($setting->value) : $setting->value;
    }

    public static function set($name, $value) {
        if (is_array($value)) {
            $value = serialize($value);
        }
        $query = "INSERT INTO settings SET name = :name, value = :value ON DUPLICATE KEY UPDATE value = VALUES(value)";
        DB::insert($query, array('name' => $name, 'value' => $value));
        return (object) array('name' => $name, 'value' => $value);
    }

    public static function rename($from, $to) {
        $query = "UPDATE settings SET name = :to WHERE name = :from";
        DB::execute($query, array('from' => $from, 'to' => $to));
    }

    public static function backup() {
        $settings = DB::getAll("SELECT * FROM settings");
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (StoragePool::is_pool_drive($sp_drive)) {
                $settings_backup_file = "$sp_drive/.gh_settings.bak";
                file_put_contents($settings_backup_file, serialize($settings));
            }
        }
    }

    public static function restore() {
        $latest_backup_time = 0;
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $settings_backup_file = "$sp_drive/.gh_settings.bak";
            if (file_exists($settings_backup_file)) {
                $last_mod_date = filemtime($settings_backup_file);
                if ($last_mod_date > $latest_backup_time) {
                    $backup_file = $settings_backup_file;
                    $latest_backup_time = $last_mod_date;
                }
            }
        }
        if (isset($backup_file)) {
            Log::info("Restoring settings from last backup: $backup_file");
            $settings = unserialize(file_get_contents($backup_file));
            foreach ($settings as $setting) {
                Settings::set($setting->name, $setting->value);
            }
            return TRUE;
        }
        return FALSE;
    }
}

?>
