#!/usr/bin/env bash
set -e

DATA_PATH="${WS_DATA_PATH:-/config}"
ENV_FILE="${DATA_PATH}/config/.env"
TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")

if [ ! -w "${DATA_PATH}" ]; then
  CH_USER=$(stat -c "%u" "${DATA_PATH}")
  CH_GRP=$(stat -c "%g" "${DATA_PATH}")
  echo "[${TIME_DATE}] ERROR: Unable to write to [${DATA_PATH}] data directory. Current user id [${UID}] while directory owner is [${CH_USER}]"
  echo "[${TIME_DATE}] change docker-compose.yaml user: to user:\"${CH_USER}:${CH_GRP}\""
  exit 1
fi

if [ -f "${ENV_FILE}" ]; then
  echo "[${TIME_DATE}] INFO: Loading environment variables from [${ENV_FILE}]."
  while read -r LINE; do
    if [[ $LINE == *'='* ]] && [[ $LINE != '#'* ]]; then
      ENV_VAR="$(echo "${LINE}" | envsubst)"
      eval "declare -x $ENV_VAR"
    fi
  done <"${ENV_FILE}"
else
  echo "[${TIME_DATE}] INFO: No environment file present at [${ENV_FILE}]."
fi

WS_DISABLE_HTTP=${WS_DISABLE_HTTP:-0}
WS_DISABLE_CRON=${WS_DISABLE_CRON:-0}
WS_DISABLE_CACHE=${WS_DISABLE_CACHE:-0}

set -u

# Generate Config structure.
#
WS_CACHE_NULL=1 /opt/bin/console -v >/dev/null

if [ 0 = "${WS_DISABLE_CACHE}" ]; then
  TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
  echo "[${TIME_DATE}] Starting Cache Server."
  redis-server "/opt/config/redis.conf"
fi

if [ 0 = "${WS_DISABLE_HTTP}" ]; then
  TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
  echo "[${TIME_DATE}] Starting HTTP Server."
  caddy start --config /opt/config/Caddyfile
fi

if [ 0 = "${WS_DISABLE_CRON}" ]; then
  TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
  echo "[${TIME_DATE}] Starting Task Scheduler."
  /opt/bin/job-runner &
fi

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Caching tool Routes."
/opt/bin/console system:routes

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Running database migrations."
/opt/bin/console system:db:migrations

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Running database maintenance tasks."
/opt/bin/console system:db:maintenance

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Ensuring State table has correct indexes."
/opt/bin/console system:index

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Running - $(/opt/bin/console --version)"

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm81 "$@"
fi

exec "$@"
