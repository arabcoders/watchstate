#!/usr/bin/env bash

# Exit if already running.
#
if [[ "$(pgrep -f $(basename $0))" ]]; then
  echo "Another process is running."
  exit 0
fi

# Loop until the sun explode.
#
while true; do
  sleep 60
  /usr/bin/console system:tasks --run --save-log
done
