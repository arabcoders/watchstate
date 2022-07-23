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
    useradd -u 1000 -U -d /config -s /bin/bash user && \
    # Create basic directories.
    mkdir -p /opt/app /config/{backup,cache,config,db,debug,logs,webhooks} && \
    # link php runtime to to php.
    ln -s /usr/bin/${PHP_V} /usr/bin/php && \
    # we are running rootless, so user,group config options has no affect.
    sed -i 's/user = nobody/; user = user/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    sed -i 's/group = nobody/; group = users/' /etc/${PHP_V}/php-fpm.d/www.conf

# Copy source code to container.
#
COPY ./ /opt/app

# install composer & packages.
#
RUN echo '' && \
    # Download composer.
    curl -sSL "https://getcomposer.org/download/latest-stable/composer.phar" -o /opt/composer && chmod +x /opt/composer && \
    # Install dependencies.
    /opt/composer --working-dir=/opt/app/ -no --no-progress --no-dev --no-cache --quiet -- install && \
    # Remove composer.
    rm /opt/composer

# Copy configuration files to the expected directories.
#
RUN ln -s ${TOOL_PATH}/bin/console /usr/bin/console && \
    cp ${TOOL_PATH}/container/files/job-runner.sh /opt/job-runner && \
    cp ${TOOL_PATH}/container/files/Caddyfile /opt/Caddyfile && \
    cp ${TOOL_PATH}/container/files/redis.conf /opt/redis.conf && \
    cp ${TOOL_PATH}/container/files/init-container.sh /opt/init-container && \
    cp ${TOOL_PATH}/container/files/fpm.conf /etc/${PHP_V}/php-fpm.d/z-container.conf && \
    rm -rf ${TOOL_PATH}/{container,var,.github,.git,.env} && \
    caddy fmt -overwrite /opt/Caddyfile

# Change Permissions.
#
RUN echo '' && \
    # Make sure console,init-container,job-runner are given executable flag.
    chmod +x /usr/bin/console /opt/init-container /opt/job-runner && \
    # Change permissions on our working directories.
    chown -R user:user /config /opt /etc/${PHP_V}

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
