#!/usr/bin/env bash
set -e

DATA_PATH="${WS_DATA_PATH:-/config}"
ENV_FILE="${DATA_PATH}/config/.env"

WS_UMASK="${WS_UMASK:-0000}"

umask "${WS_UMASK}"

if [ ! -w "${DATA_PATH}" ]; then
  CH_USER=$(stat -c "%u" "${DATA_PATH}")
  CH_GRP=$(stat -c "%g" "${DATA_PATH}")
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] ERROR: Unable to write to [${DATA_PATH}] data directory. Current user id [${UID}] while directory owner is [${CH_USER}]"
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] change docker-compose.yaml user: to user:\"${CH_USER}:${CH_GRP}\""
  exit 1
fi

if [ -f "${ENV_FILE}" ]; then
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] INFO: Loading environment variables from [${ENV_FILE}]."
  while read -r LINE; do
    if [[ $LINE == *'='* ]] && [[ $LINE != '#'* ]]; then
      ENV_VAR="$(echo "${LINE}" | envsubst)"
      eval "declare -x $ENV_VAR"
    fi
  done <"${ENV_FILE}"
else
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] INFO: No environment file present at [${ENV_FILE}]."
fi

WS_DISABLE_HTTP=${WS_DISABLE_HTTP:-0}
WS_DISABLE_CRON=${WS_DISABLE_CRON:-0}
WS_DISABLE_CACHE=${WS_DISABLE_CACHE:-0}

set -u

# Generate Config structure.
#
WS_CACHE_NULL=1 /opt/bin/console -v >/dev/null

if [ 0 = "${WS_DISABLE_CACHE}" ]; then
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting Cache Server."
  redis-server "/opt/config/redis.conf"
fi

if [ 0 = "${WS_DISABLE_HTTP}" ]; then
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting HTTP Server."
  caddy start --config /opt/config/Caddyfile
fi

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Caching tool Routes."
/opt/bin/console system:routes

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running database migrations."
/opt/bin/console system:db:migrations

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running database maintenance tasks."
/opt/bin/console system:db:maintenance

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Ensuring State table has correct indexes."
/opt/bin/console system:index

if [ 0 = "${WS_DISABLE_CRON}" ]; then
  if [ -f "/tmp/job-runner.pid" ]; then
    echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Found pre-existing tasks scheduler pid file. Removing it."
    rm -f "/tmp/job-runner.pid"
  fi

  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting Tasks Scheduler."
  /opt/bin/job-runner &
fi

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running - $(/opt/bin/console --version)"

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

exec "$@"
