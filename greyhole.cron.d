# cifs client workaround
# Ref: http://blog.dhampir.no/content/cifs-vfs-no-response-for-cmd-n-mid
@reboot		root	/sbin/modprobe cifs && echo 0 > /proc/fs/cifs/OplockEnabled
