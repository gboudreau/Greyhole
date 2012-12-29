Name:           greyhole
Version:        $VERSION
Release:        1
Summary:        Greyhole is a drive pooling technology for Samba
Group:          System Environment/Daemons
Source:         http://greyhole.googlecode.com/files/%{name}-%{version}.tar.gz
License:        GPL
BuildRoot:      %{_tmppath}/%{name}-%{version}-%{release}-root-%(%{__id_u} -n)
Requires:       samba >= 3.4.3, php >= 5, php-mysql, php-mbstring, php-intl, mysql-server, rsync, sysstat, lsof

%description
Greyhole allows you to create a storage pool, accessible from 
Samba shares, that offers data redundancy and JBOD concatenation.

%define debug_package %{nil}

%prep
%setup -q

%build


%install
rm -rf $RPM_BUILD_ROOT

mkdir -p $RPM_BUILD_ROOT/etc/rc.d/init.d
mkdir -p $RPM_BUILD_ROOT%{_bindir}
mkdir -p $RPM_BUILD_ROOT/usr/share/greyhole/
mkdir -p $RPM_BUILD_ROOT/usr/share/man/man1/
mkdir -p $RPM_BUILD_ROOT/usr/share/man/man5/

install -m 0755 -D -p initd_script.sh ${RPM_BUILD_ROOT}/etc/rc.d/init.d/greyhole
install -m 0755 -D -p greyhole ${RPM_BUILD_ROOT}%{_bindir}
install -m 0755 -D -p greyhole-dfree ${RPM_BUILD_ROOT}%{_bindir}
install -m 0755 -D -p greyhole-config-update ${RPM_BUILD_ROOT}%{_bindir}
install -m 0644 -D -p logrotate.greyhole ${RPM_BUILD_ROOT}%{_sysconfdir}/logrotate.d/greyhole
install -m 0644 -D -p USAGE ${RPM_BUILD_ROOT}/usr/share/greyhole/
install -m 0644 -D -p schema-mysql.sql ${RPM_BUILD_ROOT}/usr/share/greyhole/
install -m 0644 -D -p schema-sqlite.sql ${RPM_BUILD_ROOT}/usr/share/greyhole/
install -m 0755 -D -p db_migration-sqlite2mysql.sh ${RPM_BUILD_ROOT}/usr/share/greyhole/
install -m 0644 -D -p greyhole.example.conf ${RPM_BUILD_ROOT}%{_sysconfdir}/greyhole.conf
install -m 0644 -D -p greyhole.cron.d ${RPM_BUILD_ROOT}%{_sysconfdir}/cron.d/greyhole
install -m 0755 -D -p greyhole.cron.weekly ${RPM_BUILD_ROOT}%{_sysconfdir}/cron.weekly/greyhole
install -m 0755 -D -p greyhole.cron.daily ${RPM_BUILD_ROOT}%{_sysconfdir}/cron.daily/greyhole
install -m 0644 -D -p docs/greyhole.1.gz ${RPM_BUILD_ROOT}/usr/share/man/man1/
install -m 0644 -D -p docs/greyhole-dfree.1.gz ${RPM_BUILD_ROOT}/usr/share/man/man1/
install -m 0644 -D -p docs/greyhole.conf.5.gz ${RPM_BUILD_ROOT}/usr/share/man/man5/

%ifarch x86_64
	install -m 0644 -D -p samba-module/bin/3.4/greyhole-x86_64.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba34.so
	install -m 0644 -D -p samba-module/bin/3.5/greyhole-x86_64.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba35.so
	install -m 0644 -D -p samba-module/bin/3.6/greyhole-x86_64.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba36.so
%else
	%ifarch %{arm}
		install -m 0644 -D -p samba-module/bin/3.4/greyhole-arm.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba34.so
		install -m 0644 -D -p samba-module/bin/3.5/greyhole-arm.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba35.so
		#install -m 0644 -D -p samba-module/bin/3.6/greyhole-arm.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba36.so
	%else
		install -m 0644 -D -p samba-module/bin/3.4/greyhole-i386.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba34.so
		install -m 0644 -D -p samba-module/bin/3.5/greyhole-i386.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba35.so
		install -m 0644 -D -p samba-module/bin/3.6/greyhole-i386.so ${RPM_BUILD_ROOT}%{_libdir}/greyhole/greyhole-samba36.so
	%endif
%endif

%clean
rm -rf $RPM_BUILD_ROOT

%pre

%post
echo "Executing post-install script..."
mkdir -p /var/spool/greyhole
chmod 777 /var/spool/greyhole

LIBDIR=/usr/lib
if [ "`uname -m`" = "x86_64" ]; then
	LIBDIR=/usr/lib64
fi
if [ -f ${LIBDIR}/samba/vfs/greyhole.so ]; then
	rm ${LIBDIR}/samba/vfs/greyhole.so
fi

