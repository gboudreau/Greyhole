#!/bin/bash

# Copyright 2011 Guillaume Boudreau
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


##########
# Synopsys
# This script is used to build new Greyhole versions. It will:
#   1. Create a source TGZ, and RPM & DEB packages
#   2. Upload the new files on the greyhole.net host
#   3. Update the APT & YUM repositories
#   4. Install the new version locally (using apt-get or yum, depending on what's available)
#   5. Upload the new files to GitHub
#	6. Tag the git branch
#   7. Update the CHANGELOG on http://www.greyhole.net/releases/CHANGELOG
#   8. Send 'New version available' notifications by email, Twitter (@GreyholeApp), Facebook (Greyhole) and IRC (#greyhole on Freenode).


#######
# Setup
#   sudo easy_install twitter
#   curl -O https://raw.github.com/dtompkins/fbcmd/master/fbcmd_update.php; sudo php fbcmd_update.php install && rm fbcmd_update.php && fbcmd
#   echo -n 'github_username:github_password' > .github_userpwd
#   echo -n 'nickserv_password' > .irc_password


#######
# Usage

if [ $# -lt 1 -o $# -gt 2 ]; then
	echo "Usage: $0 <version> [build number]"
	exit 1
fi


########
# Config

# Host that will be used for SSH/SCP; specify needed port/user/etc. in your .ssh/config
HOST='ssh.greyhole.net'

# Path, on $HOST, to upload packages to; can be relative to user's home.
PATH_TO_RELEASES='www/greyhole.net/releases'

# Path, on $HOST, that contain the scripts to be used to update the APT/YUM repositories; can be relative to user's home.
PATH_TO_REPOS_UPDATER='www/greyhole.net'

# URL of the CHANGELOG file; URL should point to $HOST:$PATH_TO_RELEASES/CHANGELOG
CHANGELOG_URL='http://www.greyhole.net/releases/CHANGELOG'

# Email address that will receive a new version notification, including the CHANGELOG
ANNOUNCE_EMAIL='releases-announce@greyhole.net'

# End of Config
###############


export VERSION=$1

if [ $# -eq 2 ]; then
	export BUILD_NUMBER="$2"
else
	export BUILD_NUMBER="1"
fi

# Clean unwanted files
find . -name "._*" -delete
find . -name ".DS_Store" -delete
find . -name ".AppleDouble" -delete


################
# Build packages

# RPM
archs='i386 armv5tel x86_64'
for arch in $archs; do
	export ARCH=$arch
	make rpm
	make amahi-rpm
done

# DEB
archs='i386 amd64'
for arch in $archs; do
	export ARCH=$arch
	make deb
done

if [ `whoami` != "gb" ]; then
	exit
fi

#########################################
# Transfer files to HOST:PATH_TO_RELEASES

if [ "$BUILD_NUMBER" = "1" ]; then
	scp release/greyhole*$VERSION.tar.gz ${HOST}:${PATH_TO_RELEASES}/.
fi
scp release/*greyhole-$VERSION-*.src.rpm ${HOST}:${PATH_TO_RELEASES}/rpm/src/.
scp release/*greyhole-$VERSION-*.x86_64.rpm ${HOST}:${PATH_TO_RELEASES}/rpm/x86_64/.
scp release/*greyhole-$VERSION-*.i386.rpm ${HOST}:${PATH_TO_RELEASES}/rpm/i386/.
scp release/*greyhole-$VERSION-*.armv5tel.rpm ${HOST}:${PATH_TO_RELEASES}/rpm/armv5tel/.
scp release/greyhole-$VERSION-*.deb ${HOST}:${PATH_TO_RELEASES}/deb/.


##########################
# Update YUM/APT repo data

ssh ${HOST} ${PATH_TO_REPOS_UPDATER}/update_yum_repodata.sh

# update_deb_repodata.sh needs to be called from an interactive session on $HOST, because it needs the GPG secret key passphrase to work!
echo
echo "*******************"
echo "You now need to execute the following command in the SSH shell that will open:"
echo "  ${PATH_TO_REPOS_UPDATER}/update_deb_repodata.sh $VERSION ; exit"
echo "*******************"
ssh ${HOST}


############################################################
# Update local greyhole package to latest, from YUM/APT repo

if [ -x /usr/bin/yum ]; then
	sudo yum update greyhole
	sudo rm /usr/bin/greyhole /usr/bin/greyhole-dfree
	sudo ln -s ~/greyhole/greyhole /usr/bin/greyhole
	sudo ln -s ~/greyhole/greyhole-dfree /usr/bin/greyhole-dfree
	if [ -f /includes/common.php ]; then sudo rm /includes/common.php; fi
	sudo ln -s ~/greyhole/includes/common.php /includes/common.php
	if [ -f /includes/common.php ]; then sudo rm /includes/sql.php; fi
	sudo ln -s ~/greyhole/includes/sql.php /includes/sql.php
	sudo service greyhole condrestart
elif [ -x /usr/bin/apt-get ]; then
	sudo apt-get update && sudo apt-get install greyhole
	sudo rm /usr/bin/greyhole /usr/bin/greyhole-dfree
	sudo ln -s ~/greyhole/greyhole /usr/bin/greyhole
	sudo ln -s ~/greyhole/greyhole-dfree /usr/bin/greyhole-dfree
	if [ -f /includes/common.php ]; then sudo rm /includes/common.php; fi
	sudo ln -s ~/greyhole/includes/common.php /includes/common.php
	if [ -f /includes/common.php ]; then sudo rm /includes/sql.php; fi
	sudo ln -s ~/greyhole/includes/sql.php /includes/sql.php
	sudo restart greyhole
fi
chmod +x ~/greyhole/greyhole ~/greyhole/greyhole-dfree


############################
# Upload new files to GitHub

./github-auto-post-downloads.php $VERSION $BUILD_NUMBER


####################
# Tag the git branch
git clone git@github.com:gboudreau/Greyhole.git /tmp/Greyhole.git
if [ "$BUILD_NUMBER" = "1" ]; then
	(cd /tmp/Greyhole.git; git tag $VERSION; git push --tags)
else
	(cd /tmp/Greyhole.git; git tag $VERSION-$BUILD_NUMBER; git push --tags)
fi
rm -rf /tmp/Greyhole.git


##################
# Update CHANGELOG

if [ "$BUILD_NUMBER" = "1" ]; then
	cd release
		LAST_TGZ=`ls -1atr *.tar.gz | grep -v 'hda-' | grep -B 1 greyhole-$VERSION | head -1`
		tar --wildcards -x "*/CHANGES" -f $LAST_TGZ
		tar --wildcards -x "*/CHANGES" -f greyhole-$VERSION.tar.gz
	
		diff -b */CHANGES | sed -e 's/^> /- /' | grep -v '^[0-9]*a[0-9]*\,[0-9]*$' > /tmp/gh_changelog
	
		find . -type d -name "greyhole-*" -exec rm -rf {} \; > /dev/null 2>&1
		find . -type d -name "hda-greyhole-*" -exec rm -rf {} \; > /dev/null 2>&1
		
		# Update $CHANGELOG_URL
		echo "What's new in $VERSION" > CHANGELOG
		echo "--------------------" >> CHANGELOG
		cat /tmp/gh_changelog >> CHANGELOG
		echo >> CHANGELOG
		curl -s "${CHANGELOG_URL}" >> CHANGELOG
		scp CHANGELOG ${HOST}:${PATH_TO_RELEASES}/CHANGELOG
	cd ..
fi


############################################
# Send notifications to Twitter/FB/IRC/email

if [ "$BUILD_NUMBER" = "1" ]; then
	/usr/local/bin/twitter set "New version available: $VERSION - ChangeLog: http://t.co/hZheYwg"
	/usr/local/bin/fbcmd PPOST Greyhole "New version available: $VERSION - Downloads: http://www.greyhole.net/download/ or just use your package manager to update." 'ChangeLog' "${CHANGELOG_URL}"
	./irc_notif.sh "New version available $VERSION - Changelog: http://www.greyhole.net/releases/CHANGELOG" $VERSION
else
	/usr/local/bin/twitter set "New packages available: $VERSION-$BUILD_NUMBER. If you couldn't install the previous packages, try this one."
	/usr/local/bin/fbcmd PPOST Greyhole "New packages available: $VERSION-$BUILD_NUMBER. If you couldn't install the previous packages, try this one." 'ChangeLog' "${CHANGELOG_URL}"
	./irc_notif.sh "New packages available $VERSION-$BUILD_NUMBER. If you couldn't install the previous packages, try this one." $VERSION
fi

###
