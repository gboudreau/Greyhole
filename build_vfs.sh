#!/bin/bash

set -e

if [[ $# -lt 1 ]]; then
	>&2 echo "Build the Greyhole VFS module for a specific Samba version."
	>&2 echo "  Usage: $0 SAMBA_VERSION"
	>&2 echo "  Specify 'current' as the SAMBA_VERSION to compile the VFS module for the currently installed Samba version, and create the required symlink in the Samba VFS library folder."
	exit 1
fi

if [[ $EUID -ne 0 ]]; then
	>&2 echo "You need to execute this script as root (or using sudo)."
	exit 2
fi

ceol=$(tput el)

if [[ -z ${GREYHOLE_VFS_BUILD_DIR} ]]; then
	GREYHOLE_VFS_BUILD_DIR="/usr/share/greyhole/vfs-build"
fi

mkdir -p "${GREYHOLE_VFS_BUILD_DIR}"
cd "${GREYHOLE_VFS_BUILD_DIR}"

###

if [[ -f /usr/bin/python2 ]]; then
	alias python='/usr/bin/python2'
fi

version=$1
create_symlink=0
if [[ ${version} = "current" ]]; then
	create_symlink=1
	version=`/usr/sbin/smbd --version | awk '{print $2}' | awk -F'-' '{print $1}'`
fi

echo "Compiling Greyhole VFS module for samba-${version}... "

if [[ -z ${GREYHOLE_INSTALL_DIR} ]]; then
	echo "  Downloading Greyhole source code... "
	set +e
	GH_VERSION=`greyhole --version 2>&1 | grep version | head -1 | awk '{print $3}' | awk -F',' '{print $1}'`
	rm -f "greyhole-${GH_VERSION}.tar.gz"
	curl -LOs "https://github.com/gboudreau/Greyhole/releases/download/${GH_VERSION}/greyhole-${GH_VERSION}.tar.gz" 2>&1
	set -e
	if [[ -f "greyhole-${GH_VERSION}.tar.gz" && "${GH_VERSION}" != "%VERSION%" ]]; then
		GREYHOLE_INSTALL_DIR="`pwd`/greyhole-${GH_VERSION}"
		rm -rf "${GREYHOLE_INSTALL_DIR}"
		tar zxf "greyhole-${GH_VERSION}.tar.gz" && rm -f "greyhole-${GH_VERSION}.tar.gz"
	else
		GREYHOLE_INSTALL_DIR="`pwd`/Greyhole-master"
		rm -rf "${GREYHOLE_INSTALL_DIR}"
		curl -LOs "https://github.com/gboudreau/Greyhole/archive/master.zip"
		unzip -q master.zip && rm master.zip
	fi
fi

if [[ ! -d samba-${version} ]]; then
	echo "  Downloading Samba source code... "
	curl -LOs "http://samba.org/samba/ftp/stable/samba-${version}.tar.gz" && tar zxf samba-${version}.tar.gz && rm -f samba-${version}.tar.gz
fi

M=`echo ${version} | awk -F'.' '{print $1}'` # major
m=`echo ${version} | awk -F'.' '{print $2}'` # minor
B=`echo ${version} | awk -F'.' '{print $3}'` # build

cd samba-${version}
NEEDS_CONFIGURE=
if  [[ ! -f source3/modules/vfs_greyhole.c ]]; then
	NEEDS_CONFIGURE=1
fi
set +e
grep -i vfs_greyhole source3/wscript >/dev/null
if  [[ $? -ne 0 ]]; then
	NEEDS_CONFIGURE=1
fi
set -e

rm -f source3/modules/vfs_greyhole.c source3/bin/greyhole.so bin/default/source3/modules/libvfs*greyhole.so
if [[ -f ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.${m}.c ]]; then
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.${m}.c source3/modules/vfs_greyhole.c
else
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.x.c source3/modules/vfs_greyhole.c
fi

if [[ ${M} -eq 3 ]]; then
	cd source3
fi

if [[ "${NEEDS_CONFIGURE}" = "1" ]]; then
	echo "  Running 'configure'..."
	if [[ ${M} -eq 3 ]]; then
        ./configure >gh_vfs_build.log 2>&1 &
        PROC_ID=$!
    else
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-${M}.${m}.patch >/dev/null
        CONF_OPTIONS="--enable-debug --disable-symbol-versions --without-acl-support --without-ldap --without-ads --without-pam --without-ad-dc"
        if [[ ${m} -gt 6 ]]; then
            CONF_OPTIONS="${CONF_OPTIONS} --disable-python"
        fi
        if [[ ${m} -gt 9 ]]; then
            CONF_OPTIONS="${CONF_OPTIONS} --without-json --without-libarchive"
        elif [[ ${m} -gt 8 ]]; then
            CONF_OPTIONS="${CONF_OPTIONS} --without-json-audit --without-libarchive"
        fi
        ./configure ${CONF_OPTIONS} >gh_vfs_build.log 2>&1 &
        PROC_ID=$!
	fi

	while kill -0 "$PROC_ID" >/dev/null 2>&1; do
		sleep 1
        echo -en "\r${ceol}    Progress: "
        echo -n "`tail -n 1 gh_vfs_build.log`"
	done
	echo -en "\r${ceol}"

	if [[ ${M} -eq 3 ]]; then
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-${M}.${m}.patch >/dev/null
    fi
fi

# Patch for compiling Samba on Alpine Linux
sed -i -e 's/NETDB_INTERNAL/-1/' nsswitch/wins.c
sed -i -e 's/NETDB_SUCCESS/0/' nsswitch/wins.c
echo '#include <sys/types.h>' > file.txt
sed -i '/#include <stdbool.h>/r file.txt' -- lib/tevent/tevent.h
rm file.txt

echo "  Compiling Samba..."
make -j >gh_vfs_build.log 2>&1 &
PROC_ID=$!

while kill -0 "$PROC_ID" >/dev/null 2>&1; do
	sleep 1
    echo -en "\r${ceol}    Progress: "
    echo -n "`tail -n 1 gh_vfs_build.log`"
done

echo -en "\r${ceol}"
echo

V=`echo ${version} | awk -F'.' '{print $1$2}'`
export GREYHOLE_COMPILED_MODULE="`pwd`/greyhole-samba${V}.so"

if [[ ${M} -eq 3 ]]; then
	COMPILED_MODULE="`pwd`/source3/bin/greyhole.so"
else
	COMPILED_MODULE="`ls -1 $(pwd)/bin/default/source3/modules/libvfs*greyhole.so`"
fi

if [[ ! -f ${COMPILED_MODULE} ]]; then
	>&2 echo "Failed to compile Greyhole VFS module."
	exit 3
fi

cp ${COMPILED_MODULE} ${GREYHOLE_COMPILED_MODULE}

echo "Greyhole VFS module successfully compiled into ${GREYHOLE_COMPILED_MODULE}"

if [[ ${create_symlink} -eq 1 ]]; then
	echo
    echo "Creating the required symlink in the Samba VFS library folder."
    if [[ -d /usr/lib/x86_64-linux-gnu/samba/vfs ]]; then
        LIBDIR=/usr/lib/x86_64-linux-gnu
    elif [[ -d /usr/lib64/samba/vfs ]]; then
        LIBDIR=/usr/lib64
    else
        LIBDIR=/usr/lib
    fi
    rm -f ${LIBDIR}/samba/vfs/greyhole.so
    ln -s ${GREYHOLE_COMPILED_MODULE} ${LIBDIR}/samba/vfs/greyhole.so
    echo "Done."
    echo
fi
