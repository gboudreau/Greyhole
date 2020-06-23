# cifs client workaround
# Ref: http://blog.dhampir.no/content/cifs-vfs-no-response-for-cmd-n-mid
@reboot    root  /sbin/modprobe cifs enable_oplocks=n; if [ -x /proc/fs/cifs/OplockEnabled ]; then echo 0 > /proc/fs/cifs/OplockEnabled; fi

# On-boot initialization: create mem spool folder, mark all dangling tasks as completed, etc.
@reboot    root  /usr/bin/greyhole --create-mem-spool >/dev/null
@reboot    root  /usr/bin/greyhole --boot-init

# Process the Samba spool as soon as possible (daemon might be busy)
* * * * *  root  /usr/bin/greyhole --process-spool --keepalive >/dev/null
