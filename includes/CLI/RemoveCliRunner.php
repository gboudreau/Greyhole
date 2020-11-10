<?php
/*
Copyright 2009-2020 Guillaume Boudreau

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

require_once('includes/CLI/AbstractPoolDriveCliRunner.php');

class RemoveCliRunner extends AbstractPoolDriveCliRunner {
    public function run() {
        echo "\nIs the specified drive still available? ";
        if (is_dir("$this->drive/.gh_metastore/")) {
            echo "(It looks like it is.)\n";
        } else {
            echo "(It looks like it is not.)\n";
        }
        echo "If so, Greyhole will try to move all file copies that are only on this drive, onto your other drives.\n";
        do {
            $answer = strtolower(readline("Yes, or No? "));
        } while ($answer != 'yes' && $answer != 'no');
        $drive_still_available = ( $answer == 'yes' );

        if ($drive_still_available) {
            // Check that all scheduled fsck have completed; an incomplete fsck means some file copies might be missing!
            $total = DB::getFirstValue("SELECT COUNT(*) AS total FROM tasks WHERE action = 'fsck'");
            if ($total > 0) {
                $this->log("There are pending fsck operations. This could mean some file copies are missing, which would make it dangerous to remove a drive at this time.");
                $this->log("Please wait until all fsck operation are complete, and then retry.");
                $this->finish(2);
            }
        }

        $query = "INSERT INTO tasks SET action = :action, share = :share, full_path = :full_path, additional_info = :options, complete = 'yes'";
        $params = array(
            'action'    => ACTION_REMOVE,
            'share'     => 'pool drive ',
            'full_path' => $this->drive,
            'options'   => ( $drive_still_available ? OPTION_DRIVE_IS_AVAILABLE : '' ),
        );
        DB::insert($query, $params);

        $this->log();
        $this->log("Removal of $this->drive has been scheduled. It will start after all currently pending tasks have been completed.");
        $this->log("You will receive an email notification once it completes.");
        $this->log("You can also tail the Greyhole log to follow this operation.");
    }
}

?>
