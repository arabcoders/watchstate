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

DISABLE_CRON=${DISABLE_CRON:-0}
DISABLE_CACHE=${DISABLE_CACHE:-0}

set -u

# Generate Config structure.
#
WS_CACHE_NULL=1 /opt/bin/console -v >/dev/null

if [ 0 = "${DISABLE_CACHE}" ]; then
  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting Cache Server."
  redis-server "/opt/config/redis.conf"
fi

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Caching tool routes."
/opt/bin/console system:routes

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Caching events listeners."
/opt/bin/console events:cache

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running database migrations."
/opt/bin/console system:db:migrations

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running database maintenance tasks."
/opt/bin/console system:db:maintenance

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Ensuring state table has correct indexes."
/opt/bin/console system:index

/opt/bin/console system:apikey -q

if [ 0 = "${DISABLE_CRON}" ]; then
  if [ -f "/tmp/ws-job-runner.pid" ]; then
    echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Found pre-existing tasks scheduler pid file. Removing it."
    rm -f "/tmp/ws-job-runner.pid"
  fi

  echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Starting tasks scheduler."
  /opt/bin/ws-runner &
fi

echo "[$(date +"%Y-%m-%dT%H:%M:%S%z")] Running - $(/opt/bin/console --version)"

exec "${@}"
