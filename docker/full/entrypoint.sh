#!/usr/bin/env sh

set -e

# check for data path.
if [ -z "${WS_DATA_PATH}" ]; then
  echo "Please set data path in WS_DATA_PATH ENV."
  exit 1
fi

APP_UID=${APP_UID:-1000}
APP_GID=${APP_GID:-1000}

usermod -u ${APP_UID} www-data
groupmod -g ${APP_GID} www-data

if [ ! -d "/app/vendor" ]; then
  runuser -u www-data -- composer --ansi --working-dir=/app/ --optimize-autoloader --no-dev --no-progress --no-cache install
fi

/usr/bin/console config:php >"${PHP_INI_DIR}/conf.d/zz-app-custom-ini-settings.ini"
/usr/bin/console config:php --fpm >"${PHP_INI_DIR}/../php-fpm.d/zzz-app-pool-settings.conf"
/usr/bin/console storage:migrations
/usr/bin/console storage:maintenance

if [ -f "/etc/caddy/Caddyfile" ]; then
  caddy start -config /etc/caddy/Caddyfile
fi

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

exec "$@"
