
cd /home/gb/nginx-logs || exit

apt_users=`find . -name 'www.greyhole.net-access.log*.gz' -exec gunzip -c {} \; 2>/dev/null | grep -h '/releases/deb/dists/stable/Release.gpg' | grep -vi bot | grep -vi spider | awk '{print $1}' ; find . -name 'www.greyhole.net-access.log*' -a -not -name 'www.greyhole.net-access.log*.gz' -exec cat {} \; 2>/dev/null | grep -h '/releases/deb/dists/stable/Release.gpg' | grep -vi bot | grep -vi spider | awk '{print $1}'`
rpm_users=`find . -name 'www.greyhole.net-access.log*.gz' -exec gunzip -c {} \; 2>/dev/null | grep -h 'repodata/repomd.xml' | grep -vi bot | grep -vi spider | awk '{print $1}' ; find . -name 'www.greyhole.net-access.log*' -a -not -name 'www.greyhole.net-access.log*.gz' -exec cat {} \; 2>/dev/null | grep -h 'repodata/repomd.xml' | grep -vi bot | grep -vi spider | awk '{print $1}'`
echo "Unique APT users: `echo "$apt_users" | sort -u | wc -l`"
echo "Unique RPM users: `echo "$rpm_users" | sort -u | wc -l`"
