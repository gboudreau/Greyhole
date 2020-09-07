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

class StatsCliRunner extends AbstractAnonymousCliRunner {
    public function run() {
        if (file_exists('/sbin/zpool')) {
            if (exec("whoami") != 'root') {
                $this->log("Warning: If you are using ZFS datasets as Greyhole storage pool drives, you will need to execute this as root.");
            }
        }

        $max_drive_strlen = max(array_map('mb_strlen', Config::storagePoolDrives())) + 1;

        $stats = static::get_stats();

        if (isset($this->options['json'])) {
            echo json_encode($stats);
        } else {
            $this->log();
            $this->log("Greyhole Statistics");
            $this->log("===================");
            $this->log();
            $this->log("Storage Pool");
            $this->log(sprintf("%$max_drive_strlen"."s    Total -   Used =   Free +  Trash = Possible", ''));
            foreach ($stats as $sp_drive => $stat) {
                if ($sp_drive == 'Total') {
                    $this->log(sprintf("  %-$max_drive_strlen"."s ==========================================", ""));
                }
                $this->logn(sprintf("  %-$max_drive_strlen"."s ", "$sp_drive:"));
                if (empty($stat->total_space)) {
                    $this->log("                 Offline                  ");
                } else {
                    $this->log(
                            sprintf('%5.0f', $stat->total_space/1024/1024) . "G"               //   Total
                        . ' - ' . sprintf('%5.0f', $stat->used_space/1024/1024). "G"                 // - Used
                        . ' = ' . sprintf('%5.0f', $stat->free_space/1024/1024) . "G"                // = Free
                        . ' + ' . sprintf('%5.0f', $stat->trash_size/1024/1024) . "G"                // + Trash
                        . ' = ' . sprintf('%5.0f', $stat->potential_available_space/1024/1024) . "G" // = Possible
                    );
                }
            }
            $this->log();
        }
    }

    public static function get_stats() {
        $totals = array(
            'total_space' => 0,
            'used_space' => 0,
            'free_space' => 0,
            'trash_size' => 0,
            'potential_available_space' => 0
        );

        $stats = array();
        foreach (Config::storagePoolDrives() as $sp_drive) {
            $df = StoragePool::get_free_space($sp_drive);
            if (!$df || !file_exists($sp_drive)) {
                $stats[$sp_drive] = (object) array();
                continue;
            }
            if (!StoragePool::is_pool_drive($sp_drive)) {
                $stats[$sp_drive] = (object) array();
                continue;
            }

            $df_command = "df -k " . escapeshellarg($sp_drive) . " | tail -1";
            unset($responses);
            exec($df_command, $responses);

            $total_space = 0;
            $used_space = 0;
            if (isset($responses[0])) {
                if (preg_match("@\s+([0-9]+)\s+([0-9]+)\s+[0-9]+\s+[0-9]+%\s+.+$@", $responses[0], $regs)) {
                    $total_space = (float) $regs[1];
                    $used_space = (float) $regs[2];
                }
            }

            $free_space = $df['free'];

            $trash_path = clean_dir("$sp_drive/.gh_trash");
            if (!file_exists($trash_path)) {
                $trash_size = (float) 0;
            } else {
                $trash_size = (float) trim(exec("du -sk " . escapeshellarg($trash_path) . " | awk '{print $1}'"));
            }

            $potential_available_space = (float) $free_space + $trash_size;

            $stats[$sp_drive] = (object) array(
                'total_space' => $total_space,
                'used_space' => $used_space,
                'free_space' => $free_space,
                'trash_size' => $trash_size,
                'potential_available_space' => $potential_available_space,
            );

            $totals['total_space'] += $total_space;
            $totals['used_space'] += $used_space;
            $totals['free_space'] += $free_space;
            $totals['trash_size'] += $trash_size;
            $totals['potential_available_space'] += $potential_available_space;
        }
        $stats['Total'] = (object) $totals;
        return $stats;
    }
}

?>
