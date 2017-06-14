#!/bin/bash

## Note to self:
# Run this script on:
#   - x86_64: gb@fileserver2:~
#   - i386:   bougu@macbook:~/VirtualBox VMs/Ubuntu (32-bit)
#   - ARM:    gb@fileserver2:~/qemu-arm ; ./boot.sh ; sleep xx ; ssh -p 2223 gb@127.0.0.1

# rsync -av --exclude .git --exclude release gb@192.168.155.88:Greyhole/ ~/Greyhole
# screen
# ~/Greyhole/build_vfs.sh

export GREYHOLE_INSTALL_DIR="/home/gb/Greyhole"
export HOME="/home/gb"

###

ARCH="`uname -i`"
if [ "$ARCH" = "unknown" ]; then
    ARCH="armhf"
fi

cd "$HOME"

for version in 4.6.0 4.5.0 4.4.0 4.3.0 4.2.0 4.1.4 4.0.14 3.6.9 3.5.4 3.4.9; do
	echo "Working on samba-${version} ... "
	if [ ! -d samba-${version} ]; then
		wget http://samba.org/samba/ftp/stable/samba-${version}.tar.gz && tar zxf samba-${version}.tar.gz && rm -f samba-${version}.tar.gz
	fi

	M=`echo ${version} | awk -F'.' '{print $1}'` # major
	m=`echo ${version} | awk -F'.' '{print $2}'` # minor
	B=`echo ${version} | awk -F'.' '{print $3}'` # build

	cd samba-${version}
		NEEDS_CONFIGURE=
		if  [ ! -f source3/modules/vfs_greyhole.c ]; then
			NEEDS_CONFIGURE=1
		fi

	    rm -f source3/modules/vfs_greyhole.c source3/bin/greyhole.so bin/default/source3/modules/libvfs*greyhole.so
	    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.x.c source3/modules/vfs_greyhole.c

		if [ ${M} -eq 3 ]; then
			cd source3
		fi

		if [ ! -x ${NEEDS_CONFIGURE} ]; then
			if [ ${M} -eq 3 ]; then
	            ./configure
	            patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-${M}.${m}.patch
		    else
		        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-${M}.${m}.patch
		        ./configure --enable-debug --enable-selftest --disable-symbol-versions --without-acl-support --without-ldap --without-ads
			fi
		fi

	    make -j

		if [ ${M} -eq 3 ]; then
			cd ..
		fi

		if [ ${M} -eq 3 ]; then
			COMPILED_MODULE="source3/bin/greyhole.so"
	    else
	        COMPILED_MODULE="`ls -1 bin/default/source3/modules/libvfs*greyhole.so`"
		fi
	    ls -1 ${COMPILED_MODULE}
	    mkdir -p ${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/
	    cp ${COMPILED_MODULE} ${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-$ARCH.so
	    echo " was copied to "
	    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-$ARCH.so

	cd ..
	echo
done

echo "****************************************"
echo

exit

SSH_HOST="gb@192.168.155.7"
ARCH="i386"
cd ~/git/Greyhole/samba-module/bin/
rsync -av $SSH_HOST:Greyhole/samba-module/bin/ .

SSH_HOST="gb@192.168.155.88"
ARCH="x86_64"
cd ~/git/Greyhole/samba-module/bin/
rsync -av $SSH_HOST:Greyhole/samba-module/bin/ .

SSH_HOST="gb@127.0.0.1"
ARCH="armhf"
cd ~/Greyhole/samba-module/bin/
rsync -av -e "ssh -p 2223" $SSH_HOST:Greyhole/samba-module/bin/ .

SSH_HOST="gb@192.168.155.88"
ARCH="armhf"
cd ~/git/Greyhole/samba-module/bin/
rsync -av $SSH_HOST:Greyhole/samba-module/bin/ .
