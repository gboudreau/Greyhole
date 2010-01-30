Name:           greyhole
Version:        $VERSION
Release:        1
Summary:        Greyhole is a drive extender technology for Samba
Group:          System Environment/Daemons
Source:         http://greyhole.googlecode.com/files/greyhole-%{version}.tar.gz
License:        GPL
BuildRoot:      %{_tmppath}/%{name}-%{version}-%{release}-root-%(%{__id_u} -n)
BuildArch:      noarch
Requires:       samba php php-mysql mysql-server rsyslog

%description
Greyhole is a drive extender technology for Samba

%prep
%setup -q

%build


%install
rm -rf $RPM_BUILD_ROOT

mkdir -p $RPM_BUILD_ROOT%{_initrddir}
mkdir -p $RPM_BUILD_ROOT%{_bindir}

install -m 0755 -D -p initd_script.sh ${RPM_BUILD_ROOT}%{_initrddir}/greyhole
install -m 0755 -D -p greyhole-executer ${RPM_BUILD_ROOT}%{_bindir}
install -m 0755 -D -p greyhole-dfree ${RPM_BUILD_ROOT}%{_bindir}
install -m 0644 -D -p logrotate.greyhole ${RPM_BUILD_ROOT}%{_sysconfdir}/logrotate.d/greyhole

%clean
rm -rf $RPM_BUILD_ROOT

%pre

%post
/sbin/chkconfig --add greyhole
/sbin/chkconfig greyhole on
/sbin/service greyhole start 2>&1 > /dev/null

%preun

if [ "$1" != 0 ]; then
	/sbin/service greyhole condrestart 2>&1 > /dev/null
else
	# not an update, a complete uninstall
	/sbin/service greyhole stop 2>&1 > /dev/null
	/sbin/chkconfig --del greyhole
fi

%files
%defattr(-,root,root,-)
%{_initrddir}/greyhole
%{_bindir}/
%{_sysconfdir}/

%changelog
* Wed Jan 22 2010 Carlos Puchol
- initial version of Greyhole spec
