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

class ThawCliRunner extends AbstractCliRunner {
    private $dir;

    function __construct($options) {
        parent::__construct($options);

        $frozen_directories = Config::get(CONFIG_FROZEN_DIRECTORY);

        if (isset($this->options['cmd_param'])) {
            $this->dir = $this->options['cmd_param'];
            if (array_search($this->dir, $frozen_directories) === FALSE) {
                $this->dir = '/' . trim($this->dir, '/');
            }
        }

        if (empty($this->dir) || array_search($this->dir, $frozen_directories) === FALSE) {
            $this->printUsage();
        }
    }
    
    private function printUsage() {
        $this->log("Frozen directories:");
        $this->log("  " . implode("\n  ", Config::get(CONFIG_FROZEN_DIRECTORY)));
        $this->log("To thaw any of the above directories, use the following command:");
        $this->log("  greyhole --thaw=directory");
        $this->finish(0);
    }

    public function run() {
        $path = explode('/', $this->dir);
        $share = array_shift($path);
        $query = "UPDATE tasks SET complete = 'thawed' WHERE complete = 'frozen' AND share = :share AND full_path LIKE :path";
        DB::execute($query, array('share' => $share, 'path' => implode('/', $path).'%'));
        $this->log("$this->dir directory has been thawed.");
        $this->log("All pasts file operations that occured in this directory will now be processed by Greyhole.");
    }
}

?>
