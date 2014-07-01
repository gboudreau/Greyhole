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

require_once('includes/CLI/AbstractPoolDriveCliRunner.php');

class GoneCliRunner extends AbstractPoolDriveCliRunner {
    function __construct($options, $cli_command) {
        parent::__construct($options, $cli_command);

        if ($this->isGoing()) {
            // Check that all scheduled fsck have completed; an incomplete fsck means some file copies might be missing!
            $total = DB::getFirstValue("SELECT COUNT(*) AS total FROM tasks WHERE action = 'fsck'");
            if ($total > 0) {
                $this->log("There are pending fsck operations. This could mean some file copies are missing, which would make it dangerous to remove a drive at this time.");
                $this->log("Please wait until all fsck operation are complete, and then retry.");
                $this->finish(2);
            }
        }
    }
    
    private function isGoing() {
        return $this instanceof GoingCliRunner;
    }

    public function run() {
        // Removing this drive here will insure it won't be used for new files while we're moving files away, and that it can later be replaced.
        remove_drive_definition($this->drive);

        if ($this->isGoing()) {
            file_put_contents($this->drive . "/.greyhole_used_this", "Flag to prevent Greyhole from thinking this drive disappeared for no reason...");
            set_metastore_backup();
            Log::info("Storage pool drive " . $this->drive . " will be removed from the storage pool.");
            echo("Storage pool drive " . $this->drive . " will be removed from the storage pool.\n");

            global $going_drive; // Used in function is_greyhole_owned_drive()
            $going_drive = $this->drive;

            // For the fsck_file calls to be able to use the files on $going_drive if needed, to create extra copies.
            global $options;
            $options['find-orphans'] = TRUE;

            // fsck shares with only 1 file copy to remove those from $this->drive
            initialize_fsck_report('Shares with only 1 copy');
            foreach (SharesConfig::getShares() as $share_name => $share_options) {
                $this->log("");
                if ($share_options[CONFIG_NUM_COPIES] == 1) {
                    $this->logn("Moving file copies for share '$share_name'... Please be patient... ");
                    if (is_dir("$going_drive/$share_name")) {
                        gh_fsck_reset_du($share_name);
                        gh_fsck($share_options[CONFIG_LANDING_ZONE], $share_name);
                    }
                    $this->log("Done.");
                } else {
                    // Temporarily rename $going_drive/$share_name for fix_symlinks_on_share to be able to find symlinks that will be broken once this drive is removed.
                    @rename("$going_drive/$share_name", "$going_drive/$share_name".".tmp");
                    fix_symlinks_on_share($share_name);
                    @rename("$going_drive/$share_name".".tmp", "$going_drive/$share_name");

                    // Also, just to be safe, make sure that all the files in $going_drive/$share_name are also somewhere else, as expected.
                    $this->logn("Checking that all the files in the share '$share_name' also exist on another drive...");
                    static::check_going_dir("$going_drive/$share_name", $share_name, $going_drive);
                    $this->log(" Done.");

                }
            }
        }

        // Remove $going_drive from config file and restart (if it was running)
        ConfigHelper::removeStoragePoolDrive($this->drive);
        $this->restart_service();

        if (is_amahi()) {
            $this->log("\nYou should de-select this partition in your Amahi dashboard (http://hda), in the Shares > Storage Pool page.");
        }

        mark_gone_ok($this->drive, 'remove');
        mark_gone_drive_fscked($this->drive, 'remove');
        Log::info("Storage pool drive $this->drive has been removed.");
        $this->log("Storage pool drive $this->drive has been removed from your pool, which means the missing file copies that are in this drive will be re-created during the next fsck.");

        if ($this->isGoing()) {
            // Schedule fsck for all shares to re-create missing copies on other shares
            schedule_fsck_all_shares(array('email'));
            $this->log("All the files that were only on $this->drive have been copied somewhere else.");
            $this->log("A fsck of all shares has been scheduled, to recreate other file copies. It will start after all currently pending tasks have been completed.");
            unlink($this->drive . "/.greyhole_used_this");
        } else { // --gone
            $this->log("Sadly, file copies that were only on this drive, if any, are now lost!");
        }
    }

    private static function check_going_dir($path, $share, $going_drive) {
        $handle = @opendir($path);
        if ($handle === FALSE) {
            Log::error("Couldn't open $path to list content. Skipping...");
            return;
        }
        Log::debug("Entering $path");
        while (($filename = readdir($handle)) !== FALSE) {
            if ($filename == '.' || $filename == '..') { continue; }

            $full_path = "$path/$filename";
            $file_type = @filetype($full_path);
            if ($file_type === FALSE) {
                // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
                $file_type = @filetype(normalize_utf8_characters($full_path));
                if ($file_type !== FALSE) {
                    // Bingo!
                    $full_path = normalize_utf8_characters($full_path);
                    $path = normalize_utf8_characters($path);
                }
            }

            if ($file_type == 'dir') {
                static::check_going_dir($full_path, $share, $going_drive);
            } else {
                $file_path = trim(mb_substr($path, mb_strlen("$going_drive/$share")+1), '/');
                $file_metafiles = array();
                $file_copies_inodes = get_file_inodes($share, $file_path, $filename, $file_metafiles, TRUE);
                if (count($file_copies_inodes) == 0) {
                    Log::warn("Found a file, $full_path, that has no other copies on other drives. Removing $going_drive would make that file disappear! Will create extra copies now.");
                    echo ".";
                    gh_fsck_file($path, $filename, $file_type, 'landing_zone', $share, $going_drive);
                }
            }
        }
        closedir($handle);
    }
}

?>
