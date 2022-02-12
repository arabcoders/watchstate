#!/usr/bin/env sh

UID=$(id -u)

if [ "0" == "${UID}" ]; then
  runuser -u www-data -- php /app/console "${@}"
else
  php /app/console "${@}"
fi
