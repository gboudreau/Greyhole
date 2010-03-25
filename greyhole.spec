Name:           greyhole
Version:        $VERSION
Release:        1
Summary:        Greyhole is a drive pooling technology for Samba
Group:          System Environment/Daemons
Source:         http://greyhole.googlecode.com/files/greyhole-%{version}.tar.gz
License:        GPL
BuildRoot:      %{_tmppath}/%{name}-%{version}-%{release}-root-%(%{__id_u} -n)
Requires:       samba php php-mysql mysql-server rsyslog

%description
Greyhole is a drive pooling technology for Samba

%prep
%setup -q

%build


%install
rm -rf $RPM_BUILD_ROOT

mkdir -p $RPM_BUILD_ROOT%{_initrddir}
mkdir -p $RPM_BUILD_ROOT%{_bindir}

install -m 0755 -D -p initd_script.sh ${RPM_BUILD_ROOT}%{_initrddir}/greyhole
install -m 0755 -D -p greyhole ${RPM_BUILD_ROOT}%{_bindir}
install -m 0755 -D -p greyhole-dfree ${RPM_BUILD_ROOT}%{_bindir}
install -m 0750 -D -p greyhole-config-update ${RPM_BUILD_ROOT}%{_bindir}
install -m 0644 -D -p logrotate.greyhole ${RPM_BUILD_ROOT}%{_sysconfdir}/logrotate.d/greyhole
install -m 0644 -D -p mysql.sql ${RPM_BUILD_ROOT}/usr/local/greyhole/mysql.sql
install -m 0644 -D -p greyhole.example.conf ${RPM_BUILD_ROOT}%{_sysconfdir}/greyhole.conf.rpmnew
install -m 0644 -D -p greyhole.cron.d ${RPM_BUILD_ROOT}%{_sysconfdir}/cron.d/greyhole
%ifarch x86_64
	install -m 0755 -D -p samba-module/bin/greyhole-x86_64.so ${RPM_BUILD_ROOT}%{_libdir}/samba/vfs/greyhole.so
%else
	install -m 0755 -D -p samba-module/bin/greyhole-i386.so ${RPM_BUILD_ROOT}%{_libdir}/samba/vfs/greyhole.so
%endif

%clean
rm -rf $RPM_BUILD_ROOT

%pre

%post
# Update /etc/logrotate.d/syslog, if needed
if [ `grep greyhole /etc/logrotate.d/syslog | wc -l` = 0 ]; then
	sed --in-place -e 's@postrotate@prerotate\n        /usr/bin/greyhole --prerotate\n    endscript\n    postrotate\n        /usr/bin/greyhole --postrotate > /dev/null || true@' /etc/logrotate.d/syslog
	service rsyslog reload > /dev/null
fi

# Install conf file, if it doesn't exists yet
if [ ! -f /etc/greyhole.conf ]; then
	mv /etc/greyhole.conf.rpmnew /etc/greyhole.conf
fi

# cifs client workaround
# Ref: http://blog.dhampir.no/content/cifs-vfs-no-response-for-cmd-n-mid
modprobe cifs
echo 0 > /proc/fs/cifs/OplockEnabled

# Service install
/sbin/chkconfig --add greyhole
/sbin/chkconfig greyhole on

%preun

if [ "$1" != 0 ]; then
	if [ "`ps aux | grep greyhole-executer | grep -v grep | wc -l`" = "1" ]; then
		. /etc/rc.d/init.d/functions
		killproc greyhole-executer 2>&1 > /dev/null
		/sbin/service greyhole restart 2>&1 > /dev/null
	else
		/sbin/service greyhole condrestart 2>&1 > /dev/null
	fi
else
	# not an update, a complete uninstall
	
	# Service removal
	/sbin/service greyhole stop 2>&1 > /dev/null
	/sbin/chkconfig --del greyhole

	# Undo changes to /etc/logrotate.d/syslog
	grep -v greyhole /etc/logrotate.d/syslog > /etc/logrotate.d/syslog.new
	mv -f /etc/logrotate.d/syslog.new /etc/logrotate.d/syslog
	service rsyslog reload > /dev/null

	# Remove Greyhole from /etc/samba/smb.conf
	grep -v "dfree.*greyhole" /etc/samba/smb.conf > /etc/samba/smb.conf.new
	sed --in-place -e 's@\(vfs objects.*\) greyhole@\1@' /etc/samba/smb.conf.new
	sed --in-place -e 's@^[ \t]*vfs objects =$@@' /etc/samba/smb.conf.new
	mv -f /etc/samba/smb.conf.new /etc/samba/smb.conf
	/sbin/service smb reload 2>&1 > /dev/null
fi

%files
%defattr(-,root,root,-)
%{_initrddir}/greyhole
%{_bindir}/
%{_sysconfdir}/
%{_libdir}
/usr/local/greyhole/mysql.sql

%changelog
* Mon Feb 22 2010 Guillaume Boudreau
- major update in all sections; more automated installation
* Wed Jan 22 2010 Carlos Puchol
- initial version of Greyhole spec
