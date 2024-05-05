# Old Updates

### 2024-04-30 - [BREAKING CHANGE]

We are going to retire the old webhooks endpoint, please refer to the [FAQ](FAQ.md#how-to-add-webhooks) to know how to
update
to the new API endpoint. We are going to include `WebUI` for alpha testing after two weeks from today `2024-05-15`.
Which most likely means the old webhooks
endpoint will be removed. We will try to preserve the old endpoint for a while, but it's not guaranteed we will be able
to.

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
