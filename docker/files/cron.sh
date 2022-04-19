#!/usr/bin/env sh

UID=$(id -u)
WS_CRON_DEBUG=${WS_CRON_DEBUG:-v}

if [ 0 == "${UID}" ]; then
  runuser -u www-data -- /usr/bin/console scheduler:run --save-log -${WS_CRON_DEBUG}
else
  /usr/bin/console scheduler:run --save-log -${WS_CRON_DEBUG}
fi
