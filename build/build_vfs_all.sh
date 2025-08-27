#!/bin/bash

# See samba-modules/HOWTO for details on usage

set -e

# shellcheck disable=SC2155
export BUILD_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd "${BUILD_DIR}"
export GREYHOLE_INSTALL_DIR="${BUILD_DIR}/.."
cd gh-vfs-build-docker

# Change to 0 to get full logs during docker builds
export DOCKER_BUILDKIT=1

cp "${GREYHOLE_INSTALL_DIR}/build_vfs.sh" .

for version in 4.22.4 4.21.7 4.20.8 4.19.5 4.18.3 4.17.8 4.16.2 4.15.5 4.14.12 4.13.17 4.12.15 4.11.16; do
    M=$(echo ${version} | awk -F'.' '{print $1}') # major
    m=$(echo ${version} | awk -F'.' '{print $2}') # minor
    # shellcheck disable=SC2034
    B=$(echo ${version} | awk -F'.' '{print $3}') # build

    mkdir -p "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/"
    chown gb "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/"

    echo
    echo "********"
    echo "Compiling VFS for Samba $version..."

    cp "${GREYHOLE_INSTALL_DIR}/samba-module/"*$M.$m* .

    if [ "$(uname -m)" = "arm64" ]; then
        # Build for arm64 only on Mac M1
        echo
        echo "********"
        echo "Compiling VFS for arm64"

        # Docker images for IMAGE arg: https://hub.docker.com/_/ubuntu?tab=tags
        docker build --pull --platform linux/arm64 -t greyhole-vfs-builder:arm64 --build-arg "SAMBA_VERSION=${version}" --build-arg "PACKAGE=deb" --build-arg "IMAGE=ubuntu:noble" .
        id=$(docker create greyhole-vfs-builder:arm64)
        docker cp $id:/usr/share/greyhole/vfs-build/samba-$version/greyhole-samba$M$m.so "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-arm64-deb.so"
        docker rm -v $id
        echo
        echo -n "New VFS module created was copied to "
        ls -1 "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-arm64-deb.so"

        docker build --pull --platform linux/arm64 -t greyhole-vfs-builder:arm64 --build-arg "SAMBA_VERSION=${version}" --build-arg "PACKAGE=rpm" --build-arg "IMAGE=ubuntu:noble" .
        id=$(docker create greyhole-vfs-builder:arm64)
        docker cp $id:/usr/share/greyhole/vfs-build/samba-$version/greyhole-samba$M$m.so "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-arm64.so"
        docker rm -v $id
        echo
        echo -n "New VFS module created was copied to "
        ls -1 "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-arm64.so"
    else
        echo
        echo "********"
        echo "Compiling VFS for x86_64"

        # Docker images for IMAGE arg: https://hub.docker.com/_/ubuntu?tab=tags
        docker build --pull --platform linux/amd64 -t greyhole-vfs-builder:amd64 --build-arg "SAMBA_VERSION=${version}" --build-arg "PACKAGE=deb" --build-arg "IMAGE=ubuntu:noble" .
        id=$(docker create greyhole-vfs-builder:amd64)
        docker cp $id:/usr/share/greyhole/vfs-build/samba-$version/greyhole-samba$M$m.so "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-x86_64-deb.so"
        docker rm -v $id
        echo
        echo -n "New VFS module created was copied to "
        ls -1 "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-x86_64-deb.so"

        docker build --pull --platform linux/amd64 -t greyhole-vfs-builder:amd64 --build-arg "SAMBA_VERSION=${version}" --build-arg "PACKAGE=rpm" --build-arg "IMAGE=ubuntu:noble" .
        id=$(docker create greyhole-vfs-builder:amd64)
        docker cp $id:/usr/share/greyhole/vfs-build/samba-$version/greyhole-samba$M$m.so "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-x86_64.so"
        docker rm -v $id
        echo
        echo -n "New VFS module created was copied to "
        ls -1 "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-x86_64.so"
    fi

    chown gb "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/"*

    echo
    echo "********"
    echo

    rm ./*$M.$m.patch ./*$M.$m.c

    # Comment out this to compile all versions, instead of just the latest
    break
done

rm ./build_vfs.sh
