#!/usr/bin/env sh
set -e

WS_UID=${WS_UID:-1000}
WS_GID=${WS_GID:-1000}

usermod -u ${WS_UID} www-data
groupmod -g ${WS_GID} www-data

if [ ! -f "/app/vendor/autoload.php" ]; then
  if [ ! -f "/usr/bin/composer" ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
  fi
  runuser -u www-data -- composer --ansi --working-dir=/app/ -o --no-dev --no-progress --no-cache install
fi

chown www-data:www-data /config

/usr/bin/console config:php >"${PHP_INI_DIR}/conf.d/zz-app-custom-ini-settings.ini"
/usr/bin/console config:php --fpm >"${PHP_INI_DIR}/../php-fpm.d/zzz-app-pool-settings.conf"
/usr/bin/console storage:migrations
/usr/bin/console storage:maintenance

if [ ! -f "/config/config/config.yaml" ]; then
  /usr/bin/console config:dump config
  /usr/bin/console config:generate -q
fi

if [ ! -f "/config/config/servers.yaml" ]; then
  /usr/bin/console config:dump servers
fi

if [ -f "/etc/caddy/Caddyfile" ]; then
  caddy start -config /etc/caddy/Caddyfile
fi

/usr/sbin/crond -b -l 2

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

exec "$@"
