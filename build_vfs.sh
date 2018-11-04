#!/bin/bash

# See samba-modules/HOWTO for details on usage

export GREYHOLE_INSTALL_DIR="$HOME/Greyhole"

###

alias python='/usr/bin/python2'

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

cd "$HOME"

for version in 4.9.0 4.8.0 4.7.0 4.6.0 4.5.0 4.4.0; do
	echo "Working on samba-${version} ... "
	if [ ! -d samba-${version} ]; then
		curl -LO http://samba.org/samba/ftp/stable/samba-${version}.tar.gz && tar zxf samba-${version}.tar.gz && rm -f samba-${version}.tar.gz
	fi

	M=`echo ${version} | awk -F'.' '{print $1}'` # major
	m=`echo ${version} | awk -F'.' '{print $2}'` # minor
	B=`echo ${version} | awk -F'.' '{print $3}'` # build

	cd samba-${version}
		NEEDS_CONFIGURE=
		if  [ ! -f source3/modules/vfs_greyhole.c ]; then
			NEEDS_CONFIGURE=1
		fi
        grep -i vfs_greyhole source3/wscript >/dev/null
		if  [ $? -ne 0 ]; then
			NEEDS_CONFIGURE=1
		fi

	    rm -f source3/modules/vfs_greyhole.c source3/bin/greyhole.so bin/default/source3/modules/libvfs*greyhole.so
        if [ -f ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.${m}.c ]; then
	        ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.${m}.c source3/modules/vfs_greyhole.c
        else
            ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.x.c source3/modules/vfs_greyhole.c
        fi

		if [ ${M} -eq 3 ]; then
			cd source3
		fi

		if [ ! -x ${NEEDS_CONFIGURE} ]; then
			if [ ${M} -eq 3 ]; then
	            ./configure
	            patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-${M}.${m}.patch
		    else
		        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-${M}.${m}.patch
	            CONF_OPTIONS="--enable-debug --disable-symbol-versions --without-acl-support --without-ldap --without-ads --without-pam --without-ad-dc"
		        if [ ${m} -gt 6 ]; then
		            CONF_OPTIONS="${CONF_OPTIONS} --disable-python"
		        fi
		        if [ ${m} -gt 8 ]; then
		            CONF_OPTIONS="${CONF_OPTIONS} --without-json-audit --without-libarchive"
			    fi
		        ./configure ${CONF_OPTIONS}
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
