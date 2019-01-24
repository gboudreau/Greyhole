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

require_once('includes/CLI/AbstractAnonymousCliRunner.php');

class BalanceStatusCliRunner extends AbstractAnonymousCliRunner {
    private $refresh_interval = 15;

    public function run() {
        while (TRUE) {
            $num_lines = $this->output();

            sleep($this->refresh_interval);

            for ($i=0; $i < $num_lines; $i++) {
                echo "\r\033[K\033[1A\r\033[K\r";
            }
        }
    }

    public function output() {
        $num_lines = 0;
        $cols = exec('tput cols');

        printf("Watching every %ds:%s%s\n", $this->refresh_interval, str_repeat(' ', $cols - 50), date('r'));
        $num_lines++;

        $max_storage_pool_strlen = max(array_map('mb_strlen', Config::storagePoolDrives()));
        $cols -= $max_storage_pool_strlen + 14;

        $sorted_pool_drives = sort_storage_drives_available_space();
        $pool_drives_avail_space = array();
        foreach ($sorted_pool_drives as $available_space => $drive) {
            $pool_drives_avail_space[$drive] = $available_space;
        }
        $target_avail_space = array_sum($pool_drives_avail_space) / count($pool_drives_avail_space);

        printf("\n%$max_storage_pool_strlen"."s  %s", "", "Target free space in all storage pool drives: " . bytes_to_human($target_avail_space*1024, FALSE) . "\n");
        $num_lines+=2;

        $dfs = get_free_space_in_storage_pool_drives();
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (!isset($dfs[$sp_drive])) {
                continue;
            }

            $dfs[$sp_drive]['free'] -= (float) Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive);

            $percent_free = $dfs[$sp_drive]['free'] / ($dfs[$sp_drive]['free'] + $dfs[$sp_drive]['used']);
            $cols_free = ceil($cols * $percent_free);
            $cols_used = $cols - abs($cols_free);

            $prefix = ''; $suffix = "\033[0m";
            if ($dfs[$sp_drive]['free'] < $target_avail_space) {
                $diff = $target_avail_space - $dfs[$sp_drive]['free'];
                $percent_diff = $diff / ($dfs[$sp_drive]['free'] + $dfs[$sp_drive]['used']);
                $cols_diff = round($cols * $percent_diff);
                $cols_used -= $cols_diff;
                $prefix = "\033[31m";
                $sign = '-';
            } else {
                $diff = $dfs[$sp_drive]['free'] - $target_avail_space;
                $percent_diff = $diff / ($dfs[$sp_drive]['free'] + $dfs[$sp_drive]['used']);
                $cols_diff = round($cols * $percent_diff);
                $cols_free -= $cols_diff;
                $prefix = "\033[32m";
                $sign = '+';
            }
            $how_much = round($diff / 1024 / 1024) . 'GB';

            $cols_used = max($cols_used, 0);
            $cols_diff = max($cols_diff, 0);
            $cols_free = max($cols_free, 0);
            printf("%$max_storage_pool_strlen"."s  [%s%s%s%s%s]  %s %6s\n", $sp_drive, str_repeat(mb_convert_encoding('&#9724;', 'UTF-8', 'HTML-ENTITIES'), $cols_used), $prefix, str_repeat(mb_convert_encoding('&#9724;', 'UTF-8', 'HTML-ENTITIES'), $cols_diff), $suffix, str_repeat(' ', $cols_free), $sign, $how_much);
            $num_lines++;
        }

        return $num_lines;
    }
}

?>
