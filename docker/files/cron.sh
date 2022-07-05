#!/usr/bin/env sh

UID=$(id -u)

if [ 0 == "${UID}" ]; then
  runuser -u www-data -- /usr/bin/console system:tasks --run --save-log
else
  /usr/bin/console system:tasks --run --save-log
fi
