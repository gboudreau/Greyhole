#!/usr/bin/env bash
#
# Copyright 2017 Guillaume Boudreau
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
#

EVENT_TYPE="$1"
EVENT_CODE="$2"
LOG="$3"

echo "Received hook with event_type = ${EVENT_TYPE}, event_code = ${EVENT_CODE}" >> /tmp/gh_log.txt

if [ "${EVENT_TYPE}" = "fsck" ]; then
	echo "  fsck completed. Report:" >> /tmp/gh_log.txt
	echo "${LOG}" >> /tmp/gh_log.txt
else
	echo "Warning: Unknown event received: ${EVENT_TYPE}" >> /tmp/gh_log.txt
	exit 1
fi
