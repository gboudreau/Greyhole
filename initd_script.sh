#!/bin/sh

### BEGIN INIT INFO
# Provides:          greyhole
# Required-Start:    mysqld smb
# Required-Stop:     mysqld smb
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: Starts the Greyhole daemon.
### END INIT INFO

### For CentOS
# chkconfig:         2345 20 80
# description:       Starts the Greyhole daemon.

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

if [ -f /etc/rc.d/init.d/functions ]; then
	. /etc/rc.d/init.d/functions
fi

if [ -f /lib/lsb/init-functions ]; then
	. /lib/lsb/init-functions
fi

DAEMON="greyhole"
PIDFILE="/var/run/greyhole.pid"
COMMAND="$1"

status () {
	PID=`cat $PIDFILE 2> /dev/null`
	if [ -f $PIDFILE -a "`ps ax | grep \"^ *$PID.*greyhole --daemon\" | grep -v grep | wc -l`" -eq "1" ]; then
		[ "$COMMAND" = "status" ] && echo "Greyhole is running."
		return 0
	else
		[ "$COMMAND" = "status" ] && echo "Greyhole isn't running."
		return 1
	fi
}

start () {
	echo -n "Starting Greyhole ... "
	status && echo "greyhole already running." && return 0
	n=`grep daemon_niceness /etc/greyhole.conf | grep -v '#.*daemon_niceness' | sed 's/^.*= *\(.*\) *$/\1/'`
	if [ "$n" = "" ]; then
		n=1
	fi
	if [ -f /sbin/start-stop-daemon ]; then
		start-stop-daemon --start --pidfile $PIDFILE --exec /usr/bin/php --nicelevel $n --background -- /usr/bin/greyhole --daemon
		RETVAL=$?
		if [ $RETVAL -eq 0 ]; then
			echo "OK"
		else
			echo "FAILED"
		fi
	else
		daemon +5 --check $DAEMON $0 daemon_start
		RETVAL=$?
		if [ $RETVAL -eq 0 ]; then
			success $"$base startup"
		else
			failure $"$base startup"
		fi
		echo
	fi
	[ $RETVAL -eq 0 ] && ps ax | grep "greyhole --daemon" | grep -v grep | tail -1 | awk '{print $1}' > $PIDFILE
	return $RETVAL
}

stop () {
	echo -n "Shutting down Greyhole ... "
	if [ -f /sbin/start-stop-daemon ]; then
		start-stop-daemon --stop --quiet --retry=TERM/10/KILL/5 --pidfile $PIDFILE --exec /usr/bin/php
		RETVAL=$?
		[ $RETVAL -eq 0 ] && echo "OK" || echo "FAILED"
	else
		killproc $DAEMON
		RETVAL=$?
		[ $RETVAL -eq 0 ] && success $"$base shutdown" || failure $"$base shutdown"
		echo
	fi
	[ $RETVAL -eq 0 ] && rm -f $PIDFILE
	return $ret
}

restart () {
	stop
	sleep 1
	start
}

condrestart () {
	status && restart || :
}

case "$COMMAND" in
	status)
		status
		;;
	start)
		start
		;;
	stop)
		stop
		;;
	restart)
		restart
		;;
	force-reload)
		restart
		;;
	condrestart)
		condrestart
		;;
	*)
		echo $"Usage: $0 {start|stop|status|condrestart|restart}"
		exit 1
		;;
esac

exit $?
