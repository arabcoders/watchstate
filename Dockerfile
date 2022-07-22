FROM alpine:3.16

LABEL maintainer="admin@arabcoders.org"

ENV IN_DOCKER=1
ENV PHP_V=php81
ENV TOOL_PATH=/opt/app
ENV PHP_INI_DIR=/etc/${PHP_V}

# Setup the required environment.
#
RUN apk add --no-cache bash caddy nano curl procps net-tools iproute2 shadow sqlite redis tzdata gettext \
    ${PHP_V} ${PHP_V}-common ${PHP_V}-ctype ${PHP_V}-curl ${PHP_V}-dom ${PHP_V}-fileinfo ${PHP_V}-fpm \
    ${PHP_V}-intl ${PHP_V}-mbstring ${PHP_V}-opcache ${PHP_V}-pcntl ${PHP_V}-pdo_sqlite ${PHP_V}-phar \
    ${PHP_V}-posix ${PHP_V}-session ${PHP_V}-shmop ${PHP_V}-simplexml ${PHP_V}-snmp ${PHP_V}-sockets \
    ${PHP_V}-sodium ${PHP_V}-sysvmsg ${PHP_V}-sysvsem ${PHP_V}-sysvshm ${PHP_V}-tokenizer ${PHP_V}-xml ${PHP_V}-openssl \
    ${PHP_V}-xmlreader ${PHP_V}-xmlwriter ${PHP_V}-zip ${PHP_V}-pecl-igbinary ${PHP_V}-pecl-redis ${PHP_V}-pecl-xhprof

# Create user and group
#
RUN deluser redis && deluser caddy && groupmod -g 1588787 users && useradd -u 1000 -U -d /config -s /bin/bash user && \
    mkdir -p /config /opt/app && ln -s /usr/bin/php81 /usr/bin/php

# Copy tool files.
#
COPY ./ /opt/app

# install composer & packages.
#
ADD https://getcomposer.org/download/latest-stable/composer.phar /opt/composer

RUN chmod +x /opt/composer && \
    /opt/composer --working-dir=/opt/app/ -o --no-progress --no-interaction --no-ansi --no-dev --no-cache --quiet -- install && \
    rm /opt/composer

# Copy configuration files to the expected directories.
#
RUN ln -s ${TOOL_PATH}/bin/console /usr/bin/console && \
    cp ${TOOL_PATH}/container/files/cron.sh /opt/job-runner && \
    cp ${TOOL_PATH}/container/files/Caddyfile /opt/Caddyfile && \
    cp ${TOOL_PATH}/container/files/redis.conf /opt/redis.conf && \
    cp ${TOOL_PATH}/container/files/init-container.sh /opt/init-container && \
    cp ${TOOL_PATH}/container/files/fpm.conf /etc/${PHP_V}/php-fpm.d/z-container.conf && \
    rm -rf ${TOOL_PATH}/{container,var,.github,.git} && \
    sed -i 's/user = nobody/; user = user/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    sed -i 's/group = nobody/; group = users/' /etc/${PHP_V}/php-fpm.d/www.conf

# Change Permissions.
#
RUN chmod +x /usr/bin/console /opt/init-container /opt/job-runner && \
    chown -R user:user /config /opt /etc/${PHP_V} /var/run /run

# Set the entrypoint.
#
ENTRYPOINT ["/opt/init-container"]

# Change working directory.
#
WORKDIR /config

# Switch to user
#
USER user

# Expose the ports.
#
EXPOSE 9000 8081

# Health check.
#
HEALTHCHECK CMD /usr/bin/console -v

# Run php-fpm
#
CMD ["php-fpm81"]
