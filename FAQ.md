# FAQ

# How to enable scheduled/automatic tasks?

To turn on automatic import or export tasks:

1. Go to the `Tasks` page.
2. Enable the tasks you want to schedule for automatic execution.

By default, the tasks are scheduled:

- **Import** task runs every **1 hour**
- **Export** task runs every **1 hour and 30 minutes**

If you want to customize the schedule, go to <!--i:fa-tasks--> **Tasks** page underneath the task click on the timer,
and it will take you to the <!--i:fa-cogs--> **Env** page, where you can set the cron expression directly.

> [!NOTE]
> A great tool to validate your cron expression is [crontab.guru](https://crontab.guru/)

After making changes, visit the `Tasks` page again to see the updated schedule next to `Next Run`.

---

# Container is crashing on start-up?

This usually happens due to a misconfigured `user:` in your `compose.yaml` file or an incorrect `--user` argument. The
container is rootless and cannot change the permissions of data directory by itself, so it will crash if it doesn’t have
permission to access the data directory.

## Check permissions

Run the following command to inspect ownership of your data directory:

```bash
stat data/config/ | grep 'Uid:'
```

The path `data/config/` refers to where you have mounted your data directory. Adjust it if necessary.

You should see output like:

```
Access: (0755/drwxr-xr-x)  Uid: ( 1000/  user)   Gid: ( 1000/  user)
```

## Fixing the issue

Use the UID and GID from the output as parameters:

### compose.yaml

```yaml
  user: "1000:1000"
```

### docker run

```bash
docker run ... --user 1000:1000
```

Make sure the container has the correct permissions to access all necessary data paths to prevent crashes.

----

# MAPPER: Conflict Detected in `'user@backend_name`...?

This warning appears when there is a mismatch between the local database and a backend regarding the watch state of a
movie or episode. Specifically, it means:

- The item is marked as **played** in the local database.
- The backend is reporting it as **unplayed**.
- There is no metadata indicating that the item was previously imported from that backend to make it possible to mark it
  as unplayed.

In this case, the system prioritizes preserving your local play state. The item is marked as **tainted** and will be
re-processed accordingly. To resolve this conflict and sync the backend with your local state:

- Go to the `Backends`.
- Under the relevant backend, find the **Quick operations** list.
- Select **3. Force export local play state to this backend.**

This operation will overwrite the backend's watch state with your current local state to bring them back in sync.

----

# How to Use Jellyfin or Emby OAuth Tokens

Due to limitations on the Jellyfin/Emby side, our implementation requires you to provide your credentials in the
`username:password` format. This is necessary because their API does not allow us to determine the currently
authenticated user directly.

When prompted for the API key, simply enter your credentials like this:

```
username:password
```

WatchState will then generate an OAuth token for you and automatically replace the username and password with the token.
This is a one-time process, and you won’t need to repeat it.

Your `username` and `password` are **not** stored.

----

# My New Backend Is Overriding My Old Backend’s State / My Watch State Is Incorrect

This issue typically occurs when a newly added backend reports **newer timestamps** than an existing backend.  
By default, the system prioritizes data with the latest timestamps, which usually ensures the most up-to-date watch
state is preserved.

However, if the new backend's state is incorrect, it may unintentionally override your accurate local watch history.

## How to Fix the play state

To synchronize both backends correctly:

- **Add the backend** that contains the correct watch state first.
- Enable **Full Import** for that backend.
- Go to `Tasks` page, and run the **Import** task via `Run via console` button.
- Once the import is complete, **add the second backend** (the one with incorrect or outdated play state).
- Under the newly added backend, locate the **Quick operations** section.
- Select **3. Force export local play state to this backend.**

This will push your local watch state to the backend and ensure both are in sync.

----

# My New Backend Watch State Is Not Being Updated?

This issue is most likely caused by a **date mismatch**. When exporting watch state, the system compares the date of the
item on the backend with the date stored in the local database. If the backend's date is equal to or newer than the
local one, the export may be skipped.

To confirm if this is the issue, follow these steps:

1. Go to the `Backends`.
2. Under the relevant backend, locate the **Quick operations** section.
3. Select **7. Run export and save debug log.**

This will generate a log file at `/config/user@backend_name.export.txt` If the file is too large to view in a regular
text editor, you can read it using:

```bash
docker exec -ti watchstate bash -c "cat /config/user@backend_name.export.txt | more"
```

Look for lines like the following:

```
[YYYY-MM-DDTHH:MM:SS-ZZ] DEBUG: Ignoring 'user@backend_name' 'Title - (Year or episode)'. reason. { ..., (comparison: [ ... ]) }
```

If you see messages such as `Backend date is equal or newer than database date.` that confirms the issue.

## How to Fix It

To override the date check and force an update do the following:

* Go to the `Backends`.
* Under the relevant backend, find the **Quick operations** list.
* Select **3. Force export local play state to this backend.**

This will sync your local database state to the backend, ignoring date comparisons.

----

# Is there support for Multi-user setup?

The tool is primarily designed for single-user use, The Multi-user/sub-users functionality is built on top of that.
From our testing, sub-users functionality works well right now and behave as expected in the majority of cases. Please
refer to [sub users](/guides/sub-users.md) guide for more information on how to set it up correctly.

----

# Does WatchState require Webhooks to work?

No, webhooks are **not required** for the tool to function. You can use the built-in **scheduled tasks** or manually run
**import/export operations** on demand.

> [!NOTE]
> There are problems with jellyfin API, which are fixed by using webhooks, please check out
> the [webhook guide](/guides/webhooks.md#media-backends-webhook-limitations) to learn more.

---

# I'm Using Media Backends Hosted Behind HTTPS and See Errors Related to HTTP/2-3

In some cases, issues may arise due to HTTP/2-2 compatibility problems with our internal http client. Before submitting
a bug report, please try the following workaround:

* Go to the `Backends`.
* Find the backend where the issue occurs and click the **Edit** button.
* Expand the **Additional options...** section.
* Under **Add new option**, select `client.http_version` from the dropdown list.
* Click the green **+** add button.
* Once the option appears, set its value to `1.0`.
* Click **Save Settings**.

This setting forces the internal HTTP client to use **HTTP/1.1** instead of HTTP/2. If the issue persists after making
this change, please open a bug report so it can be investigated further.

---

# Sync operations are failing due to request timeout?

If you're encountering request timeouts during sync operations, you can increase the timeout for a specific backend by
following these steps:

* Go to the `Backends`.
* Find the backend where the issue occurs and click the **Edit** button.
* Expand the **Additional options...** section.
* Under **Add new option**, select `client.timeout` from the dropdown list.
* Click the green **+** add button.
* Once the option appears, set its value to `600`.
* Click **Save Settings**.

The value `600` represents the number of seconds the system will wait before terminating the request due to a timeout.

---

# How to fix a corrupt sqlite database

If your SQLite database becomes corrupted, you may see an error like:

```
General error: 11 database disk image is malformed
```

To repair the database, follow these steps:

```bash
docker exec -ti watchstate console db:repair /config/db/watchstate_v01.db
```

> [!NOTE]
> change `/config/db/watchstate_v01.db` to the path of your database file.

You should get similar output to the following:

```
INFO: Attempting to repair database '{db_name}'.
INFO: Copied database '{db_name}' to '{db_name}.before.repair.db' as backup.
INFO: Attempting to repair database '{db_name}'.
INFO: Database '{db_name}' repaired successfully.
INFO: Checking database integrity...
INFO: SQLite3: ok
INFO: Database '{db_name}' is valid.
INFO: Updating database version to 1723988129.
INFO: Renaming database '{db_name}.new.db' to '{db_name}'.
INFO: Repair completed successfully. Database '{db_name}' is now valid.
```

If there are no errors, the database has been repaired successfully. And you can resume using the tool.

---

# Which Providers id `GUIDs` supported by for PlexClient?

* tvdb://(id) `New plex agent`
* imdb://(id) `New plex agent`
* tmdb://(id) `New plex agent`
* com.plexapp.agents.imdb://(id)?lang=en `(Legacy plex agent)`
* com.plexapp.agents.tmdb://(id)?lang=en `(Legacy plex agent)`
* com.plexapp.agents.themoviedb://(id)?lang=en `(Legacy plex agent)`
* com.plexapp.agents.thetvdb://(seriesId)?lang=en `(Legacy plex agent)`
* com.plexapp.agents.xbmcnfo://(id)?lang=en `(XBMC NFO Movies agent)`
* com.plexapp.agents.xbmcnfotv://(id)?lang=en `(XBMC NFO TV agent)`
* com.plexapp.agents.hama://(db)\d?-(id)?lang=en `(HAMA multi source db agent mainly for anime)`
* com.plexapp.agents.ytinforeader://(id)
  ?lang=en [ytinforeader.bundle](https://github.com/arabcoders/plex-ytdlp-info-reader-agent)
  With [jp_scanner.py](https://github.com/arabcoders/plex-daily-scanner) as scanner.
* com.plexapp.agents.cmdb://(id)
  ?lang=en [cmdb.bundle](https://github.com/arabcoders/cmdb.bundle) `(User custom metadata database)`.

---

# Which Providers id supported by Jellyfin/Emby Client?

* imdb://(id)
* tvdb://(id)
* tmdb://(id)
* tvmaze://(id)
* tvrage://(id)
* anidb://(id)
* ytinforeader://(
  id) [jellyfin](https://github.com/arabcoders/jf-ytdlp-info-reader-plugin) & [Emby](https://github.com/arabcoders/emby-ytdlp-info-reader-plugin).
  `(A yt-dlp info reader plugin)`.
* cmdb://(
  id) [jellyfin](https://github.com/arabcoders/jf-custom-metadata-db) & [Emby](https://github.com/arabcoders/emby-custom-metadata-db).
  `(User custom metadata database)`.

---

# Environment Variables

You can configure WatchState extensively using environment variables. The recommended approach is for keys that starts
with `WS_` is to use the `Env` page. It will do some validation for you and ensure the values are correct.

## WatchState specific environment variables.

to see list of loaded environment variables, click on `Env` page.

## Container specific environment variables.

> [!IMPORTANT]
> These environment variables relates to the container itself, and MUST be added via container environment or by
> the `compose.yaml` file.

| Key           | Type    | Description                        | Default |
| ------------- | ------- | ---------------------------------- | ------- |
| DISABLE_CRON  | integer | Disable included `Task Scheduler`. | `0`     |
| DISABLE_CACHE | integer | Disable included `Cache Server`.   | `0`     |

> [!NOTE]
> You need to restart the container after changing these environment variables. those variables are not managed by the
> WatchState tool, they are managed by the container itself.

---

# How to disable the included cache server and use an external cache server?

To disable the built-in cache server and connect to an external Redis instance, follow these steps:

In your `compose.yaml` file, set the following environment variable `DISABLE_CACHE=1`.

Configure the external Redis connection by setting the `WS_CACHE_URL` environment variable.

The format is:

```
redis://host:port?password=your_password&db=db_number
```

For example, to connect to a Redis server running in another container:

```
redis://172.23.1.10:6379?password=my_secret_password&db=8
```

> [!NOTE]
> Only **Redis** and **API-compatible alternatives** are supported.

After updating the environment variables, **restart the container** to apply the changes.

---

# How to get WatchState working with YouTube content/library?

Due to the nature on how people name their youtube files I had to pick something specific for it to work cross supported
media agents. Please visit [this link](https://github.com/arabcoders/jf-ytdlp-info-reader-plugin#usage) to know how to
name your files. Please be aware these plugins and scanners `REQUIRE`
that you have a `yt-dlp` `.info.json` files named exactly as your media file.

For example, if you have `20231030 my awesome youtube video [youtube-RandomString].mkv`you should
have `20231030 my awesome youtube video [youtube-RandomString].info.json` in the same directory. In the future,
I plan to make `.info.json` optional However at the moment the file is required for emby/jellyfin plugin to work.

## Plex Setup

* Download this agent [ytinforeader.bundle](https://github.com/arabcoders/plex-ytdlp-info-reader-agent) please follow
  the instructions on how to install it from the link itself. It's important to use the specified scanner otherwise the
  syncing will not work.

## Jellyfin Setup

* Download this plugin [jf-ytdlp-info-reader-plugin](https://github.com/arabcoders/jf-ytdlp-info-reader-plugin). Please
  refer to the link on how to install it.

## Emby Setup

* Download this plugin [emby-ytdlp-info-reader-plugin](https://github.com/arabcoders/emby-ytdlp-info-reader-plugin).
  Please refer to the link on how to install it.

If your media is not matching correctly or not marking it as expected, it's most likely scanners issues as plex and
jellyfin/emby reports the GUID differently, and we try our best to match them. So, please hop on discord with the
relevant data if they are not matching correctly, and we hopefully can resolve it.

---

# How to check if the container able to communicate with the media backends?

If you're having problem adding a backend to `WatchState`, it most likely network related problem, where the container
isn't able to communicate with the media backend. Thus, you will get errors. To make sure the container is able to
communicate with the media backend,

Run the following tests via: <!--i:fa-tools--> **Tools** <!--i:fa-external-link--> **URL Checker**.

From the `Pre-defined` templates select the media server you want to test against and replace the following with your
info

* Replace `[PLEX_TOKEN]` with your plex token.
* Replace `[API_KEY]` with your jellyfin/emby api key.
* Replace `[IP:port]` with your media backend host or ip:port. it can be a host or ip:port i.e. `media.mydomain.ltd`
  or `172.23.0.11:8096`.

If everything is working correctly you should see `200 Status code response.` with green text. this good indicator that
the container is able to communicate with the media backend. If you see `403` or `404` or any other error code, please
check your backend settings and make sure the token is correct and the ip:port is reachable from the container.

----

# I keep receiving this warning in logs

## INFO: Ignoring [xxx] Episode range, and treating it as single episode. Backend says it covers [00-00]?

To increase the limit per backend, go to <!--i:fa-server--> **Backends** > <!--i:fa-cog--> **Edit** > Expand
**Additional options...** section > Under **Add new option** select
`MAX_EPISODE_RANGE` from the dropdown list > Click the green **<!--i:fa-plus--> add** button > Once the
option appears, set its value to the number of episodes you want to allow per episode range then,
Click **<!--i:fa-save--> Save Settings**.

## I Keep receiving 'jellyfin' item 'id: name' is marked as 'played' vs local state 'unplayed'

Sadly, this is due to bug in jellyfin, where it marks the item as played without updating the LastPlayedDate, and as
such, watchstate doesn't really know the item has changed since last sync. Unfortunately, there is no way to fix this
issue from our side for the `state:import` task as it working as intended.

### Workarounds.

There are two possible workarounds:

### (1) Webhooks (Recommended)

However, we managed implemented a workaround for this issue using the webhooks as workaround, until jellyfin devs fixes
the issue. Please enable webhooks for your jellyfin backend to avoid this issue.

### (1) by special handling

We have added an experimental workaround for this issue in the `state:import` command, to enable it, add the env `WS_CLIENTS_JELLYFIN_FIX_PLAYED` via the `Env` page. It's turned off by default as it may cause some issues as it's untested in production, so please use it with caution and report any issues you find.

## CODE: DM001 - Marking the item as tainted and re-processing.

This warning appears when there is a mismatch between the local database and a backend regarding the item. This error mostly occurs in some edge cases. which can be fixed by forced state export to the backend.

---

# How does the file integrity feature works?

The feature first scan your entire history for reported media file paths. Depending on the results we do the following:

* If metadata reports a path, then we will run stat check on each component of the path from lowest to highest.
* If no metadata reports a path, then simply the record will be marked as OK.

## Here is the break-down example

Lets says you have a media file `/media/series/season 1/episode 1.mkv` The scanner does the following:

* `/media` Does this path component exists? if not mark everything starting from `/media` as not found. if it exists
  simply move to the next component until we reach the end of the path.
* `/media/series` Do same as above.
* `/media/series/season 1` Do same as above.
* `/media/series/season 1/episode 1.mkv` Do same as above.

Using this approach allow us to cache calls and reduce unnecessary calls to the filesystem. If you have for example
`/media/seriesX/` with thousands of files,
and the path component `/media/seriesX` doesn't exists, we simply ignore everything that starts with `/media/seriesX/`
and treat them as not found.

This helps with slow stat calls in network shares, or cloud storage.

Everytime we do a stat call we cache it for 1 hour, so if we have multiple records reporting the same path, we only do
the stat check once. Assuming all your media backends are using same path for the media files.

---

# How to use hardware acceleration for video playback?

As the container is rootless, we cannot do the necessary changes to the container to enable hardware acceleration.
However, We do have the drivers and ffmpeg already installed and the CPU transcoding should work regardless. To enable
hardware acceleration You need to alter your `compose.yaml` file to mount the necessary devices to the container. Here
is an example of how to do it for debian based systems.

```yaml
services:
    watchstate:
        container_name: watchstate
        image: ghcr.io/arabcoders/watchstate:latest   # The image to use. you can use the latest or dev tag.
        user: "${UID:-1000}:${UID:-1000}"             # user and group id to run the container under. 
        group_add:
            - "44"                                    # Add video group to the container.
            - "105"                                   # Add render group to the container.
        restart: unless-stopped
        ports:
            - "8080:8080"                             # The port which the watchstate will listen on. 
        devices:
            - /dev/dri:/dev/dri                       # mount the dri devices to the container.
        volumes:
            - ./data:/config:rw                       # mount current directory to container /config directory.
            - /storage/media:/media:ro                # mount your media directory to the container.
```

This setup should work for VAAPI encoding in `x86_64` containers, There are currently an issue with nvidia h264_nvenc
encoding, the alpine build for`ffmpeg` doesn't include the codec. I am looking for a way include the codec without
ballooning the image size by 600MB+. If you have a solution please let me know.

Please know that your `video`, `render` group id might be different from mine, you can run the follow command in docker
host server to get the group ids for both groups.

```bash
cat /etc/group | grep -E 'render|video'

video:x:44:your_docker_username
render:x:105:your_docker_username
```

In my docker host the group id for `video` is `44` and for `render` is `105`. change what needed in the `compose.yaml`
file to match your setup.

Note: the tip about adding the group_add came from the user `binarypancakes` in discord.

---

# Advanced: How to extend the GUID parser to support more GUIDs or custom ones?

Visit `More > Custom GUIDs` page, you can add custom GUIDs to the parser using the add form.

If you added or removed a guid from the `guid.yaml` file, you should run `system:index --force-reindex` command to
update the database indexes with the new guids.

---

# Sync watch progress.

For best experience, first Enable the [webhook](/guides/webhooks.md) feature for the media backends you want to sync the
watch progress, however, if you are unable to do so, the `Tasks > import` task will also generate progress watch events.
However, it's not as reliable as the `Webhooks` or as fast it will only sync the progress when the import task is run.

To check if there is any watch progress events being registered, You can go to `More > Events` and check `on_progress`
events, if you are seeing those, this means the progress is being synced. Check the `Tasks logs` to see the event log.

If this is set up and working you may be ok with changing the `WS_CRON_IMPORT_AT/WS_CRON_EXPORT_AT` schedule to
something less frequent as the sync progress working will update the progress near realtime. For example, you could
change these tasks to run daily to know how to do that please
check [this FAQ entry](#how-to-enable-scheduledautomatic-tasks).

---

# WatchState is hammering my media backend with requests?

By default, we do async requests to the media backends to speed up the sync process. However, in some cases this may
lead to overloading the media backend with requests, especially if you have a large library. To mitigate this, you can
instead switch the tasks to use synchronous requests. This will slow down the sync process by a lot, but will reduce the
load on your media backend. You have to manually edit the tasks `args` to include `--sync-requests` flag.

For example, to change the `Import` task to use synchronous requests:

1. Go to the `Tasks`.
2. Find the `Import` task and click on the `Args: -v` button.
3. This will redirect you to the `Env` page to edit the relevant environment variable.
4. In the `WS_CRON_IMPORT_ARGS` value field, change the value from `-v` to `-v --sync-requests`.
5. Click `Save Settings`.

Repeat for all sync related tasks you want to change.

---

# Speed up the sync operations?

If you have a large library, the initial sync operations can take a long time. To speed up the process, you can
switch the db operation mode to use MEMORY mode. This will load the entire database into memory during the sync
operations.

> [!WARNING]
> Using MEMORY mode can consume a lot of RAM, especially for large databases. Make sure your system has enough memory
> to handle the database size. If the system runs out of memory, it may lead to crashes or data loss.

To enable MEMORY mode, follow these steps:

1. Go to the `Env` page.
2. Add a new environment variable.
3. Select `WS_DB_MODE` from the dropdown list.
4. select the `MEMORY` option.
5. Click `Save`.

---

# I have external auth layer how to disable internal auth?

If you have an external authentication layer (like a reverse proxy with auth) and want to disable the internal auth of
WatchState, you can do so by setting the following environment variable to true.

- `WS_TRUST_PROXY`
    - Tells WatchState to trust the `X-Forwarded-For` header (or the one you set in `WS_TRUST_HEADER`) as the user IP.
- `WS_TRUST_LOCAL`
    - Tells WatchState to trust all requests coming from local network addresses bypassing authentication.

You still have to create the initial admin user, however, once these settings are applied, anyone accessing WatchState
from the trusted sources will be granted access without further authentication.

## The supported local net addresses

- 10.0.0.0/8
- 127.0.0.1/32
- 172.16.0.0/12
- 192.168.0.0/16
- ::1/128'

> [!IMPORTANT]
> Setting both `WS_TRUST_PROXY` and `WS_TRUST_LOCAL` to true will disable all internal authentication mechanisms.
> Ensure that your external auth layer is secure and properly configured to prevent unauthorized access. Your entire
> WatchState instance will be open to anyone who access it via local network.

--- 

# Failing due to duplicate UUID

If you clone your media backend instance, your UUID might be duplicated as well. Unfortunately we cannot add a check
against that as it would break `3-way sync`. Duplicate UUID may lead to unforeseen issues such as automatic sub users
creation failing to load. To fix the issue it's recommended to change the backend UUID to do so,

* For jellyfin: `config/data/device.txt`
* For Emby: `data/device.txt`
* For Plex: `Library/Application Support/Plex Media Server/Preferences.xml` key: `ProcessedMachineIdentifier`.

Those values need to be unique per instance.

---

# Some Plex users are not showing up in the user list?

Sometimes, the plex user list may not fully load all users, Please <!--i:fa-cogs--> **Env** page and add the following environment variable:
`WS_CLIENTS_PLEX_DISABLE_DEDUP` and turn it on. This will disable the deduplication logic for plex users. Once you enable this option please restart watchstate 
and try again. If the user still doesn't show up, please open a bug report with the relevant logs and we will investigate the issue.
