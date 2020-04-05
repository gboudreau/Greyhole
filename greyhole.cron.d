# cifs client workaround
# Ref: http://blog.dhampir.no/content/cifs-vfs-no-response-for-cmd-n-mid
@reboot    root  /sbin/modprobe cifs enable_oplocks=n; if [ -x /proc/fs/cifs/OplockEnabled ]; then echo 0 > /proc/fs/cifs/OplockEnabled; fi
@reboot    root  /usr/bin/greyhole --create-mem-spool >/dev/null
@reboot    root  /usr/bin/greyhole --boot-init
* * * * *  root  /usr/bin/greyhole --process-spool --keepalive
