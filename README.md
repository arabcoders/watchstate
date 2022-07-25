# WatchState

This tool primary goal is to sync your backends play state without relying on third party services,
out of the box, this tool support `Jellyfin`, `Plex` and `Emby` media servers.

# Features

* Sync backends play state (from many to many).
* Backup your backends play state into `portable` format.
* Receive Webhook events from media backends..
* Find `un-matched` or `mis-matched` items.
* Search your backend for `title` or `item id`.
* Display and filter your play state. Can be exported as `yaml` or `json`.

----

## Breaking change since 2022-07-23

We rebuilt the container to be `rootless`. So, there are some breaking changes that might need your attention. Things
that need to be adjusted if you run this tool before 2022-07-22:

### Webhook default listener port

Default port has been changed from `80` to `8080`. If you are using the webhook functionality, You have to change the
port in your media backends and or your frontend proxy.

### User and group id mapping

Running `rootless` container means we cannot change the user and group id during runtime. If you have changed the
default user and/or group id before using `WS_GID`, `WS_UID` environment variables those no longer works. You need to
use the `user:` directive. There is example written in the [installation](#install) section. Most users will not be
affected as the default user/group id is `1000`.

**Note**: `Unraid` users you need to change the `user:` directive to be `user: "99:100"` to match the default user/group
mapping.

**Note**: this change does not affect Windows users.

-----

# Install

create your `docker-compose.yaml` with the following content:

```yaml
version: '2.3'
services:
    watchstate:
        image: ghcr.io/arabcoders/watchstate:latest
        # To change the user/group id associated with the tool change the following line.
        user: "${UID:-1000}:${GID:-1000}"
        container_name: watchstate
        restart: unless-stopped
        # For information about supported environment variables visit FAQ page.
        # works for both global and container specific environment variables. 
        environment:
            - WS_TZ=Asia/Kuwait # Set timezone.
        ports:
            - "8080:8080" # webhook listener port.
        volumes:
            - ./data:/config:rw # mount current directory to container /config directory.
```

Create directory called `data` next to the `docker-compose.yaml` file.

After creating your docker compose file, start the container.

```bash
$ mkdir -p ./data && docker-compose pull && docker-compose up -d
```

# Adding backend

After starting the container you should start adding your backends and to do so run the following command:

```bash
$ docker exec -ti watchstate console config:add [BACKEND_NAME]
```

This command is interactive and will ask you for some questions to add your backend.

# Managing backend

To edit backend settings run

```bash
$ docker exec -ti watchstate console config:manage [BACKEND_NAME]
```

# Importing play state.

To import your current play state from backends that have import enabled, run the following command:

```bash
$ docker exec -ti watchstate console state:import -v
```

This command will pull your play state from all your backends. To import from specific backends use
the `[-s, --select-backends]` flag which accept comma seperated list of backend names. For example,

```bash
$ docker exec -ti watchstate console state:import -v --select-backends 'home_plex,home_jellyfin' 
```

Now that you have imported your current play state enable the import task by adding the following environment variables
to your `docker-compose.yaml` file `WS_CRON_IMPORT=1`. By default, we have it disabled. for more environment variables
please refer to [Environment variables list](FAQ.md#environment-variables).

### Supported import methods

* Scheduled Task.
* On demand.
* Webhooks.

**Note**: Even if all your backends support webhooks, you should keep import task enabled. This help keep healthy
relationship.
and pick up any missed events.

---

# Exporting play state

To export your current play state to backends that have export enabled, run the following command

```bash
$ docker exec -ti watchstate console state:export -v
```

This command will export your current play state to all of your export enabled backends. To export to
specific backends use the `[-s, --select-backends]` flag which accept comma seperated list of backend names. For
example,

```bash
$ docker exec -ti watchstate console state:export -v --select-backends 'home_plex,home_jellyfin' 
```

Now that you have exported your current play state, enable the export task by adding the following environment variables
to your `docker-compose.yaml` file `WS_CRON_EXPORT=1`. By default, we have it disabled. for more environment variables
please refer to [Environment variables list](FAQ.md#environment-variables).

---

# FAQ

Take look at this [frequently asked questions](FAQ.md) page. to know more about this tool and how to enable webhook
support and answers to many questions.
