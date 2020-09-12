ARCH=i386

deb: build
	rm initd_script.sh
	rm greyhole
	rm greyhole-dfree
	rm greyhole-php
	rm greyhole-dfree.php
	rm -f greyhole-mv
	rm logrotate.greyhole
	rm schema-mysql.sql
	rm greyhole.example.conf
	rm greyhole.cron.d
	rm greyhole.cron.weekly
	rm greyhole.cron.daily
	rm upstart.conf
	rm CHANGES
	rm COPYING
	rm INSTALL
	rm USAGE
	rm -rf samba-module/ docs/ includes/ web-app/ scripts-examples/

build:
	mkdir -p $(DESTDIR)etc/init.d/
	mkdir -p $(DESTDIR)etc/init/
	mkdir -p $(DESTDIR)usr/bin/
	mkdir -p $(DESTDIR)usr/share/greyhole/
	mkdir -p $(DESTDIR)etc/logrotate.d/
	mkdir -p $(DESTDIR)usr/share/greyhole/web-app/
	mkdir -p $(DESTDIR)usr/share/greyhole/web-app/du/
	mkdir -p $(DESTDIR)usr/share/greyhole/web-app/install/
	mkdir -p $(DESTDIR)usr/share/greyhole/web-app/views/
	mkdir -p $(DESTDIR)usr/share/greyhole/scripts-examples/
	mkdir -p $(DESTDIR)etc/cron.d/
	mkdir -p $(DESTDIR)etc/cron.daily/
	mkdir -p $(DESTDIR)etc/cron.weekly/
	mkdir -p $(DESTDIR)usr/share/man/man1/
	mkdir -p $(DESTDIR)usr/share/man/man5/

	install -m 0755 -D -p greyhole $(DESTDIR)usr/bin
	install -m 0755 -D -p greyhole-dfree $(DESTDIR)usr/bin
	install -m 0755 -D -p greyhole-php $(DESTDIR)usr/bin
	install -m 0755 -D -p greyhole-dfree.php $(DESTDIR)usr/share/greyhole/
	install -m 0755 -D -p build_vfs.sh $(DESTDIR)usr/share/greyhole/

	install -m 0644 -D -p schema-mysql.sql $(DESTDIR)usr/share/greyhole/
	install -m 0644 -D -p greyhole.example.conf $(DESTDIR)usr/share/greyhole/

	install -m 0644 -D -p greyhole.example.conf $(DESTDIR)etc/greyhole.conf
	install -m 0755 -D -p initd_script.sh $(DESTDIR)etc/init.d/greyhole
	install -m 0644 -D -p upstart.conf $(DESTDIR)etc/init/greyhole.conf
	install -m 0644 -D -p logrotate.greyhole $(DESTDIR)etc/logrotate.d/greyhole
	install -m 0644 -D -p greyhole.cron.d $(DESTDIR)etc/cron.d/greyhole
	install -m 0755 -D -p greyhole.cron.weekly $(DESTDIR)etc/cron.weekly/greyhole
	install -m 0755 -D -p greyhole.cron.daily $(DESTDIR)etc/cron.daily/greyhole

	install -m 0644 -D -p web-app/index.php $(DESTDIR)usr/share/greyhole/web-app/index.php
	install -m 0644 -D -p web-app/README $(DESTDIR)usr/share/greyhole/web-app/README
	install -m 0644 -D -p web-app/LICENSE.md $(DESTDIR)usr/share/greyhole/web-app/LICENSE.md
	install -m 0644 -D -p web-app/favicon.png $(DESTDIR)usr/share/greyhole/web-app/favicon.png
	install -m 0644 -D -p web-app/includes.inc.php $(DESTDIR)usr/share/greyhole/web-app/includes.inc.php
	install -m 0644 -D -p web-app/init.inc.php $(DESTDIR)usr/share/greyhole/web-app/init.inc.php
	install -m 0644 -D -p web-app/config_definitions.inc.php $(DESTDIR)usr/share/greyhole/web-app/config_definitions.inc.php
	install -m 0644 -D -p web-app/scripts.js $(DESTDIR)usr/share/greyhole/web-app/scripts.js
	install -m 0644 -D -p web-app/styles.css $(DESTDIR)usr/share/greyhole/web-app/styles.css
	install -m 0644 -D -p web-app/du/index.php $(DESTDIR)usr/share/greyhole/web-app/du/index.php
	install -m 0644 -D -p web-app/install/index.php $(DESTDIR)usr/share/greyhole/web-app/install/index.php
	install -m 0644 -D -p web-app/install/step1.inc.php $(DESTDIR)usr/share/greyhole/web-app/install/step1.inc.php
	install -m 0644 -D -p web-app/install/step2.inc.php $(DESTDIR)usr/share/greyhole/web-app/install/step2.inc.php
	install -m 0644 -D -p web-app/install/step3.inc.php $(DESTDIR)usr/share/greyhole/web-app/install/step3.inc.php
	install -m 0644 -D -p web-app/install/step4.inc.php $(DESTDIR)usr/share/greyhole/web-app/install/step4.inc.php
	install -m 0644 -D -p web-app/install/step5.inc.php $(DESTDIR)usr/share/greyhole/web-app/install/step5.inc.php
	install -m 0644 -D -p web-app/install/step6.inc.php $(DESTDIR)usr/share/greyhole/web-app/install/step6.inc.php
	install -m 0644 -D -p web-app/install/step7.inc.php $(DESTDIR)usr/share/greyhole/web-app/install/step7.inc.php
	install -m 0644 -D -p web-app/views/greyhole_config.php $(DESTDIR)usr/share/greyhole/web-app/views/greyhole_config.php
	install -m 0644 -D -p web-app/views/samba_config.php $(DESTDIR)usr/share/greyhole/web-app/views/samba_config.php
	install -m 0644 -D -p web-app/views/samba_shares.php $(DESTDIR)usr/share/greyhole/web-app/views/samba_shares.php
	install -m 0644 -D -p web-app/views/status.php $(DESTDIR)usr/share/greyhole/web-app/views/status.php
	install -m 0644 -D -p web-app/views/storage_pool.php $(DESTDIR)usr/share/greyhole/web-app/views/storage_pool.php

	install -m 0755 -D -p scripts-examples/greyhole_file_changed.sh $(DESTDIR)usr/share/greyhole/scripts-examples/
	install -m 0755 -D -p scripts-examples/greyhole_idle.sh $(DESTDIR)usr/share/greyhole/scripts-examples/
	install -m 0755 -D -p scripts-examples/greyhole_notify_error.sh $(DESTDIR)usr/share/greyhole/scripts-examples/
	install -m 0755 -D -p scripts-examples/greyhole_send_fsck_report.sh $(DESTDIR)usr/share/greyhole/scripts-examples/

	install -m 0644 -D -p USAGE $(DESTDIR)usr/share/greyhole/

	install -m 0644 -D -p docs/greyhole.1.gz $(DESTDIR)usr/share/man/man1/
	install -m 0644 -D -p docs/greyhole-dfree.1.gz $(DESTDIR)usr/share/man/man1/
	install -m 0644 -D -p docs/greyhole.conf.5.gz $(DESTDIR)usr/share/man/man5/

