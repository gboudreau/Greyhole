#!/usr/bin/greyhole-php
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

require_once('includes/common.php');
require_once('includes/CLI/CommandLineHelper.php'); // Command line helper (abstract classes, command line definitions & parsing, Runners, etc.)
require_once('includes/DaemonRunner.php');
ConfigHelper::parse();

$total_space = 0;
$total_free_space = 0;
foreach (Config::storagePoolDrives() as $sp_drive) {
	$response = explode(' ', exec("df -k ".escapeshellarg($sp_drive)." 2>/tmp/greyhole_df_error.log | tail -1 | awk '{print \$(NF-4),\$(NF-2)}'"));
	if (count($response) != 2) {
		continue;
	}
	$total_space += $response[0];
	$total_free_space += $response[1];
}

// Free space available on shares is based on the num_copies option of the specified LZ
// i.e. real free space = 1 GB, num_copies = 4 => available free space = 250 MB
chdir($argv[1]);
$options = SharesConfig::getShareOptions(getcwd());
if ($options) {
    $total_free_space /= $options['num_copies'];
}

echo "$total_space $total_free_space 1024\n";
?>
