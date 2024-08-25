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
        $source_landing_zone = get_share_landing_zone($source_share);
        $source_is_file = is_file("$source_landing_zone/$full_path");
        if (!$source_is_file) {
            $source_is_dir = is_dir("$source_landing_zone/$full_path");
            if (!$source_is_dir) {
                echo "Error: source does not exist.\n";
                exit(2);
            }
            // Will work from source dir
            chdir("$source_landing_zone/$full_path");
        }

        $paths = explode('/', $destination);
        array_shift($paths); // Remove share name
        $full_path = implode('/', $paths);
        $landing_zone = get_share_landing_zone($destination_share);
        $destination_folder_exists = is_dir("$landing_zone/$full_path");

        if ($source_is_file) {
            if ($destination_folder_exists) {
                // mv Share1/dir1/file1 Share2/dir2/
                $destination = clean_dir("$destination/" . basename($source));
            } else {
                // mv Share1/dir1/file1 Share2/dir2/file2
            }

            static::move_file($source, $destination);
            exit(0);
        } else {
            // $source_is_dir
            $source = trim($source, '/');
            if ($destination_folder_exists) {
                // mv Share1/dir1 Share2/
                $destination = clean_dir("$destination/" . basename($source));
            } else {
                // mv Share1/dir1 Share2/dir2
            }

            exec("find . -type f -o -type l", $all_files);
            foreach ($all_files as $file) {
                $source_file = clean_dir($source . '/' . substr($file, 2));
                $destination_file = clean_dir($destination . '/' . substr($file, 2));
                static::move_file($source_file, $destination_file);
            }

            if ($source_is_dir) {
                // Trash empty folders
                $folder_to_delete = getcwd();
                exec("find " . escapeshellarg($folder_to_delete) . " -type d", $folders_to_delete);
                foreach ($folders_to_delete as $dir) {
                    if ($dir === $folder_to_delete) continue; // Will be deleted last
                    static::rmdir($source_share, $source_landing_zone, $dir);
                }
                static::rmdir($source_share, $source_landing_zone, $folder_to_delete);
            }
        }
    }

    protected static function rmdir($source_share, $source_landing_zone, $full_path_in_lz) {
        $full_path = trim(str_replace($source_landing_zone, '', $full_path_in_lz), '/');
        echo "[INFO] Deleting empty folder: $source_share/$full_path\n";
        rmdir("$source_landing_zone/$full_path");
        $task = AbstractTask::instantiate([
            'action'    => 'rmdir',
            'share'     => $source_share,
            'full_path' => $full_path,
        ]);
        $task->execute();
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
            unlink($metafile);
        }

        // Remove (possibly) empty folders in metastores
        foreach ($metafiles as $metafile) {
            @rmdir(dirname($metafile));
        }

        // Create new metafiles
        $fsck_task = new FsckTask(array('additional_info' => OPTION_ORPHANED));
        foreach ($sp_drives_affected as $sp_drive) {
            $full_path = "$sp_drive/$destination_share/$destination_full_path";
            list($path, $filename) = explode_full_path($full_path);
            $fsck_task->initialize_fsck_report($full_path);
            $fsck_task->gh_fsck_file($path, $filename, 'file', 'mv', $destination_share, $sp_drive);

            // Running gh_fsck_file() on one file will find all file copies; no need to run it for each $sp_drive
            break;
        }
    }

    protected static function move_real_file($source, $destination) {
        $target_folder = dirname($destination);
        if (!file_exists($target_folder)) {
            $source_folder = dirname($source);
            gh_mkdir($target_folder, $source_folder);
        }
        if (file_exists($destination)) {
            echo "[WARN] $destination already exists. Will rename to $destination.bak before continuing.\n";
            rename($destination, "$destination.bak");
        }
        rename($source, $destination);
    }
}

?>
