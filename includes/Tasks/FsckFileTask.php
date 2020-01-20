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

require_once('includes/Tasks/FsckTask.php');

class FsckFileTask extends FsckTask {

    public function execute() {
        $this->full_path = get_share_landing_zone($this->share) . '/' . $this->full_path;
        $file_type = @filetype($this->full_path);
        list($path, $filename) = explode_full_path($this->full_path);
        FSCKLogFile::loadFSCKReport('Missing files', $this); // Create or load the fsck_report from disk

        $this->gh_fsck_file($path, $filename, $file_type, 'metastore', $this->share);

        $send_email = $this->has_option(OPTION_EMAIL);
        if ($send_email || Hook::hasHookForEvent(LogHook::EVENT_TYPE_FSCK)) {
            // Save the report to disk to be able to email it when we're done with all fsck_file tasks
            FSCKLogFile::saveFSCKReport($send_email, $this);
        }
        return TRUE;
    }

}

?>
