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

class RmdirTask extends AbstractTask {

    public function execute() {
        $share = $this->share;
        $full_path = $this->full_path;

        $landing_zone = get_share_landing_zone($share);
        if (!$landing_zone) {
            return TRUE;
        }

        Log::info("Directory deleted: $landing_zone/$full_path");

        if (array_contains(ConfigHelper::$trash_share_names, $share)) {
            // Remove that directory from all trashes
            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (@rmdir("$sp_drive/.gh_trash/$full_path")) {
                    Log::debug("  Removed copy from trash at $sp_drive/.gh_trash/$full_path");
                }
            }
            return TRUE;
        }

        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (@rmdir("$sp_drive/$share/$full_path/")) {
                Log::debug("  Removed copy at $sp_drive/$share/$full_path");
            }
            $metastore = "$sp_drive/" . Metastores::METASTORE_DIR;
            if (@rmdir("$metastore/$share/$full_path/")) {
                Log::debug("  Removed metadata files directory $metastore/$share/$full_path");
            }
        }

        FileHook::trigger(FileHook::EVENT_TYPE_RMDIR, $share, $full_path);

        return TRUE;
    }

}

?>
