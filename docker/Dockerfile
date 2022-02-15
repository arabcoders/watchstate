FROM php:8.1-fpm-alpine

ENV IN_DOCKER=1
LABEL maintainer="admin@arabcoders.org"

# Setup required environment
#
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/bin/

RUN mv "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini" && chmod +x /usr/bin/install-php-extensions && \
    sync && install-php-extensions pdo mbstring ctype sqlite3 json opcache xhprof && \
    apk add --no-cache caddy nano curl procps net-tools iproute2 shadow runuser && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer && \
    mkdir -p /app /config

COPY ./files/entrypoint.sh /usr/bin/entrypoint-docker
COPY ./files/Caddyfile /etc/caddy/Caddyfile

RUN echo '* * * * * /usr/bin/run-app-cron'>>/etc/crontabs/www-data && \
    chmod +x /usr/bin/entrypoint-docker

ENTRYPOINT ["/usr/bin/entrypoint-docker"]

WORKDIR /config

EXPOSE 9000 80

CMD ["php-fpm"]
