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

class FsckTask extends AbstractTask {

    protected static $current_task;

    /** @var FSCKReport */
    private $fsck_report;

    public function __construct($task) {
        parent::__construct($task);
        static::$current_task = $this;
    }

    /**
     * @param stdClass|array $task
     *
     * @return self
     */
    public static function getCurrentTask($task = array()) {
        if (empty(static::$current_task)) {
            static::$current_task = new self($task);
        }
        return static::$current_task;
    }

    public function execute() {
        $new_conf_hash = static::get_conf_hash();
        if ($this->has_option(OPTION_IF_CONF_CHANGED)) {
            // Let's check if the conf file changed since the last fsck

            // Last value
            $last_hash = Settings::get('last_fsck_conf_md5');

            // New value
            if ($new_conf_hash == $last_hash) {
                Log::info("Skipping fsck; --if-conf-changed was specified, and the configuration file didn't change since the last fsck.");
                return TRUE;
            }
        }

        $fscked_dir = $this->share;

        $where_clause = "";
        $params = array();

        $max_lz_length = 0;
        foreach (SharesConfig::getShares() as $share_name => $share_options) {
            if (strpos($fscked_dir, $share_options[CONFIG_LANDING_ZONE]) === 0 && strlen($share_options[CONFIG_LANDING_ZONE]) > $max_lz_length) {
                $max_lz_length = strlen($share_options[CONFIG_LANDING_ZONE]);
                $where_clause = "AND share = :share";
                $params['share'] = $share_name;
            }
        }

        // First, let's remove all md5 tasks that would be duplicates of the ones we'll create during this fsck
        DB::execute("DELETE FROM tasks WHERE action = 'md5' $where_clause", $params);

        // Second, let's make sure all fsck_file tasks marked idle get executed.
        $query = "UPDATE tasks SET complete = 'yes' WHERE action = 'fsck_file' AND complete = 'idle' AND id < $this->id $where_clause";
        DB::execute($query, $params);
        $num_updated_rows = DB::getFirstValue("SELECT COUNT(*) AS num_updated_rows FROM tasks WHERE action = 'fsck_file' AND complete = 'yes' AND id < $this->id $where_clause", $params);
        if ($num_updated_rows > 0) {
            // Updated some fsck_file to complete; let's just return here, to allow them to be executed first.
            Log::info("Will execute all ($num_updated_rows) pending fsck_file operations for $fscked_dir before running this fsck (task ID $this->id).");
            return FALSE;
        }

        Log::info("Starting fsck for $fscked_dir");
        FSCKWorkLog::startTask($this->id);
        $this->initialize_fsck_report($fscked_dir);
        clearstatcache();

        if ($this->has_option(OPTION_CHECKSUMS)) {
            // Spawn md5 worker threads; those will calculate files MD5, and save the result in the DB.
            // The Greyhole daemon will then read those, and check them against each other to make sure all is fine.
            $checksums_thread_ids = Md5Task::spawn_threads_for_pool_drives();
            Log::debug("Spawned " . count($checksums_thread_ids) . " worker threads to calculate MD5 checksums. Will now wait for results, and check them as they come in.");
        }

        $storage_volume = FALSE;
        $share_options = SharesConfig::getShareOptions($fscked_dir);
        if ($share_options === FALSE) {
            // Since share_options is FALSE we didn't get a share path, maybe we got a storage volume path, let's check
            $storage_volume = StoragePool::getDriveFromPath($fscked_dir);
            $share_options = SharesConfig::getShareOptionsFromDrive($fscked_dir, $storage_volume);
        }
        Log::debug("  Storage volume? " . ($storage_volume ? $storage_volume : 'No'));
        Log::debug("  Share? " . ($share_options ? $share_options['name'] : 'No'));

        if ($share_options === FALSE) {
            if ($storage_volume !== FALSE) {
                // fsck a full storage pool drive
                foreach (SharesConfig::getShares() as $share_name => $share_options) {
                    $this->gh_fsck("$storage_volume/$share_name", $share_name, $storage_volume);
                }
            } else {
                Log::error("Unknown folder to fsck. You should specify a storage pool folder, a metadata store folder, a shared folder, or a subdirectory of any of those.", Log::EVENT_CODE_FSCK_UNKNOWN_FOLDER);
            }
        } else {
            $share = $share_options['name'];
            $metastore = Metastores::get_metastore_from_path($fscked_dir);

            if ($storage_volume === FALSE && $metastore === FALSE) {
                $fsck_type = FSCK_TYPE_SHARE;
            } else if ($storage_volume !== FALSE) {
                $fsck_type = FSCK_TYPE_STORAGE_POOL_DRIVE;
            } else {
                $fsck_type = FSCK_TYPE_METASTORE;
            }

            // Only calculate du stats if the user specified a folder in a share
            if ($fsck_type == FSCK_TYPE_SHARE) {
                $subdir = trim(str_replace($share_options[CONFIG_LANDING_ZONE], '', $fscked_dir), '/');
                $this->gh_fsck_reset_du($share, $subdir);
            }

            // Only kick off an fsck on the passed dir if it's not a metastore; that will be handled below.
            if ($fsck_type != FSCK_TYPE_METASTORE) {
                $this->gh_fsck($fscked_dir, $share, $storage_volume);
            }

            Log::debug("  Scan metadata stores? " . ($this->has_option(OPTION_SKIP_METASTORE) ? 'No' : 'Yes'));
            if ($this->has_option(OPTION_SKIP_METASTORE) === FALSE) {
                if ($fsck_type == FSCK_TYPE_METASTORE) {
                    // This is a metastore directory, so only kick off a metastore fsck for the indicated directory (this will not fsck the corresponding metastore path on other volumes)
                    $subdir = str_replace("$metastore", '', $fscked_dir);
                    Log::debug("Starting metastore fsck for $metastore/$subdir");
                    $this->gh_fsck_metastore($metastore, $subdir, $share);
                } else {
                    // This isn't a metastore dir so we'll check the metastore of this path on all volumes
                    if ($fsck_type == FSCK_TYPE_STORAGE_POOL_DRIVE) {
                        $subdir = str_replace($storage_volume, '', $fscked_dir);
                    } else {
                        $subdir = "/$share" . str_replace($share_options[CONFIG_LANDING_ZONE], '', $fscked_dir);
                    }
                    Log::debug("Starting metastores fsck for $subdir");
                    foreach (Metastores::get_metastores() as $metastore) {
                        $this->gh_fsck_metastore($metastore, $subdir, $share);
                    }
                }
            }
            if ($fsck_type != FSCK_TYPE_STORAGE_POOL_DRIVE && $this->has_option(OPTION_ORPHANED)) {
                $subdir = "/$share" . str_replace($share_options[CONFIG_LANDING_ZONE], '', $fscked_dir);
                Log::debug("Starting orphans search for $subdir");
                $this->additional_info = str_replace('checksums', '', $this->additional_info);
                foreach (Config::storagePoolDrives() as $sp_drive) {
                    if (StoragePool::is_pool_drive($sp_drive)) {
                        $this->gh_fsck("$sp_drive/$subdir", $share, $sp_drive);
                    }
                }
            }
        }
        Log::info("fsck for $fscked_dir completed.");

        Settings::set('last_fsck_conf_md5', $new_conf_hash);

        FSCKWorkLog::taskCompleted($this->id, $this->has_option(OPTION_EMAIL));

        return TRUE;
    }

