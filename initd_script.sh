#!/bin/sh

# Copyright 2009 Guillaume Boudreau
# 
# This file is part of Greyhole.
# 
# Greyhole is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
# 
# Greyhole is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
# 
# You should have received a copy of the GNU General Public License
# along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.


### BEGIN INIT INFO
# Provides:          greyhole
# Required-Start:    $network $local_fs $remote_fs mysqld
# Required-Stop:     $network $local_fs $remote_fs mysqld
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: start Greyhole executer/balancer
### END INIT INFO

# Where the application was installed
APP_DIR="/usr/local/greyhole"

if [ -f /lib/lsb/init-functions ]; then
	. /lib/lsb/init-functions
fi

# Allow status as non-root.
if [ "$1" = status ]; then
        PID=`ps auxwww | grep "greyhole-executer --daemon" | grep -v grep | awk '{print $2}'`
        if [ "$PID" = "" ]; then
                echo "Greyhole isn't running."
        else
                echo "Greyhole is running (PID $PID)."
        fi
        exit $?
fi

# Check that we can write to it... so non-root users stop here
[ -w /etc/samba/smb.conf ] || exit 4

case "$1" in
	start)
		PID=`ps auxwww | grep "greyhole-executer --daemon" | grep -v grep | awk '{print $2}'`
		if [ "$PID" = "" ]; then
			echo Starting Greyhole...
			cd "$APP_DIR"
			nohup ionice -c 2 -n 7 "$APP_DIR/greyhole-executer" --daemon &
			echo Done
		else
			echo "Greyhole is already running (PID $PID)."
		fi
		;;
	stop)
		PID=`ps auxwww | grep "greyhole-executer --daemon" | grep -v grep | awk '{print $2}'`
		if [ "$PID" != "" ]; then
			echo Stopping Greyhole...
			kill $PID
			echo Done
		else
			echo "Greyhole isn't running."
		fi
		;;
	restart)
		$0 stop
		sleep 2
		$0 start
		;;
	*)
		echo "Usage: /etc/init.d/greyhole {start|stop|restart|status}"
		exit 1
		;;
esac

exit 0
