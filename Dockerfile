FROM ghcr.io/arabcoders/php_container:latest

LABEL maintainer="admin@arabcoders.org"

ENV IN_DOCKER=1

RUN mkdir -p /app /config

COPY . /app

RUN usermod -u 1000 www-data && groupmod -g 1000 www-data && chown -R www-data:www-data /app && \
    runuser -u www-data -- composer --working-dir=/app/ -o --no-progress --no-interaction --no-ansi --no-dev --no-cache --quiet -- install && \
    echo '* * * * * /usr/bin/run-app-cron'>>/etc/crontabs/www-data && \
    cp /app/docker/files/nginx.conf /etc/nginx/nginx.conf && \
    cp /app/docker/files/fpm.conf /usr/local/etc/php-fpm.d/docker.conf && \
    cp /app/docker/files/entrypoint.sh /usr/bin/entrypoint-docker && \
    cp /app/docker/files/app_console.sh /usr/bin/console && \
    cp /app/docker/files/cron.sh /usr/bin/run-app-cron && \
    cp /app/docker/files/redis.conf /etc/redis.conf && \
    rm -rf /app/docker/ /app/var/ /app/.github/ && \
    chmod +x /usr/bin/run-app-cron /usr/bin/console /usr/bin/entrypoint-docker && \
    chown -R www-data:www-data /app /config /var/lib/nginx/

ENTRYPOINT ["/usr/bin/entrypoint-docker"]

WORKDIR /config

EXPOSE 9000 80 443

CMD ["php-fpm"]
