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

final class SambaUtils {

    public static function samba_get_version() {
        return str_replace(' ', '.', exec('/usr/sbin/smbd --version | awk \'{print $2}\' | awk -F\'-\' \'{print $1}\' | awk -F\'.\' \'{print $1,$2}\''));
    }

    public static function samba_restart() {
        Log::info("The Samba daemon will now restart...");
        if (is_file('/etc/init/smbd.conf')) {
            exec("/sbin/restart smbd");
        } else if (is_file('/etc/init.d/samba')) {
            exec("/etc/init.d/samba restart");
        } else if (is_file('/etc/init.d/smb')) {
            exec("/etc/init.d/smb restart");
        } else if (is_file('/etc/init.d/smbd')) {
            exec("/etc/init.d/smbd restart");
        } else if (is_file('/etc/systemd/system/multi-user.target.wants/smb.service')) {
            exec("systemctl restart smb.service");
        } else {
            Log::critical("Couldn't find how to restart Samba. Please restart the Samba daemon manually.", Log::EVENT_CODE_SAMBA_RESTART_FAILED);
        }
    }

    public static function samba_check_vfs() {
        $vfs_is_ok = FALSE;

        // Samba version
        $version = str_replace('.', '', SambaUtils::samba_get_version());

        // CPU architecture (x86_64 or i386 or armv6l or armv5*)
        $arch = exec('uname -m');

        // Find VFS symlink
        if (file_exists('/usr/lib/x86_64-linux-gnu/samba/vfs')) {
            $source_libdir = '/usr/lib64'; # Makefile will always install Greyhole .so files in /usr/lib64, for x86_64 CPUs. @see Makefile
            $target_libdir = '/usr/lib/x86_64-linux-gnu';
        } else if ($arch == "x86_64") {
            $source_libdir = '/usr/lib64';
            $target_libdir = '/usr/lib64';

            # For Ubuntu, where even x86_64 install use /usr/lib
            if (file_exists('/usr/lib/samba/vfs')) {
                $target_libdir = '/usr/lib';
            }
        } else {
            $source_libdir = '/usr/lib';
            $target_libdir = '/usr/lib';
        }

        $vfs_file = "$target_libdir/samba/vfs/greyhole.so";

        Log::debug("Checking symlink at $vfs_file...");
        if (is_file($vfs_file)) {
            // Get VFS symlink target
            $vfs_target = @readlink($vfs_file);
            if (strpos($vfs_target, "/greyhole-samba$version.so") !== FALSE) {
                Log::debug("  Is OK.");
                $vfs_is_ok = TRUE;
            }
        }
        if (!$vfs_is_ok) {
            $vfs_target = "$source_libdir/greyhole/greyhole-samba$version.so";
            Log::warn("  Greyhole VFS module for Samba was missing, or the wrong version for your Samba. It will now be replaced with a symlink to $vfs_target", Log::EVENT_CODE_VFS_MODULE_WRONG);
            if (is_file($vfs_file)) {
                unlink($vfs_file);
            }
            if (!is_dir(dirname($vfs_file))) {
                mkdir(dirname($vfs_file));
            }
            gh_symlink($vfs_target, $vfs_file);
            SambaUtils::samba_restart();
        }

        // Bugfix for Ubuntu 14 (Trusty) that is missing libsmbd_base.so, which is used to compile the VFS
        if (file_exists("$target_libdir/samba/libsmbd_base.so.0") && !file_exists("$target_libdir/samba/libsmbd_base.so")) {
            Log::info("  Ubuntu 14 bugfix: creating symlink pointing to libsmbd_base.so.0 as libsmbd_base.so.");
            gh_symlink("$target_libdir/samba/libsmbd_base.so.0", "$target_libdir/samba/libsmbd_base.so");
        }

        // Checking shared libraries required by greyhole.so
        exec("ldd " . escapeshellarg($vfs_file) . " 2>/dev/null | grep 'not found'", $output);
        if (!empty($output)) {
            Log::warn("  Greyhole VFS module ($vfs_file) seems to be missing some required libraries. If you have issues connecting to your Greyhole-enabled shares, try to compile a new VFS module for Samba by running this command: /usr/share/greyhole/build_vfs.sh current", Log::EVENT_CODE_VFS_MODULE_WRONG);
        }
    }

}

?>