ifeq ($(ARCH), amd64)
	mkdir -p $(DESTDIR)usr/lib64/greyhole/
	install -m 0644 -D -p samba-module/bin/3.4/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba34.so
	install -m 0644 -D -p samba-module/bin/3.5/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba35.so
	install -m 0644 -D -p samba-module/bin/3.6/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba36.so
	install -m 0644 -D -p samba-module/bin/4.0/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba40.so
	install -m 0644 -D -p samba-module/bin/4.1/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba41.so
	install -m 0644 -D -p samba-module/bin/4.2/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba42.so
	install -m 0644 -D -p samba-module/bin/4.3/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba43.so
	install -m 0644 -D -p samba-module/bin/4.4/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba44.so
	install -m 0644 -D -p samba-module/bin/4.5/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba45.so
	install -m 0644 -D -p samba-module/bin/4.6/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba46.so
	install -m 0644 -D -p samba-module/bin/4.7/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba47.so
	install -m 0644 -D -p samba-module/bin/4.8/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba48.so
	install -m 0644 -D -p samba-module/bin/4.9/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba49.so
	install -m 0644 -D -p samba-module/bin/4.10/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba410.so
	install -m 0644 -D -p samba-module/bin/4.11/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba411.so
	install -m 0644 -D -p samba-module/bin/4.12/greyhole-x86_64.so $(DESTDIR)usr/lib64/greyhole/greyhole-samba412.so
