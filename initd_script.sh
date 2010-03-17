#!/bin/sh
#
### BEGIN INIT INFO
# Provides:          greyhole
# Required-Start:    $network $local_fs $remote_fs mysqld smb
# Required-Stop:     $network $local_fs $remote_fs mysqld smb
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: start Greyhole executer/balancer
### END INIT INFO

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

DAEMON="greyhole-executer"
PIDFILE="/var/run/greyhole.pid"
LOCKFILE="/var/lock/subsys/greyhole"

status () {
	if [ -f $PIDFILE ]; then
		echo "Greyhole is running."
	else
		echo "Greyhole isn't running."
	fi
	exit $?
}

daemon_start () {
	ionice -c 2 -n 7 $DAEMON --daemon > /dev/null &
	RETVAL=$?
	return $RETVAL
}

start () {
	if [ -f $LOCKFILE ]; then
		return 0
	fi
	echo -n $"Starting Greyhole ... "
	daemon +5 --check $DAEMON $0 daemon_start
	RETVAL=$?
	if [ $RETVAL -eq 0 ]; then
		touch $LOCKFILE
		pidof $DAEMON > $PIDFILE
		success $"$base startup"
	else
		failure $"$base startup"
	fi
	echo
	return $RETVAL
}

stop () {
	echo -n $"Shutting down Greyhole: "
	killproc $DAEMON
	RETVAL=$?
	[ $RETVAL -eq 0 ] && success $"$base shutdown" || failure $"$base shutdown"
	[ $RETVAL -eq 0 ] && rm -f $LOCKFILE $PIDFILE
	echo
	return $ret
}

restart () {
	stop
	sleep 1
	start
}

condrestart () {
    [ -e $LOCKFILE ] && restart || :
}

case "$1" in
	status)
		status
		;;
	start)
		start
		;;
	daemon_start)
		daemon_start
		;;
	stop)
		stop
		;;
	restart)
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
