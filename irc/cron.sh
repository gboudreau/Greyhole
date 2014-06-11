#!/bin/bash
grep -H . /home/gb/.znc/users/gb/moddata/log/*greyhole_* | grep -v ' is now known as ' | grep -v '\*\*\* [QuitsJoinsPartsChanServ:]* ' | sed -e 's@/home/gb/.znc/users/gb/moddata/log/[default_]*#greyhole_\(....\)\(..\)\(..\).log:\[@[\1-\2-\3 @' | sort > /var/www/html/greyhole.net/irc/greyhole.log
