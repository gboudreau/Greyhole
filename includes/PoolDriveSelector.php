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

class PoolDriveSelector {
    var $num_drives_per_draft;
    var $selection_algorithm;
    var $drives;
    var $is_forced;

    var $sorted_target_drives;
    var $last_resort_sorted_target_drives;

    function __construct($num_drives_per_draft, $selection_algorithm, $drives, $is_forced) {
        $this->num_drives_per_draft = $num_drives_per_draft;
        $this->selection_algorithm = $selection_algorithm;
        $this->drives = $drives;
        $this->is_forced = $is_forced;
    }

    public function isForced() {
        return $this->is_forced;
    }

    function init(&$sorted_target_drives, &$last_resort_sorted_target_drives) {
        // Sort by used space (asc) for least_used_space, or by available space (desc) for most_available_space
        if ($this->selection_algorithm == 'least_used_space') {
            $sorted_target_drives = $sorted_target_drives['used_space'];
            $last_resort_sorted_target_drives = $last_resort_sorted_target_drives['used_space'];
            asort($sorted_target_drives);
            asort($last_resort_sorted_target_drives);
        } else if ($this->selection_algorithm == 'most_available_space') {
            $sorted_target_drives = $sorted_target_drives['available_space'];
            $last_resort_sorted_target_drives = $last_resort_sorted_target_drives['available_space'];
            arsort($sorted_target_drives);
            arsort($last_resort_sorted_target_drives);
        } else {
            Log::critical("Unknown '" . CONFIG_DRIVE_SELECTION_ALGORITHM . "' found: " . $this->selection_algorithm, Log::EVENT_CODE_CONFIG_INVALID_VALUE);
        }
        // Only keep drives that are in $this->drives
        $this->sorted_target_drives = array();
        foreach ($sorted_target_drives as $sp_drive => $space) {
            if (array_contains($this->drives, $sp_drive)) {
                $this->sorted_target_drives[$sp_drive] = $space;
            }
        }
        $this->last_resort_sorted_target_drives = array();
        foreach ($last_resort_sorted_target_drives as $sp_drive => $space) {
            if (array_contains($this->drives, $sp_drive)) {
                $this->last_resort_sorted_target_drives[$sp_drive] = $space;
            }
        }
    }

    function draft() {
        $drives = array();
        $drives_last_resort = array();

        while (count($drives)<$this->num_drives_per_draft) {
            $arr = kshift($this->sorted_target_drives);
            if ($arr === FALSE) {
                break;
            }
            list($sp_drive, $space) = $arr;
            if (!StoragePool::is_pool_drive($sp_drive)) { continue; }
            $drives[$sp_drive] = $space;
        }
        while (count($drives)+count($drives_last_resort)<$this->num_drives_per_draft) {
            $arr = kshift($this->last_resort_sorted_target_drives);
            if ($arr === FALSE) {
                break;
            }
            list($sp_drive, $space) = $arr;
            if (!StoragePool::is_pool_drive($sp_drive)) { continue; }
            $drives_last_resort[$sp_drive] = $space;
        }

        return array($drives, $drives_last_resort);
    }

    static function parse($config_string, $drive_selection_groups) {
        $ds = array();
        if ($config_string == 'least_used_space' || $config_string == 'most_available_space') {
            $ds[] = new PoolDriveSelector(count(Config::storagePoolDrives()), $config_string, Config::storagePoolDrives(), FALSE);
            return $ds;
        }
        if (!preg_match('/forced ?\((.+)\) ?(least_used_space|most_available_space)/i', $config_string, $regs)) {
            Log::critical("Can't understand the '" . CONFIG_DRIVE_SELECTION_ALGORITHM . "' value: $config_string", Log::EVENT_CODE_CONFIG_INVALID_VALUE);
        }
        $selection_algorithm = $regs[2];
        $groups = array_map('trim', explode(',', $regs[1]));
        foreach ($groups as $group) {
            $group = explode(' ', preg_replace('/^([0-9]+)x/', '\\1 ', $group));
            $num_drives = trim($group[0]);
            $group_name = trim($group[1]);
            if (!isset($drive_selection_groups[$group_name])) {
                //Log::warn("Warning: drive selection group named '$group_name' is undefined.");
                continue;
            }
            if (stripos(trim($num_drives), 'all') === 0 || $num_drives > count($drive_selection_groups[$group_name])) {
                $num_drives = count($drive_selection_groups[$group_name]);
            }
            $ds[] = new PoolDriveSelector($num_drives, $selection_algorithm, $drive_selection_groups[$group_name], TRUE);
        }
        return $ds;
    }

    function update() {
        // Make sure num_drives_per_draft and drives have been set, in case storage_pool_drive lines appear after drive_selection_algorithm line(s) in the config file
        if (!$this->is_forced && ($this->selection_algorithm == 'least_used_space' || $this->selection_algorithm == 'most_available_space')) {
            $this->num_drives_per_draft = count(Config::storagePoolDrives());
            $this->drives = Config::storagePoolDrives();
        }
    }
}

?>
