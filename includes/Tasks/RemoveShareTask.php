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

class RemoveShareTask extends AbstractTask {
    protected $log = '';

    public function execute() {
        $landing_zone = get_share_landing_zone($this->share);

        $log = "Will remove '$this->share' share from the Greyhole storage pool, by moving all the data files inside this share to it's landing zone: $landing_zone";
        $this->log($log);
        Log::info($log);

        // Adjust the landing zone free space, if some file copies are already on that drive
        $storage_pool_drives_to_unwind = array();
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (!file_exists("$sp_drive/$this->share")) {
                continue;
            }
            if (SystemHelper::directory_uuid("$sp_drive/$this->share") === SystemHelper::directory_uuid("$landing_zone")) {
                // We also to make sure this drive is 'unwind' first, to make sure it will have enough free space to receive all the file copies.
                array_unshift($storage_pool_drives_to_unwind, $sp_drive);
            } else {
                array_push($storage_pool_drives_to_unwind, $sp_drive);
            }
        }

        $this->log();
        $log = "Deleting symlinks from landing zone... This might take a while.";
        $this->log($log);
        Log::debug($log);
        exec("find " . escapeshellarg($landing_zone) . " -type l -delete");
        $this->log("  Done.");

        // Copy the data files from the storage pool into the landing zone
        $num_files_total = 0;
        foreach ($storage_pool_drives_to_unwind as $sp_drive) {
            unset($result);
            $log = "Moving files from $sp_drive/$this->share into $landing_zone... This might take a while.";
            $this->log($log);
            Log::debug($log);
            exec("rsync -rlptgoDuvW --remove-source-files " . escapeshellarg("$sp_drive/$this->share/") . " " . escapeshellarg('/' . trim($landing_zone, '/')), $result);
            $num_files = count($result)-5;
            if ($num_files < 0) {
                $num_files = 0;
            }
            $num_files_total += $num_files;
            exec("find " . escapeshellarg("$sp_drive/$this->share/") . " -type d -delete");
            $this->log("  Done. Copied $num_files files.");
        }
        $this->log("All done. Copied $num_files_total files.\n\nYou should now remove the Greyhole options from the [$this->share] share in your smb.conf.");

        ConfigHelper::removeShare($this->share);

        DBSpool::archive_task($this->id);

        Log::info("Share removal completed.");

        $this->sendEmail();

        DaemonRunner::restart_service();

        return TRUE;
    }

    protected function sendEmail() {
        // Email report
        $subject = "[Greyhole] Removal of share '$this->share' completed on " . exec('hostname');
        $email_to = Config::get(CONFIG_EMAIL_TO);
        Log::debug("Sending share removal report to $email_to");
        mail($email_to, $subject, $this->log);
    }

    protected function log($log = '') {
        $this->log .= "$log\n";
    }
}

?>
