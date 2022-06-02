#!/usr/bin/env sh
set -e

WS_UID=${WS_UID:-1000}
WS_GID=${WS_GID:-1000}
WS_NO_CHOWN=${WS_NO_CHOWN:-0}
WS_DISABLE_HTTP=${WS_DISABLE_HTTP:-0}
WS_DISABLE_CRON=${WS_DISABLE_CRON:-0}
WS_DISABLE_CACHE=${WS_DISABLE_CACHE:-0}
TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")

set -u

if [ "${WS_UID}" != "$(id -u www-data)" ]; then
  usermod -u "${WS_UID}" www-data
fi

if [ "${WS_GID}" != "$(id -g www-data)" ]; then
  groupmod -g "${WS_GID}" www-data
fi

if [ ! -f "/app/vendor/autoload.php" ]; then
  if [ ! -f "/usr/bin/composer" ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
  fi
  runuser -u www-data -- composer install --working-dir=/app/ --optimize-autoloader --no-ansi --no-progress --no-dev
fi

if [ ! -f "/usr/bin/console" ]; then
  cp /app/docker/files/app_console.sh /usr/bin/console
  chmod +x /usr/bin/console
fi

if [ ! -f "/usr/bin/run-app-cron" ]; then
  cp /app/docker/files/cron.sh /usr/bin/run-app-cron
  chmod +x /usr/bin/run-app-cron
fi

if [ 0 = "${WS_NO_CHOWN}" ]; then
  chown -R www-data:www-data /app /config /var/lib/nginx/ /etc/redis.conf
fi

/usr/bin/console config:php >"${PHP_INI_DIR}/conf.d/zz-app-custom-ini-settings.ini"
/usr/bin/console config:php --fpm >"${PHP_INI_DIR}/../php-fpm.d/zzz-app-pool-settings.conf"
/usr/bin/console storage:migrations
/usr/bin/console storage:maintenance

if [ 0 = "${WS_DISABLE_HTTP}" ]; then
  echo "[${TIME_DATE}] Starting HTTP Server."
  nginx
fi

if [ 0 = "${WS_DISABLE_CRON}" ]; then
  echo "[${TIME_DATE}] Starting Task Scheduler."
  /usr/sbin/crond -b -l 2
fi

if [ 0 = "${WS_DISABLE_CACHE}" ]; then
  echo "[${TIME_DATE}] Starting Cache Server."
  runuser -u www-data -- redis-server "/etc/redis.conf"
fi

echo "[${TIME_DATE}] Running - $(/usr/bin/console --version)"

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

exec "$@"