SMB_VERSION="`smbd --version | awk '{print $2}' | awk -F'-' '{print $1}' | awk -F'.' '{print $1,$2}'`"
if [ "$SMB_VERSION" = "3 4" ]; then
	ln -s ${LIBDIR}/greyhole/greyhole-samba34.so ${LIBDIR}/samba/vfs/greyhole.so
elif [ "$SMB_VERSION" = "3 5" ]; then
	ln -s ${LIBDIR}/greyhole/greyhole-samba35.so ${LIBDIR}/samba/vfs/greyhole.so
elif [ "$SMB_VERSION" = "3 6" ]; then
	ln -s ${LIBDIR}/greyhole/greyhole-samba36.so ${LIBDIR}/samba/vfs/greyhole.so
else
	echo "Warning: Greyhole doesn't include a VFS module for your version of Samba ($SMB_VERSION)."
	echo "We will try to use the VFS for version 3.6, but that might not work."
	ln -s ${LIBDIR}/greyhole/greyhole-samba36.so ${LIBDIR}/samba/vfs/greyhole.so
fi

if [ -f /etc/logrotate.d/syslog ]; then
	# Undo changes to /etc/logrotate.d/syslog; deprecated now.
	grep -v greyhole /etc/logrotate.d/syslog > /etc/logrotate.d/syslog.new
	mv -f /etc/logrotate.d/syslog.new /etc/logrotate.d/syslog
	[ -f /etc/init.d/syslog ] && service syslog reload > /dev/null || service rsyslog restart > /dev/null
fi

if [ -f /proc/fs/cifs/OplockEnabled ]; then
	# cifs client workaround
	# Ref: http://blog.dhampir.no/content/cifs-vfs-no-response-for-cmd-n-mid
	modprobe cifs
	echo 0 > /proc/fs/cifs/OplockEnabled
fi
if [ -f /sys/module/cifs/parameters/enable_oplocks ]; then
	# cifs client workaround
	# Ref: http://blog.dhampir.no/content/cifs-vfs-no-response-for-cmd-n-mid
	modprobe cifs enable_oplocks=0
	echo 0 > /sys/module/cifs/parameters/enable_oplocks
fi

running=0
if [ -f /sbin/start ]; then
	# Using Upstart instead of SYSV init.d
	if [ -f /etc/init.d/greyhole ]; then
		rm /etc/init.d/greyhole
	fi
	if [ "`status greyhole | grep running | wc -l`" = "1" ]; then
		restart greyhole
		running=1
	fi
else
	# (SYSV) Service install & start
	if [ -f /etc/init/greyhole.conf ]; then
		rm /etc/init/greyhole.conf
	fi
	if [ -f /etc/init.d/mysql ]; then
	    sed -i 's/mysqld/mysql/' /etc/init.d/greyhole
    fi
    if [ -f /etc/init.d/samba ]; then
        sed -i 's/smb/samba/' /etc/init.d/greyhole
    fi
		/sbin/chkconfig --add greyhole
		/sbin/chkconfig greyhole on
	if [ "`service greyhole status | grep 'is running' | wc -l`" = "1" ]; then
		service greyhole restart
		running=1
	fi
fi

if [ $running -eq 0 ]; then
	echo "==========================================================================="
	echo "See /usr/share/greyhole/USAGE to learn how to configure and start Greyhole."
	echo "==========================================================================="
fi

%preun

# Delete VFS module symlinks, if any
rm -f /usr/lib64/samba/vfs/greyhole.so
rm -f /usr/lib/samba/vfs/greyhole.so

if [ "$1" != 0 ]; then
	/sbin/service greyhole condrestart 2>&1 > /dev/null
else
	# not an update, a complete uninstall
	
	# Service removal
	/sbin/service greyhole stop 2>&1 > /dev/null
	/sbin/chkconfig --del greyhole

	# Remove Greyhole from /etc/samba/smb.conf
	grep -v "dfree.*greyhole" /etc/samba/smb.conf > /etc/samba/smb.conf.new
	sed --in-place -e 's@\(vfs objects.*\) greyhole@\1@' /etc/samba/smb.conf.new
	sed --in-place -e 's@^[ \t]*vfs objects =$@@' /etc/samba/smb.conf.new
	mv -f /etc/samba/smb.conf.new /etc/samba/smb.conf
	/sbin/service smb reload 2>&1 > /dev/null
fi

%files
%defattr(-,root,root,-)
%config(noreplace) %{_sysconfdir}/greyhole.conf
/etc/rc.d/init.d/greyhole
%{_bindir}/
%{_sysconfdir}/
%{_libdir}
/usr/share/greyhole/*
/usr/share/man/*

%changelog
* Sun Jan 02 2011 Guillaume Boudreau
- Fedora 14 (Samba 3.5) compatibility fixes
* Mon Mar 29 2010 Carlos Puchol
- add sqlite schema file, rename mysql one
- use /usr/share/greyhole instead of local 
* Mon Feb 22 2010 Guillaume Boudreau
- major update in all sections; more automated installation
* Wed Jan 22 2010 Carlos Puchol
- initial version of Greyhole spec
