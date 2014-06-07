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

class DaemonRunner extends AbstractRunner {
	
	public function run() {
		// Prevent multiple daemons from running simultaneously
		if (self::isRunning()) {
			die("Found an already running Greyhole daemon with PID " . trim(file_get_contents('/var/run/greyhole.pid')) . ".\nCan't start multiple Greyhole daemons.\nQuitting.\n");
		}

		Log::info("Greyhole (version %VERSION%) daemon started.");
		$this->initialize();

        // The daemon runs indefinitely, this the infinite loop here.
		while (TRUE) {
			// Process the spool directory, and insert each task found there into the database.
            parse_samba_spool();

			// Check that storage pool drives are OK (using their UUID, or .greyhole_uses_this files)
            Log::setAction(ACTION_CHECK_POOL);
            check_storage_pool_drives();

			// Execute the next task from the tasks queue ('tasks' table in the database)
            execute_next_task();
		}
	}
	
	private static function isRunning() {
        $num_daemon_processes = exec('ps ax | grep "greyhole --daemon\|greyhole -D" | grep -v grep | wc -l');
	    return $num_daemon_processes > 1;
	}
    
	private function initialize() {
		// Check the database tables, and repair them if needed.
        DB::repairTables();
		
		// Creates a GUID (if one doesn't exist); will be used to uniquely identify this Greyhole install, when reporting anonymous usage to greyhole.net
        GetGUIDCliRunner::setUniqID();
		
		// Terminology changed (attic > trash, graveyard > metadata store, tombstones > metadata files); this requires filesystem & database changes.
        MigrationHelper::terminologyConversion();

		// For files which don't have extra copies, we at least create a copy of the metadata on a separate drive, in order to be able to identify the missing files if a hard drive fails.
        set_metastore_backup();

		// We backup the database settings to disk, in order to be able to restore them if the database is lost.
        Settings::backup();
		
		// Check that the Greyhole VFS module used by Samba is the correct one for the current Samba version. This is needed when Samba is updated to a new major version after Greyhole is installed.
        samba_check_vfs();

        // Check if the in-memory spool folder exists, and if no, create it and mount a tmpfs there. VFS will write there recvfile (etc.) operations.
        create_mem_spool();
		
		// Process the spool directory, and insert each task found there into the database.
        parse_samba_spool();
		
        // Create the dfree cache folder, if it doesn't exist
        gh_mkdir('/var/cache/greyhole-dfree', (object) array('fileowner' => 0, 'filegroup' => 0, 'fileperms' => (int) base_convert("0777", 8, 10)));
	}
	
	public function finish($returnValue = 0) {
		// The daemon should never finish; it will be killed by the init script.
		// Not that it can reach finish() anyway, since it's in an infinite while(TRUE) loop in run()... :)
	}
}

?>
