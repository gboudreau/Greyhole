#!/bin/bash

set -e

TRIGGER_FILE="/var/www/html/greyhole.net/docker-build.trigger/ping"
if [ -f "$TRIGGER_FILE" ]; then
    rm "$TRIGGER_FILE"
    /home/gb/bin/greyhole-docker-build-develop.sh
fi
