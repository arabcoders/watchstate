# WatchState

This tool primary goal is to sync your backends play state without relying on third party services,
out of the box, this tool support `Jellyfin`, `Plex` and `Emby` media servers.

# Features

* Sync backends play state (from many to many).
* Backup your backends play state into `portable` format.
* Webhook play state receiver.
* Find `un-matched` media items.
* FInd `mis-matched` media items.
* Search your backend for `title`.
* Search your backend for `item id`.
* Display and filter your play state can be exported to `json` or `yaml`.

# Install

create your `docker-compose.yaml` file:

```yaml
version: '3.3'
services:
    watchstate:
        image: ghcr.io/arabcoders/watchstate:latest
        # If you want to change the default uid/gid mapping enable the user option.
        #user: "${UID:-1000}:${GID:-1000}"
        container_name: watchstate
        restart: unless-stopped
        # For information about supported environment variables visit FAQ page.
        # works for both global and container specific environment variables. 
        environment:
            - WS_TZ=Asia/Kuwait${UID:-1000} # Set container user id.
            - WS_GID=${GID:-1000} # Set container group id.
        ports:
            - "8081:8081" # webhook listener port.
        volumes:
            - ./data:/config:rw # mount current directory to container /config directory.
```

----

## Breaking change since 2022-07-22

We fully switched to rootless container, as such we have to rebuild the container.

Things that need to be adjusted:

* Default webhook listener port is now `8081` instead of `80`. If you were using the webhook functionality you have to
  change the port.
* If you changed the default gid/uid `1000` You have to use the `user:` directive instead of `WS_GID`, `WS_UID`. The
  example above has the syntax for it just uncomment `#user:` and add your uid:gid

-----

After creating your docker compose file, start the container.

```bash
$ docker-compose pull && docker-compose up -d
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
