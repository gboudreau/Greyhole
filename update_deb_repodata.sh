#!/bin/bash

set -e

VERSION=$1

if [ $# -eq 2 ]; then
	BUILD_NUMBER="$2"
else
	BUILD_NUMBER="1"
fi

destdir='/var/www/html/greyhole.net/releases/deb'

cd ${destdir}

# Sign .DEB files
archs='i386 amd64 armhf'
for arch in $archs; do
	cp greyhole-$VERSION-$BUILD_NUMBER.$arch.deb greyhole-$VERSION-$BUILD_NUMBER.$arch.deb.unsigned
	ar x greyhole-$VERSION-$BUILD_NUMBER.$arch.deb
	cat debian-binary control.tar.gz data.tar.gz > /tmp/combined-contents
	if [ -f _gpgorigin ]; then rm _gpgorigin; fi
	gpg --digest-algo=sha512 -abso _gpgorigin /tmp/combined-contents
	ar rc greyhole-$VERSION-$BUILD_NUMBER.$arch.deb _gpgorigin debian-binary control.tar.gz data.tar.gz
	rm _gpgorigin debian-binary control.tar.gz data.tar.gz
done

# Update APT repo
rm -rf db/ dists/ pool/
reprepro includedeb stable *.deb

# Sign Release file
cd dists/stable
gpg --digest-algo=sha512 -bao Release.gpg Release

# Add changelog file
cat > $destdir/pool/main/g/greyhole/greyhole_$VERSION-$BUILD_NUMBER.changelog <<EOF
greyhole ($VERSION) unstable; urgency=high

  * See https://www.greyhole.net/releases/CHANGELOG for details.

 -- Guillaume Boudreau <guillaume (at) greyhole.net>  `date`
EOF
