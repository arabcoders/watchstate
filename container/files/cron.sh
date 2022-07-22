#!/usr/bin/env bash

PID="/var/run/runner.pid"

trap "rm -f ${pid}" SIGSEGV
trap "rm -f ${pid}" SIGINT

# Exit if already running.
#
if [ -f "${PID}" ]; then
  echo "Another process is running. $(cat "${PID}")"
  exit 0
fi

echo $$ >"${PID}"

# Loop until the sun explode.
#
while true; do
  sleep 60
  /usr/bin/console system:tasks --run --save-log
done

if [ -f "${PID}" ]; then
  rm -f "${PID}"
fi

exit 0
