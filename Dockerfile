FROM caddy:builder-alpine AS builder

RUN xcaddy build --with github.com/caddyserver/transform-encoder

FROM alpine:3.16

COPY --from=builder /usr/bin/caddy /usr/sbin/caddy
COPY --from=composer:2 /usr/bin/composer /opt/bin/composer

LABEL maintainer="admin@arabcoders.org"

ARG TZ=UTC
ARG PHP_V=php81
ARG PHP_PACKAGES="common ctype curl dom fileinfo fpm intl mbstring opcache pcntl pdo_sqlite phar posix session shmop simplexml snmp sockets sodium sysvmsg sysvsem sysvshm tokenizer xml openssl xmlreader xmlwriter zip pecl-igbinary pecl-xhprof pecl-redis"
ARG TOOL_PATH=/opt/app

ENV IN_CONTAINER=1
ENV PHP_INI_DIR=/etc/${PHP_V}
ENV PATH=/opt/bin:${PATH}

# Setup the required environment.
#
RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime && echo ${TZ} > /etc/timezone && \
    for ext in ${PHP_PACKAGES}; do PACKAGES="${PACKAGES} ${PHP_V}-${ext}"; done && \
    apk add --no-cache bash icu-data-full nano curl procps net-tools iproute2  \
    shadow sqlite redis tzdata gettext fcgi ${PHP_V} ${PACKAGES} && \
    # Basic clean yo Delete unused users change users group gid to allow unRaid users to use gid 100
    deluser redis && groupmod -g 1588787 users && \
    # Create our own user.
    useradd -u 1000 -U -d /config -s /bin/bash user

# Copy source code to container.
#
COPY ./ /opt/app

# install composer & packages.
#
RUN echo '' && \
    # Create basic directories.
    bash -c 'mkdir -p /temp_data/ /opt/{app,bin,config} /config/{backup,cache,config,db,debug,logs,webhooks}' && \
    # Link console & php.
    ln -s /usr/bin/${PHP_V} /usr/bin/php && ln -s ${TOOL_PATH}/bin/console /usr/bin/console && \
    ln -s ${TOOL_PATH}/bin/console /opt/bin/console && \
    # we are running rootless, so user,group config options has no affect.
    sed -i 's/user = nobody/; user = user/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    sed -i 's/group = nobody/; group = users/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    # expose php-fpm on all interfaces.
    sed -i 's/listen = 127.0.0.1:9000/listen = 0.0.0.0:9000/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    # Install dependencies.
    /opt/bin/composer --working-dir=/opt/app/ -no --no-progress --no-dev --no-cache --quiet -- install && \
    # Copy configuration files to the expected directories.
    cp ${TOOL_PATH}/container/files/job-runner.sh /opt/bin/job-runner && \
    cp ${TOOL_PATH}/container/files/init-container.sh /opt/bin/init-container && \
    cp ${TOOL_PATH}/container/files/php-fpm-healthcheck.sh /opt/bin/php-fpm-healthcheck && \
    cp ${TOOL_PATH}/container/files/Caddyfile /opt/config/Caddyfile && \
    cp ${TOOL_PATH}/container/files/redis.conf /opt/config/redis.conf && \
    caddy fmt --overwrite /opt/config/Caddyfile && \
    # Make sure /bin/* files are given executable flag.
    chmod +x /opt/bin/* && \
    # Update php.ini & php fpm
    WS_DATA_PATH=/temp_data/ WS_CACHE_NULL=1 /opt/bin/console system:php >"${PHP_INI_DIR}/conf.d/zz-custom-php.ini" && \
    WS_DATA_PATH=/temp_data/ WS_CACHE_NULL=1 /opt/bin/console system:php --fpm >"${PHP_INI_DIR}/php-fpm.d/zz-custom-pool.conf" && \
    # Remove unneeded directories and tools.
    bash -c 'rm -rf /temp_data/ /opt/bin/composer ${TOOL_PATH}/{container,var,.github,.git,.env}' && \
    # Change Permissions.
    chown -R user:user /config /opt /var/log/ && chmod -R 775 /var/log/

# Set the entrypoint.
#
ENTRYPOINT ["/opt/bin/init-container"]

# Change working directory.
#
WORKDIR /config

# Switch to user
#
USER user

# Expose the ports.
#
EXPOSE 9000 8080 8443

# Health check.
#
HEALTHCHECK CMD /opt/bin/php-fpm-healthcheck -v

# Run php-fpm
#
CMD ["php-fpm81"]
