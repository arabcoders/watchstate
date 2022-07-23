#!/usr/bin/env bash
set -e

ENV_FILE="${WS_DATA_PATH:-/config}/config/.env"

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")

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
WS_CACHE_NULL=1 /usr/bin/console -v >/dev/null

if [ 0 = "${WS_DISABLE_CACHE}" ]; then
  TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
  echo "[${TIME_DATE}] Starting Cache Server."
  redis-server "/opt/redis.conf"
fi

if [ 0 = "${WS_DISABLE_HTTP}" ]; then
  TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
  echo "[${TIME_DATE}] Starting HTTP Server."
  caddy start --config /opt/Caddyfile
fi

if [ 0 = "${WS_DISABLE_CRON}" ]; then
  TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
  echo "[${TIME_DATE}] Starting Task Scheduler."
  /opt/job-runner &
fi

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Caching tool Routes."
/usr/bin/console system:routes

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Running database migrations."
/usr/bin/console system:db:migrations

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Running database maintenance tasks."
/usr/bin/console system:db:maintenance

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Ensuring State table has correct indexes."
/usr/bin/console system:index

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Running - $(/usr/bin/console --version)"

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

exec "$@"
