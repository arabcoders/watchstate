# Old Updates

### 2025-01-18

Due to popular demand, we finally have added the ability to sync all users data, however, it's limited to only
play state, no progress syncing implemented at this stage. This feature still in alpha expect bugs and issues.

However our local tests shows that it's working as expected, but we need more testing to be sure. Please report any
issues you encounter. To enable this feature, you will see new task in the `Tasks` page called `Sync`.

This task will sync all your users play state, However you need to have the backends added with admin token for plex and
API key for jellyfin and emby. Enable the task and let it run, it will sync all users play state.

Please read the FAQ entry about it at [this link](FAQ.md#is-there-support-for-multi-user-setup).

### 2024-12-30

We have removed the old environment variables `WS_CRON_PROGRESS` and `WS_CRON_PUSH` in favor of the new ones
`WS_SYNC_PROGRESS` and `WS_PUSH_ENABLED`. please update your environment variables accordingly. We have also added
new FAQ entry about watch progress syncing via [this link](FAQ.md#sync-watch-progress).

### 2024-10-07

We have added a WebUI page for Custom GUIDs and stabilized on `v1.0` for the `guid.yaml` file spec. We strongly
recommend
to use the `WebUI` to manage the GUIDs, as it's much easier to use than editing the `guid.yaml` file directly. and both
the
`WebUI` and `API` have safeguards to prevent you from breaking the parser. For more information please check out the
associated
FAQ entry about it at [this link](FAQ.md#advanced-how-to-extend-the-guid-parser-to-support-more-guids-or-custom-ones).

### 2024-09-14

We have recently added support for extending WatchState with more GUIDs, as of now, the support for it is done via
editing a`/config/guid.yaml` file in the config directory. We plan to hopefully add management via WebUI in near the
future. For more information please check out the associated
FAQ entry about it at [this link](FAQ.md#advanced-how-to-extend-the-guid-parser-to-support-more-guids-or-custom-ones).

The mapping should work for all officially supported clients. If you have a client that is not supported, you have to
manually add support for that client,
or request the maintainer to add support for it.

### 2024-08-19

We have migrated the `state:push` task into the new events system, as such the old task `state:push` is now gone.
To enable the new event handler for push events, use the new environment variable `WS_PUSH_ENABLED` and set it to
`true`.
Right now, it's disabled by default. However, for people who had the old task enabled, it will reuse that setting.

Keep in mind, the new event handler is more efficient and will only push data when there is a change in the play state.
And it's much faster
than the old task. This event handler will push data within a minute of the change.

PS: Please enable the task by setting its new environment variable `WS_PUSH_ENABLED` to `true`. The old `WS_CRON_PUSH`
is now gone.
and will be removed in the future releases.

### 2024-08-18

We have started migrating the old events system to a new one, so far we have migrated the `progress` and `requests` to
it. As such,
The old tasks `state:progress` and `state:requests` are now gone. To control if you want to enable the watch progress,
there is new
environment variable `WS_SYNC_PROGRESS` which you can set to `true` to enable the watch progress. It's disabled by
default.

We will continue to migrate the rest of the events to the new system, and we will keep you updated.

### 2024-08-10

I have recently added new experimental feature, to play your content directly from the WebUI. This feature is still in
alpha, and missing a lot of features. But it's a start. Right now it does auto transcode on the fly to play any content
in the browser.

The feature requires that you mount your media directories to the `WatchState` container similar to the `File integrity`
feature. I have plans to expand
the feature to support more controls, however, right now it's only support basic subtitles streams and default audio
stream or first audio stream.

The transcoder works by converting the media on the fly to `HLS` segments, and the subtitles are selectable via the
player ui which are also converted to `vtt` format.

Expects bugs and issues, as the feature is still in alpha. But I would love to hear your feedback. You can play the
media by visiting
the history page of the item you will see red play button on top right corner of the page. If the items has a play
button, then you correctly mounted
the media directories. otherwise, the button be disabled with tooltip of `Media is inaccessible`.

The feature is not meant to replace your backend media player, the purpose of this feature is to quickly check the media
without leaving the WebUI.

### 2024-08-01

We recently enabled listening on tls connections via `8443` which can be controlled by `HTTPS_PORT` environment
variable.
Before today, we simply only exposed the port via the `Dockerfile`, but we weren't listening for connections on it.

However, please keep in mind that the certificate is self-signed, and you might get a warning from your browser. You can
either accept the warning or add the certificate to your trusted certificates. We strongly recommend using a reverse
proxy.
instead of relying on self-signed certificates.

### 2024-07-22

We have recently added a new WebUI feature, `File integrity`, this feature will help you to check if your media backends
are reporting files that are not available on the disk. This feature is still in alpha, and we are working on improving
it.

This feature `REQUIRES` that you mount your media directories to the `WatchState` container preferably as readonly.
There is plans to add
a path replacement feature to allow you change the pathing, but it's not implemented yet.

This feature will work on both local and remote cloud storages provided they are mounted into the container. We also may
recommend not to
use this feature depending on how your cloud storage provider treats file stat calls. As it might lead to unnecessary
money spending. and of course
it will be slower.

For more information about how we cache the stat calls, please refer to
the [FAQ](FAQ.md#How-does-the-file-integrity-feature-works).

### 2024-07-06

Recently we have introduced a new feature that allows you to use Jellyfin and Emby OAuth access tokens for syncing
your play state. This is especially handy if you're not the server owner and can't create API keys. Please note, this
feature is in its experimental phase, so you might encounter some issues as we yet to explorer the full depth of the
implementation. We're actively working on making it better, If you have any feedback or suggestions, please let us know.

Getting your OAuth token is easy. When prompted, simply enter your `username:password` in place of the API key through
the `WebUI` or the `config:add/manage` command. `WatchState` will automatically contact the backend and generate the
token for you, as this step is required to get more information like your `User ID` which is sadly inaccessible without
us generating the token. Both Emby & Jellyfin doesn't provide an API endpoint to inquiry about the current user.

We have also added new `config:test` command to run functional tests on your backends, this will not alter your state,
And it's quite useful to know if the tool is able to communicate with your backends. without problems, It will report
the following, `OK` which mean the indicated test has passed, `FA` which mean the indicated test has failed. And `SK`
which mean the indicated test has been skipped or not yet implemented.

### 2024-06-23

WE are happy to announce that the `WebUI` is ready for wider usage and we are planning to release it in the next few
months.
We are actively working on it to improve it. If you have any feedback or suggestions, please let us know. We feel it's
almost future complete
for the things that we want.

On another related news, we have added new environment variable `WS_API_AUTO` "disabled by default" which can be used
to automatically expose your **API KEY/TOKEN**. This is useful for users who are using the `WebUI` from many different
browsers
and want to automate the configuration process.

While the `WebUI` is included in the main project, it's a standalone feature and requires the API settings to be
configured before it
can be used. This environment variable can be enabled by setting `WS_API_AUTO=true` in `${WS_DATA_PATH}/config/.env`.

> [!IMPORTANT]
> This environment variable is **GREAT SECURITY RISK**, and we strongly recommend not to use it if `WatchState` is
> exposed to the internet.

### 2024-05-14

We are happy to announce the beta testing of the `WebUI`. To get started on using it you just need to visit the url
`http://localhost:8080` We are supposed to
enabled it by default tomorrow, but we decided to give you a head start. We are looking forward to your feedback. If you
don't use the `WebUI` then you need to
add the environment variable `WEBUI_ENABLED=0` in your `compose.yaml` file. and restart the container.

### 2024-05-13

In preparation for the beta testing of `WebUI` in two days, we have made little breaking change, we have changed the
environment variable `WS_WEBUI_ENABLED` to just `WEBUI_ENABLED`, We made this change to make sure people don't disable
the `WebUI`by mistake via the environment page in the `WebUI`. The `WebUI` will be enabled by default, in two days from
now, to disable it from now add `WEBUI_ENABLED=false` to your `compose.yaml` file. As this environment variable is
system level, it cannot be set via `.env` file.

Note: `WS_WEBUI_ENABLED` will be gone in few weeks, However it will still work for now, if `WEBUI_ENABLED` is not set.

### 2024-05-05

**Edit** - We received requests that people are exposing watchstate externally, and there was concern that having open
webhook endpoints might lead to abuse. As such, we have added a new environment variable `WS_SECURE_API_ENDPOINTS`.
Simply set
the environment variable to `1` to secure the webhook endpoint. This means you have to add `?apikey=yourapikey` to the
end
of the webhook endpoint.

----- 

We are deprecating the use of the following environment
variables `WS_DISABLE_HTTP`, `WS_DISABLE_CRON`, `WS_DISABLE_CACHE`,
and replacing them with `DISABLE_CACHE`, `DISABLE_CRON`, `DISABLE_HTTP`. The old environment variables will be removed
in the future versions.
It doesn't make sense to mark them as `WS_` since they are global and do not relate to the tool itself. And they must be
set from the `compose.yaml` file itself.

### 2024-05-04

The new webhook endpoint no longer requires a key, and it's now open to public you just need to specify the backend
name.

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
used to override the default `servers.yaml` file. This was not working as expected as there are more than just the
`servers.yaml`
to consider like, the state of cache, and the state of the database. As such, we have removed this flag. However, we
have
added a new environment variable called `WS_BACKENDS_FILE` which can be used to override the default `servers.yaml`
file.
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
