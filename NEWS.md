# NEWS

### 2026-04-30

WatchState now uses the new versioned `v02` database schema. On first boot after updating, startup may take a bit longer than usual while legacy databases are imported, migrations are applied, and indexes are rebuilt.

During that upgrade, the old database files are kept as `.migrated` safety copies. Once the first boot finishes and you have confirmed everything looks good, you can delete those `.migrated` files if you want to reclaim the space.

### 2026-04-26

Cross-backend sync for playlists is now available as a **beta** feature. This is still early work, so expect some rough edges, 
backend-specific issues, and possible breaking changes as it matures. 

Playlist behavior differs across backends, so this feature may change or be removed if consistent support is not
possible. To enable it, go to Tasks and enable the `Playlist` task.

### 2026-04-23

Plex appears to have backtracked on the API change that broke external `invited users`, so WatchState supports them again for now.
Support may change again if Plex reverses course or make it harder to access invited users tokens.

### 2026-04-23

Plex appears to have backtracked on the API change that broke external `invited users`, so WatchState supports them again for now.
Support may change again if Plex reverses course or make it harder to access invited users tokens.

### 2026-03-26

Unfortunately, due to changes from plex regarding their API, we can no longer generate access tokens for external users i.e. `invited users`, thus we had to disable and remove
support from WatchState. This change only effects external users, home/managed users are not affected. Please see [this issue](https://github.com/arabcoders/watchstate/issues/793) for more details.

### 2025-10-29

After more than **3.5 years**, **2.2k+ commits**, **900+ stars**, and **1 million+ downloads**, **WatchState v1.0.0**
is now available as the first stable release.

This release closes the initial v1.0.0 feature set. Thanks to everyone who provided feedback and reported bugs.

Future work will focus on **maintenance and bug fixes**. Feedback and suggestions remain welcome, but **major new
features** may be limited.

### 2025-08-18

We have added the final feature planned before tagging `v1.0.0`: a tool for finding duplicate file references in the
database. It is useful when multiple backends reference the same file but report different metadata.

To make it skip multi-episode items, go to backends page, and from quick operation list, select
`Force metadata import from this backend.` this will recreate the metadata which we have included flag to mark
multi-episode items.

Future updates will focus on bug fixes, performance improvements, and documentation updates.

### 2025-08-14

We are planning on tagging `v1.0.0` in the 4th quarter of 2025, as such if you are relying on deprecated
behavior or features please start migrating to the new alternatives that we have implemented. things that will be
removed before the `v1.0.0`. We will document all the deprecated features that are subject to removal in the
coming weeks. Most notably the removal of the per user/backend webhook end point.

Please refer to [NEWS](/NEWS.md) for the latest updates and changes.

### 2025-05-30

The new [webhooks](/guides/webhooks.md) system is now available. The old webhook system is deprecated and will be
removed in the next release. Migrate existing integrations to the new endpoint.

### 2025-05-23

The new webhook endpoint supports all users and backends. See the [webhook-v2 guide](guides/webhooks-v2.md) for setup
instructions. The endpoint is still in beta and may change.

### 2025-05-14

**Breaking change:** WebUI authentication now uses a username and password instead of an API key. API keys remain
available for the API, but not for the WebUI.

The first time you access the WebUI after the update, you will be asked to create a system username and password. If you
lose the password, reset it by running the following command from the host machine.

```bash
# change docker to podman if you are using podman
$ docker exec watchstate console system:resetpassword
```

### 2025-05-05

Requests can now be sent **sequentially** instead of using the default **parallel** mode. Set
`WS_HTTP_SYNC_REQUESTS` to enable sequential requests. This applies to `import`, `export`, and `backup` tasks.

Additionally, two command-line flags let you override the mode on the fly `--sync-requests` and `--async-requests`.

We’ll be evaluating this feature, and if it proves effective (and the slowdown is acceptable), we may
make **sequential** mode the default in a future release. So far from our testing, we’ve seen between 1.5x to 2.0x
increase in import time when using the sequential mode.

> [!NOTE]
> Because we cache many HTTP requests, comparing timings between sequential and parallel runs of `import` can be
> misleading. To get an accurate benchmark of `--sync-requests`, either start with a fresh setup (new installation) or
> purge your Redis instance before testing.

### 2025-04-06

We have recently re-worked how the `backend:create` command works, and we no longer generate random name for invalid
backends names or usernames. We do a normalization step to make sure the name is valid. This should help with the
confusion of having random names. This means if you re-run the `backend:create` you most likely will get a different
name than before. So, we suggest to re-run the command with `--re-create` flag. This flag will delete the current
sub-users, and regenerate updated config files.

We have also added new guard for the command, so if you already generated your sub-users, re-running the command will
show you a warning message and exit without doing anything. to run the command again either you need to use
`--re-create` or `--run` flag. The `--run` flag will run the command without deleting the current sub-users.

### 2025-03-13

We have recently added support for plex webhooks via tautulli which you can use if you don't have PlexPass. This should
help close the gap with other media servers.

### 2025-02-19

We have introduced new experimental feature to allow syncing watch progress for played items. This feature is still in
early stages, and might not work as expected. and there are probably still many bugs that we need to fix. Please report
any issues you might face.

The feature is disabled by default, to enable it you need to run add this environment variable `WS_PROGRESS_THRESHOLD`
with seconds as value, the minimum value is `180` seconds. `0` seconds means it's disabled. We think reasonable value is
`86400` or more this number is about 1day.

We are still not keen on this feature, and it might be removed in future releases if we aren't able to deal with the
issues we are facing.

### 2025-02-11

We recently have added support to generate accesstoken for external `Plex` users, i.e. `not home users`. so the
`backends:create` command now supports generating the needed config files for external users. Beware the support for
this is still in early stages, and might not work as expected. report any issues you might face.

### 2025-02-05

We have added initial support to browse the WebUI as sub user, it's still in early stages, only few Endpoints support
it.
Webhooks now support sub-users. Add a hook using `user@backend`; see the [FAQ](FAQ.md#how-to-add-webhooks) for details.

### 2025-02-02

`state:import` and `state:export` now support multiple users directly. The `state:sync` command has been removed. After
generating the sub-user configuration, those commands run alongside the main user.

### 2025-02-01

Breaking changes as of version 20250201~, in earlier versions, if you want to sync multi-user play state, you only had
to run `state:sync` command, However, due to us extending support for more operation to support multi-user data, we
needed a way to generate per user config instead of relying on `state:sync`, thus we have introduced a new command
called `backends:create`, the purpose of this command is to generate the needed config files for each user.

This change allow us to support more operations in the future.

We also have minor breaking change in per user db name, before it was named `user_name.db`, now it's named `user.db`
this change shouldn't effect you as we have backward compatibility in place to rename the old db to the new name.

for more information about multi-user, Please read the FAQ entry about it
at [this link](FAQ.md#is-there-support-for-multi-user-setup).

### 2025-01-24

We are excited to share that multi-user sync is now fully supported! Our first goal was to make sure the feature worked,
and since releasing it, we’ve worked hard to improve it based on feedback and testing. We’re now confident that it works
as expected and are happy to invite you to start using it. To learn more and get started, please check out the FAQ entry
here: [this link](FAQ.md#is-there-support-for-multi-user-setup).

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

TLS connections are now available on port `8443`, controlled by the `HTTPS_PORT` environment variable.
Previously, the `Dockerfile` exposed the port without listening for connections on it.

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

Jellyfin and Emby OAuth access tokens can now be used to sync play state. This is useful when you cannot create API keys.
The feature is experimental. Report any problems or feedback.

When prompted, enter `username:password` instead of an API key in the `WebUI` or the `config:add/manage` command.
WatchState contacts the backend and generates the token to obtain the `User ID`. Neither Emby nor Jellyfin provides an
API endpoint for querying the current user.

The new `config:test` command runs functional tests against your backends without changing their state. It reports
`OK` for a passed test, `FA` for a failed test, and `SK` for a skipped or unimplemented test.

### 2024-06-23

The `WebUI` is ready for wider use. We planned a public release for the following months and continued testing it with
user feedback.

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

The `WebUI` is now available for beta testing at `http://localhost:8080`. To disable it, set `WEBUI_ENABLED=0` in
`compose.yaml` and restart the container.

### 2024-05-13

Before the `WebUI` beta, the environment variable changed from `WS_WEBUI_ENABLED` to `WEBUI_ENABLED`. The WebUI will be
enabled by default. To disable it, set `WEBUI_ENABLED=false` in `compose.yaml`; this system-level variable cannot be set
through `.env`.

Note: `WS_WEBUI_ENABLED` will be gone in few weeks, However it will still work for now, if `WEBUI_ENABLED` is not set.

### 2024-05-05

**Edit** - We received requests that people are exposing watchstate externally, and there was concern that having open
webhook endpoints might lead to abuse. As such, we have added a new environment variable `WS_SECURE_API_ENDPOINTS`.
Set the environment variable to `1` to secure the webhook endpoint. Requests must then include
`?apikey=yourapikey`.

----- 

We are deprecating the use of the following environment
variables `WS_DISABLE_HTTP`, `WS_DISABLE_CRON`, `WS_DISABLE_CACHE`,
and replacing them with `DISABLE_CACHE`, `DISABLE_CRON`, `DISABLE_HTTP`. The old environment variables will be removed
in the future versions.
It doesn't make sense to mark them as `WS_` since they are global and do not relate to the tool itself. And they must be
set from the `compose.yaml` file itself.

### 2024-05-04

The new webhook endpoint does not require a key. Specify the backend name in the request.

### 2024-04-30 - [BREAKING CHANGE]

We are going to retire the old webhooks endpoint, please refer to the [FAQ](FAQ.md#how-to-add-webhooks) to know how to
update
to the new API endpoint. We are going to include `WebUI` for alpha testing after two weeks from today `2024-05-15`.
Which most likely means the old webhooks
endpoint will be removed. We will try to preserve the old endpoint for a while, but it's not guaranteed we will be able
to.

### 2024-03-08

This update changes how commands accept flags and options. The `-s, --select-backend` flag is now available on every
command that supports backend selection.
Commands that accepted a comma-separated backend list now require one option per backend. For example, use
`--select-backend home_plex --select-backend home_jellyfin` instead of `--select-backend home_plex,home_jellyfin`.

Commands that accepted a backend name as an argument now accept the `-s, --select-backend` flag.

The `-c, --config` flag has been removed. It only changed the `servers.yaml` path and did not change other state paths,
such as the cache and database. Use `WS_BACKENDS_FILE` to override the default `servers.yaml` path. This setting can
leave other state files in a different location, so use it only when those paths are configured separately.

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

Backups are now enabled by default. To disable them, set `WS_CRON_BACKUP=0`.

### 2023-10-31

We added new command called `db:parity` which will check if your backends are reporting the same data.
