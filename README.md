# WatchState

![Build Status](https://github.com/arabcoders/WatchState/actions/workflows/build.yml/badge.svg)
![MIT License](https://img.shields.io/github/license/arabcoders/WatchState.svg)
![Docker pull](https://img.shields.io/docker/pulls/arabcoders/watchstate.svg)

This tool primary goal is to sync your backends play state without relying on third party services,
out of the box, this tool support `Jellyfin`, `Plex` and `Emby` media servers.

## updates

### 2024-03-08

This update include breaking changes to how we process commands, we have streamlined the command interface to accept
some consistent flags and options. Notably, we have added `-s, --select-backend` flag to all commands that accept it.
commands that were accepting comma separated list of backends now needs to be separate option call for example
`--select-backend home_plex --select-backend home_jellyfin` instead of `--select-backend home_plex,home_jellyfin`.

All commands that was accepting backend name as argument now accepts `-s, --select-backend` flag. This change is to make
the command interface more consistent and easier to use.

Another breaking change is the removal of the `-c, --config` flag from all commands that was accepting it. This flag was
used to override the default `servers.yaml` file. This was not working as expected as there are more than just the `servers.yaml`
to consider like, the state of cache, and the state of the database. As such, we have removed this flag. However, we have
added a new environment variable called `WS_BACKENDS_FILE` which can be used to override the default `servers.yaml` file.
We strongly recommend not to use it as it might lead to unexpected behavior.

We started working on a `Web API` which hopefully will lead to a `web frontend` to manage the tool. This is a long
term goal, and it's not expected to be ready soon. However, the `Web API` is expected within 3rd quarter of 2024.

### 2023-11-11

We added new feature `watch progress tracking` YAY which works exclusively via webhooks at the moment to keep tracking
of your play progress.
As this feature is quite **EXPERIMENTAL** we have separate command and task for it `state:progress` will send back
progress to your backends.
However, Sadly this feature is not working at the moment with `Jellyfin` once they accept
my [PR #10573](https://github.com/jellyfin/jellyfin/pull/10573) i'll add support for it. However,
The feature works well with both `Plex` and `Emby`.

The support via `webhooks` is excellent, and it's the recommended way to track your progress. However, if you cant use
webhooks, the `state:import` command
will pull the progress from your backends. however at reduced rate due to the nature of the command. If you want faster
progress tracking, you should use `webhooks`.

To sync the progress update, You have to use `state:progress` command, it will push the update to all `export` enabled
backends.
This feature is disabled by default like the other features. To enable it add new environment variable
called`WS_CRON_PROGRESS=1`.
We push progress update every `45 minutes`, to change it like other features add `WS_CRON_PROGRESS_AT="*/45 * * * *"`
This is the default timer.

On another point, we have decided to enable backup by default. To disable it simply add new environment
variable `WS_CRON_BACKUP=0`.

### 2023-10-31

We added new command called `db:parity` which will check if your backends are reporting the same data.

# Features

* Sync backends play state (from many to many).
* Backup your backends play state into `portable` format.
* Receive Webhook events from media backends.
* Find `un-matched` or `mis-matched` items.
* Search your backend for `title` or `item id`.
* Display and filter your play state. Can be exported as `yaml` or `json`.
* Check if your media servers reporting same data via the parity command.
* Track your watch progress via webhooks.

----

# Install

create your `docker-compose.yaml` with the following content:

```yaml
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
            - WS_TZ=UTC # Set timezone.
        ports:
            - "8080:8080" # webhook listener port.
        volumes:
            - ./data:/config:rw # mount current directory to container /config directory.
```

Create directory called `data` next to the `docker-compose.yaml` file. After creating your docker compose file, start
the container.

```bash
$ mkdir -p ./data && docker-compose pull && docker-compose up -d
```

> [!IMPORTANT]
> It's really important to match the `user:` to the owner of the `data` directory, the container is rootless, as such
> it will crash if it's unable to write to the data directory. It's really not recommended to run containers as root,
> but if you fail to run the container you can try setting the `user: "0:0"` if that works it means you have permissions
> issues. refer to [FAQ](FAQ.md) to troubleshoot the problem.

> [!NOTE]
> For `Unraid` users You can install the `Community Applications` plugin, and search for `watchstate` it comes
> preconfigured. Otherwise, to manually install it, you need to add value to the `Extra Parameters` section in advanced
> tab/view. add the following value `--user 99:100`. This has to happen before you start the container, otherwise it
> will
> have the old user id, and you then have to run the following command from
> terminal `chown -R 99:100 /mnt/user/appdata/watchstate`.

> [!NOTE]
> To use this container with `podman` set `docker-compose.yaml` `user` to `0:0`. it will appear to be working as root
> inside the container, but it will be mapped to the user in which the command was run under.

# Adding backend

After starting the container you should start adding your backends and to do so run the following command:

> [!NOTE]
> to get your plex token, please
> visit [this plex page](https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/) to
> know
> how to extract your plex token.
> For jellyfin & emby. Go to Dashboard > Advanced > API keys > then create new api keys.

```bash
$ docker exec -ti watchstate console config:add
```

This command is interactive and will ask you for some questions to add your backend.

# Managing backend

To edit backend settings run

```bash
$ docker exec -ti watchstate console config:manage -s backend_name
```

# Importing play state.

What does `Import` or what does the command `state:import` means in context of watchstate?

Import means, pulling data from the backends into the database while attempting to normalize the state.

To import your current play state from backends that have import enabled, run the following command:

```bash
$ docker exec -ti watchstate console state:import -v
```

This command will pull your play state from all your backends. To import from specific backends use
the `[-s, --select-backend]` flag. For example,

```bash
$ docker exec -ti watchstate console state:import -v -s home_plex -s home_jellyfin 
```

> [!NOTE]
> Now that you have imported your current play state enable the import task by adding the following environment
> variables to
> your `docker-compose.yaml` file `WS_CRON_IMPORT=1`. By default, we have it disabled. for more environment variables
> please
> refer to [Environment variables list](FAQ.md#environment-variables).

### Supported import methods

Out of the box, we support the following import methods:

* Scheduled Task. `Cron jobs that pull data from backends on a schedule.`
* On demand. `Pull data from backends on demand. By running the state:import & state:export command manually`
* Webhooks. `Receive events from backends and update the database accordingly.`

> [!NOTE]
> Even if all your backends support webhooks, you should keep import task enabled. This help keep healthy relationship.
> and pick up any missed events.

---

# Exporting play state

What does `export` or what does the command `state:export` means in context of watchstate?

Export means, sending data back to backends, while trying to minimize the network traffic.

To export your current play state to backends that have export enabled, run the following command

```bash
$ docker exec -ti watchstate console state:export -v
```

This command will export your current play state to all of your export enabled backends. To export to
specific backends use the `[-s, --select-backend]`flag. For example,

```bash
$ docker exec -ti watchstate console state:export -v -s home_plex -s home_jellyfin 
```

> [!NOTE]
> Now that you have exported your current play state, enable the export task by adding the following environment
> variables to
> your `docker-compose.yaml` file `WS_CRON_EXPORT=1`. By default, we have it disabled. for more environment variables
> please
> refer to [Environment variables list](FAQ.md#environment-variables).

---

# FAQ

Take look at this [frequently asked questions](FAQ.md) page. to know more about this tool and how to enable webhook
support and answers to many questions.

# Social contact

If you have short or quick questions, you are free to join my [discord server](https://discord.gg/haUXHJyj6Y) and ask
the question. keep in mind it's solo project, as such it might take me a bit of time to reply.

# Donate

If you feel like donating and appreciate my work, you can do so by donating to children charity. For
example [Make-A-Wish](https://worldwish.org).
I Personally don't need the money, but I do appreciate the gesture. Making a child happy is the best thing you can do in
this world.
