<?php
/*
Copyright 2009-2014 Guillaume Boudreau, Andrew Hopkinson

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

class GetGUIDCliRunner extends AbstractAnonymousCliRunner {
    public function run() {
        $this->logn(GetGUIDCliRunner::setUniqID());
    }

    // This unique identifier will be used when Greyhole calls home, to uniquely (and anonymously) identify this Greyhole instance.
    public static function setUniqID() {
        $uniq_id = Settings::get('uniq_id');

        if (!$uniq_id) {
            // No uid found in DB; look on filesystem
            foreach (Config::storagePoolDrives() as $sp_drive) {
                $f = "$sp_drive/.greyhole_uses_this";
                if (file_exists($f) && filesize($f) == 23) {
                    // Found a valid uid
                    $uniq_id = file_get_contents($f);
                    break;
                }
            }

            if (!$uniq_id) {
                // No uid found; generate a new one
                $uniq_id = uniqid('', TRUE);
            }

            Settings::set('uniq_id', $uniq_id);
        }

        return $uniq_id;
    }
}

?>
