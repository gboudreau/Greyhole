<?php
/*
Copyright 2020 Guillaume Boudreau

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

class LinkTask extends WriteTask {

    public function execute() {
        $share = $this->share;
        $full_path = $this->full_path;
        $target_full_path = $this->additional_info;

        $landing_zone = get_share_landing_zone($share);
        if (!$landing_zone) {
            return TRUE;
        }

        if ($this->should_ignore_file()) {
            return TRUE;
        }

        Log::info("File (hard)linked: $landing_zone/$target_full_path -> $landing_zone/$full_path");
        $this->full_path = $target_full_path;

        parent::execute();

        return TRUE;
    }

    public function should_ignore_file($share = NULL, $full_path = NULL) {
        // We ignore this task, if the target is ignored, whatever the source ($this->full_path) is
        if (empty($full_path)) {
            $full_path = $this->additional_info;
        }
        return parent::should_ignore_file($share, $full_path);
    }

}

?>
