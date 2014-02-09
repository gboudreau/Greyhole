<?php
/*
Copyright 2009-2012 Guillaume Boudreau, Andrew Hopkinson

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

class DeleteMetadataCliRunner extends AbstractCliRunner {
    private $dir;

    function __construct($options) {
        parent::__construct($options);
        if (!isset($this->options['cmd_param'])) {
            $this->log("Please specify the path to a file that is gone from your storage pool. Eg. 'Movies/HD/The Big Lebowski.mkv'");
            $this->finish(1);
        }
        $this->dir = $this->options['cmd_param'];
    }

    public function run() {
        $share = trim(mb_substr($this->dir, 0, mb_strpos($this->dir, '/')+1), '/');
        $full_path = trim(mb_substr($this->dir, mb_strpos($this->dir, '/')+1), '/');
        list($path, $filename) = explode_full_path($full_path);
        set_metastore_backup();
        foreach (get_metafile_data_filenames($share, $path, $filename) as $file) {
            if (file_exists($file)) {
                $this->log("Deleting $file");
                unlink($file);
            }
        }
        $this->log("Done.");
    }
}

?>
