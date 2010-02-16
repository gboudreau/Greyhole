# Daily fsck
0 0 * * *	root	/usr/bin/greyhole-executer --fsck --email-report > /dev/null

# Daily restart; clears caches etc. to keep memory usage in check
0 0 * * *	root	service greyhole restart

# cifs client workaround
# Ref: http://blog.dhampir.no/content/cifs-vfs-no-response-for-cmd-n-mid
@reboot		root	/sbin/modprobe cifs && echo 0 > /proc/fs/cifs/OplockEnabled
