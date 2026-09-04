#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -eq 0 ]; then
  echo "ERROR: No WatchState command was provided." >&2
  exit 2
fi

declare -a pids=()

if [ -n "${CACHE_PID:-}" ]; then
  pids+=("${CACHE_PID}")
fi

stop_services() {
  trap '' TERM INT
  service_pgid="$(ps -o pgid= -p "$$")"
  service_pgid="${service_pgid//[[:space:]]/}"
  kill -TERM -- "-${service_pgid}" 2>/dev/null || true
  wait "${pids[@]}" 2>/dev/null || true
}

wait_services() {
  trap - TERM INT
  wait "${pids[@]}" 2>/dev/null || true
}

trap 'wait_services; exit 0' TERM INT

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting worker."
/opt/bin/ws-runner &
pids+=("$!")

"$@" &
pids+=("$!")

status=0
wait -n "${pids[@]}" || status=$?
if [ 0 = "${status}" ]; then
  status=1
fi

stop_services
exit "${status}"
