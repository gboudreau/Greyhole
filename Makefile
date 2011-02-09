VERSION=0.9.0
ARCH=x86_64
PACKAGE=greyhole

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
	rsync -a --exclude .svn/ --exclude make_rpm.sh --exclude release/ * release/$(PACKAGE)-$(VERSION)/
	(cd release/$(PACKAGE)-$(VERSION)/ && svn log -r 1:HEAD http://greyhole.googlecode.com/svn/trunk/ > CHANGES)
	(cd release/$(PACKAGE)-$(VERSION)/ && sed -i -e 's/^Version:\(\s*\).VERSION\s*$$/Version:\1$(VERSION)/' $(PACKAGE).spec)
	(cd release/$(PACKAGE)-$(VERSION)/ && sed -i -e 's/%VERSION%/$(VERSION)/' greyhole)

	# Inject includes/common.php...
	(cd release/$(PACKAGE)-$(VERSION)/ && tail -n +`grep -n "\*/" includes/common.php | head -1 | awk -F':' '{print $$1+2}'` includes/common.php > includes/common.php.1)
	(cd release/$(PACKAGE)-$(VERSION)/ && head -`wc -l includes/common.php.1 | awk '{print $$1-1}'` includes/common.php.1 > includes/common.php.2)

	# ... in greyhole
	(cd release/$(PACKAGE)-$(VERSION)/ && head -`grep -n "include('includes/common.php');" greyhole | awk -F':' '{print $$1-1}'` greyhole > greyhole.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && cat includes/common.php.2 >> greyhole.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && tail -n +`grep -n "include('includes/common.php');" greyhole | awk -F':' '{print $$1+1}'` greyhole >> greyhole.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && mv greyhole.new greyhole)

	# ... in greyhole-dfree
	(cd release/$(PACKAGE)-$(VERSION)/ && head -`grep -n "include('includes/common.php');" greyhole-dfree | awk -F':' '{print $$1-1}'` greyhole-dfree > greyhole-dfree.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && cat includes/common.php.2 >> greyhole-dfree.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && tail -n +`grep -n "include('includes/common.php');" greyhole-dfree | awk -F':' '{print $$1+1}'` greyhole-dfree >> greyhole-dfree.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && mv greyhole-dfree.new greyhole-dfree)
	rm release/$(PACKAGE)-$(VERSION)/includes/common.php.[1,2]

	# Inject includes/sql.php...
	(cd release/$(PACKAGE)-$(VERSION)/ && tail -n +`grep -n "\*/" includes/sql.php | head -1 | awk -F':' '{print $$1+2}'` includes/sql.php > includes/sql.php.1)
	(cd release/$(PACKAGE)-$(VERSION)/ && head -`wc -l includes/sql.php.1 | awk '{print $$1-1}'` includes/sql.php.1 > includes/sql.php.2)

	# ... in greyhole
	(cd release/$(PACKAGE)-$(VERSION)/ && head -`grep -n "include('includes/sql.php');" greyhole | awk -F':' '{print $$1-1}'` greyhole > greyhole.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && cat includes/sql.php.2 >> greyhole.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && tail -n +`grep -n "include('includes/sql.php');" greyhole | awk -F':' '{print $$1+1}'` greyhole >> greyhole.new)
	(cd release/$(PACKAGE)-$(VERSION)/ && mv greyhole.new greyhole)
	rm release/$(PACKAGE)-$(VERSION)/includes/sql.php.[1,2]

	mv release/$(PACKAGE)-$(VERSION)/includes/ release/$(PACKAGE)-$(VERSION)/web-app/
	mv release/$(PACKAGE)-$(VERSION)/web-app/ release/$(PACKAGE)-web-app-$(VERSION)
	(cd release/ && tar -czvf $(PACKAGE)-$(VERSION).tar.gz $(PACKAGE)-$(VERSION))
	(cd release/ && rm -rf $(PACKAGE)-$(VERSION))
	mv release/$(PACKAGE)-web-app-$(VERSION) release/$(PACKAGE)-$(VERSION)
	(cd release/ && tar -czvf $(PACKAGE)-web-app-$(VERSION).tar.gz $(PACKAGE)-$(VERSION))
	(cd release/ && rm -rf $(PACKAGE)-$(VERSION))

install: rpm
	(cd release && sudo rpm -Uvh $(PACKAGE)-$(VERSION)-*.$(ARCH).rpm)
