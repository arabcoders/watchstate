#!/usr/bin/env sh

NOW=$(date +"%Y_%m_%d")
WS_UID=${WS_UID:-1000}
WS_GID=${WS_GID:-1000}

# check for data path.
if [ -z "${WS_DATA_PATH}" ]; then
  WS_DATA_PATH="/config"
fi

if [ 1 == "${WS_CRON}" ]; then
  LOGFILE="${WS_DATA_PATH}/logs/cron/${NOW}.log"

  if [ ! -d "${WS_DATA_PATH}/logs/cron/" ]; then
    runuser -u www-data -- mkdir -p "${WS_DATA_PATH}/logs/cron/"
  fi

  if [ ! -f "${LOGFILE}" ]; then
    runuser -u www-data -- touch "${LOGFILE}"
    chown ${WS_UID}:${WS_GID} "${LOGFILE}"
  fi

  /usr/bin/console scheduler:run -o >>"${LOGFILE}"
fi
