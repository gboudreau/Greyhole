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

if [ "${EVENT_TYPE}" = "warning" ] || [ "${EVENT_TYPE}" = "error" ] || [ "${EVENT_TYPE}" = "critical" ]; then
    # Expected log format: Jan 20 07:52:56 WARN initialize: ...
    IFS=' '
    arrLOG=(${LOG})
    EVENT_DATE="${arrLOG[0]} ${arrLOG[1]} ${arrLOG[2]}"
    LOG_LEVEL=${arrLOG[3]}
    ACTION=${arrLOG[4]%?} # See ACTION_* defines in the includes/Log.php file: https://github.com/gboudreau/Greyhole/blob/master/includes/Log.php#L21
    MESSAGE=(${arrLOG[@]:5})
    MESSAGE="${MESSAGE[@]}"
    echo "  Tokenize'd log: EVENT_DATE=${EVENT_DATE} ; LOG_LEVEL=${LOG_LEVEL} ; ACTION=${ACTION} ; MESSAGE=${MESSAGE}" >> /tmp/gh_log.txt
else
    echo "Warning: Unknown event received: ${EVENT_TYPE}" >> /tmp/gh_log.txt
    exit 1
fi
