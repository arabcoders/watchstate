#!/usr/bin/env bash

# Exit if already running.
#
if [[ "`pidof -x $(basename $0) -o %PPID`" ]]; then
    exit;
fi

# Loop until the sun explode.
#
while true
do
    sleep 60
    /usr/bin/console system:tasks --run --save-log
done
