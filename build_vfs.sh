#!/bin/bash

## Note to self:
# Run this script on:
#   - x86_64: gb@fileserver2:~
#   - i386:   bougu@macbook:~/VirtualBox VMs/Ubuntu (32-bit)
#   - ARM:    gb@fileserver2:~/qemu-arm ; ./boot.sh ; sleep xx ; ssh -p 2223 gb@127.0.0.1
# And don't forget to get the latest version of the samba-module from fileserver2 first:
#   scp gb@192.168.155.88:Greyhole/samba-module/* ~/Greyhole/samba-module/

export GREYHOLE_INSTALL_DIR="/home/gb/Greyhole"
export HOME="/home/gb"

###

cd "$HOME"

cd samba-3.4.9/source3
    if  [ ! -f modules/vfs_greyhole.c ]; then
        ./configure
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-3.4.patch
    fi
    rm -f modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-3.4.c modules/vfs_greyhole.c
    make -j
cd ../..

cd samba-3.5.4/source3
    if  [ ! -f modules/vfs_greyhole.c ]; then
        ./configure
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-3.5.patch
    fi
    rm -f modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-3.5.c modules/vfs_greyhole.c
    make -j
cd ../..

cd samba-3.6.9/source3
    if  [ ! -f modules/vfs_greyhole.c ]; then
        ./configure
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-3.6.patch
    fi
    rm -f modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-3.6.c modules/vfs_greyhole.c
    make -j
cd ../..

cd samba-4.0.14
    if  [ ! -f source3/modules/vfs_greyhole.c ]; then
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-4.0.patch
        ./configure --enable-debug --enable-selftest --disable-symbol-versions
    fi
    rm -f source3/modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-4.0.c source3/modules/vfs_greyhole.c
    make -j
cd ..

cd samba-4.1.4
    if  [ ! -f source3/modules/vfs_greyhole.c ]; then
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-4.1.patch
        ./configure --enable-debug --enable-selftest --disable-symbol-versions
    fi
    rm -f source3/modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-4.1.c source3/modules/vfs_greyhole.c
    make -j
cd ..

cd samba-4.2.0
    if  [ ! -f source3/modules/vfs_greyhole.c ]; then
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-4.2.patch
        ./configure --enable-debug --enable-selftest --disable-symbol-versions --without-acl-support --without-ldap --without-ads
    fi
    rm -f source3/modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-4.2.c source3/modules/vfs_greyhole.c
    make -j
cd ..

cd samba-4.3.0
    if  [ ! -f source3/modules/vfs_greyhole.c ]; then
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-4.3.patch
        ./configure --enable-debug --enable-selftest --disable-symbol-versions --without-acl-support --without-ldap --without-ads
    fi
    rm -f source3/modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-4.3.c source3/modules/vfs_greyhole.c
    make -j
cd ..

if [ ! -d samba-4.4.0 ]; then
	wget http://samba.org/samba/ftp/stable/samba-4.4.0.tar.gz
	tar zxf samba-4.4.0.tar.gz && rm -f samba-4.4.0.tar.gz
fi
cd samba-4.4.0
    if  [ ! -f source3/modules/vfs_greyhole.c ]; then
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-4.4.patch
        ./configure --enable-debug --enable-selftest --disable-symbol-versions --without-acl-support --without-ldap --without-ads
    fi
    rm -f source3/modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-4.4.c source3/modules/vfs_greyhole.c
    make -j
cd ..

echo
echo "****************************************"
echo

ARCH="`uname -i`"
if [ "$ARCH" = "unknown" ]; then
    ARCH="armhf"
fi