    private static function get_conf_hash() {
        exec("grep -ie 'num_copies\|storage_pool_directory\|storage_pool_drive\|sticky_files' " . escapeshellarg(ConfigHelper::$config_file) . " | grep -v '^#'", $content);
        exec("grep -ie 'path\|vfs objects' " . escapeshellarg(ConfigHelper::$smb_config_file) . " | grep -v '^#'", $content);
        return md5(implode("\n", $content));
    }

    public function gh_fsck_reset_du($share, $full_path=null) {
        if (!$this->has_option(OPTION_DU)) {
            $this->additional_info .= '|' . OPTION_DU;
        }
        $params = array('share' => $share);
        if (empty($full_path)) {
            $query = "DELETE FROM du_stats WHERE share = :share";
        } else {
            $params['full_path'] = "$full_path";
            $query = "SELECT depth, size FROM du_stats WHERE share = :share AND full_path = :full_path";
            $infos = DB::getFirst($query, $params);

            $parts = explode('/', $full_path);
            array_pop($parts);
            for ($i=$infos->depth-1; $i>0; $i--) {
                $path = implode('/', $parts);
                $q = "UPDATE du_stats SET size = size - :size WHERE share = :share AND full_path = :full_path";
                DB::execute($q, ['size' => $infos->size, 'share' => $share, 'full_path' => $path]);
                array_pop($parts);
            }

            $query = "DELETE FROM du_stats WHERE share = :share AND full_path LIKE :full_path";
            $params['full_path'] = "$full_path%";
        }
        DB::execute($query, $params);
    }

