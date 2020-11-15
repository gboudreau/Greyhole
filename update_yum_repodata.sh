#!/bin/bash

set -e

archs='i386 x86_64 armv5tel src'
destdir='/home/gb/www/greyhole.net/releases/rpm'
for arch in $archs; do
	cd ${destdir}/${arch}
	rm -rf repodata
	createrepo .
done