if  [ -f samba-3.4.9/source3/bin/greyhole.so ]; then
    ls -1 samba-3.4.9/source3/bin/greyhole.so
    cp samba-3.4.9/source3/bin/greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.4/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.4/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-3.5.4/source3/bin/greyhole.so ]; then
    ls -1 samba-3.5.4/source3/bin/greyhole.so
    cp samba-3.5.4/source3/bin/greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.5/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.5/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-3.6.9/source3/bin/greyhole.so ]; then
    ls -1 samba-3.6.9/source3/bin/greyhole.so
    cp samba-3.6.9/source3/bin/greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.6/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.6/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-4.0.14/bin/default/source3/modules/libvfs-greyhole.so ]; then
    ls -1 samba-4.0.14/bin/default/source3/modules/libvfs-greyhole.so
    cp samba-4.0.14/bin/default/source3/modules/libvfs-greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.0/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.0/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-4.1.4/bin/default/source3/modules/libvfs-greyhole.so ]; then
    ls -1 samba-4.1.4/bin/default/source3/modules/libvfs-greyhole.so
    cp samba-4.1.4/bin/default/source3/modules/libvfs-greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.1/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.1/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-4.2.0/bin/default/source3/modules/libvfs_module_greyhole.so ]; then
    ls -1 samba-4.2.0/bin/default/source3/modules/libvfs_module_greyhole.so
    cp samba-4.2.0/bin/default/source3/modules/libvfs_module_greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.2/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.2/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-4.3.0/bin/default/source3/modules/libvfs_module_greyhole.so ]; then
    ls -1 samba-4.3.0/bin/default/source3/modules/libvfs_module_greyhole.so
    cp samba-4.3.0/bin/default/source3/modules/libvfs_module_greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.3/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.3/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-4.4.0/bin/default/source3/modules/libvfs_module_greyhole.so ]; then
    ls -1 samba-4.4.0/bin/default/source3/modules/libvfs_module_greyhole.so
    mkdir -p ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.4
    cp samba-4.4.0/bin/default/source3/modules/libvfs_module_greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.4/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.4/greyhole-$ARCH.so
    echo
fi

echo "****************************************"
echo

exit

SSH_HOST="gb@192.168.155.208"
ARCH="i386"
cd ~/git/Greyhole/samba-module/bin/
scp $SSH_HOST:Greyhole/samba-module/bin/3.4/greyhole-$ARCH.so 3.4/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/3.5/greyhole-$ARCH.so 3.5/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/3.6/greyhole-$ARCH.so 3.6/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.0/greyhole-$ARCH.so 4.0/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.1/greyhole-$ARCH.so 4.1/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.2/greyhole-$ARCH.so 4.2/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.3/greyhole-$ARCH.so 4.3/greyhole-$ARCH.so

SSH_HOST="gb@192.168.155.88"
ARCH="x86_64"
cd ~/git/Greyhole/samba-module/bin/
scp $SSH_HOST:Greyhole/samba-module/bin/3.4/greyhole-$ARCH.so 3.4/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/3.5/greyhole-$ARCH.so 3.5/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/3.6/greyhole-$ARCH.so 3.6/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.0/greyhole-$ARCH.so 4.0/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.1/greyhole-$ARCH.so 4.1/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.2/greyhole-$ARCH.so 4.2/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.3/greyhole-$ARCH.so 4.3/greyhole-$ARCH.so

SSH_HOST="gb@127.0.0.1"
ARCH="armhf"
cd ~/Greyhole/samba-module/bin/
scp -P 2223 $SSH_HOST:Greyhole/samba-module/bin/3.4/greyhole-$ARCH.so 3.4/greyhole-$ARCH.so
scp -P 2223 $SSH_HOST:Greyhole/samba-module/bin/3.5/greyhole-$ARCH.so 3.5/greyhole-$ARCH.so
scp -P 2223 $SSH_HOST:Greyhole/samba-module/bin/3.6/greyhole-$ARCH.so 3.6/greyhole-$ARCH.so
scp -P 2223 $SSH_HOST:Greyhole/samba-module/bin/4.0/greyhole-$ARCH.so 4.0/greyhole-$ARCH.so
scp -P 2223 $SSH_HOST:Greyhole/samba-module/bin/4.1/greyhole-$ARCH.so 4.1/greyhole-$ARCH.so
scp -P 2223 $SSH_HOST:Greyhole/samba-module/bin/4.2/greyhole-$ARCH.so 4.2/greyhole-$ARCH.so
scp -P 2223 $SSH_HOST:Greyhole/samba-module/bin/4.3/greyhole-$ARCH.so 4.3/greyhole-$ARCH.so

SSH_HOST="gb@192.168.155.88"
ARCH="armhf"
cd ~/git/Greyhole/samba-module/bin/
scp $SSH_HOST:Greyhole/samba-module/bin/3.4/greyhole-$ARCH.so 3.4/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/3.5/greyhole-$ARCH.so 3.5/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/3.6/greyhole-$ARCH.so 3.6/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.0/greyhole-$ARCH.so 4.0/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.1/greyhole-$ARCH.so 4.1/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.2/greyhole-$ARCH.so 4.2/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.3/greyhole-$ARCH.so 4.3/greyhole-$ARCH.so