    public function gh_fsck($path, $share, $storage_path = FALSE) {
        $path = clean_dir($path);
        Log::debug("Entering $path");
        $this->fsck_report->count(FSCK_COUNT_LZ_DIRS);

        foreach (Config::storagePoolDrives() as $sp_drive) {
            if (StoragePool::getDriveFromPath($path) == $sp_drive && StoragePool::is_pool_drive($sp_drive)) {
                $dir_path = str_replace(clean_dir("$sp_drive/$share"), '', $path);
                $dir_in_lz = clean_dir(get_share_landing_zone($share) . "/$dir_path");
                if (!file_exists($dir_in_lz)) {
                    Log::info("Re-creating $dir_in_lz from $path");
                    gh_mkdir($dir_in_lz, $path);
                }
                break;
            }
        }


        $handle = @opendir($path);
        if ($handle === FALSE) {
            Log::error("  Couldn't open $path to list content. Skipping...", Log::EVENT_CODE_LIST_DIR_FAILED);
            return;
        }
        while (($filename = readdir($handle)) !== FALSE) {
            if ($filename != '.' && $filename != '..') {
                $full_path = "$path/$filename";
                $file_type = @filetype($full_path);
                if ($file_type === FALSE) {
                    // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
                    $file_type = @filetype(normalize_utf8_characters($full_path));
                    if ($file_type !== FALSE) {
                        // Bingo!
                        $full_path = normalize_utf8_characters($full_path);
                        $path = normalize_utf8_characters($path);
                        $filename = normalize_utf8_characters($filename);
                    }
                }
                if ($file_type == 'dir') {
                    $this->gh_fsck($full_path, $share, $storage_path);
                } else {
                    $this->gh_fsck_file($path, $filename, $file_type, 'landing_zone', $share, $storage_path);

                    if ($this->has_option(OPTION_CHECKSUMS)) {
                        $count_md5 = DBSpool::get_num_tasks('md5');
                        if ($count_md5 > 1000) {
                            // 1000+ md5 tasks in the DB. Let's work on those a little before continuing.

                            // Make sure the MD5 worker threads are running.
                            Md5Task::check_md5_workers();

                            while ($count_md5 > 500) {
                                Log::debug("MD5 tasks pending: $count_md5");
                                $query = "SELECT id, action, share, full_path, additional_info, complete FROM tasks WHERE complete = 'yes' AND action = 'md5' ORDER BY id LIMIT 1";
                                $task = DB::getFirst($query);
                                if ($task) {
                                    Md5Task::gh_check_md5(AbstractTask::instantiate($task));
                                } else {
                                    sleep(5);
                                }

                                $count_md5 = DBSpool::get_num_tasks('md5');
                            }
                        }
                    }
                }
            }
        }
        closedir($handle);
    }

    public function gh_fsck_metastore($root, $path, $share) {
        if (!is_dir("$root$path")) {
            // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
            $root = normalize_utf8_characters($root);
            $path = normalize_utf8_characters($path);
            if (!is_dir("$root$path")) {
                return;
            }
        }
        Log::debug("Entering metastore " . clean_dir($root . $path));

        $handle = opendir("$root$path");
        while (($filename = readdir($handle)) !== FALSE) {
            if ($filename != '.' && $filename != '..') {
                if (@is_dir("$root$path/$filename")) {
                    $this->fsck_report->count(FSCK_COUNT_META_DIRS);
                    $this->gh_fsck_metastore($root, "$path/$filename", $share);
                } else {
                    // Found a metafile
                    $path_parts = explode('/', $path);
                    array_shift($path_parts);
                    $share = array_shift($path_parts);
                    $landing_zone = get_share_landing_zone($share);
                    $local_path = $landing_zone . '/' . implode('/', $path_parts);

                    // If file exists in landing zone, we already fsck-ed it in gh_fsck(); let's not repeat ourselves, shall we?
                    if (!file_exists("$local_path/$filename")) {
                        $this->gh_fsck_file($local_path, $filename, FALSE, 'metastore', $share);
                    }
                }
            }
        }
        closedir($handle);
    }

