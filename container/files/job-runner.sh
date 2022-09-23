#!/usr/bin/env bash

PID="/opt/job-runner.pid"

# shellcheck disable=SC2064
trap 'rm -f "${PID}"; exit' EXIT SIGQUIT SIGINT SIGTERM ERR

# Exit if already running.
#
if [ -f "${PID}" ]; then
  echo "Another process is running. [${PID}]: $(cat "${PID}")"
  exit 0
fi

echo $$ >"${PID}"

# Loop until the sun explode.
#
while true; do
  sleep 60
  /opt/bin/console system:tasks --run --save-log >/dev/null 2>&1
done

if [ -f "${PID}" ]; then
  rm -f "${PID}"
fi

exit 0
