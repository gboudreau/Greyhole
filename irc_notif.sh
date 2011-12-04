#!/usr/bin/env bash
# Originally: shellbot.sh - IRC Bot, Author: Sean B. Palmer, inamidst.com
# Adapted for Greyhole notifications by Guillaume Boudreau, pommepause.com

NICK=GHNotifier
SERVER=irc.freenode.net
PORT=6667
CHANNEL=#greyhole
PASSWORD=`cat .irc_password`
MSG=$1

cat > /tmp/shellbot.input <<EOF
NICK $NICK
USER gboudreau +Zi $NICK :$0
PRIVMSG NickServ :IDENTIFY $PASSWORD
JOIN $CHANNEL
PRIVMSG $CHANNEL :$MSG
EOF

tail -n 10 -f /tmp/shellbot.input | telnet $SERVER $PORT | \
  while true
  do read LINE || break
    echo "`date +"%Y-%m-%d %H:%M:%S"` $LINE"
    if echo $LINE | grep -i "MODE $CHANNEL +o $NICK" &> /dev/null
    then
      echo "TOPIC $CHANNEL :Greyhole - Storage Pooling on Samba - Latest version: $VERSION - Homepage: http://greyhole.net - Support: http://support.greyhole.net - Twitter: @greyholeapp" >> /tmp/shellbot.input
      sleep 1
      echo "QUIT" >> /tmp/shellbot.input
      sleep 1
      killall telnet
      killall tail
	fi
  done

rm /tmp/shellbot.input
