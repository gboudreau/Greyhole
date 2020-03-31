#!/bin/bash

# See samba-modules/HOWTO for details on usage

set -e

export GREYHOLE_INSTALL_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
export GREYHOLE_VFS_BUILD_DIR=$(dirname "${GREYHOLE_INSTALL_DIR}")

###

ARCH="`uname -i`"
if [ "$ARCH" = "unknown" ]; then
    ARCH="`uname -m`"
fi
if [ "$ARCH" = "armv6l" ]; then
    ARCH="armhf"
fi
if [ "$ARCH" = "i686" ]; then
    ARCH="i386"
fi

for version in 4.12.0 4.11.0 4.10.0 4.9.0 4.8.0 4.7.0 4.6.0 4.5.0 4.4.0; do
	source "${GREYHOLE_INSTALL_DIR}/build_vfs.sh" ${version}

	M=`echo ${version} | awk -F'.' '{print $1}'` # major
	m=`echo ${version} | awk -F'.' '{print $2}'` # minor
	B=`echo ${version} | awk -F'.' '{print $3}'` # build

    mkdir -p ${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/
    cp ${GREYHOLE_COMPILED_MODULE} ${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-$ARCH.so
    echo -n "  was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-$ARCH.so

	echo
	echo "********"
	echo
done