    public function gh_fsck_file($path, $filename, $file_type, $source, $share, $storage_path = FALSE) {
        $landing_zone = get_share_landing_zone($share);
        if($storage_path === FALSE) {
            $file_path = trim(mb_substr($path, mb_strlen($landing_zone)+1), '/');
        }else{
            $file_path = trim(mb_substr($path, mb_strlen("$storage_path/$share")+1), '/');
        }
        if ($file_type === FALSE) {
            clearstatcache();
            // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
            $file_type = @filetype(normalize_utf8_characters("$path/$filename"));
            if ($file_type !== FALSE) {
                // Bingo!
                $file_path = normalize_utf8_characters($file_path);
                $path = normalize_utf8_characters($path);
                $filename = normalize_utf8_characters($filename);
            }
        }
        if ($source == 'metastore') {
            $this->fsck_report->count(FSCK_COUNT_META_FILES);
        }
        if ($file_type !== FALSE) {
            $this->fsck_report->count(FSCK_COUNT_LZ_FILES);
        }
        if ($file_type == 'file') {
            if($storage_path === FALSE) {
                // Let's just add a 'write' task for this file; if it's a duplicate of an already pending task, it won't be processed twice, since the simplify function will remove such duplicates.
                Log::info("$path/$filename is a file (not a symlink). Adding a new 'write' pending task for that file.");
                WriteTask::queue($share, clean_dir("$file_path/$filename"));
                return;
            }
        } else {
            if ($source == 'metastore') {
                if ($file_type == 'link' && !file_exists(readlink("$path/$filename"))) {
                    // Link points to now gone copy; let's just remove it, and treat this as if the link was not there in the first place.
                    unlink("$path/$filename");
                    $file_type = FALSE;
                }
                if ($file_type === FALSE) {
                    if (!Log::actionIs(ACTION_FSCK_FILE)) {
                        // Maybe this file was removed after fsck started, and thus shouldn't be re-created here!
                        // We'll queue this file fsck (to restore the symlink) for when all other file operations have been executed.
                        Log::debug("  Queuing a new fsck_file task for " . clean_dir("$share/$file_path/$filename"));
                        FsckFileTask::queue($share, empty($file_path) ? $filename : clean_dir("$file_path/$filename"), $this->additional_info);
                        return;
                    }
                }
            }
        }

        if (Metastores::get_metafile_data_filename($share, $file_path, $filename) === FALSE && Metastores::get_metafile_data_filename($share, normalize_utf8_characters($file_path), normalize_utf8_characters($filename)) === FALSE) {
            $full_path = clean_dir("$path/$filename");

            // Check if this is a temporary file; if so, just delete it.
            if (StorageFile::is_temp_file($full_path)) {
                $this->fsck_report->found_problem(FSCK_PROBLEM_TEMP_FILE, $full_path);
                Trash::trash_file($full_path);
                return;
            }

            if ($storage_path !== FALSE) {
                if ($this->has_option(OPTION_ORPHANED)) {
                    Log::info("$full_path is an orphaned file; we'll proceed to find all copies and symlink this file appropriately.");
                    $this->fsck_report->found_problem(FSCK_COUNT_ORPHANS);
                } else {
                    Log::info("$full_path is an orphaned file, but we're not looking for orphans. For Greyhole to recognize this file, initiate a fsck with the --find-orphaned-files option.");
                    return;
                }
            }
        }

        // Look for this file on all available drives
        $file_metafiles = array();
        $file_copies_inodes = StoragePool::get_file_copies_inodes($share, $file_path, $filename, $file_metafiles);
        if (count($file_metafiles) == 0) {
            // If we found 0 file copies the first time, we normalize the file path (using NFC) and try again.
            // Ref: http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization
            $file_copies_inodes = StoragePool::get_file_copies_inodes($share, normalize_utf8_characters($file_path), normalize_utf8_characters($filename), $file_metafiles);
            if (count($file_metafiles) > 0) {
                // Bingo!
                $file_path = normalize_utf8_characters($file_path);
                $filename = normalize_utf8_characters($filename);
            }
        }

        $num_ok = count($file_copies_inodes);
        if ($num_ok == 0 && count($file_metafiles) > 0) {
            // We found 1+ files, but none or them are on a defined storage drive; we can still use them as the source to create additional copies.
            $metadata = reset($file_metafiles);
            $original_file_path = $metadata->path;
        }

        foreach (Metastores::get_metafiles($share, $file_path, $filename, TRUE) as $metafile_block) {
            foreach ($metafile_block as $metafile) {
                $inode_number = @gh_fileinode($metafile->path);
                $root_path = str_replace(clean_dir("/$share/$file_path/$filename"), '', $metafile->path);
                if ($root_path == $metafile->path) {
                    // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
                    $root_path = str_replace(normalize_utf8_characters(clean_dir("/$share/$file_path/$filename")), '', normalize_utf8_characters($metafile->path));
                    if ($root_path == $metafile->path) {
                        Log::warn("Couldn't find root path for $metafile->path", Log::EVENT_CODE_FSCK_METAFILE_ROOT_PATH_NOT_FOUND);
                    }
                    if ($inode_number !== FALSE && $metafile->state == Metafile::STATE_OK) {
                        Log::debug("Found $metafile->path");
                    }
                }

                // Sometimes, two paths will be almost the same, except for the UTF-8 normalization they use.
                // For those, we could end up with two entries in $file_metafiles - bad!
                // So we make sure we don't end up with duplicates like this:
                foreach ($file_metafiles as $k => $v) {
                    if ($k == $metafile->path || normalize_utf8_characters($k) == normalize_utf8_characters($metafile->path)) {
                        $metafile->path = $v->path;
                        $metafile->state = $v->state;
                        unset($file_metafiles[$k]);
                        break;
                    }
                }

                if (is_link($metafile->path)) {
                    $link_target = readlink($metafile->path);
                    if (array_contains($file_copies_inodes, $link_target)) {
                        // This link points to another file copy. Bad, bad!
                        Log::warn("Warning! Found a symlink in your storage pool: $metafile->path -> $link_target. Deleting.", Log::EVENT_CODE_FSCK_SYMLINK_FOUND_IN_STORAGE_POOL);
                        Trash::trash_file($metafile->path);
                    }
                    $inode_number = FALSE;
                }
                if ($inode_number === FALSE || !StoragePool::is_pool_drive($root_path)) {
                    $metafile->state = Metafile::STATE_GONE;
                    $metafile->is_linked = FALSE;
                    if (StoragePool::gone_ok($root_path)) {
                        // Let's not replace this copy yet...
                        $file_copies_inodes[$metafile->path] = $metafile->path;
                        $num_ok++;
                        $this->fsck_report->count(FSCK_COUNT_GONE_OK);
                    }
                } else if (is_dir($metafile->path)) {
                    Log::debug("Found a directory that should be a file! Will try to remove it, if it's empty.");
                    @rmdir($metafile->path);
                    $metafile->state = Metafile::STATE_GONE;
                    $metafile->is_linked = FALSE;
                    continue;
                } else {
                    $metafile->state = Metafile::STATE_OK;
                    if (!isset($file_metafiles[$metafile->path])) {
                        $file_copies_inodes[$inode_number] = $metafile->path;
                        $num_ok++;
                    }
                }
                $file_metafiles[clean_dir($metafile->path)] = $metafile;
            }
        }

        $num_copies_required = SharesConfig::getNumCopies($share);
        if ($num_copies_required == -1) {
            Log::warn("Tried to fsck a share that is missing from greyhole.conf. Skipping.", Log::EVENT_CODE_FSCK_UNKNOWN_SHARE);
            return;
        }

        if (count($file_copies_inodes) > 0) {
            $found_linked_metafile = FALSE;
            foreach ($file_metafiles as $metafile) {
                if ($metafile->is_linked) {
                    if (file_exists($metafile->path)) {
                        // Supposed to be the target of the symlink; but we need to make sure that's true!
                        $symlink_file_path = clean_dir(get_share_landing_zone($share) . "/$file_path/$filename");
                        $found_linked_metafile = @filetype($symlink_file_path) == 'link' && readlink($symlink_file_path) == clean_dir($metafile->path);
                        $expected_file_size = gh_filesize($metafile->path);
                        $original_file_path = $metafile->path;
                        break;
                    } else {
                        $metafile->is_linked = FALSE;
                        $metafile->state = Metafile::STATE_GONE;
                    }
                }
            }
            // If no metafile is linked, link the 1st one (that is OK)
            if (!$found_linked_metafile) {
                foreach ($file_metafiles as $first_metafile) {
                    $root_path = str_replace(clean_dir("/$share/$file_path/$filename"), '', $first_metafile->path);
                    if ($first_metafile->state == Metafile::STATE_OK && StoragePool::is_pool_drive($root_path)) {
                        $first_metafile->is_linked = TRUE;
                        $expected_file_size = gh_filesize($first_metafile->path);
                        $original_file_path = $first_metafile->path;
                        break;
                    }
                }
            }

            if ($this->has_option(OPTION_DU)) {
                // Calculate du stats
                $du_path = trim(clean_dir("$file_path"), '/');
                /** @noinspection PhpUndefinedVariableInspection */
                do {
                    /** @noinspection PhpUndefinedVariableInspection */
                    $size = ($expected_file_size * $num_copies_required);

                    if (empty($du_path)) {
                        $depth = 1;
                    } else {
                        $chars_count = count_chars($du_path, 1);
                        if (!isset($chars_count[ord('/')])) {
                            $chars_count[ord('/')] = 0;
                        }
                        $depth = $chars_count[ord('/')] + 2;
                    }

                    $query = "INSERT INTO du_stats SET share = :share, full_path = :full_path, depth = :depth, size = :size ON DUPLICATE KEY UPDATE size = size + VALUES(size)";
                    $params = array(
                        'share' => $share,
                        'full_path' => $du_path,
                        'depth' => $depth,
                        'size' => $size,
                    );
                    DB::insert($query, $params);

                    $p = mb_strrpos($du_path, '/');
                    if ($p) {
                        $du_path = mb_substr($du_path, 0, $p);
                    } else if (!empty($du_path)) {
                        $last = TRUE;
                        $du_path = '';
                    } else {
                        $last = FALSE;
                    }
                } while (!empty($du_path) || $last);
            }

            // Check that all file copies have the same size
            foreach ($file_copies_inodes as $key => $real_full_path) {
                if (array_contains(array_keys($file_copies_inodes), $real_full_path)) {
                    // That file isn't available atm, but it's OK.
                    continue;
                }
                $file_size = gh_filesize($real_full_path);
                /** @noinspection PhpUndefinedVariableInspection */
                if ($file_size != $expected_file_size) {
                    // Found a file with a different size than the original...
                    // There might be a good reason. Let's look for one!
                    /** @noinspection PhpUndefinedVariableInspection */
                    if (gh_is_file_locked($real_full_path) !== FALSE || gh_is_file_locked($original_file_path) !== FALSE) {
                        // Write operation in progress
                        continue;
                    }
                    // A pending write transaction maybe?
                    SambaSpool::parse_samba_spool();
                    $query = "SELECT * FROM tasks WHERE action = 'write' AND share = :share AND full_path = :full_path";
                    $task = DB::getFirst($query, array('share' => $share, 'full_path' => "$file_path/$filename"));
                    if ($task) {
                        // Pending write task
                        continue;
                    }
                    // Found no good reason!

                    if ($file_size === FALSE) {
                        // Empty file; just delete it.
                        Log::warn("  An empty file copy was found: $real_full_path is 0 bytes. Original: $original_file_path is " . number_format($expected_file_size) . " bytes. This empty copy will be deleted.", Log::EVENT_CODE_FSCK_EMPTY_FILE_COPY_FOUND);
                        unlink($real_full_path);
                    } else {
                        Log::warn("  A file copy with a different file size than the original was found: $real_full_path is " . number_format($file_size) . " bytes. Original: $original_file_path is " . number_format($expected_file_size) . " bytes.", Log::EVENT_CODE_FSCK_SIZE_MISMATCH_FILE_COPY_FOUND);
                        Trash::trash_file($real_full_path);
                        $this->fsck_report->found_problem(FSCK_PROBLEM_WRONG_COPY_SIZE, array($file_size, $expected_file_size, $original_file_path), clean_dir($real_full_path));
                    }
                    // Will not count that copy as a valid copy!
                    unset($file_copies_inodes[$key]);
                    unset($file_metafiles[clean_dir($real_full_path)]);
                }
            }
        }

        if (count($file_copies_inodes) == $num_copies_required) {
            // It's okay if the file isn't a symlink so long as we're looking at a storage volume path and not a share path
            /** @noinspection PhpUndefinedVariableInspection */
            if (!$found_linked_metafile || ($file_type != 'link' && $storage_path === FALSE)) {
                // Re-create symlink...
                if (!$found_linked_metafile) {
                    // ... the old one points to a drive that was replaced
                    Log::info('  Symlink target moved. Updating symlink.');
                    $this->fsck_report->found_problem(FSCK_COUNT_SYMLINK_TARGET_MOVED);
                } else {
                    // ... it was missing
                    Log::info('  Symlink was missing. Creating new symlink.');
                }
                foreach ($file_metafiles as $key => $metafile) {
                    if ($metafile->is_linked) {
                        $this->update_symlink($metafile->path, "$landing_zone/$file_path/$filename", $share, $file_path, $filename);
                        break;
                    }
                }
                Metastores::save_metafiles($share, $file_path, $filename, $file_metafiles);
            }
        } else if (count($file_copies_inodes) == 0 && !isset($original_file_path)) {
            Log::warn('  WARNING! No copies of this file are available in the Greyhole storage pool: "' . clean_dir("$share/$file_path/$filename") . '". ' . (is_link("$landing_zone/$file_path/$filename") ? 'Deleting from share.' : (gh_is_file("$landing_zone/$file_path/$filename") ? 'Did you copy that file there without using your Samba shares? (If you did, don\'t do that in the future.)' : '')), Log::EVENT_CODE_FSCK_NO_FILE_COPIES);
            if ($source == 'metastore' || Metastores::get_metafile_data_filename($share, $file_path, $filename) !== FALSE) {
                $this->fsck_report->found_problem(FSCK_PROBLEM_NO_COPIES_FOUND, clean_dir("$share/$file_path/$filename"));
            }
            if (is_link("$landing_zone/$file_path/$filename")) {
                Trash::trash_file("$landing_zone/$file_path/$filename");
            } else if (gh_is_file("$landing_zone/$file_path/$filename")) {
                Log::info("$share/$file_path/$filename is a file (not a symlink). Adding a new 'write' pending task for that file.");
                WriteTask::queue($share, empty($file_path) ? $filename : clean_dir("$file_path/$filename"));
            }
            if ($this->has_option(OPTION_DEL_ORPHANED_METADATA)) {
                Metastores::remove_metafiles($share, $file_path, $filename);
            } else {
                Metastores::save_metafiles($share, $file_path, $filename, $file_metafiles);
            }
        } else if (count($file_copies_inodes) < $num_copies_required && $num_copies_required > 0) {
            // Create new copies
            Log::info("  Missing file copies. Expected $num_copies_required, got " . count($file_copies_inodes) . ". Will create more copies using $original_file_path");
            if ($this->fsck_report) {
                $this->fsck_report->found_problem(FSCK_COUNT_MISSING_COPIES);
            }
            clearstatcache(); $filesize = gh_filesize($original_file_path);
            $file_metafiles = Metastores::create_metafiles($share, "$file_path/$filename", $num_copies_required, $filesize, $file_metafiles);

            // Re-copy the file everywhere, and re-create the symlink
            $symlink_created = FALSE;
            $num_copies_current = 1; # the source file
            global $going_drive;
            if (!empty($going_drive)) {
                // Let's not count the source file here, since it will be gone soon!
                $num_copies_current = 0;
            }
            foreach ($file_metafiles as $key => $metafile) {
                if ($original_file_path != $metafile->path) {
                    if ($num_copies_current >= $num_copies_required) {
                        $metafile->state = Metafile::STATE_GONE;
                        $file_metafiles[$key] = $metafile;
                        continue;
                    }

                    list($metafile_dir_path, ) = explode_full_path($metafile->path);

                    if ($metafile->state == Metafile::STATE_GONE) {
                        foreach (Config::storagePoolDrives() as $sp_drive) {
                            if (StoragePool::getDriveFromPath($metafile_dir_path) == $sp_drive && StoragePool::is_pool_drive($sp_drive)) {
                                $metafile->state = Metafile::STATE_PENDING;
                                $file_metafiles[$key] = $metafile;
                                break;
                            }
                        }
                    }

                    if ($metafile->state != Metafile::STATE_GONE) {
                        list($original_path, ) = explode_full_path(get_share_landing_zone($share) . "/$file_path");
                        if (!gh_mkdir($metafile_dir_path, $original_path)) {
                            $metafile->state = Metafile::STATE_GONE;
                            $file_metafiles[$key] = $metafile;
                            continue;
                        }
                    }

                    if (!is_dir($metafile_dir_path) || $metafile->state == Metafile::STATE_GONE) {
                        if ($metafile->state != Metafile::STATE_GONE) {
                            // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
                            if (is_dir(normalize_utf8_characters($metafile_dir_path))) {
                                // Bingo!
                                $metafile->path = normalize_utf8_characters($metafile->path);
                                $file_metafiles[$key] = $metafile;
                            } else {
                                continue;
                            }
                        }
                    }

                    if ($metafile->state == Metafile::STATE_PENDING) {
                        if (StorageFile::create_file_copy($original_file_path, $metafile->path)) {
                            $metafile->state = Metafile::STATE_OK;
                            $num_copies_current++;
                        } else {
                            if ($metafile->is_linked) {
                                $metafile->is_linked = FALSE;
                            }
                            $metafile->state = Metafile::STATE_GONE;
                        }
                        $file_metafiles[$key] = $metafile;
                    }
                }
                if ($original_file_path == $metafile->path || $metafile->is_linked) {
                    if (!empty($going_drive) && StoragePool::getDriveFromPath($original_file_path) == $going_drive) {
                        $metafile->is_linked = FALSE;
                        $metafile->state = Metafile::STATE_GONE;
                        $file_metafiles[$key] = $metafile;
                        continue;
                    }
                    if ($symlink_created /* already */) {
                        $metafile->is_linked = FALSE;
                        $file_metafiles[$key] = $metafile;
                        continue;
                    }

                    $this->update_symlink($metafile->path, "$landing_zone/$file_path/$filename", $share, $file_path, $filename);
                    $symlink_created = TRUE;
                }
            }
            if (!$symlink_created) {
                foreach ($file_metafiles as $key => $metafile) {
                    if ($metafile->state == Metafile::STATE_OK) {
                        $metafile->is_linked = TRUE;
                        $file_metafiles[$key] = $metafile;
                        $this->update_symlink($metafile->path, "$landing_zone/$file_path/$filename", $share, $file_path, $filename);
                        break;
                    }
                }
            }
            Metastores::save_metafiles($share, $file_path, $filename, $file_metafiles);
        } else {
            # Let's not assume that files on missing drives are really there... Removing files here could be dangerous!
            foreach ($file_copies_inodes as $inode => $path) {
                if (string_starts_with($inode, '/')) {
                    unset($file_copies_inodes[$inode]);
                }
            }
            if (count($file_copies_inodes) > $num_copies_required) {
                Log::info("  Too many file copies. Expected $num_copies_required, got " . count($file_copies_inodes) . ". Will try to remove some.");
                if (DBSpool::isFileLocked($share, "$file_path/$filename") !== FALSE) {
                    Log::info("  File is locked. Will not remove copies at this time. The next fsck will try to remove copies again.");
                    return;
                }
                $this->fsck_report->found_problem(FSCK_COUNT_TOO_MANY_COPIES);

                $local_target_drives = array_values(StoragePool::choose_target_drives(0, TRUE, $share, $file_path));

                // The drives that are NOT returned by order_target_drives(), but have a file copy, should be removed first
                $gone_drives = array();
                foreach (Config::storagePoolDrives() as $sp_drive) {
                    $file = clean_dir("$sp_drive/$share/$file_path/$filename");
                    if (!array_contains($local_target_drives, $sp_drive) && file_exists($file)) {
                        $gone_drives[] = $sp_drive;
                        $local_target_drives[] = $sp_drive;
                    }
                }
                if (!empty($gone_drives)) {
                    Log::debug("Drives that shouldn't be used anymore: " . implode(' - ', $gone_drives));
                }

                while (count($file_copies_inodes) > $num_copies_required && !empty($local_target_drives)) {
                    $sp_drive = array_pop($local_target_drives);
                    $key = clean_dir("$sp_drive/$share/$file_path/$filename");
                    Log::debug("  Looking for copy at $key");
                    if (isset($file_metafiles[$key]) || gh_file_exists($key)) {
                        if (isset($file_metafiles[$key])) {
                            $metafile = $file_metafiles[$key];
                        }
                        /** @noinspection PhpUndefinedVariableInspection */
                        if (gh_file_exists($key) || $metafile->state == Metafile::STATE_OK) {
                            Log::debug("    Found file copy at $key, or metadata file is marked OK.");
                            if (gh_is_file_locked($key) !== FALSE) {
                                Log::debug("    File copy is locked. Won't remove it.");
                                continue;
                            }
                            $this->fsck_report->found_problem(FSCK_PROBLEM_TOO_MANY_COPIES, $key);
                            Log::debug("    Removing copy at $key");
                            unset($file_copies_inodes[gh_fileinode($key)]);
                            Trash::trash_file($key);
                            if (isset($file_metafiles[$key])) {
                                unset($file_metafiles[$key]);
                            }
                            $num_ok--;
                        }
                    }
                }

                // If no metafile is linked, link the 1st one
                $found_linked_metafile = FALSE;
                foreach ($file_metafiles as $key => $metafile) {
                    if ($metafile->is_linked) {
                        $found_linked_metafile = ( @readlink("$landing_zone/$file_path/$filename") == $metafile->path );
                        break;
                    }
                }
                if (!$found_linked_metafile) {
                    $metafile = reset($file_metafiles);
                    $this->update_symlink($metafile->path, "$landing_zone/$file_path/$filename", $share, $file_path, $filename);
                    reset($file_metafiles)->is_linked = TRUE;
                }

                Metastores::save_metafiles($share, $file_path, $filename, $file_metafiles);
            }
        }

        // Queue all file copies checksum calculations, if --checksums was specified
        if ($this->has_option(OPTION_CHECKSUMS)) {
            foreach (Metastores::get_metafiles($share, $file_path, $filename, TRUE) as $metafile_block) {
                foreach ($metafile_block as $metafile) {
                    if ($metafile->state != Metafile::STATE_OK) { continue; }
                    $inode_number = @gh_fileinode($metafile->path);
                    if ($inode_number !== FALSE) {
                        // Let's calculate this file's MD5 checksum to validate that all copies are valid.
                        Md5Task::queue($share, clean_dir("$file_path/$filename"), $metafile->path);
                    }
                }
            }
        }
    }

