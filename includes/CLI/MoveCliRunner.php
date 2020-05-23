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

require_once('includes/CLI/AbstractCliRunner.php');

class MoveCliRunner extends AbstractCliRunner {
    public function run() {
        Log::setAction(ACTION_INITIALIZE);
        Metastores::choose_metastores_backups();
        Log::setAction(ACTION_MOVE);

        $argc = $GLOBALS['argc'];
        $argv = $GLOBALS['argv'];

        if ($argc != 4) {
            echo "Usage: greyhole --mv source target\n  `source` and `target` should start with a share name, following by the full path to a file or folder.\n\nExample:\n  greyhole --mv Videos/TV/24 VideosAttic/TV/\n";
            exit(1);
        }

        $source = $argv[2];
        $destination = $argv[3];

        foreach (SharesConfig::getShares() as $share_name => $share_options) {
            if ($share_name == first(explode('/', $source))) {
                $source_share = $share_name;
            }
            if ($share_name == first(explode('/', $destination))) {
                $destination_share = $share_name;
            }
        }
        if (!isset($source_share)) {
            echo "Error: source ($source) doesn't start with a Greyhole share name.\n";
            exit(2);
        }
        if (!isset($destination_share)) {
            echo "Error: destination ($destination) doesn't start with a Greyhole share name.\n";
            exit(2);
        }
        if ($source_share == $destination_share) {
            echo "Error: source and destination are on the same Greyhole share.\n";
            exit(2);
        }

        $paths = explode('/', $source);
        array_shift($paths); // Remove share name
        $full_path = implode('/', $paths);
        $landing_zone = get_share_landing_zone($source_share);
        $source_is_file = is_file("$landing_zone/$full_path");
        //echo "[DEBUG] source_is_file: " . json_encode($source_is_file) . "\n";
        if (!$source_is_file) {
            $source_is_dir = is_dir("$landing_zone/$full_path");
            //echo "[DEBUG] source_is_dir: " . json_encode($source_is_dir) . "\n";
            if (!$source_is_file && !$source_is_dir) {
                echo "Error: source does not exist.\n";
                exit(2);
            }
            // Will work from source dir
            chdir("$landing_zone/$full_path");
        }

        $paths = explode('/', $destination);
        array_shift($paths); // Remove share name
        $full_path = implode('/', $paths);
        $landing_zone = get_share_landing_zone($destination_share);
        $destination_folder_exists = is_dir("$landing_zone/$full_path");
        //echo "[DEBUG] destination_folder_exists: " . json_encode($destination_folder_exists) . "\n";

        if ($source_is_file) {
            if ($destination_folder_exists) {
                // mv Share1/dir1/file1 Share2/dir2/
                $destination = clean_dir("$destination/" . basename($source));
                //echo "[DEBUG] destination: $destination" . "\n";
            } else {
                // mv Share1/dir1/file1 Share2/dir2/file2
                //echo "[DEBUG] destination: $destination" . "\n";
            }

            static::move_file($source, $destination);
            exit(0);
        } else {
            // $source_is_dir
            $source = trim($source, '/');
            if ($destination_folder_exists) {
                // mv Share1/dir1 Share2/
                $destination = clean_dir("$destination/" . basename($source));
                //echo "[DEBUG] destination: $destination" . "\n";
            } else {
                // mv Share1/dir1 Share2/dir2
                //echo "[DEBUG] destination: $destination" . "\n";
            }

            exec("find . -type f -o -type l", $all_files);
            foreach ($all_files as $file) {
                $source_file = clean_dir($source . '/' . substr($file, 2));
                $destination_file = clean_dir($destination . '/' . substr($file, 2));
                static::move_file($source_file, $destination_file);
            }

            // Trash empty folders
            //echo "[DEBUG] delete (empty folders): $source" . "\n";
            $folder_to_delete = getcwd();
            exec("find " . escapeshellarg($folder_to_delete) . " -type d -exec rmdir {} \; 2>/dev/null");
            @rmdir($folder_to_delete);
        }
    }

    protected static function move_file($source, $destination) {
        echo "[INFO] Moving file: $source > $destination\n";

        $source_parts = explode('/', $source);
        $source_share = array_shift($source_parts);
        $source_full_path = implode('/', $source_parts);
        $source_landing_zone = get_share_landing_zone($source_share);

        $destination_parts = explode('/', $destination);
        $destination_share = array_shift($destination_parts);
        $destination_full_path = implode('/', $destination_parts);
        //$destination_landing_zone = get_share_landing_zone($destination_share);

        $sp_drives_affected = array();
        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (file_exists("$sp_drive/$source")) {
                static::move_real_file("$sp_drive/$source", "$sp_drive/$destination");
                $sp_drives_affected[] = $sp_drive;
            }
        }

        // Remove old symlink
        if (is_link("$source_landing_zone/$source_full_path")) {
            unlink("$source_landing_zone/$source_full_path");
        }

        // Remove old metafiles
        list($path, $filename) = explode_full_path($source_full_path);
        $metafiles = Metastores::get_metafile_data_filenames($source_share, $path, $filename);
        foreach ($metafiles as $metafile) {
            //echo "[DEBUG] delete: $metafile\n";
            unlink($metafile);
        }

        // Remove (possibly) empty folders in metastores
        foreach ($metafiles as $metafile) {
            //echo "[DEBUG] rmdir: " . dirname($metafile) . "\n";
            @rmdir(dirname($metafile));
        }

        // Create new metafiles
        $fsck_task = new FsckTask(array('additional_info' => OPTION_ORPHANED));
        foreach ($sp_drives_affected as $sp_drive) {
            $full_path = "$sp_drive/$destination_share/$destination_full_path";
            list($path, $filename) = explode_full_path($full_path);
            $fsck_task->initialize_fsck_report($full_path);
            //echo "[DEBUG] gh_fsck_file: $path, $filename, 'file', 'mv', $destination_share, $sp_drive\n";
            $fsck_task->gh_fsck_file($path, $filename, 'file', 'mv', $destination_share, $sp_drive);

            // Running gh_fsck_file() on one file will find all file copies; no need to run it for each $sp_drive
            break;
        }
    }

    protected static function move_real_file($source, $destination) {
        $target_folder = dirname($destination);
        if (!file_exists($target_folder)) {
            $source_folder = dirname($source);
            //echo "[DEBUG] create folder: $target_folder\n";
            gh_mkdir($target_folder, $source_folder);
        }
        if (file_exists($destination)) {
            echo "[WARN] $destination already exists. Will rename to $destination.bak before continuing.\n";
            rename($destination, "$destination.bak");
        }
        //echo "[DEBUG] rename: $source > $destination\n";
        rename($source, $destination);
    }
}

?>
