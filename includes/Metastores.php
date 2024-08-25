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

final class Metastores {

    const METASTORE_DIR = '.gh_metastore';
    const METASTORE_BACKUP_DIR = '.gh_metastore_backup';

    /** @var string[] */
    private static $metastores = [];

    public static function get_metastores($use_cache=TRUE) {
        if (!$use_cache) {
            static::$metastores = [];
        }
        if (empty(static::$metastores)) {
            foreach (Config::storagePoolDrives() as $sp_drive) {
                if (StoragePool::is_pool_drive($sp_drive)) {
                    static::$metastores[] = $sp_drive . '/' . static::METASTORE_DIR;
                }
            }
            foreach (Config::get(CONFIG_METASTORE_BACKUPS) as $metastore_backup_drive) {
                $sp_drive = str_replace('/' . static::METASTORE_BACKUP_DIR, '', $metastore_backup_drive);
                if (StoragePool::is_pool_drive($sp_drive)) {
                    static::$metastores[] = $metastore_backup_drive;
                }
            }
        }
        return static::$metastores;
    }

    public static function choose_metastores_backups($try_restore=TRUE) {
        $num_metastore_backups_needed = 2;
        if (count(Config::storagePoolDrives()) < 2) {
            Config::set(CONFIG_METASTORE_BACKUPS, array());
            return;
        }

        Log::debug("Loading metadata store backup directories...");
        $metastore_backup_drives = Config::get(CONFIG_METASTORE_BACKUPS);
        if (empty($metastore_backup_drives)) {
            // In the DB ?
            $metastore_backup_drives = Settings::get('metastore_backup_directory', TRUE);
            if ($metastore_backup_drives) {
                Log::debug("  Found " . count($metastore_backup_drives) . " directories in the settings table.");
            } elseif ($try_restore) {
                // Try to load a backup from the data drive, if we can find one.
                if (Settings::restore()) {
                    static::choose_metastores_backups(FALSE);
                    return;
                }
            }
        }

        // Verify the drives, if any
        if (empty($metastore_backup_drives)) {
            $metastore_backup_drives = array();
        } else {
            foreach ($metastore_backup_drives as $key => $metastore_backup_drive) {
                if (!StoragePool::is_pool_drive(str_replace('/' . static::METASTORE_BACKUP_DIR, '', $metastore_backup_drive))) {
                    // Directory is now invalid; stop using it.
                    Log::debug("Removing $metastore_backup_drive from available 'metastore_backup_directories' - this directory isn't a Greyhole storage pool drive (anymore?)");
                    unset($metastore_backup_drives[$key]);
                } elseif (!is_dir($metastore_backup_drive)) {
                    // Directory is invalid, but needs to be created (was rm'ed?)
                    mkdir($metastore_backup_drive);
                }
            }
        }

        if (empty($metastore_backup_drives) || count($metastore_backup_drives) < $num_metastore_backups_needed) {
            Log::debug("  Missing some drives. Need $num_metastore_backups_needed, currently have " . count($metastore_backup_drives) . ". Will select more...");
            $metastore_backup_drives_hash = array();
            if (count($metastore_backup_drives) > 0) {
                $metastore_backup_drives_hash[array_shift($metastore_backup_drives)] = TRUE;
            }

            while (count($metastore_backup_drives_hash) < $num_metastore_backups_needed) {
                // Let's pick new one
                $metastore_backup_drive = ConfigHelper::randomStoragePoolDrive() . '/' . static::METASTORE_BACKUP_DIR;
                $metastore_backup_drives_hash[$metastore_backup_drive] = TRUE;
                if (!is_dir($metastore_backup_drive)) {
                    mkdir($metastore_backup_drive);
                }
                Log::debug("    Randomly picked $metastore_backup_drive");
            }
            $metastore_backup_drives = array_keys($metastore_backup_drives_hash);

            // Got 2 drives now; save them in the DB
            Settings::set('metastore_backup_directory', $metastore_backup_drives);
        }

        Config::set(CONFIG_METASTORE_BACKUPS, $metastore_backup_drives);
    }

