#!/usr/bin/env sh

UID=$(id -u)
NOW=$(date +"%Y_%m_%d")
WS_UID=${WS_UID:-1000}
WS_GID=${WS_GID:-1000}
WS_CRON_DEBUG=${WS_CRON_DEBUG:-v}

# check for data path.
if [ -z "${WS_DATA_PATH}" ]; then
  WS_DATA_PATH="/config"
fi

LOGFILE="${WS_DATA_PATH}/logs/cron/${NOW}.log"

# Check cron logs path.
if [ ! -d "${WS_DATA_PATH}/logs/cron/" ]; then
  if [ 0 == "${UID}" ]; then
    runuser -u www-data -- mkdir -p "${WS_DATA_PATH}/logs/cron/"
  else
    mkdir -p "${WS_DATA_PATH}/logs/cron/"
  fi
fi

if [ 0 == "${UID}" ]; then
  runuser -u www-data -- /usr/bin/console scheduler:run -o -${WS_CRON_DEBUG} >>${LOGFILE}
else
  /usr/bin/console scheduler:run -o -${WS_CRON_DEBUG} >>${LOGFILE}
fi
