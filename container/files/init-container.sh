#!/usr/bin/env bash
set -e

DATA_PATH="${WS_DATA_PATH:-/config}"
ENV_FILE="${DATA_PATH}/config/.env"

W_UMASK="${UMASK:-0000}"

umask "${W_UMASK}"

echo_err() { cat <<< "$@" 1>&2; }

if [ ! -w "${DATA_PATH}" ]; then
  CH_USER=$(stat -c "%u" "${DATA_PATH}")
  CH_GRP=$(stat -c "%g" "${DATA_PATH}")
  echo_err "ERROR: Unable to write to [${DATA_PATH}] data directory. Current user id [${UID}] while directory owner is [${CH_USER}]"
  echo_err "[Running under docker]"
  echo_err "change compose.yaml user: to user:\"${CH_USER}:${CH_GRP}\""
  echo_err "Run the following command to change the directory ownership"
  echo_err "chown -R \"${CH_USER}:${CH_GRP}\" ./data"
  echo_err "[Running under podman]"
  echo_err "change compose.yaml user: to user:\"0:0\""
  exit 1
fi

export XDG_DATA_HOME=${DATA_PATH}
export XDG_CACHE_HOME=/tmp
export XDG_RUNTIME_DIR=/tmp

if [ -f "${ENV_FILE}" ]; then
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] INFO: Loading environment variables from [${ENV_FILE}]."
  sourced=()
  while read -r LINE; do
    if [[ ${LINE} == *'='* ]] && [[ ${LINE} != '#'* ]]; then
      # spilt the line into key and value
      ENV_NAME="$(echo "${LINE}" | cut -d '=' -f 1)"
      ENV_VALUE="$(echo "${LINE}" | cut -d '=' -f 2-)"
      # check if the value starts with quote
      if [[ ${ENV_VALUE} == \"*\" ]] || [[ ${ENV_VALUE} == \'*\' ]]; then
        ENV_VAR="$(echo "${ENV_NAME}=${ENV_VALUE}" | envsubst)"
      else
        ENV_VAR="$(echo "${ENV_NAME}=\"${ENV_VALUE}\"" | envsubst)"
      fi

      sourced+=("${ENV_NAME}")
      eval "declare -x ${ENV_VAR}"
    fi
  done <"${ENV_FILE}"

  if [ ${#sourced[@]} -gt 0 ]; then
    echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] INFO: Loaded '${#sourced[@]}' environment variables from '${ENV_FILE}'."
  fi
else
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] INFO: No environment file present at [${ENV_FILE}]."
fi

DISABLE_HTTP=${DISABLE_HTTP:-0}
DISABLE_CRON=${DISABLE_CRON:-0}
DISABLE_CACHE=${DISABLE_CACHE:-0}
FPM_PORT=${FPM_PORT:-9000}

WS_DISABLE_HTTP=${WS_DISABLE_HTTP:-0}
WS_DISABLE_CRON=${WS_DISABLE_CRON:-0}
WS_DISABLE_CACHE=${WS_DISABLE_CACHE:-0}

if [ 0 != "${WS_DISABLE_HTTP}" ] || [ 0 != "${WS_DISABLE_CRON}" ] || [ 0 != "${WS_DISABLE_CACHE}" ]; then
  echo_err ""
  echo_err "---------------------------------------------------------------------------------------------"
  echo_err "-----------------------------------[ DEPRECATION NOTICE ]------------------------------------"
  echo_err "---------------------------------------------------------------------------------------------"
  echo_err "The use of the following variables is deprecated and will be removed in future releases."
  echo_err "WS_DISABLE_HTTP, WS_DISABLE_CRON, WS_DISABLE_CACHE."
  echo_err "Please use the DISABLE_HTTP, DISABLE_CRON, DISABLE_CACHE variables instead."
  echo_err "---------------------------------------------------------------------------------------------"
  echo_err ""
fi

if [ 9000 != "${FPM_PORT}" ]; then
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] INFO: Changing PHP-FPM port to [${FPM_PORT}]."
  sed -i "s/listen = 0.0.0.0:9000/listen = 0.0.0.0:${FPM_PORT}/" /etc/*/php-fpm.d/www.conf
fi

set -u

# Generate Config structure.
#
WS_CACHE_NULL=1 /opt/bin/console -v >/dev/null

if [ 0 = "${DISABLE_CACHE}" ] && [ 0 = "${WS_DISABLE_CACHE}" ]; then
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting Cache Server."
  redis-server "/opt/config/redis.conf"
fi

if [ 0 = "${DISABLE_HTTP}" ] && [ 0 = "${WS_DISABLE_HTTP}" ]; then
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting HTTP Server."
  _CADDY_UUID_FILE="${XDG_DATA_HOME}/caddy/instance.uuid"
  if [ ! -f "${_CADDY_UUID_FILE}" ]; then
    mkdir -p "${XDG_DATA_HOME}/caddy"
    printf '%s' "$(cat /proc/sys/kernel/random/uuid)" > "${_CADDY_UUID_FILE}"
  fi
  caddy start --config /opt/config/Caddyfile
fi

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Caching tool routes."
/opt/bin/console system:routes

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running database migrations."
/opt/bin/console system:db:migrations

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running database maintenance tasks."
/opt/bin/console system:db:maintenance

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Ensuring state table has correct indexes."
/opt/bin/console system:index

/opt/bin/console system:apikey -q

if [ 0 = "${DISABLE_CRON}" ] && [ 0 = "${WS_DISABLE_CRON}" ]; then
  if [ -f "/tmp/job-runner.pid" ]; then
    echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Found pre-existing tasks scheduler pid file. Removing it."
    rm -f "/tmp/job-runner.pid"
  fi

  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting tasks scheduler."
  /opt/bin/job-runner &
fi

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running - $(/opt/bin/console --version)"

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "${@}"
fi

exec "${@}"
