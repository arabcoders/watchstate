FROM php:8.1-fpm-alpine

LABEL maintainer="admin@arabcoders.org"

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/bin/

RUN chmod +x /usr/bin/install-php-extensions && sync && \
    install-php-extensions pdo mbstring ctype sqlite3 json opcache xhprof

RUN apk add --no-cache nano curl procps net-tools iproute2 shadow runuser

COPY ./entrypoint.sh /entrypoint-docker

RUN chmod +x /entrypoint-docker

RUN echo '#!/usr/bin/env ash'>/usr/bin/console && echo 'runuser -u www-data -- php /app/console "${@}"'>>/usr/bin/console && \
    chmod +x /usr/bin/console

RUN mv "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"

# Add Composer
#
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

ENTRYPOINT ["/entrypoint-docker"]

WORKDIR /config

EXPOSE 9000

CMD ["php-fpm"]