else
ifeq ($(ARCH), armhf)
		mkdir -p $(DESTDIR)usr/lib/greyhole/
		install -m 0644 -D -p samba-module/bin/3.4/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba34.so
		install -m 0644 -D -p samba-module/bin/3.5/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba35.so
		install -m 0644 -D -p samba-module/bin/3.6/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba36.so
		install -m 0644 -D -p samba-module/bin/4.0/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba40.so
		install -m 0644 -D -p samba-module/bin/4.1/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba41.so
		install -m 0644 -D -p samba-module/bin/4.2/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba42.so
		install -m 0644 -D -p samba-module/bin/4.3/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba43.so
		install -m 0644 -D -p samba-module/bin/4.4/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba44.so
		install -m 0644 -D -p samba-module/bin/4.5/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba45.so
		install -m 0644 -D -p samba-module/bin/4.6/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba46.so
		install -m 0644 -D -p samba-module/bin/4.7/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba47.so
		install -m 0644 -D -p samba-module/bin/4.8/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba48.so
		install -m 0644 -D -p samba-module/bin/4.9/greyhole-armhf.so $(DESTDIR)usr/lib/greyhole/greyhole-samba49.so
else
ifeq ($(ARCH), arm64)
		mkdir -p $(DESTDIR)usr/lib/greyhole/
		install -m 0644 -D -p samba-module/bin/4.4/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba44.so
		install -m 0644 -D -p samba-module/bin/4.5/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba45.so
		install -m 0644 -D -p samba-module/bin/4.6/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba46.so
		install -m 0644 -D -p samba-module/bin/4.7/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba47.so
		install -m 0644 -D -p samba-module/bin/4.8/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba48.so
		install -m 0644 -D -p samba-module/bin/4.9/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba49.so
		install -m 0644 -D -p samba-module/bin/4.10/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba410.so
		install -m 0644 -D -p samba-module/bin/4.11/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba411.so
		install -m 0644 -D -p samba-module/bin/4.12/greyhole-arm64.so $(DESTDIR)usr/lib/aarch64-linux-gnu/greyhole/greyhole-samba412.so
else
		mkdir -p $(DESTDIR)usr/lib/greyhole/
		install -m 0644 -D -p samba-module/bin/3.4/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba34.so
		install -m 0644 -D -p samba-module/bin/3.5/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba35.so
		install -m 0644 -D -p samba-module/bin/3.6/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba36.so
		install -m 0644 -D -p samba-module/bin/4.0/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba40.so
		install -m 0644 -D -p samba-module/bin/4.1/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba41.so
		install -m 0644 -D -p samba-module/bin/4.2/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba42.so
		install -m 0644 -D -p samba-module/bin/4.3/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba43.so
		install -m 0644 -D -p samba-module/bin/4.4/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba44.so
		install -m 0644 -D -p samba-module/bin/4.5/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba45.so
		install -m 0644 -D -p samba-module/bin/4.6/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba46.so
		install -m 0644 -D -p samba-module/bin/4.7/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba47.so
		install -m 0644 -D -p samba-module/bin/4.8/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba48.so
		install -m 0644 -D -p samba-module/bin/4.9/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba49.so
		install -m 0644 -D -p samba-module/bin/4.10/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba410.so
		install -m 0644 -D -p samba-module/bin/4.11/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba411.so
		install -m 0644 -D -p samba-module/bin/4.12/greyhole-i386.so $(DESTDIR)usr/lib/greyhole/greyhole-samba412.so
endif
endif
endif
