#!/usr/bin/env sh

UID=$(id -u)

if [ 0 == "${UID}" ]; then
  runuser -u www-data -- /usr/bin/console system:tasks --save-log
else
  /usr/bin/console system:tasks --save-log
fi