    private function update_symlink($target, $symlink, $share, $file_path, $filename) {
        clearstatcache();
        if (!file_exists($symlink)) {
            Log::debug("  Missing symlink... A pending unlink transaction maybe?");
            SambaSpool::parse_samba_spool();
            $query = "SELECT * FROM tasks WHERE action = 'unlink' AND share = :share AND full_path = :full_path";
            $params = array(
                'share' => $share,
                'full_path' => trim("$file_path/$filename", '/'),
            );
            $task = DB::getFirst($query, $params);
            if ($task) {
                Log::debug("    Indeed! Pending unlink task found. Will not re-create this symlink.");
                return;
            }
            Log::debug("    No... Found no good reason for the symlink to be missing! Let's re-create it.");
        }

        Log::debug("  Updating symlink at $symlink to point to $target");
        Trash::trash_file($symlink);
        gh_mkdir(dirname($symlink), dirname($target));
        gh_symlink($target, $symlink);
    }

    /**
     * @param $fsck_report FSCKReport
     */
    public function set_fsck_report($fsck_report) {
        $this->fsck_report = $fsck_report;
    }

    public function initialize_fsck_report($what) {
        $this->fsck_report = new FSCKReport($what);
    }

    /**
     * @return FSCKReport
     */
    public function get_fsck_report() {
        return $this->fsck_report;
    }
}

?>
