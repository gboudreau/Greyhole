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

final class SystemHelper {

    public static function is_amahi() {
        return file_exists('/usr/bin/hda-ctl');
    }

    public static function directory_uuid($dir) {
        $dev = exec('df ' . escapeshellarg($dir) . ' 2> /dev/null | grep \'/dev\' | awk \'{print $1}\'');
        if (!is_dir($dir)) {
            return FALSE;
        }
        if (empty($dev) || strpos($dev, '/dev/') !== 0) {
            // ZFS pool maybe?
            if (file_exists('/sbin/zpool')) {
                $dataset = exec('df ' . escapeshellarg($dir) . ' 2> /dev/null | awk \'{print $1}\'');
                if (strpos($dataset, '/') !== FALSE) {
                    $is_zfs = exec('mount | grep ' . escapeshellarg("$dataset .*zfs") . ' 2> /dev/null | wc -l');
                    if ($is_zfs == 1) {
                        $p = explode('/', $dataset);
                        $pool = $p[0];
                        $dev_name = exec('/sbin/zpool list -v ' . escapeshellarg($pool) . ' 2> /dev/null | awk \'{print $1}\' | tail -n 1');
                        if (!empty($dev_name)) {
                            $dev = exec("ls -l /dev/disk/*/$dev_name | awk '{print \$(NF-2)}'");
                            if (empty($dev) && file_exists("/dev/$dev_name")) {
                                $dev = '/dev/$dev_name';
                                Log::info("Found a ZFS pool ($pool) that uses a device name in /dev/ ($dev). That is a bad idea, since those can easily change, which would prevent this pool from mounting automatically. You should use any of the /dev/disk/*/ links instead. For example, you could do: zpool export $pool && zpool import -d /dev/disk/by-id/ $pool. More details at http://zfsonlinux.org/faq.html#WhatDevNamesShouldIUseWhenCreatingMyPool");
                            }
                        }
                        if (empty($dev)) {
                            Log::warn("Warning! Couldn't find the device used by your ZFS pool name $pool. That pool will never be used.");
                            return FALSE;
                        }
                    }
                }
            }
            if (empty($dev)) {
                return 'remote';
            }
        }
        $uuid = trim(exec('/sbin/blkid '.$dev.' | awk -F\'UUID="\' \'{print $2}\' | awk -F\'"\' \'{print $1}\''));
        if (empty($uuid)) {
            return 'remote';
        }
        return $uuid;
    }
}

function gh_symlink($target, $link) {
    return symlink($target, $link);
    # Or, if you have issues with the above, comment it out, and de-comment this one:
    # exec("ln -s " . escapeshellarg($target) . " " . escapeshellarg($link)); return gh_is_file($link);
}

// Get CPU architecture (x86_64 or i386 or armv6l or armv5*)
$arch = exec('uname -m');
if ($arch != 'x86_64') {
    function gh_filesize($filename) {
        $result = exec("stat -c %s ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (float) $result;
    }

    function gh_fileowner($filename) {
        $result = exec("stat -c %u ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (int) $result;
    }

    function gh_filegroup($filename) {
        $result = exec("stat -c %g ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (int) $result;
    }

    function gh_fileperms($filename) {
        $result = exec("stat -c %a ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return "0" . $result;
    }

    function gh_is_file($filename) {
        exec('[ -f '.escapeshellarg($filename).' ]', $tmp, $result);
        return $result === 0;
    }

    function gh_fileinode($filename) {
        // This function returns deviceid_inode to make sure this value will be different for files on different devices.
        $result = exec("stat -c '%d_%i' ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (string) $result;
    }

    function gh_file_deviceid($filename) {
        $result = exec("stat -c '%d' ".escapeshellarg($filename)." 2>/dev/null");
        if (empty($result)) {
            return FALSE;
        }
        return (string) $result;
    }

    function gh_rename($filename, $target_filename) {
        exec("mv ".escapeshellarg($filename)." ".escapeshellarg($target_filename)." 2>/dev/null", $output, $result);
        return $result === 0;
    }
} else {
    function gh_filesize(&$filename) {
        $size = @filesize($filename);
        // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
        if ($size === FALSE) {
            // Try NFC form [http://en.wikipedia.org/wiki/Unicode_equivalence#Normalization]
            $size = @filesize(normalize_utf8_characters($filename));
            if ($size !== FALSE) {
                // Bingo!
                $filename = normalize_utf8_characters($filename);
            }
        }
        return $size;
    }

    function gh_fileowner($filename) {
        return fileowner($filename);
    }

    function gh_filegroup($filename) {
        return filegroup($filename);
    }

    function gh_fileperms($filename) {
        return mb_substr(decoct(fileperms($filename)), -4);
    }

    function gh_is_file($filename) {
        return is_file($filename);
    }

    function gh_fileinode($filename) {
        // This function returns deviceid_inode to make sure this value will be different for files on different devices.
        $stat = @stat($filename);
        if ($stat === FALSE) {
            return FALSE;
        }
        return $stat['dev'] . '_' . $stat['ino'];
    }

    function gh_file_deviceid($filename) {
        $stat = @stat($filename);
        if ($stat === FALSE) {
            return FALSE;
        }
        return $stat['dev'];
    }

    function gh_rename($filename, $target_filename) {
        return @rename($filename, $target_filename);
    }
}

?>
