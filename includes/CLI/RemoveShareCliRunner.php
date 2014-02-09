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

require_once('includes/CLI/AbstractCliRunner.php');

class RemoveShareCliRunner extends AbstractCliRunner {
    private $share;

    function __construct($options) {
        parent::__construct($options);
        
        if (!isset($this->options['cmd_param'])) {
            $this->log("Please specify the share to remove.");
            $this->finish(1);
        }

        $this->share = $this->options['cmd_param'];

        if (!SharesConfig::exists($this->share)) {
            $this->log("'$this->share' is not a known share.");
            $this->log("If you removed it already from your Greyhole configuration, please re-add it, and retry.");
            $this->log("Otherwise, please use one of the following share name:");
            $this->log("  " . implode("\n  ", array_keys(SharesConfig::getShares())));
            $this->finish(1);
        }
    }

    public function run() {
        $landing_zone = get_share_landing_zone($this->share);

        $this->log("Will remove '$this->share' share from the Greyhole storage pool, by moving all the data files inside this share to it's landing zone: $landing_zone");

        // df the landing zone, to see how much free space it has
        $free_space = 1024 * exec("df -k --total " . escapeshellarg($landing_zone) . " | awk '/^total/{print $4}'");

        // du the files to see if there's room in the landing zone
        $this->log("  Finding how much space is needed, and if you have enough... Please wait...");
        $space_needed = 1024 * exec("du -skL " . escapeshellarg($landing_zone) . " | awk '{print $1}'");
        $this->log("    Space needed: " . bytes_to_human($space_needed, FALSE));
        $this->log("    Free space: " . bytes_to_human($free_space, FALSE));

        // Adjust the landing zone free space, if some file copies are already on that drive
        $storage_pool_drives_to_unwind = array();
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (!file_exists("$sp_drive/$this->share")) {
                #echo "  Folder does not exist: $sp_drive/$share. Skipping.\n";
                continue;
            }
            if (gh_dir_uuid("$sp_drive/$this->share") === gh_dir_uuid("$landing_zone")) {
                $more_free_space = 1024 * exec("du -sk " . escapeshellarg("$sp_drive/$this->share") . " | awk '{print $1}'");
                $free_space += $more_free_space;
                $this->log("      + " . bytes_to_human($more_free_space, FALSE) . " (file copies already on $sp_drive) = " . bytes_to_human($free_space, FALSE) . " (Total available space)");

                // We also need to make sure this drive is 'unwind' first, to make sure it will have enough free space to receive all the file copies.
                array_unshift($storage_pool_drives_to_unwind, $sp_drive);
            } else {
                array_push($storage_pool_drives_to_unwind, $sp_drive);
            }
        }

        if ($space_needed > $free_space) {
            $this->log("Not enough free space available in $landing_zone. Aborting.");
            $this->finish(1);
        }
        $this->log("  OK! Let's do this.");

        // Remove the symlinks from the landing zone
        $this->log("  Deleting symlinks from landing zone... Please wait...");
        exec("find " . escapeshellarg($landing_zone) . " -type l -delete");
        $this->log("    Done.");

        // Copy the data files from the storage pool into the landing zone
        $num_files_total = 0;
        foreach ($storage_pool_drives_to_unwind as $sp_drive) {
            unset($result);
            $this->log("  Moving files from $sp_drive/$this->share into $landing_zone... Please wait...");
            exec("rsync -rlptgoDuvW --remove-source-files " . escapeshellarg("$sp_drive/$this->share/") . " " . escapeshellarg('/' . trim($landing_zone, '/')), $result);
            $num_files = count($result)-5;
            if ($num_files < 0) {
                $num_files = 0;
            }
            $num_files_total += $num_files;
            exec("find " . escapeshellarg("$sp_drive/$this->share/") . " -type d -delete");
            $this->log("    Done. Copied $num_files files.");
        }
        $this->log("All done. Copied $num_files_total files.\nYou should now remove the Greyhole options from the [$this->share] share in your smb.conf.");

        ConfigHelper::removeShare($this->share);
        $this->restart_service();
    }
}

?>
