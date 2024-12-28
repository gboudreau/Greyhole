#!/bin/bash

# Copyright 2011-2014 Guillaume Boudreau
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
#   3. Update the APT & YUM repositories (manual intervention required)
#   4. Install the new version locally (using apt-get or yum, depending on what's available)
#	5. Tag the git branch
#   6. Update the CHANGELOG on https://www.greyhole.net/releases/CHANGELOG


#######
# Setup
#   sudo yum -y install dpkg
#   echo -n 'github_api_token' > build/.github_token


#######
# Usage

if [ $# -lt 1 ] || [ $# -gt 2 ]; then
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
CHANGELOG_URL='https://www.greyhole.net/releases/CHANGELOG'

# End of Config
###############

cd "$(dirname "${BASH_SOURCE[0]}")/.."

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

set -e
git pull
set +e

################
# Build packages

cp build/Makefile .

# RPM
archs='x86_64 i386 armv5tel'
for arch in $archs; do
	export ARCH=$arch
	make rpm
done

# DEB
archs='amd64 i386 arm64 armhf'
for arch in $archs; do
	export ARCH=$arch
	make deb
done

rm ./Makefile

if [ "$(whoami)" != "gb" ]; then
	exit
fi

#########################################
# Transfer files to HOST:PATH_TO_RELEASES

if [ "$BUILD_NUMBER" = "1" ]; then
	scp release/greyhole*"$VERSION".tar.gz ${HOST}:${PATH_TO_RELEASES}/.
fi
scp release/*greyhole-"$VERSION"-*.src.rpm ${HOST}:${PATH_TO_RELEASES}/rpm/src/.
scp release/*greyhole-"$VERSION"-*.x86_64.rpm ${HOST}:${PATH_TO_RELEASES}/rpm/x86_64/.
scp release/*greyhole-"$VERSION"-*.i386.rpm ${HOST}:${PATH_TO_RELEASES}/rpm/i386/.
scp release/*greyhole-"$VERSION"-*.armv5tel.rpm ${HOST}:${PATH_TO_RELEASES}/rpm/armv5tel/.
scp release/greyhole-"$VERSION"-*.deb ${HOST}:${PATH_TO_RELEASES}/deb/.


##########################
# Update YUM/APT repo data

ssh ${HOST} /opt/docker-services/greyhole-repos/run.sh /var/www/html/greyhole.net/update_yum_repodata.sh

# update_deb_repodata.sh needs to be called from an interactive session on $HOST, because it needs the GPG secret key passphrase to work!
echo
echo "*******************"
echo "You now need to execute the following command in the SSH shell that will open:"
echo "  ${PATH_TO_REPOS_UPDATER}/update_deb_repodata.sh $VERSION $BUILD_NUMBER && exit"
echo "*******************"
ssh ${HOST}


############################################################
# Update local greyhole package to latest, from YUM/APT repo
if [ -f /usr/bin/greyhole ]; then
    set -e
    if [ -x /usr/bin/yum ]; then
        sudo yum update greyhole
        sudo rm /usr/bin/greyhole /usr/bin/greyhole-dfree /usr/bin/greyhole-php
        sudo ln -s ~/greyhole/greyhole /usr/bin/greyhole
        sudo ln -s ~/greyhole/greyhole-dfree /usr/bin/greyhole-dfree
        sudo ln -s ~/greyhole/greyhole-php /usr/bin/greyhole-php
        chmod +x ~/greyhole/greyhole ~/greyhole/greyhole-dfree ~/greyhole/greyhole-php
        sudo service greyhole condrestart
    elif [ -x /usr/bin/apt-get ]; then
        sudo apt-get update && sudo apt-get install greyhole
        sudo rm /usr/bin/greyhole /usr/bin/greyhole-dfree /usr/bin/greyhole-php
        sudo ln -s ~/greyhole/greyhole /usr/bin/greyhole
        sudo ln -s ~/greyhole/greyhole-dfree /usr/bin/greyhole-dfree
        sudo ln -s ~/greyhole/greyhole-php /usr/bin/greyhole-php
        chmod +x ~/greyhole/greyhole ~/greyhole/greyhole-dfree ~/greyhole/greyhole-php
        sudo service greyhole restart
    fi
    set +e
fi

####################
# Tag the git branch
git clone git@github.com:gboudreau/Greyhole.git /tmp/Greyhole.git
if [ "$BUILD_NUMBER" = "1" ]; then
	(cd /tmp/Greyhole.git; git tag "$VERSION"; git push --tags)
else
	(cd /tmp/Greyhole.git; git tag "$VERSION-$BUILD_NUMBER"; git push --tags)
fi
rm -rf /tmp/Greyhole.git


##################
# Update CHANGELOG

if [ "$BUILD_NUMBER" = "1" ]; then
	cd release
		# shellcheck disable=SC2010
		LAST_TGZ=$(ls -1atr ./*.tar.gz | grep -v 'hda-' | grep -B 1 "greyhole-$VERSION" | head -1)
		tar --wildcards -x "*/CHANGES" -f "$LAST_TGZ"
		tar --wildcards -x "*/CHANGES" -f "greyhole-$VERSION.tar.gz"
	
		diff -b ./*/CHANGES | sed -e 's/^> /- /' | grep -v '^[0-9]*a[0-9]*\,[0-9]*$' > /tmp/gh_changelog
	
		find . -type d -name "greyhole-*" -exec rm -rf {} \; > /dev/null 2>&1
		find . -type d -name "hda-greyhole-*" -exec rm -rf {} \; > /dev/null 2>&1
		
		# Update $CHANGELOG_URL
		{
		    echo "What's new in $VERSION"
		    echo "---------------------"
		    cat /tmp/gh_changelog
		    echo
		    curl -s "${CHANGELOG_URL}"
		} > CHANGELOG
		scp CHANGELOG ${HOST}:${PATH_TO_RELEASES}/CHANGELOG
	cd ..
fi

##########################
# Create release on Github

echo "Creating release on Github.com"
file_to_upload="release/greyhole-$VERSION.tar.gz"
json=$(echo -n "$VERSION" | php -r '$version=file_get_contents("php://stdin");$changelog=trim(file_get_contents("/tmp/gh_changelog"));echo json_encode(array("tag_name"=>$version,"name"=>$version,"body"=>$changelog));')
curl -s -H "Authorization: token $(cat build/.github_token)" -H "Accept: application/vnd.github.manifold-preview" -X POST -d "$json" https://api.github.com/repos/gboudreau/greyhole/releases > /tmp/response.json
filename=$(basename "$file_to_upload")
upload_url=$(echo "$filename" | php -r '$o=json_decode(file_get_contents("/tmp/response.json"));echo str_replace("{?name,label}", "?name=".file_get_contents("php://stdin"), $o->upload_url);')
curl -s -H "Authorization: token $(cat build/.github_token)" -H "Accept: application/vnd.github.manifold-preview" -X POST -H "Content-Type: application/x-gzip" --data-binary @"$file_to_upload" "$upload_url"
php -r '$o=json_decode(file_get_contents("/tmp/response.json"));echo $o->html_url."\n";'

#########################
# Create new Docker image

# Initial setup (for --push)
# sudo docker login --username gboudreau

# Requirements for multi-arch builds:
# sudo -i
# apt-get install -y --no-install-recommends qemu-user-static binfmt-support
# update-binfmts --enable qemu-arm
# update-binfmts --display qemu-arm
# echo ':arm:M::\x7fELF\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x02\x00\x28\x00:\xff\xff\xff\xff\xff\xff\xff\x00\xff\xff\xff\xff\xff\xff\xff\xff\xfe\xff\xff\xff:/usr/bin/qemu-arm-static:' > /proc/sys/fs/binfmt_misc/register
# docker run --rm --privileged multiarch/qemu-user-static --reset -p yes
# docker buildx create --name mybuilder --driver docker-container --use
# docker buildx inspect --bootstrap

echo "Creating new Docker images..."
cd ~/docker-services/samba-greyhole/docker-build/
sudo docker buildx use mybuilder
PLATFORMS="linux/amd64,linux/arm64,linux/arm/v7,linux/arm/v6"
sudo docker buildx build --platform ${PLATFORMS} --push -t "gboudreau/samba-greyhole:$VERSION" -t "gboudreau/samba-greyhole:latest" --build-arg "GREYHOLE_VERSION=$VERSION" .
echo

###
