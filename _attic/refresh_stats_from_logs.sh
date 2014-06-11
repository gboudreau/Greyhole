cd /usr/local/lsws/logs/

apt_users=`find . -name 'www.greyhole.net*' -mtime -30 -exec gunzip -c {} \; 2>/dev/null | grep -h '/releases/deb/dists/stable/Release.gpg' | grep -vi bot | grep -vi spider | awk '{print $1}'`
rpm_users=`find . -name 'www.greyhole.net*' -mtime -30 -exec gunzip -c {} \; 2>/dev/null | grep -h 'repodata/repomd.xml' | grep -vi bot | grep -vi spider | awk '{print $1}'`
uniq_users=`echo "$apt_users
$rpm_users" | sort -u | wc -l`
echo $uniq_users > /var/www/html/greyhole.net/uniq_users.data

deb_downloads=`find . -name 'www.greyhole.net*' -mtime -30 -exec gunzip -c {} \; 2>/dev/null | grep -h '\.deb' | grep APT-HTTP | awk '{print $1}'`
rpm_downloads=`find . -name 'www.greyhole.net*' -mtime -30 -exec gunzip -c {} \; 2>/dev/null | grep -h '\.rpm' | grep -vi bot | grep -vi spider | grep -v src.rpm | awk '{print $1}'`
uniq_downloads=`echo "$deb_downloads
$rpm_downloads" | sort -u | wc -l`
echo $uniq_downloads > /var/www/html/greyhole.net/uniq_downloads.data
