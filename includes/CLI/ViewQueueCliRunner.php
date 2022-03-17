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

class ViewQueueCliRunner extends AbstractAnonymousCliRunner {
    public function run() {
        $shares_names = array_keys(SharesConfig::getShares());
        natcasesort($shares_names);

        $max_share_strlen = max(array_merge(array_map('mb_strlen', $shares_names), array(7)));

        $queues = static::getData();

        if (isset($this->options['json'])) {
            echo json_encode($queues);
        } else {
            $this->log();
            $this->log("Greyhole Work Queue Statistics");
            $this->log("==============================");
            $this->log();
            $this->log("This table gives you the number of pending operations queued for the Greyhole daemon, per share.");
            $this->log();

            $col_size = 7;
            foreach ($queues['Total'] as $num) {
                $num = number_format($num, 0);
                if (strlen($num) > $col_size) {
                    $col_size = strlen($num);
                }
            }
            $col_format = '%' . $col_size . 's';

            /** @noinspection PhpFormatFunctionParametersMismatchInspection */
            $header = sprintf("%$max_share_strlen"."s  $col_format  $col_format  $col_format  $col_format", '', 'Write', 'Delete', 'Rename', 'Check');
            $this->log($header);

            foreach ($queues as $share_name => $queue) {
                if ($share_name == 'Spooled') continue;
                if ($share_name == 'Total') {
                    $this->log(str_repeat('=', $max_share_strlen+2+(4*$col_size)+(3*2)));
                }
                $this->log(sprintf("%-$max_share_strlen"."s", $share_name) . "  "
                    . sprintf($col_format, number_format($queue->num_writes_pending, 0)) . "  "
                    . sprintf($col_format, number_format($queue->num_delete_pending, 0)) . "  "
                    . sprintf($col_format, number_format($queue->num_rename_pending, 0)) . "  "
                    . sprintf($col_format, number_format($queue->num_fsck_pending, 0))
                );
            }
            $this->log($header);
            $this->log();
            $this->log("The following is the number of pending operations that the Greyhole daemon still needs to parse.");
            $this->log("Until it does, the nature of those operations is unknown.");
            $this->log("Spooled operations that have been parsed will be listed above and disappear from the count below.");
            $this->log();
            $this->log(sprintf("%-$max_share_strlen"."s  ", 'Spooled') . number_format($queues['Spooled'], 0));
            $this->log();
        }
    }

    public static function getData() {
        $shares_names = array_keys(SharesConfig::getShares());
        natcasesort($shares_names);

        $queues = array();
        $total_num_writes_pending = $total_num_delete_pending = $total_num_rename_pending = $total_num_fsck_pending = 0;
        foreach ($shares_names as $share_name) {
            $num_writes_pending = (int) DB::getFirstValue("SELECT COUNT(*) FROM tasks WHERE action = 'write' AND share = :share AND complete IN ('yes', 'thawed', 'written')", array('share' => $share_name));
            $total_num_writes_pending += $num_writes_pending;

            $num_delete_pending = (int) DB::getFirstValue("SELECT COUNT(*) FROM tasks WHERE (action = 'unlink' OR action = 'rmdir') AND share = :share AND complete IN ('yes', 'thawed', 'written')", array('share' => $share_name));
            $total_num_delete_pending += $num_delete_pending;

            $num_rename_pending = (int) DB::getFirstValue("SELECT COUNT(*) FROM tasks WHERE action = 'rename' AND share = :share AND complete IN ('yes', 'thawed', 'written')", array('share' => $share_name));
            $total_num_rename_pending += $num_rename_pending;

            $num_fsck_pending = (int) DB::getFirstValue("SELECT COUNT(*) FROM tasks WHERE (action = 'fsck' OR action = 'fsck_file' OR action = 'md5') AND share = :share", array('share' => $share_name));

            $landing_zone = SharesConfig::get($share_name, CONFIG_LANDING_ZONE);
            $num_fsck_pending += (int) DB::getFirstValue("SELECT COUNT(*) FROM tasks WHERE (action = 'fsck' OR action = 'fsck_file' OR action = 'md5') AND share LIKE :landing_zone", array('landing_zone' => "$landing_zone/%"));
            $total_num_fsck_pending += $num_fsck_pending;

            $queues[$share_name] = (object) array(
                'num_writes_pending' => $num_writes_pending,
                'num_delete_pending' => $num_delete_pending,
                'num_rename_pending' => $num_rename_pending,
                'num_fsck_pending' => $num_fsck_pending,
            );
        }
        $queues['Total'] = (object) array(
            'num_writes_pending' => $total_num_writes_pending,
            'num_delete_pending' => $total_num_delete_pending,
            'num_rename_pending' => $total_num_rename_pending,
            'num_fsck_pending' => $total_num_fsck_pending,
        );

        $queues['Spooled'] = (int) exec("find -L /var/spool/greyhole -type f 2> /dev/null | wc -l");
        return $queues;
    }
}

?>
