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

        $groups = static::getData();
        foreach ($groups as $group) {
            printf("\n%$max_storage_pool_strlen"."s  %s", "", "Target free space in $group->name storage pool drives: " . bytes_to_human($group->target_avail_space*1024, FALSE) . "\n");
            $num_lines += 2;

            foreach ($group->drives as $sp_drive => $drive_infos) {
                $cols_free = ceil($cols * $drive_infos->percent_free);
                $cols_used = $cols - abs($cols_free);

                $suffix = "\033[0m";
                if ($drive_infos->direction == '-') {
                    $cols_diff = round($cols * $drive_infos->percent_diff);
                    $cols_used -= $cols_diff;
                    $prefix = "\033[31m";
                } else {
                    $cols_diff = round($cols * $drive_infos->percent_diff);
                    $cols_free -= $cols_diff;
                    $prefix = "\033[32m";
                }
                $how_much = round($drive_infos->diff / 1024 / 1024) . 'GB';

                $sign = $drive_infos->direction;

                $cols_used = max($cols_used, 0);
                $cols_diff = max($cols_diff, 0);
                $cols_free = max($cols_free, 0);
                printf("%$max_storage_pool_strlen"."s  [%s%s%s%s%s]  %s %6s\n", $sp_drive, str_repeat(mb_convert_encoding('&#9724;', 'UTF-8', 'HTML-ENTITIES'), $cols_used), $prefix, str_repeat(mb_convert_encoding('&#9724;', 'UTF-8', 'HTML-ENTITIES'), $cols_diff), $suffix, str_repeat(' ', $cols_free), $sign, $how_much);
                $num_lines++;
            }
        }

        return $num_lines;
    }

    public static function getData() {
        $groups = [];

        /** @var $drives_selectors PoolDriveSelector[] */
        $drives_selectors = Config::get(CONFIG_DRIVE_SELECTION_ALGORITHM);
        foreach ($drives_selectors as $ds) {
            $pool_drives_avail_space = StoragePool::get_drives_available_space();
            foreach ($pool_drives_avail_space as $drive => $available_space) {
                if (!array_contains($ds->drives, $drive)) {
                    // Only work on the drives part of the current PoolDriveSelector
                    unset($pool_drives_avail_space[$drive]);
                    continue;
                }
            }

            if (count($pool_drives_avail_space) == 0) {
                continue;
            }

            $target_avail_space = array_sum($pool_drives_avail_space) / count($pool_drives_avail_space);

            $group = (object) [
                'name' => $ds->group_name,
                'target_avail_space' => $target_avail_space,
                'drives' => [],
            ];
            $groups[] = $group;

            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (!array_contains($ds->drives, $sp_drive)) {
                    continue;
                }
                $df = StoragePool::get_free_space($sp_drive);
                if (!$df) {
                    continue;
                }

                $df['free'] -= (float) Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive);

                $drive_infos = (object) [
                    'df' => $df,
                    'percent_free' => $df['free'] / ($df['free'] + $df['used']),
                ];
                $group->drives[$sp_drive] = $drive_infos;

                if ($df['free'] < $target_avail_space) {
                    $drive_infos->direction = '-';
                    $drive_infos->diff = $target_avail_space - $df['free'];
                    $drive_infos->percent_diff = $drive_infos->diff / ($df['free'] + $df['used']);
                } else {
                    $drive_infos->direction = '+';
                    $drive_infos->diff = $df['free'] - $target_avail_space;
                    $drive_infos->percent_diff = $drive_infos->diff / ($df['free'] + $df['used']);
                }
            }
        }

        return $groups;
    }
}

?>
