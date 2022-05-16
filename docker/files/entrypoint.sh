#!/usr/bin/env sh
set -eo pipefail

WS_UID=${WS_UID:-1000}
WS_GID=${WS_GID:-1000}
WS_NO_CHOWN=${WS_NO_CHOWN:-0}
WS_DISABLE_HTTP=${WS_DISABLE_HTTP:-0}
WS_DISABLE_CRON=${WS_DISABLE_CRON:-0}
WS_DISABLE_REDIS=${WS_DISABLE_REDIS:-0}

set -u

if [ "${WS_UID}" != "$(id -u www-data)" ]; then
  usermod -u ${WS_UID} www-data
fi

if [ "${WS_GID}" != "$(id -g www-data)" ]; then
  groupmod -g ${WS_GID} www-data
fi

if [ ! -f "/app/vendor/autoload.php" ]; then
  if [ ! -f "/usr/bin/composer" ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
  fi
  runuser -u www-data -- composer --ansi --working-dir=/app/ -o --no-progress --no-dev --no-cache install
fi

if [ ! -f "/usr/bin/console" ]; then
  cp /app/docker/files/app_console.sh /usr/bin/console
  chmod +x /usr/bin/console
fi

if [ ! -f "/usr/bin/run-app-cron" ]; then
  cp /app/docker/files/cron.sh /usr/bin/run-app-cron
  chmod +x /usr/bin/run-app-cron
fi

if [ 0 == "${WS_NO_CHOWN}" ]; then
  chown -R www-data:www-data /config /var/lib/nginx/
fi

/usr/bin/console config:php >"${PHP_INI_DIR}/conf.d/zz-app-custom-ini-settings.ini"
/usr/bin/console config:php --fpm >"${PHP_INI_DIR}/../php-fpm.d/zzz-app-pool-settings.conf"
/usr/bin/console storage:migrations
/usr/bin/console storage:maintenance

if [ 0 == "${WS_DISABLE_HTTP}" ]; then
  echo "Starting Nginx server.."
  nginx
fi

if [ 0 == "${WS_DISABLE_CRON}" ]; then
  echo "Starting cron..."
  /usr/sbin/crond -b -l 2
fi

if [ 0 == "${WS_DISABLE_REDIS}" ]; then
  echo "Starting Redis Server..."
  redis-server /etc/redis.conf --daemonize yes
fi

APP_VERSION=$(/usr/bin/console --version)
echo "Running ${APP_VERSION}"

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

exec "$@"
