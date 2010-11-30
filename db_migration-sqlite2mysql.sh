#!/bin/sh

echo "Stopping Greyhole service watchdog..."
rm /etc/monit.d/greyhole.conf
service monit reload
echo "Done."
echo

echo "Stopping Greyhole service..."
service greyhole stop
echo "Done."
echo

echo "Creating MySQL database and user..."
hda-create-db-and-user greyhole
mysql -u greyhole -pgreyhole greyhole < /usr/share/greyhole/schema-mysql.sql
echo "Done."
echo

echo "Modifying Greyhole configuration to use MySQL..."
sed -ie 's/^.*db_engine: .*/db_engine: mysql/' /var/hda/platform/html/config/greyhole.yml
sed -ie 's/^.*database: .*/db_name: greyhole/' /var/hda/platform/html/config/greyhole.yml
sed -ie 's/^.*username: .*/db_user: greyhole/' /var/hda/platform/html/config/greyhole.yml
sed -ie 's/^.*password: .*/db_pass: greyhole/' /var/hda/platform/html/config/greyhole.yml
sed -ie 's/^.*host: .*/db_host: localhost/' /var/hda/platform/html/config/greyhole.yml
sed -ie 's/^.*db_name: .*/db_name: greyhole/' /var/hda/platform/html/config/greyhole.yml
sed -ie 's/^.*db_user: .*/db_user: greyhole/' /var/hda/platform/html/config/greyhole.yml
sed -ie 's/^.*db_pass: .*/db_pass: greyhole/' /var/hda/platform/html/config/greyhole.yml
sed -ie 's/^.*db_host: .*/db_host: localhost/' /var/hda/platform/html/config/greyhole.yml
cat >> /etc/greyhole.conf<<EOF
db_host = localhost
db_user = greyhole
db_pass = greyhole
db_name = greyhole
EOF
sed -ie 's/^.*db_engine = .*/db_engine = mysql/' /etc/greyhole.conf
sed -ie 's/^.*db_host = .*/db_host = localhost/' /etc/greyhole.conf
sed -ie 's/^.*db_user = .*/db_user = greyhole/' /etc/greyhole.conf
sed -ie 's/^.*db_pass = .*/db_pass = greyhole/' /etc/greyhole.conf
sed -ie 's/^.*db_name = .*/db_name = greyhole/' /etc/greyhole.conf
echo "Done."
echo

echo "Migrating data from SQLite to MySQL..."
sqlite3 /var/cache/greyhole.sqlite ".dump settings tasks" | grep INSERT | grep -v sqlite_sequence > greyhole.dump.sql
sed -ie 's/INSERT INTO "tasks"/INSERT INTO tasks/' greyhole.dump.sql
sed -ie 's/INSERT INTO "settings"/INSERT INTO settings/' greyhole.dump.sql
mysql -u greyhole -pgreyhole -e 'truncate settings' greyhole
mysql -u greyhole -pgreyhole greyhole < greyhole.dump.sql
rm greyhole.dump.sql
echo "Done."
echo

echo "Starting Greyhole service..."
service greyhole start
echo "Done."
echo

echo "Starting Greyhole service watchdog..."
cat > /etc/monit.d/greyhole.conf <<EOF
check process greyhole with pidfile /var/run/greyhole.pid
    start program = "/etc/init.d/greyhole start"
    stop  program = "/etc/init.d/greyhole stop"
EOF
service monit reload
echo "Done."
