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

# Update Caddy and add packages to it.
#
RUN echo 'Adding non modules to HTTP Server.' && \
    # add modules to caddy.
    caddy add-package github.com/lolPants/caddy-requestid github.com/caddyserver/transform-encoder >/dev/null 2>&1

# Basic setup
#
RUN echo '' && \
    # Delete unused users change users group gid to allow unRaid users to use gid 100
    deluser redis && deluser caddy && groupmod -g 1588787 users && \
    # Create our own user.
    useradd -u 1000 -U -d /config -s /bin/bash user

# Copy source code to container.
#
COPY ./ /opt/app

# install composer & packages.
#
RUN echo '' && \
    # Create basic directories.
    bash -c 'mkdir -p /temp_data/ /opt/app /config/{backup,cache,config,db,debug,logs,webhooks}' && \
    # link current PHP runtime to PHP.
    ln -s /usr/bin/${PHP_V} /usr/bin/php && \
    # we are running rootless, so user,group config options has no affect.
    sed -i 's/user = nobody/; user = user/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    sed -i 's/group = nobody/; group = users/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    # Download composer.
    curl -sSL "https://getcomposer.org/download/latest-stable/composer.phar" -o /opt/composer && chmod +x /opt/composer && \
    # Install dependencies.
    /opt/composer --working-dir=/opt/app/ -no --no-progress --no-dev --no-cache --quiet -- install && \
    # Copy configuration files to the expected directories.
    ln -s ${TOOL_PATH}/bin/console /usr/bin/console && \
    cp ${TOOL_PATH}/container/files/job-runner.sh /opt/job-runner && \
    cp ${TOOL_PATH}/container/files/Caddyfile /opt/Caddyfile && \
    cp ${TOOL_PATH}/container/files/redis.conf /opt/redis.conf && \
    cp ${TOOL_PATH}/container/files/init-container.sh /opt/init-container && \
    caddy fmt -overwrite /opt/Caddyfile && \
    # Make sure console,init-container,job-runner are given executable flag.
    chmod +x /usr/bin/console /opt/init-container /opt/job-runner && \
    # Update php.ini & php fpm
    WS_DATA_PATH=/temp_data/ WS_CACHE_NULL=1 /usr/bin/console system:php >"${PHP_INI_DIR}/conf.d/zz-custom-php.ini" && \
    WS_DATA_PATH=/temp_data/ WS_CACHE_NULL=1 /usr/bin/console system:php --fpm >"${PHP_INI_DIR}/php-fpm.d/zz-custom-pool.conf" && \
    # Remove unneeded directories and tools.
    bash -c 'rm -rf /temp_data/ /opt/composer ${TOOL_PATH}/{container,var,.github,.git,.env}' && \
    # Change Permissions.
    chown -R user:user /config /opt && chmod -R 777 /config /opt

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
EXPOSE 9000 8080 8443

# Health check.
#
HEALTHCHECK CMD /usr/bin/console -v

# Run php-fpm
#
CMD ["php-fpm81"]
