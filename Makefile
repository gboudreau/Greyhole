# VERSION, BUILD_NUMBER & ARCH are now specified in build_greyhole.sh, using exports
PACKAGE=greyhole

deb: dist
	(cd release && rm -rf $(PACKAGE)-$(VERSION))
	(cd release && tar -xzf $(PACKAGE)-$(VERSION).tar.gz)
	rm release/$(PACKAGE)-$(VERSION)/greyhole.spec
	cp -r DEBIAN release/$(PACKAGE)-$(VERSION)/
	sed -i 's/^Version:.*/Version: $(VERSION)-$(BUILD_NUMBER)/' release/$(PACKAGE)-$(VERSION)/DEBIAN/control
	sed -i 's/^Architecture:.*/Architecture: $(ARCH)/' release/$(PACKAGE)-$(VERSION)/DEBIAN/control
	sed -i 's/__VERSION__/$(VERSION)-$(BUILD_NUMBER)/' release/$(PACKAGE)-$(VERSION)/DEBIAN/changelog
	sed -i "s/__DATE__/`date +'%a, %d %b %Y %T %z'`/" release/$(PACKAGE)-$(VERSION)/DEBIAN/changelog
	(cd release/$(PACKAGE)-$(VERSION) && mv DEBIAN/Makefile .)
	(cd release/$(PACKAGE)-$(VERSION) && mkdir -p usr/share/doc/greyhole/ && mv DEBIAN/copyright usr/share/doc/greyhole/)
	(cd release/$(PACKAGE)-$(VERSION) && mv DEBIAN/changelog usr/share/doc/greyhole/ && cd usr/share/doc/greyhole/ && gzip -9 changelog && mv changelog.gz changelog.Debian.gz)
	(cd release/$(PACKAGE)-$(VERSION) && sed -i "s/^ARCH=.*/ARCH=$(ARCH)/" Makefile)
	(cd release/$(PACKAGE)-$(VERSION) && make deb && rm Makefile)
	(cd release/$(PACKAGE)-$(VERSION) && find . -type f -exec md5sum {} \; | grep -v 'DEBIAN' > DEBIAN/md5sums)
	sed -i 's@ \./@ @' release/$(PACKAGE)-$(VERSION)/DEBIAN/md5sums
	(cd release/$(PACKAGE)-$(VERSION) && chmod +x DEBIAN/postinst DEBIAN/postrm)
	(cd release/$(PACKAGE)-$(VERSION) && sudo chown -R root:root .)
	(cd release && sudo dpkg-deb --build $(PACKAGE)-$(VERSION) greyhole-$(VERSION)-$(BUILD_NUMBER).$(ARCH).deb)
	(cd release && sudo rm -rf $(PACKAGE)-$(VERSION))

rpm: dist
	(cd release && rpmbuild -ta --target $(ARCH) $(PACKAGE)-$(VERSION).tar.gz)
	mv ~/rpmbuild/RPMS/$(ARCH)/$(PACKAGE)-$(VERSION)-*.$(ARCH).rpm release/
	mv ~/rpmbuild/SRPMS/$(PACKAGE)-$(VERSION)-*.src.rpm release/

amahi-rpm: dist
	(cd release && rm -rf $(PACKAGE)-$(VERSION) hda-$(PACKAGE)-$(VERSION))
	(cd release && tar -xzf $(PACKAGE)-$(VERSION).tar.gz && mv $(PACKAGE)-$(VERSION) hda-$(PACKAGE)-$(VERSION))
	(cd release/hda-$(PACKAGE)-$(VERSION)/ && sed -i -e 's/Name:           greyhole/Name:           hda-greyhole/' $(PACKAGE).spec && mv $(PACKAGE).spec hda-$(PACKAGE).spec)
	(cd release && tar -czf hda-$(PACKAGE)-$(VERSION).tar.gz hda-$(PACKAGE)-$(VERSION))
	(cd release && rpmbuild -ta --target $(ARCH) hda-$(PACKAGE)-$(VERSION).tar.gz)
	mv ~/rpmbuild/RPMS/$(ARCH)/hda-$(PACKAGE)-$(VERSION)-*.$(ARCH).rpm release/
	mv ~/rpmbuild/SRPMS/hda-$(PACKAGE)-$(VERSION)-*.src.rpm release/
	(cd release/ && rm -rf hda-$(PACKAGE)-$(VERSION))

dist:
	(mkdir -p release && cd release && mkdir -p $(PACKAGE)-$(VERSION))
	rsync -a --exclude-from=.build_excluded_files.txt * release/$(PACKAGE)-$(VERSION)/
	
	if [ -d /tmp/Greyhole.git ]; then (cd /tmp/Greyhole.git; git pull); else git clone git@github.com:gboudreau/Greyhole.git /tmp/Greyhole.git; fi
	(cd /tmp/Greyhole.git; git log --pretty=oneline --reverse | cut -d ' ' -f2-  | grep -v '^Tag: ' > /tmp/Greyhole-CHANGES)
	cp /tmp/Greyhole-CHANGES release/$(PACKAGE)-$(VERSION)/CHANGES

	(cd release/$(PACKAGE)-$(VERSION)/ && sed -i -e 's/^Version:\(\s*\).VERSION\s*$$/Version:\1$(VERSION)/' $(PACKAGE).spec)
	(cd release/$(PACKAGE)-$(VERSION)/ && sed -i -e 's/^Release:\(\s*\).BUILD_NUMBER\s*$$/Release:\1$(BUILD_NUMBER)/' $(PACKAGE).spec)
	(cd release/$(PACKAGE)-$(VERSION)/ && sed -i -e 's/%VERSION%/$(VERSION)/' greyhole)
	(cd release/$(PACKAGE)-$(VERSION)/ && sed -i -e 's/%VERSION%/$(VERSION)/' docs/greyhole.1)
	(cd release/$(PACKAGE)-$(VERSION)/ && sed -i -e 's/%VERSION%/$(VERSION)/' docs/greyhole-dfree.1)
	(cd release/$(PACKAGE)-$(VERSION)/ && sed -i -e 's/%VERSION%/$(VERSION)/' docs/greyhole.conf.5)
	(cd release/$(PACKAGE)-$(VERSION)/docs/ && gzip -9 greyhole.1 && gzip -9 greyhole-dfree.1 && gzip -9 greyhole.conf.5)

    # Inject require()'d files
	(cd release/$(PACKAGE)-$(VERSION)/ && ../../inject-includes.php greyhole)
	(cd release/$(PACKAGE)-$(VERSION)/ && ../../inject-includes.php greyhole-dfree)
	(cd release/$(PACKAGE)-$(VERSION)/ && ../../inject-includes.php web-app/index.php)

    # Create tgz
	(cd release/ && tar -czvf $(PACKAGE)-$(VERSION).tar.gz $(PACKAGE)-$(VERSION))
	(cd release/ && rm -rf $(PACKAGE)-$(VERSION))

install: rpm
	(cd release && sudo rpm -Uvh $(PACKAGE)-$(VERSION)-*.$(ARCH).rpm)
