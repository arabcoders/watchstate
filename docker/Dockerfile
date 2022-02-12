FROM php:8.1-fpm-alpine

ENV IN_DOCKER=1
LABEL maintainer="admin@arabcoders.org"

# Installed Required PHP Extensions.
#
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/bin/

RUN mv "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini" && chmod +x /usr/bin/install-php-extensions && \
    sync && install-php-extensions pdo mbstring ctype sqlite3 json opcache xhprof && \
    rm /usr/bin/install-php-extensions && \
    apk add --no-cache caddy nano curl procps net-tools iproute2 shadow runuser && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

# App Verison
#
ARG APP_VERSION=latest

# Add Latest Watchstate release.
#
COPY ./files/download.sh /usr/bin/downloadapp
COPY ./files/Caddyfile /etc/caddy/Caddyfile
COPY ./files/entrypoint.sh /entrypoint-docker
COPY ./files/app_console.sh /usr/bin/console
COPY ./files/cron.sh /usr/bin/run-app-cron

RUN chmod +x /entrypoint-docker /usr/bin/console /usr/bin/run-app-cron /usr/bin/downloadapp && \
    echo '* * * * * /usr/bin/run-app-cron'>>/etc/crontabs/root

RUN /usr/bin/downloadapp ${APP_VERSION}

ENTRYPOINT ["/entrypoint-docker"]

WORKDIR /config

EXPOSE 9000 80

CMD ["php-fpm"]
