# Daily fsck
0 0 * * 1-6	root	/usr/bin/greyhole --fsck --email-report > /dev/null

# Weekly fsck
0 0 * * 7	root	/usr/bin/greyhole --fsck --email-report --walk-graveyard > /dev/null

# Daily restart; clears caches etc. to keep memory usage in check
59 23 * * *	root	/sbin/service greyhole condrestart > /dev/null

# cifs client workaround
# Ref: http://blog.dhampir.no/content/cifs-vfs-no-response-for-cmd-n-mid
@reboot		root	/sbin/modprobe cifs && echo 0 > /proc/fs/cifs/OplockEnabled
