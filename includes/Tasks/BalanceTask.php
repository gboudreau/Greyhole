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

class BalanceTask extends AbstractTask {

    public function execute() {
        Log::info("Starting available space balancing");

        // Start with shares that have sticky files, so that subsequent shares will be used to try to balance what moving files into stick_into drives could debalance...
        // Then start with the shares for which we keep the most # copies;
        // That way, if the new drive fails soon, it won't take with it files for which we only have one copy!
        $sorted_shares_options = SharesConfig::getShares();
        unset($sorted_shares_options[CONFIG_TRASH_SHARE]);
        uasort($sorted_shares_options, array('BalanceTask', 'compare_share_balance'));
        $skip_stickies = FALSE;
        Log::debug("┌ Will balance the shares in the following order: " . implode(", ", array_keys($sorted_shares_options)));
        foreach ($sorted_shares_options as $share_name => $share_options) {
            if ($share_options[CONFIG_NUM_COPIES] == count(Config::storagePoolDrives())) {
                // Files are everywhere; won't be able to use that share to balance available space!
                Log::debug("├ Skipping share $share_name; has num_copies = max");
                continue;
            }

            if ($skip_stickies && is_share_sticky($share_name)) {
                Log::debug("├ Skipping share $share_name; is sticky");
                continue;
            }

            Log::debug("├┐ Balancing share: $share_name");

            // Move files from the drive with the less available space to the drive with the most available space.
            $sorted_pool_drives = sort_storage_drives_available_space();
            $pool_drives_avail_space = array();
            foreach ($sorted_pool_drives as $available_space => $drive) {
                $pool_drives_avail_space[$drive] = $available_space;
            }
            $num_total_drives = count($sorted_pool_drives);

            $balance_direction_asc = array();
            foreach ($sorted_pool_drives as $available_space => $drive) {
                $target_avail_space = array_sum($pool_drives_avail_space) / count($pool_drives_avail_space);
                $balance_direction_asc[$drive] = $pool_drives_avail_space[$drive] < $target_avail_space;
            }

            foreach ($sorted_pool_drives as $source_drive) {
                $current_avail_space = $pool_drives_avail_space[$source_drive];
                $target_avail_space = array_sum($pool_drives_avail_space) / count($pool_drives_avail_space);
                $delta_needed = $target_avail_space - $current_avail_space;
                if ($delta_needed <= 10*1024) {
                    Log::debug("│├ Skipping balancing storage pool drive: $source_drive; it has enough available space: (". bytes_to_human($current_avail_space*1024, FALSE) ." available, target: ". bytes_to_human($target_avail_space*1024, FALSE) .")");
                    continue;
                }

                Log::debug("│├┐ Balancing storage pool drive: $source_drive (". bytes_to_human($current_avail_space*1024, FALSE) ." available, target: ". bytes_to_human($target_avail_space*1024, FALSE) .")");

                // Files candidate to get moved
                $files = array();
                if (is_dir("$source_drive/$share_name")) {
                    $max_file_size = floor($delta_needed / 1024);
                    $command = "find ". escapeshellarg("$source_drive/$share_name") ." -type f -size +10M -size -{$max_file_size}M";
                    Log::debug("│││ Looking for files to move on $share_name, with file size between 10-{$max_file_size}MB ...");
                    exec($command, $files);
                }
                if (count($files) == 0) {
                    Log::debug("│├┘ Found no files that could be moved.");
                    continue;
                }
                Log::debug("│││ Found ". count($files) ." files that can be moved.");

                // Repeat until all drives' available space is balanced.
                foreach ($files as $file) {
                    $current_avail_space = $pool_drives_avail_space[$source_drive];
                    $target_avail_space = array_sum($pool_drives_avail_space) / count($pool_drives_avail_space);
                    $delta_needed = $target_avail_space - $current_avail_space;
                    if ($delta_needed <= 50*1024) {
                        Log::debug("│├┘ Storage pool drive $source_drive now has enough available space: (". bytes_to_human($current_avail_space*1024, FALSE) ." available, target: ". bytes_to_human($target_avail_space*1024, FALSE) .")");
                        break;
                    }

                    // Let's not try to move locked files!
                    if (real_file_is_locked($file) !== FALSE) {
                        Log::debug("││├ File $file is locked by another process. Skipping.");
                        continue;
                    }

                    $filesize = gh_filesize($file)/1024; // KB

                    if ($filesize > $delta_needed) {
                        Log::debug("││├ File is too large (" .  bytes_to_human($filesize*1024, FALSE) . "). Skipping.");
                        continue;
                    }

                    $full_path = mb_substr($file, mb_strlen("$source_drive/$share_name/"));
                    list($path, ) = explode_full_path($full_path);
                    Log::debug("││├┐ Working on file: $share_name/$full_path (". bytes_to_human($filesize*1024, FALSE) .")");

                    // $is_sticky is set in order_target_drives(), based on $share_name & $path
                    $sp_drives = order_target_drives($filesize, FALSE, $share_name, $path, '  ', $is_sticky);

                    unset($sp_drive);
                    if ($is_sticky) {
                        /** @noinspection PhpStatementHasEmptyBodyInspection */
                        if (count($sp_drives) == $num_total_drives - 1 && !array_contains($sp_drives, $source_drive)) {
                            // Only drive full is the source drive. Let's move files away from there!
                        } else if (count($sp_drives) < $num_total_drives) {
                            $skip_stickies = TRUE;
                            Log::debug("│├┴┘ Some drives are full. Skipping sticky shares until all drives have some free space.");
                            break;
                        }

                        $sticky_drives = array_slice($sp_drives, 0, get_num_copies($share_name));
                        if (array_contains($sticky_drives, $source_drive)) {
                            // Source drive is a stick_into drive; let's not move that file!
                            Log::debug("││├┘ Source is sticky. Skipping.");
                            continue;
                        }
                        $already_stuck_copies = 0;
                        foreach ($sticky_drives as $drive) {
                            if (file_exists("$drive/$share_name/$full_path")) {
                                $already_stuck_copies++;
                            } else {
                                $sp_drive = $drive;
                            }
                        }
                    } else {
                        while (count($sp_drives) > 0) {
                            $drive = array_shift($sp_drives);
                            if (!file_exists("$drive/$share_name/$full_path")) {
                                $sp_drive = $drive;
                                break;
                            }
                        }
                    }

                    if (!isset($sp_drive)) {
                        // Can't find a drive that doesn't have this file; skipping.
                        if ($is_sticky) {
                            Log::debug("││├┘ Sticky file is already where it should be. Skipping.");
                        }
                        continue;
                    }

                    Log::debug("││││ Target drive: $sp_drive (". bytes_to_human($pool_drives_avail_space[$sp_drive]*1024, FALSE) ." available)");

                    if ($is_sticky) {
                        Log::debug("││││ Moving sticky file, even if that means it won't help balancing available space.");
                    } else {
                        $new_drive_needs_more_avail_space = $balance_direction_asc[$sp_drive];
                        $new_drive_needs_less_avail_space = !$new_drive_needs_more_avail_space;
                        $new_drive_avail_space = $pool_drives_avail_space[$sp_drive];
                        if ($new_drive_needs_more_avail_space && $new_drive_avail_space <= $target_avail_space) {
                            Log::debug("││├┘ Target drive needs more available space; moving a file there would do the opposite. Skipping.");
                            continue;
                        }
                        if ($new_drive_needs_less_avail_space && $new_drive_avail_space <= $target_avail_space) {
                            Log::debug("││├┘ Target drive needed less available space; is low enough now. Skipping.");
                            continue;
                        }
                    }

                    // Make sure the parent directory exists, before we try moving something there...
                    $original_path = clean_dir("$source_drive/$share_name/$path");
                    list($target_path, $filename) = explode_full_path("$sp_drive/$share_name/$full_path");
                    gh_mkdir($target_path, $original_path);

                    // Move the file
                    $temp_path = get_temp_filename("$sp_drive/$share_name/$full_path");
                    $file_infos = gh_get_file_infos($file);
                    Log::debug("││││ Moving file copy...");
                    $it_worked = gh_rename($file, $temp_path);
                    if ($it_worked) {
                        gh_rename($temp_path, "$sp_drive/$share_name/$full_path");
                        gh_chperm("$sp_drive/$share_name/$full_path", $file_infos);

                        $pool_drives_avail_space[$sp_drive] -= $filesize;
                        $pool_drives_avail_space[$source_drive] += $filesize;
                    } else {
                        Log::warn("││├┘ Failed file copy. Skipping.", Log::EVENT_CODE_FILE_COPY_FAILED);
                        @unlink($temp_path);
                        continue;
                    }

                    // Update metafiles
                    foreach (get_metafiles($share_name, $path, $filename, TRUE, TRUE, FALSE) as $existing_metafiles) {
                        foreach ($existing_metafiles as $key => $metafile) {
                            if ($metafile->path == $file) {
                                $metafile->path = "$sp_drive/$share_name/$full_path";
                                unset($existing_metafiles[$key]);
                                $metafile->state = 'OK';
                                if ($metafile->is_linked) {
                                    // Re-create correct symlink
                                    $landing_zone = $share_options[CONFIG_LANDING_ZONE];
                                    Log::debug("││││ Updating symlink at $landing_zone/$full_path to point to $metafile->path");
                                    if (is_link("$landing_zone/$full_path")) {
                                        gh_recycle("$landing_zone/$full_path");
                                    }
                                    // Creating this symlink can fail if the parent dir was removed
                                    @gh_symlink($metafile->path, "$landing_zone/$full_path");
                                }
                                $existing_metafiles[$metafile->path] = $metafile;
                                save_metafiles($share_name, $path, $filename, $existing_metafiles);
                                break;
                            }
                        }
                    }
                    $current_avail_space = $pool_drives_avail_space[$source_drive];
                    $target_avail_space = array_sum($pool_drives_avail_space) / count($pool_drives_avail_space);
                    Log::debug("││├┘ Balancing storage pool drive: $source_drive (". bytes_to_human($current_avail_space*1024, FALSE) ." available, target: ". bytes_to_human($target_avail_space*1024, FALSE) .")");
                }

                $delta_needed = $target_avail_space - $current_avail_space;
                if ($delta_needed > 50*1024) {
                    Log::debug("│├┘ Balancing storage pool drive $source_drive finished before enough space was made available: (" . bytes_to_human($current_avail_space * 1024, FALSE) . " available, target: ". bytes_to_human($target_avail_space*1024, FALSE) .")");
                }
            }

            Log::debug("├┘ Done balancing share: $share_name");
        }
        Log::debug("└ Done balancing all shares");

        if (@$skip_stickies) {
            // We skipped some stickies... Let's re-balance to move those, and continue balancing.
            $arr = debug_backtrace();
            if (count($arr) < 93) {
                Log::debug("Some shares with sticky files were skipped. Balancing will now re-start to continue moving those sticky files as needed, and further balance. Recursion level = " . count($arr));
                $this->execute();
            } else {
                Log::info("Maximum number of consecutive balance reached. You'll need to re-execute --balance if you want to balance further.");
            }
        }

        Log::info("Available space balancing completed.");
        return TRUE;
    }

    private static function compare_share_balance($a, $b) {
        if (is_share_sticky($a['name']) && !is_share_sticky($b['name'])) {
            return -1;
        }
        if (!is_share_sticky($a['name']) && is_share_sticky($b['name'])) {
            return 1;
        }
        if ($a[CONFIG_NUM_COPIES] != $b[CONFIG_NUM_COPIES]) {
            return $a[CONFIG_NUM_COPIES] > $b[CONFIG_NUM_COPIES] ? -1 : 1;
        }
        // Randomize order of the shares that have the same num_copies, in order to not always work with the same shares first
        return rand(0, 1) === 0 ? -1 : 1;
    }

}

?>
