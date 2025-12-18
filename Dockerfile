FROM node:lts-alpine AS npm_builder

WORKDIR /frontend
COPY ./frontend ./
ENV NODE_ENV=production
RUN if [ ! -d "/frontend/exported" ]; then \
  npm install -g pnpm && \
  NODE_ENV=production pnpm install --frozen-lockfile --prod --ignore-scripts && \
  pnpm run generate; \
  else echo "Skipping UI build, already built."; fi

FROM debian:13

COPY --from=composer/composer:2-bin /composer /opt/bin/composer

LABEL maintainer="admin@arabcoders.org"

ARG TZ=UTC
ARG TOOL_PATH=/opt/app
ARG USER_ID=1000

ENV IN_CONTAINER=1
ENV PATH=/opt/bin:${PATH}
ENV WS_DATA_PATH=/config
ENV WS_TZ=UTC
ENV PACKAGES=""

# Setup the required environment.
#
RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime && echo ${TZ} > /etc/timezone && \
    ARCH="$(dpkg --print-architecture)" && \
    if [ "$ARCH" = "amd64" ]; then PACKAGES="${PACKAGES} intel-media-va-driver i965-va-driver libmfx-gen1.2"; fi && \
    apt update && apt install -y --no-install-recommends nano curl procps net-tools iproute2 tzdata sqlite3 \
    redis gettext ca-certificates fontconfig fonts-freefont-ttf fonts-noto fonts-terminus fonts-dejavu vainfo ${PACKAGES} && \
    # Delete unused users change users group gid to allow unRaid users to use gid 100 \
    deluser redis && groupmod -g 1588787 users && \
    # Create our own user. \
    useradd -u ${USER_ID:-1000} -U -d /config -s /bin/bash user && \
    # Cache fonts.
    fc-cache -f && fc-list | sort

# Copy frankenphp (caddy+php) to the container.
#
COPY --chown=app:app --from=ghcr.io/arabcoders/franken_builder:latest /usr/local/bin/frankenphp /opt/bin/
COPY --chown=app:app --from=ghcr.io/arabcoders/jellyfin-ffmpeg /usr/bin/ffmpeg /usr/bin/ffmpeg
COPY --chown=app:app --from=ghcr.io/arabcoders/jellyfin-ffmpeg /usr/bin/ffprobe /usr/bin/ffprobe

# Copy source code to container.
COPY ./ /opt/app

# Copy frontend to public directory.
COPY --chown=app:app --from=npm_builder /frontend/exported/ /opt/app/public/exported/

# install composer & packages.
#
RUN echo '' && \
    chmod +x /opt/bin/frankenphp && \
    # create /usr/bin/php that points to /opt/bin/frankenphp php-cli "$@" \
    echo '#!/bin/sh' > /usr/bin/php && \
    echo 'exec /opt/bin/frankenphp php-cli "$@"' >> /usr/bin/php && chmod +x /usr/bin/php && \
    # Create basic directories \
    bash -c 'umask 0000 && mkdir -p /temp_data/ /opt/{app,bin,config} /config/{backup,cache,config,db,debug,logs,webhooks,profiler}' && \
    # Link console. \
    ln -s ${TOOL_PATH}/bin/console /opt/bin/console && \
    # Install dependencies. \
    /opt/bin/composer --working-dir=/opt/app/ -no --no-progress --no-dev --no-cache --quiet -- install && \
    # Copy configuration files to the expected directories.
    cp ${TOOL_PATH}/container/files/init-container.sh /opt/bin/init-container && \
    cp ${TOOL_PATH}/container/files/runner.sh /opt/bin/ws-runner && \
    cp ${TOOL_PATH}/container/files/redis.conf /opt/config/redis.conf && \
    # Make sure /bin/* files are given executable flag.
    chmod +x /opt/bin/* && \
    # Update php.ini & php fpm \
    mkdir -p /etc/frankenphp/php.d && \
    WS_DATA_PATH=/temp_data/ WS_CACHE_NULL=1 /opt/bin/console system:php >"/etc/frankenphp/php.d/php.ini" && \
    # Remove unneeded directories and tools. \
    bash -c 'rm -rf /temp_data/ /opt/bin/composer ${TOOL_PATH}/{tests,container,var,.github,.git,.env}' && \
    # Change Permissions. \
    chown -R user:user /config /opt /var/log && chmod -R 777 /var/log /etc/frankenphp/php.d

# Set the entrypoint.
#
ENTRYPOINT ["/opt/bin/init-container"]

# Change working directory.
#
WORKDIR /config

# Declare the config directory as a volume.
#
VOLUME ["/config"]

# Switch to user
#
USER user

# Expose the ports.
#
EXPOSE 8080

# Health check.
#
HEALTHCHECK --interval=10s --timeout=3s CMD curl -f http://localhost:8080/v1/api/system/healthcheck || exit 1

# Run php-fpm
#
CMD ["/opt/bin/frankenphp", "php-server", "--listen","0.0.0.0:8080", "--root", "/opt/app/public"]