    /**
     * Does the specified folder exist in any of the metastores?
     *
     * @param string $share
     * @param string $full_path
     *
     * @return bool
     */
    public static function dir_exists_in_metastores($share, $full_path) {
        foreach (static::get_metastores() as $metastore) {
            if (is_dir("$metastore/$share/$full_path")) {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Find the path of the metastore that contains the specified folder (or file).
     * Example: /path/to/hdd1/.gh_metastore/ShareName/dir1/ > /path/to/hdd1/.gh_metastore
     *
     * @param string $path Absolute path of directory (or file) inside a metastore.
     *
     * @return bool|string
     */
    public static function get_metastore_from_path($path) {
        $metastore_path = FALSE;
        foreach (static::get_metastores() as $metastore) {
            if (string_starts_with($path, $metastore)) {
                $metastore_path = $metastore;
                break;
            }
        }
        return $metastore_path;
    }

    public static function get_metastores_from_storage_volume($storage_volume) {
        $volume_metastores = array();
        foreach (static::get_metastores() as $metastore) {
            if (StoragePool::getDriveFromPath($metastore) == $storage_volume) {
                $volume_metastores[] = $metastore;
            }
        }
        return $volume_metastores;
    }

    /**
     * @param string $share
     * @param string $path
     * @param string $filename
     * @param bool   $first_only
     *
     * @return string[]
     */
    public static function get_metafile_data_filenames($share, $path, $filename, $first_only=FALSE) {
        $filenames = array();

        if ($first_only) {
            $share_file = get_share_landing_zone($share) . "/$path/$filename";
            if (is_link($share_file)) {
                $target = readlink($share_file);
                $first_metastore = str_replace(clean_dir("/$share/$path/$filename"), "", $target);
                $f = clean_dir("$first_metastore/" . static::METASTORE_DIR . "/$share/$path/$filename");
                if (is_file($f)) {
                    $filenames[] = $f;
                    return $filenames;
                }
            }
        }

        foreach (static::get_metastores() as $metastore) {
            $f = clean_dir("$metastore/$share/$path/$filename");
            if (is_file($f)) {
                $filenames[] = $f;
                if ($first_only) {
                    return $filenames;
                }
            }
        }
        return $filenames;
    }

    /**
     * @param string $share
     * @param string $path
     * @param string $filename
     *
     * @return string|false
     */
    public static function get_metafile_data_filename($share, $path, $filename) {
        $filenames = static::get_metafile_data_filenames($share, $path, $filename, TRUE);
        return first($filenames, FALSE);
    }

    /**
     * @param string      $share
     * @param string      $path
     * @param string|null $filename
     * @param bool        $load_nok_metafiles
     * @param bool        $quiet
     * @param bool        $check_symlink
     *
     * @return iterable
     */
    public static function get_metafiles($share, $path, $filename=NULL, $load_nok_metafiles=FALSE, $quiet=FALSE, $check_symlink=TRUE) {
        if ($filename === NULL) {
            // For a directory, we return an Iterator, because we don't want to load all metafiles from that folder in memory!
            return new metafile_iterator($share, $path, $load_nok_metafiles, $quiet, $check_symlink);
        } else {
            return array(static::get_metafiles_for_file($share, $path, $filename, $load_nok_metafiles, $quiet, $check_symlink));
        }
    }

    public static function get_metafiles_for_file($share, $path, $filename=NULL, $load_nok_metafiles=FALSE, $quiet=FALSE, $check_symlink=TRUE) {
        if (!$quiet) {
            Log::debug("Loading metafiles for " . clean_dir($share . (!empty($path) ? "/$path" : "") . "/$filename") . ' ...');
        }
        $metafiles_data_file = static::get_metafile_data_filename($share, $path, $filename);
        clearstatcache();
        $metafiles = array();
        if (file_exists($metafiles_data_file)) {
            $t = file_get_contents($metafiles_data_file);
            /** @var Metafile[] $metafiles */
            $metafiles = unserialize($t);
        }
        if (!is_array($metafiles)) {
            $metafiles = array();
        }

        if ($check_symlink) {
            // Fix wrong 'is_linked' flags
            $share_file = get_share_landing_zone($share) . "/$path/$filename";
            if (is_link($share_file)) {
                $share_file_link_to = readlink($share_file);
                if ($share_file_link_to !== FALSE) {
                    foreach ($metafiles as $key => $metafile) {
                        if ($metafile->state == Metafile::STATE_OK) {
                            if (@$metafile->is_linked && $metafile->path != $share_file_link_to) {
                                if (!$quiet) {
                                    Log::debug('  Changing is_linked to FALSE for ' . $metafile->path);
                                }
                                $metafile->is_linked = FALSE;
                                $metafiles[$key] = $metafile;
                                static::save_metafiles($share, $path, $filename, $metafiles);
                            } else if (empty($metafile->is_linked) && $metafile->path == $share_file_link_to) {
                                if (!$quiet) {
                                    Log::debug('  Changing is_linked to TRUE for ' . $metafile->path);
                                }
                                $metafile->is_linked = TRUE;
                                $metafiles[$key] = $metafile;
                                static::save_metafiles($share, $path, $filename, $metafiles);
                            }
                        }
                    }
                }
            }
        }

        $ok_metafiles = array();
        foreach ($metafiles as $key => $metafile) {
            $valid_path = FALSE;

            $drive = StoragePool::getDriveFromPath($metafile->path);
            if ($drive !== FALSE) {
                $valid_path = TRUE;
            }
            if ($valid_path && ($load_nok_metafiles || $metafile->state == Metafile::STATE_OK)) {
                $key = clean_dir($metafile->path);
                if (isset($ok_metafiles[$key])) {
                    $previous_metafile = $ok_metafiles[$key];
                    if ($previous_metafile->state == Metafile::STATE_OK && $metafile->state != Metafile::STATE_OK) {
                        // Don't overwrite previous OK metafiles with NOK metafiles that point to the same files!
                        continue;
                    }
                }
                $ok_metafiles[$key] = $metafile;
            } else {
                if (!$valid_path && $metafile->state != Metafile::STATE_GONE) {
                    Log::warn("Found a metadata file pointing to a drive not defined in your storage pool: '$metafile->path'. Will mark it as Gone.", Log::EVENT_CODE_METADATA_POINTS_TO_GONE_DRIVE);
                    $metafile->state = Metafile::STATE_GONE;
                    $metafiles[$key] = $metafile;
                    static::save_metafiles($share, $path, $filename, $metafiles);
                }
            }
        }
        $metafiles = $ok_metafiles;

        if (!$quiet) {
            Log::debug("  Got " . count($metafiles) . " metadata files.");
        }
        return $metafiles;
    }

    public static function create_metafiles($share, $full_path, $num_copies_required, $filesize, $metafiles=[]) {
        $found_link_metafile = FALSE;

        list($path, ) = explode_full_path($full_path);

        $num_ok = count($metafiles);
        foreach ($metafiles as $key => $metafile) {
            $sp_drive = str_replace(clean_dir("/$share/$full_path"), '', $metafile->path);
            if (!StoragePool::is_pool_drive($sp_drive)) {
                $metafile->state = Metafile::STATE_GONE;
            }

            // Check free space!
            $df = StoragePool::get_free_space($sp_drive);
            if (!$df) {
                $free_space = 0;
            } else {
                $free_space = $df['free'];
            }
            if ($free_space <= $filesize/1024) {
                $metafile->state = Metafile::STATE_GONE;
            }

            if ($metafile->state != Metafile::STATE_OK && $metafile->state != Metafile::STATE_PENDING) {
                $num_ok--;
            }
            if ($key != $metafile->path) {
                unset($metafiles[$key]);
                $key = $metafile->path;
            }
            if ($metafile->is_linked) {
                $found_link_metafile = TRUE;
            }
            $metafiles[$key] = $metafile;
        }

        // Select drives that have enough free space for this file
        if ($num_ok < $num_copies_required) {
            $target_drives = StoragePool::choose_target_drives($filesize/1024, FALSE, $share, $path, '  ');
        }
        /** @noinspection PhpUndefinedVariableInspection */
        while ($num_ok < $num_copies_required && count($target_drives) > 0) {
            $sp_drive = array_shift($target_drives);
            $clean_target_full_path = clean_dir("$sp_drive/$share/$full_path");
            // Don't use drives that already have a copy
            if (isset($metafiles[$clean_target_full_path])) {
                continue;
            }
            foreach ($metafiles as $metafile) {
                if ($clean_target_full_path == clean_dir($metafile->path)) {
                    continue;
                }
            }
            // Prepend new target drives, to make sure sticky directories will be used first
            $metafiles = array_reverse($metafiles);
            $metafiles[$clean_target_full_path] = (object) array('path' => $clean_target_full_path, 'is_linked' => FALSE, 'state' => Metafile::STATE_PENDING);
            $metafiles = array_reverse($metafiles);
            $num_ok++;
        }

        if (!$found_link_metafile) {
            foreach ($metafiles as $metafile) {
                $metafile->is_linked = TRUE;
                break;
            }
        }

        return $metafiles;
    }

    public static function save_metafiles($share, $path, $filename, $metafiles) {
        if (count($metafiles) == 0) {
            static::remove_metafiles($share, $path, $filename);
            return;
        }

        // We don't care about the keys (we'll re-create them on load), so let's not waste disk space, and use numeric indexes.
        $metafiles = array_values($metafiles);

        Log::debug("  Saving " . count($metafiles) . " metadata files for " . clean_dir($share . (!empty($path) ? "/$path" : "") . ($filename!== null ? "/$filename" : "")));
        $paths_used = array();
        foreach (static::get_metastores() as $metastore) {
            $sp_drive = str_replace('/' . static::METASTORE_DIR, '', $metastore);
            $data_filepath = clean_dir("$metastore/$share/$path");
            $has_metafile = FALSE;
            foreach ($metafiles as $metafile) {
                if (StoragePool::getDriveFromPath($metafile->path) == $sp_drive && StoragePool::is_pool_drive($sp_drive)) {
                    gh_mkdir($data_filepath, get_share_landing_zone($share) . "/$path");
                    //Log::debug("    Saving metadata in " . clean_dir("$data_filepath/$filename"));
                    if (is_dir("$data_filepath/$filename")) {
                        exec("rm -rf " . escapeshellarg("$data_filepath/$filename"));
                    }
                    $worked = @file_put_contents("$data_filepath/$filename", serialize($metafiles));
                    if ($worked === FALSE) {
                        // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
                        $worked = @file_put_contents(normalize_utf8_characters("$data_filepath/$filename"), serialize($metafiles));
                        if ($worked !== FALSE) {
                            // Bingo!
                            $data_filepath = normalize_utf8_characters($data_filepath);
                            $filename = normalize_utf8_characters($filename);
                        } else {
                            Log::warn("  Failed to save metadata file in $data_filepath/$filename", Log::EVENT_CODE_NO_METADATA_SAVED);
                        }
                    }
                    $has_metafile = TRUE;
                    $paths_used[] = $data_filepath;
                    break;
                }
            }
            if (!$has_metafile && file_exists("$data_filepath/$filename")) {
                if (is_dir("$data_filepath/$filename")) {
                    // Was a folder before, is now a file
                    rmdir("$data_filepath/$filename");
                } else {
                    unlink("$data_filepath/$filename");
                }
            }
        }
        if (count($paths_used) == 1) {
            // Also save a backup on another drive
            $metastore_backup_drives = Config::get(CONFIG_METASTORE_BACKUPS);
            if (!empty($metastore_backup_drives)) {
                if (!string_contains($paths_used[0], str_replace(static::METASTORE_BACKUP_DIR, static::METASTORE_DIR, $metastore_backup_drives[0]))) {
                    $metastore_backup_drive = $metastore_backup_drives[0];
                } else {
                    $metastore_backup_drive = $metastore_backup_drives[1];
                }
                $data_filepath = "$metastore_backup_drive/$share/$path";
                Log::debug("    Saving backup metadata file in $data_filepath/$filename");
                if (gh_mkdir($data_filepath, get_share_landing_zone($share) . "/$path")) {
                    if (!@file_put_contents("$data_filepath/$filename", serialize($metafiles))) {
                        Log::warn("  Failed to save backup metadata file in $data_filepath/$filename", Log::EVENT_CODE_NO_METADATA_SAVED);
                    }
                }
            }
        }
    }

    public static function remove_metafiles($share, $path, $filename) {
        Log::debug("  Removing metadata files for $share" . (!empty($path) && $path != '.' ? "/$path" : "") . ($filename!== null ? "/$filename" : ""));
        foreach (static::get_metafile_data_filenames($share, $path, $filename) as $f) {
            @unlink($f);
            Log::debug("    Removed metadata file at $f");
            clearstatcache();
        }
    }

}

class Metafile extends stdClass {
    const STATE_OK = 'OK';
    const STATE_GONE = 'Gone';
    const STATE_PENDING = 'Pending';

    /** @var string Absolute path pointing to a file copy. */
    public $path;
    /** @var string OK, Gone */
    public $state;
    /** @var bool Is this file copy the one used as the target of the symlink on the LZ? */
    public $is_linked;
}

class metafile_iterator implements Iterator {
    private $path;
    private $share;
    private $load_nok_metafiles;
    private $quiet;
    private $check_symlink;
    private $metafiles;
    private $metastores;
    private $dir_handle;
    private $directory_stack;

    public function __construct($share, $path, $load_nok_metafiles=FALSE, $quiet=FALSE, $check_symlink=TRUE) {
        $this->quiet = $quiet;
        $this->share = $share;
        $this->path = $path;
        $this->check_symlink = $check_symlink;
        $this->load_nok_metafiles = $load_nok_metafiles;
    }

    #[\ReturnTypeWillChange]
    public function rewind() {
        $this->metastores = Metastores::get_metastores();
        $this->directory_stack = array($this->path);
        $this->dir_handle = NULL;
        $this->metafiles = array();
        $this->next();
    }

    #[\ReturnTypeWillChange]
    public function current() {
        return $this->metafiles;
    }

    #[\ReturnTypeWillChange]
    public function key() {
        return count($this->metafiles);
    }

    #[\ReturnTypeWillChange]
    public function next() {
        $this->metafiles = array();
        while (count($this->directory_stack) > 0 && $this->directory_stack !== NULL) {
            $dir = array_pop($this->directory_stack);
            if (!$this->quiet) {
                Log::debug("Loading metadata files for (dir) " . clean_dir($this->share . (!empty($dir) ? "/" . $dir : "")) . " ...");
            }
            for ($i = 0; $i < count($this->metastores); $i++) {
                $metastore = $this->metastores[$i];
                $base = "$metastore/" . $this->share . "/";
                if (!file_exists($base . $dir)) {
                    continue;
                }
                if ($this->dir_handle = opendir($base . $dir)) {
                    while (false !== ($file = readdir($this->dir_handle))) {
                        memory_check();
                        if ($file=='.' || $file=='..') {
                            continue;
                        }
                        if (!empty($dir)) {
                            $full_filename = $dir . '/' . $file;
                        } else {
                            $full_filename = $file;
                        }
                        if (is_dir($base . $full_filename)) {
                            $this->directory_stack[] = $full_filename;
                        } else {
                            $full_filename = str_replace("$this->path/",'',$full_filename);
                            if (isset($this->metafiles[$full_filename])) {
                                continue;
                            }
                            $this->metafiles[$full_filename] = Metastores::get_metafiles_for_file($this->share, $dir, $file, $this->load_nok_metafiles, $this->quiet, $this->check_symlink);
                        }
                    }
                    closedir($this->dir_handle);
                    $this->directory_stack = array_unique($this->directory_stack);
                }
            }
            if (count($this->metafiles) > 0) {
                break;
            }

        }
        if (!$this->quiet) {
            Log::debug('Found ' . count($this->metafiles) . ' metadata files.');
        }
        return $this->metafiles;
    }

    #[\ReturnTypeWillChange]
    public function valid() {
        return count($this->metafiles) > 0;
    }
}

?>
