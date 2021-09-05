<?php
/*
Copyright 2021 Guillaume Boudreau

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

class CopyCliRunner extends AbstractCliRunner {
    protected $source;
    protected $share_name;
    protected $target;

    public function run() {
        ConfigHelper::parse();
        DB::connect();
        Log::setAction(ACTION_INITIALIZE);
        Metastores::choose_metastores_backups();
        Log::setAction(ACTION_CP);
        Log::setLevel(Log::INFO);

        $argc = $GLOBALS['argc'];
        $argv = $GLOBALS['argv'];

        if (basename($argv[0]) == 'cpgh') {
            $num_required_args = 3;
        } else {
            $num_required_args = 4;
        }

        if ($argc != $num_required_args) {
            error_log(
                "\n"
                . "Usage: cpgh source share_name/target/dir/\n"
                . "       greyhole --cp source share_name/target/dir/\n"
                . "\n"
                . "Examples: cpgh \"Some Movie (2021)\" Videos/Movies/\n"
                . "          greyhole --cp \"Something Large\" Backups/\n"
                . "\n"
                . "`cpgh` is used to ADD files onto your Greyhole storage pool, without going through Samba.\n"
                . "\n"
                . "Instead of copying the files into a Samba share, and letting the Greyhole daemon then\n"
                . "  move the files into one of your storage pool drives, this command will copy the SOURCE\n"
                . "  files directly into a storage pool drive.\n"
                . "  It will also create extra copies of those files, if the TARGET share is configured with\n"
                . "  num_copies > 1.\n"
            );
            exit(1);
        }

        $source = $argv[$num_required_args-2];
        $target = $argv[$num_required_args-1];

        if (!file_exists($source)) {
            error_log("cpgh: cannot access '$source': No such file or directory");
            if (getenv('IN_DOCKER')) {
                error_log("\nNote that since you are using Docker, the 'source' file/folder needs to be accessible within the Docker container.");
            }
            exit(2);
        }

        $target = explode('/', $target);
        $share_name = array_shift($target);
        $target = implode('/', $target) . '/' . basename($source);
        if (is_dir($source)) {
            $target .= '/';
        }

        if (!SharesConfig::exists($share_name)) {
            error_log("cpgh: target '$share_name' is not a valid Samba/Greyhole share.");
            exit(3);
        }

        echo "Source" . (is_dir($source) ? ' (folder)' : '') . ": $source\n";
        echo "Target share: $share_name\n";
        echo "Target in share: $target\n";

        $this->source = $source;
        $this->share_name = $share_name;
        $this->target = $target;

        // No need to check for locks, since we copy the files
        Config::set(CONFIG_CHECK_FOR_OPEN_FILES, FALSE);

        if (is_dir($source)) {
            static::glob_dir($source);
        } else {
            static::copy_file($source);
        }
    }

    protected function copy_file($file) {
        $file = clean_dir($file);
        $target_full_path = $this->target . str_replace($this->source, '', $file);
        $target_full_path = clean_dir(trim($target_full_path, '/'));
        $t = new WriteTask(
            [
                'id'              => 0,
                'action'          => 'write',
                'share'           => $this->share_name,
                'full_path'       => $target_full_path,
                'additional_info' => 'source:' . $file,
                'complete'        => 'yes',
                'event_date'      => date('Y-m-d H:i:s'),
            ]
        );
        echo "\n";
        $t->execute();
    }

    protected function glob_dir($dir) {
        $dir = new DirectoryIterator($dir);
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }
            $file = $fileinfo->getPathname();
            if ($fileinfo->isDir()) {
                static::glob_dir($file);
            } else {
                static::copy_file($file);
            }
        }
    }
}

?>
