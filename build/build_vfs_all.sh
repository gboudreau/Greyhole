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

for version in 4.14.0 4.13.0 4.12.0 4.11.0 4.10.0 4.9.0 4.8.0 4.7.0 4.6.0 4.5.0 4.4.0; do
    M=$(echo ${version} | awk -F'.' '{print $1}') # major
    m=$(echo ${version} | awk -F'.' '{print $2}') # minor
    # shellcheck disable=SC2034
    B=$(echo ${version} | awk -F'.' '{print $3}') # build

    mkdir -p "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/"

    echo
    echo "********"
    echo "Compiling VFS for Samba $version..."

    cp "${GREYHOLE_INSTALL_DIR}/samba-module/"*$M.$m* .

    echo
    echo "********"
    echo "1/3 Compiling VFS for arm64"

    # Docker images for IMAGE arg: https://hub.docker.com/_/ubuntu?tab=tags
    docker build --pull --platform linux/arm64 -t greyhole-vfs-builder:arm64 --build-arg "SAMBA_VERSION=${version}" --build-arg "IMAGE=ubuntu@sha256:64162ac111b666daf1305de1888eb67a3033f62000f5ff781fe529aff8f88b09" .
    id=$(docker create greyhole-vfs-builder:arm64)
    docker cp $id:/usr/share/greyhole/vfs-build/samba-$version/greyhole-samba$M$m.so "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-arm64.so"
    docker rm -v $id
    echo
    echo -n "New VFS module created was copied to "
    ls -1 "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-arm64.so"

    echo
    echo "********"
    echo "2/3 Compiling VFS for x86_64"

    # Docker images for IMAGE arg: https://hub.docker.com/_/ubuntu?tab=tags
    docker build --pull --platform linux/amd64 -t greyhole-vfs-builder:amd64 --build-arg "SAMBA_VERSION=${version}" --build-arg "IMAGE=ubuntu@sha256:57df66b9fc9ce2947e434b4aa02dbe16f6685e20db0c170917d4a1962a5fe6a9" .
    id=$(docker create greyhole-vfs-builder:amd64)
    docker cp $id:/usr/share/greyhole/vfs-build/samba-$version/greyhole-samba$M$m.so "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-x86_64.so"
    docker rm -v $id
    echo
    echo -n "New VFS module created was copied to "
    ls -1 "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-x86_64.so"

    echo
    echo "********"
    echo "3/3 Compiling VFS for i386"

    # Docker images for IMAGE arg: https://hub.docker.com/r/i386/ubuntu/tags
    docker build --pull --platform linux/i386 -t greyhole-vfs-builder:i386 --build-arg "SAMBA_VERSION=${version}" --build-arg "IMAGE=i386/ubuntu@sha256:4a2efd378fc094a2b78f4478d88d8c02a828bb3adf551b903cdfe24ac0ea852f" .
    id=$(docker create greyhole-vfs-builder:i386)
    docker cp $id:/usr/share/greyhole/vfs-build/samba-$version/greyhole-samba$M$m.so "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-i386.so"
    docker rm -v $id
    echo
    echo -n "New VFS module created was copied to "
    ls -1 "${GREYHOLE_INSTALL_DIR}/samba-module/bin/${M}.${m}/greyhole-i386.so"

    echo
    echo "********"
    echo

    rm ./*$M.$m.patch ./*$M.$m.c

    # Comment out this to compile all versions, instead of just the latest
    break
done

rm ./build_vfs.sh
