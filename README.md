# WatchState

WatchState is a tool that can sync your play state cross your media backends, without relying on 3rd party services,
this tool support `Jellyfin`, `Plex Media Server`and `Emby`. It's also come with some features, like
finding `mis-identified` or `unmatched` items, and the ability to
`search` your backend for specific `item id` or `title`.

# Install

create your `docker-compose.yaml` file:

```yaml
version: '3.3'
services:
    watchstate:
        image: ghcr.io/arabcoders/watchstate:latest
        container_name: watchstate
        restart: unless-stopped
        # For information about supported environment variables head to FAQ.md page.
        # works for both global and container specific environment variables. 
        environment:
            - WS_UID=${UID:-1000} # Set container user id.
            - WS_GID=${GID:-1000} # Set container group id.
        ports:
            - "8081:80" # webhook listener port.
        volumes:
            - ${PWD}:/config:rw # mount current directory to container /config directory.
```

After creating your docker compose file, start the container.

```bash
$ docker-compose pull && docker-compose up -d
```

# Adding backends

after starting the container for the first time you need to add your backends, and to do so run the following command:

```bash
$ docker exec -ti watchstate console backends:manage --add -- [BACKEND_NAME]
```

This command is interactive and will ask you for some questions to add your backend, if you want to edit the backend
config again or if you made mistake just run the same command without `--add` flag. After adding your backends, You
should import your current play state.

---

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
please refer to [Environment variables list](FAQ.md#q-what-environment-variables-supported).

### Supported import methods

* Scheduled Task.
* On demand.
* Webhooks.

### Note:

Even if all your backends support webhooks, you should keep import task enabled. This help keep healthy relationship.
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
please refer to [Environment variables list](FAQ.md#q-what-environment-variables-supported).

---

# FAQ

Take look at this [frequently asked questions](FAQ.md) page. to know more about this tool and how to enable webhook
support and answers to many questions.
