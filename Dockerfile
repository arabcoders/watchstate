FROM php:8.1-fpm-alpine

ENV IN_DOCKER=1
LABEL maintainer="admin@arabcoders.org"

# Setup required environment
#
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/bin/

RUN mv "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini" && chmod +x /usr/bin/install-php-extensions && \
    sync && install-php-extensions pdo mbstring ctype sqlite3 json opcache xhprof pgsql mysqlnd && \
    apk add --no-cache nginx nano curl procps net-tools iproute2 shadow runuser sqlite && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer && \
    mkdir -p /app /config

COPY . /app

RUN echo '* * * * * /usr/bin/run-app-cron'>>/etc/crontabs/www-data && \
    cp /app/docker/files/nginx.conf /etc/nginx/nginx.conf && \
    cp /app/docker/files/entrypoint.sh /usr/bin/entrypoint-docker && \
    cp /app/docker/files/app_console.sh /usr/bin/console && \
    cp /app/docker/files/cron.sh /usr/bin/run-app-cron && \
    rm -rf /app/docker/ /app/var/ /app/docs/ /app/.github/ && \
    chmod +x /usr/bin/run-app-cron /usr/bin/console /usr/bin/entrypoint-docker && \
    /usr/bin/composer --working-dir=/app/ -o --no-progress --no-cache install && \
    chown -R www-data:www-data /app /config

ENTRYPOINT ["/usr/bin/entrypoint-docker"]

WORKDIR /config

EXPOSE 9000 80 443

CMD ["php-fpm"]
