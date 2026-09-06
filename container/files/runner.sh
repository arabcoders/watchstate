#!/usr/bin/env bash
set -u

CHILD_PID=""

stop_runner() {
  trap - TERM INT
  if [ -n "${CHILD_PID}" ]; then
    wait "${CHILD_PID}" 2>/dev/null || true
  fi
  exit 0
}

trap stop_runner TERM INT

while true; do
  /opt/bin/console system:worker -v &
  CHILD_PID=$!
  status=0
  wait "${CHILD_PID}" || status=$?
  CHILD_PID=""

  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Worker exited with status '${status}'. Restarting in 1 second." >&2

  sleep 1 &
  CHILD_PID=$!
  wait "${CHILD_PID}" || true
  CHILD_PID=""
done
