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
SHARE="$2"
PATH_ON_SHARE="$3"

case "${EVENT_TYPE}" in
	create|edit|rename)
		FILE_SIZE=`ls -l "/mnt/samba/${SHARE}/${PATH_ON_SHARE}" | awk '{print $5}'`
		echo "The file on share ${SHARE} at ${PATH_ON_SHARE} was ${EVENT_TYPE}-ed. File size: ${FILE_SIZE}" >> /tmp/gh_files_changed.txt
		;;
	delete)
		echo "The file on share ${SHARE} at ${PATH_ON_SHARE} was ${EVENT_TYPE}d." >> /tmp/gh_files_changed.txt
		;;
	mkdir)
		echo "The folder on share ${SHARE} at ${PATH_ON_SHARE} was created." >> /tmp/gh_files_changed.txt
		;;
	rmdir)
		echo "The folder on share ${SHARE} at ${PATH_ON_SHARE} was deleted." >> /tmp/gh_files_changed.txt
		;;
	*)
		echo "Warning: Unknown event received: ${EVENT_TYPE}" >> /tmp/gh_files_changed.txt
		exit 1
		;;
esac
