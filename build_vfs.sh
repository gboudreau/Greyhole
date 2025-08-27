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
	version=$(/usr/sbin/smbd --version | awk '{print $2}' | awk -F'-' '{print $1}')
fi

for_debian_ubuntu=0
if [[ $# -gt 1 && "$2" = "deb" ]]; then
  for_debian_ubuntu=1
fi

M=$(echo "${version}" | awk -F'.' '{print $1}') # major
m=$(echo "${version}" | awk -F'.' '{print $2}') # minor
# shellcheck disable=SC2034
B=$(echo "${version}" | awk -F'.' '{print $3}') # build

echo
echo "Installing build dependencies..."
echo "- Installing build-essential / gcc (etc.)"
if command -v apt-get >/dev/null; then
    apt-get -y install build-essential python3-dev libgnutls28-dev pkg-config >/dev/null || true
fi
if command -v yum >/dev/null; then
    yum -y install patch gcc python-devel gnutls-devel make rpcgen >/dev/null || true
fi
if [[ ${M} -ge 4 ]]; then
    if [[ ${m} -ge 20 ]]; then
        if command -v apt-get >/dev/null; then
            echo "- Installing bison"
            apt-get -y install bison >/dev/null || true
        fi
    fi
    if [[ ${m} -ge 12 ]]; then
        if command -v yum >/dev/null; then
            yum -y install perl-CPAN >/dev/null || true
        fi
        echo "- Installing Parse::Yapp::Driver perl module"
        # shellcheck disable=SC2034
        PERL_MM_USE_DEFAULT=1
        echo | perl -MCPAN -e 'install Parse::Yapp::Driver' >/dev/null
    fi
    if [[ ${m} -ge 13 ]]; then
        echo "- Installing zlib-devel"
        if command -v apt-get >/dev/null; then
            apt-get -y install zlib1g-dev flex locales >/dev/null || true
        fi
        if command -v yum >/dev/null; then
            yum -y install zlib-devel >/dev/null || true
        fi
    fi
    if [[ ${m} -ge 14 ]]; then
        if command -v /sbin/apk >/dev/null; then
            echo "- Installing bison & flex"
            apk add bison flex >/dev/null || true
        fi
        if command -v yum >/dev/null; then
            echo "- Installing bison & flex"
            yum -y install bison flex >/dev/null || true
            echo "- Installing JSON perl module"
            cpan install JSON >/dev/null 2>&1
        fi
    fi
    if [[ ${m} -ge 15 ]]; then
        if command -v apt-get >/dev/null; then
            echo "- Installing com_err & heimdal-devel"
            apt-get -y install comerr-dev heimdal-multidev >/dev/null || true
        fi
        if command -v yum >/dev/null; then
            echo "- Installing e2fsprogs-devel & heimdal-devel"
            yum -y install e2fsprogs-devel heimdal-devel >/dev/null || true
        fi
    fi
    if [[ ${m} -ge 17 && ${for_debian_ubuntu} -eq 1 ]]; then
      # Ref: https://github.com/gboudreau/Greyhole/issues/312#issuecomment-1613206381
      echo "- Installing libtdb-dev, libldb-dev, libtalloc-dev & libtevent-dev (to unbundle those from Samba)"
      apt-get -y install libtdb-dev libldb-dev libtalloc-dev libtevent-dev >/dev/null
    fi
fi

echo

echo "Compiling Greyhole VFS module for samba-${version}... "

if [[ -z ${GREYHOLE_INSTALL_DIR} ]]; then
	echo "  Downloading Greyhole source code"
	set +e
	GH_VERSION=$(greyhole --version 2>&1 | grep version | head -1 | awk '{print $3}' | awk -F',' '{print $1}')
	rm -f "greyhole-${GH_VERSION}.tar.gz"
	curl -LOs "https://github.com/gboudreau/Greyhole/releases/download/${GH_VERSION}/greyhole-${GH_VERSION}.tar.gz" 2>&1
	set -e
	if [[ -f "greyhole-${GH_VERSION}.tar.gz" && "${GH_VERSION}" != "%VERSION%" ]]; then
		GREYHOLE_INSTALL_DIR="$(pwd)/greyhole-${GH_VERSION}"
		rm -rf "${GREYHOLE_INSTALL_DIR}"
		tar zxf "greyhole-${GH_VERSION}.tar.gz" && rm -f "greyhole-${GH_VERSION}.tar.gz"
	else
		GREYHOLE_INSTALL_DIR="$(pwd)/Greyhole-master"
		rm -rf "${GREYHOLE_INSTALL_DIR}"
		curl -LOs "https://github.com/gboudreau/Greyhole/archive/master.zip"
		unzip -q master.zip && rm master.zip
	fi
fi

if [[ ! -d samba-${version} ]]; then
	echo "  Downloading Samba source code"
	curl -LOs "http://samba.org/samba/ftp/stable/samba-${version}.tar.gz" && tar zxf "samba-${version}.tar.gz" && rm -f "samba-${version}.tar.gz"
fi

cd "samba-${version}" || true
NEEDS_CONFIGURE=
if [[ ! -f source3/modules/vfs_greyhole.c ]]; then
	NEEDS_CONFIGURE=1
fi
set +e

if ! grep -i vfs_greyhole source3/wscript >/dev/null; then
	NEEDS_CONFIGURE=1
fi
if [[ -f .greyhole_needs_configures ]]; then
	NEEDS_CONFIGURE=1
fi
set -e

rm -f source3/modules/vfs_greyhole.c source3/bin/greyhole.so bin/default/source3/modules/libvfs*greyhole.so
if [[ -f "${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.${m}.c" ]]; then
  ln -s "${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.${m}.c" source3/modules/vfs_greyhole.c
else
  ln -s "${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-${M}.x.c" source3/modules/vfs_greyhole.c
fi

if [[ ${M} -eq 3 ]]; then
	cd source3
fi

if [[ "${NEEDS_CONFIGURE}" = "1" ]]; then
	echo "  Running 'configure'"
	touch .greyhole_needs_configures
	set +e
	if [[ ${M} -eq 3 ]]; then
    ./configure >gh_vfs_build.log 2>&1 &
    PROC_ID=$!
  else
    if [[ -f "${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-${M}.${m}.patch" ]]; then
      patch -p1 < "${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-${M}.${m}.patch" >/dev/null
    else
      patch -p1 < "${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-${M}.x.patch" >/dev/null
    fi
    CONF_OPTIONS="--enable-debug --disable-symbol-versions --without-acl-support --without-ldap --without-ads --without-pam --without-ad-dc"
    if [[ ${m} -ge 7 ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --disable-python'
    fi
    if [[ ${m} -ge 13 ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --with-shared-modules=!vfs_snapper'
    fi
    if [[ ${m} -ge 10 ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --without-json --without-libarchive'
    elif [[ ${m} -ge 9 ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --without-json-audit --without-libarchive'
    fi
    if [[ ${m} -ge 15 && ! -f /sbin/apk && ! -f /bin/yum ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --with-system-heimdalkrb5'
    fi
    if [[ ${m} -ge 21 ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --without-ldb-lmdb'
    fi
    if [[ ${m} -ge 22 && ${for_debian_ubuntu} -eq 1 ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --bundled-libraries=!pyldb-util,!pytalloc-util,!pytdb'
    elif [[ ${m} -ge 21 && ${for_debian_ubuntu} -eq 1 ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --bundled-libraries=!pyldb-util,!talloc,!pytalloc-util,!tevent,!pytdb'
    elif [[ ${m} -ge 20 && ${for_debian_ubuntu} -eq 1 ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --bundled-libraries=!pyldb-util,!talloc,!pytalloc-util,!tevent,!tdb,!pytdb'
    elif [[ ${m} -ge 17 && ${for_debian_ubuntu} -eq 1 ]]; then
      # Ref: https://github.com/gboudreau/Greyhole/issues/312#issuecomment-1613206381
      CONF_OPTIONS=${CONF_OPTIONS}' --bundled-libraries=!ldb,!pyldb-util,!talloc,!pytalloc-util,!tevent,!tdb,!pytdb'
    fi
    if [[ ${m} -ge 19 && -f /sbin/apk ]]; then
      CONF_OPTIONS=${CONF_OPTIONS}' --without-libunwind'
    fi
    echo "./configure ${CONF_OPTIONS}" > gh_vfs_build.log
    # shellcheck disable=SC2086
    ./configure ${CONF_OPTIONS} >>gh_vfs_build.log 2>&1 &
    PROC_ID=$!
		sleep 15
	fi

	while kill -0 "$PROC_ID" >/dev/null 2>&1; do
		sleep 1
    echo -en "\r${ceol}    Progress: "
    echo -n "$(tail -n 1 gh_vfs_build.log)"
	done
	echo -en "\r${ceol}"
	if ! wait "$PROC_ID"; then
	  echo
	  echo "Configuring Samba failed."
	  echo "Hint : install the required dependencies. See step 3 in https://raw.githubusercontent.com/gboudreau/Greyhole/master/INSTALL"
	  echo
	  echo "cat $(pwd)/gh_vfs_build.log :"
	  cat gh_vfs_build.log
	  exit 4
  fi
  rm -rf .greyhole_needs_configures

	set -e

	if [[ ${M} -eq 3 ]]; then
    patch -p1 < "${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-${M}.${m}.patch" >/dev/null
  fi
fi

# Patches for compiling Samba on Alpine Linux; from https://git.alpinelinux.org/aports/tree/main/samba?h=3.15-stable
echo "  Applying patches (if any)..."
if [[ "${M}.${m}" = "4.11" ]]; then
    curl -sLo "${GREYHOLE_INSTALL_DIR}/smb411-nsstest.patch" "https://gist.githubusercontent.com/gboudreau/778cc1299362cb15a3ea74686cf77794/raw/d578c0a3599cc27d1fe3bc4da015fb4e5aa4652d/smb411-nsstest.patch"
fi
if [[ "${M}.${m}" = "4.19" && -f /sbin/apk && "$(uname -m)" = "aarch64" ]]; then
    curl -sLo "${GREYHOLE_INSTALL_DIR}/smb419-cmocka.patch" "https://gist.githubusercontent.com/gboudreau/3cf3bb9bbadc3dad12eb0fd8484eb1cb/raw/9880c597ab6854d0d362eb443325c1336a7776b6/smb419-cmocka.patch"
fi
shopt -s nullglob
for f in "${GREYHOLE_INSTALL_DIR}/"*.patch; do
    echo -n "  - "
    patch -p1 -i "$f" || true
done
echo '#include <sys/types.h>' > file.txt
sed -i '/#include <stdbool.h>/r file.txt' -- lib/tevent/tevent.h
rm -f file.txt

echo "  Compiling Samba"
set +e
make -j >gh_vfs_build.log 2>&1 &
PROC_ID=$!

while kill -0 "$PROC_ID" >/dev/null 2>&1; do
	sleep 1
  echo -en "\r${ceol}    Progress: "
  echo -n "$(tail -n 1 gh_vfs_build.log)"
done
echo -en "\r${ceol}"
if ! wait "$PROC_ID"; then
  echo
  echo "Compiling Samba failed."
  echo
  echo "cat $(pwd)/gh_vfs_build.log :"
  cat gh_vfs_build.log
  exit 5
fi
echo

V=$(echo "${version}" | awk -F'.' '{print $1$2}')
GREYHOLE_COMPILED_MODULE="$(pwd)/greyhole-samba${V}.so"
export GREYHOLE_COMPILED_MODULE

if [[ ${M} -eq 3 ]]; then
	COMPILED_MODULE="source3/bin/greyhole.so"
else
	COMPILED_MODULE=$(ls -1 "$(pwd)"/bin/default/source3/modules/libvfs*greyhole.so)
fi

if [[ ! -f ${COMPILED_MODULE} ]]; then
	>&2 echo "Failed to compile Greyhole VFS module."
  echo
  echo "cat $(pwd)/gh_vfs_build.log :"
  cat gh_vfs_build.log
	exit 3
fi

set -e

cp "${COMPILED_MODULE}" "${GREYHOLE_COMPILED_MODULE}"

echo "Greyhole VFS module successfully compiled into ${GREYHOLE_COMPILED_MODULE}"

if [[ ${create_symlink} -eq 1 ]]; then
	echo
  echo "Creating the required symlink in the Samba VFS library folder."
  if [[ -d /usr/lib/x86_64-linux-gnu/samba/vfs ]]; then
    LIBDIR=/usr/lib/x86_64-linux-gnu
  elif [[ -d /usr/lib64/samba/vfs ]]; then
    LIBDIR=/usr/lib64
  elif [[ -d /usr/lib/aarch64-linux-gnu/samba/vfs/ ]]; then
		LIBDIR=/usr/lib/aarch64-linux-gnu
  else
    LIBDIR=/usr/lib
  fi
  rm -f ${LIBDIR}/samba/vfs/greyhole.so
  ln -s "${GREYHOLE_COMPILED_MODULE}" ${LIBDIR}/samba/vfs/greyhole.so
  echo "Done."
  echo
fi
