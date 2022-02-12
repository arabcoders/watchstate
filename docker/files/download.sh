#!/usr/bin/env sh
set -e

if [ -z "${1}" ]; then
  echo "No Version was given"
  exit 1
fi

APP_VERSION=${1}

if [ ! -f "/app/public/index.php" ]; then

  if [ "latest" == "${APP_VERSION}" ]; then
    URL=https://github.com/ArabCoders/watchstate/archive/refs/heads/master.tar.gz
  else
    URL=https://github.com/ArabCoders/watchstate/archive/refs/tags/${APP_VERSION}.tar.gz
  fi

  echo "Downloading Version ${APP_VERSION} via ${URL}"

  /usr/bin/wget ${URL} -O /tmp/master.tar.gz

  if [ ! -f "/tmp/master.tar.gz" ]; then
    echo "No source code was downloaded."
    exit 1
  fi

  if [ ! -d "/app" ]; then
    mkdir -p /app
  fi

  tar -xz --strip-components=1 --directory=/app -f /tmp/master.tar.gz
  /usr/bin/composer --working-dir=/app/ -o --no-dev --no-progress --no-cache install

  chown -R www-data:www-data /app

  #  cp /app/docker/files/entrypoint.sh /entrypoint-docker
  #  cp /app/docker/files/Caddyfile /etc/caddy/Caddyfile
  #  cp /app/docker/files/app_console.sh /usr/bin/console
  #  cp /app/docker/files/cron.sh /usr/bin/run-app-cron
  #
  #  chmod +x /usr/bin/console /usr/bin/downloadapp /usr/bin/run-app-cron
  #  echo '* * * * * /usr/bin/run-app-cron' >/etc/crontabs/root
  #  chown root:root /entrypoint-docker
fi
