#!/bin/bash

# Detect package type from /etc/issue
_found_arch() {
    local _ostype="$1"
    shift
    grep -qis "$*" /etc/issue && _OSTYPE="$_ostype"
}

# Detect package type
_OSTYPE_detect() {
    _found_arch apt-get "Debian GNU/Linux" && return
    _found_arch apt-get "Ubuntu" && return
    _found_arch yum     "CentOS" && return
    _found_arch yum     "Red Hat" && return
    _found_arch yum     "Fedora" && return
    
    [[ -x "/usr/bin/apt-get" ]] && _OSTYPE="apt-get" && return
    [[ -x "/usr/bin/yum" ]]     && _OSTYPE="yum" && return
    
    echo
    echo "Error: can't find either yum or apt-get to install Greyhole."
    echo "  Download the latest .tar.gz file on the Github Releases page: https://github.com/gboudreau/Greyhole/releases"
    echo "  Then follow the instructions from the INSTALL file: https://raw.github.com/gboudreau/Greyhole/master/INSTALL"
    echo
    exit 1
}

mysql_server_installed() {
    rpm -q mysql-server >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        return 0
    fi
    
    rpm -q mysql-community-server >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        return 0
    fi
    
    rpm -q mariadb-server >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        return 0
    fi
    
    rpm -q mariadb-galera-server >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        return 0
    fi
    
    return 1
}

install_mysql_server() {
    yum info mysql-server >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        sudo yum install -y mysql-server
        return
    fi
    
    yum info mysql-community-server >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        sudo yum install -y mysql-community-server
        return
    fi
    
    yum info mariadb-server >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        sudo yum install -y mariadb-server
        return
    fi
    
    yum info mariadb-galera-server >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        sudo yum install -y mariadb-galera-server
        return
    fi
    
    echo
    echo "Can't find any packages in your configured yum repos that provide mysql-server functionality."
    echo "Searched for: mysql-server, mysql-community-server, mariadb-server, mariadb-galera-server"
    echo
    exit 2
}

php_mysql_installed() {
    rpm -q php-mysql >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        return 0
    fi
    
    rpm -q php-mysqlnd >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        return 0
    fi
    
    return 1
}

install_php_mysql() {
    yum info php-mysql >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        sudo yum install -y php-mysql
        return
    fi
    
    yum info php-mysqlnd >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        sudo yum install -y php-mysqlnd
        return
    fi
    
    echo
    echo "Can't find any packages in your configured yum repos that provide php-mysql functionality."
    echo "Searched for: php-mysql, php-mysqlnd"
    echo
    exit 2
}

_OSTYPE_detect

if [ "$_OSTYPE" = "yum" ]; then
    sudo curl -so /etc/yum.repos.d/greyhole.repo https://www.greyhole.net/releases/rpm/greyhole.repo
    
    # Can't hard-code mysql-server dependency into the RPM, because some distributions (CentOS 7) don't offer it, and include MariaDB instead.
    mysql_server_installed
    if [ $? -ne 0 ]; then
        echo "Can't find mysql-server or mariadb-server installed."
        echo "Will install either one (whichever is available for your distribution)."
        install_mysql_server
    fi
    
    # Can't hard-code php-mysql dependency into the RPM, because some distributions (FC25) don't offer it, and include php-mysqlnd instead.
    php_mysql_installed
    if [ $? -ne 0 ]; then
        echo "Can't find php-mysql or php-mysqlnd installed."
        echo "Will install either one (whichever is available for your distribution)."
        install_php_mysql
    fi

    if [ ! -f /sbin/chkconfig -a ! -f /usr/sbin/update-rc.d ]; then
        echo "Installing chkconfig..."
	    sudo yum install -y chkconfig
	fi
    if [ ! -f /sbin/service ]; then
        echo "Installing initscripts..."
	    sudo yum install -y initscripts
	fi

    sudo yum install -y greyhole
    if [ $? -ne 0 ]; then
        exit -2;
    fi
elif [ "$_OSTYPE" = "apt-get" ]; then
    apt-cache showpkg php-mbstring >/dev/null
    if [ $? -eq 0 ]; then
        apt-get -y install php-mbstring
    fi
    echo "deb https://www.greyhole.net/releases/deb stable main v0.12 v0.11" > /etc/apt/sources.list.d/greyhole.list
    curl -s https://www.greyhole.net/releases/deb/greyhole-debsig.asc | apt-key add -
    apt-get update
    apt-get -y -o DPkg::options::=--force-confmiss install greyhole
    if [ $? -ne 0 ]; then
        exit -2;
    fi
fi

echo
echo "----------------------------------------"
echo "You will need to configure Greyhole now."
echo "See USAGE file for details: /usr/share/greyhole/USAGE"
echo "  or online: https://raw.github.com/gboudreau/Greyhole/master/USAGE"
echo "----------------------------------------"
echo
