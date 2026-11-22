# FAQ

# Import vs Export

## What does it mean to export or import in WatchState terminology?

WatchState syncs data between your configured backends.

For each backend:

* **Import** allows WatchState to read data from that backend.
* **Export** allows WatchState to write data to that backend.

**Import** controls what WatchState can use as a source. **Export** controls what WatchState can update as a target.

For example, if Plex should provide the data and Jellyfin should be updated to match it, enable:

* **Import** on Plex
* **Export** on Jellyfin

If both options are enabled on the same backend, WatchState can both read from it and write updates back to it during sync.

---

# How to enable scheduled/automatic tasks?

To turn on automatic import or export tasks:

1. Open **Activity > Tasks**.
2. Turn on **Enabled** for the tasks you want to run automatically.

Task schedules apply across identities, not just the identity selected in the header.

By default, the tasks are scheduled:

- **Import** task runs every **1 hour**
- **Export** task runs **hourly at 30 minutes past the hour**

To customize the schedule, open **Activity > Tasks** and click the task's **Timer** value.
It opens the corresponding variable in **Settings > Environment**, where you can edit the cron expression and click **Save**.

**Schedule** describes the frequency and links to [crontab.guru](https://crontab.guru/) for cron syntax help.

For a one-off run, choose **Queue task** for background execution or **Run in CLI** to review the command in Console.
**Cancel queued task** removes a pending run; it doesn't stop an active process.

When **Last run** is a link, open it to inspect the latest completed run's status and logs. A completed run can have
succeeded or failed; its timestamp alone doesn't confirm success.

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

In this case, the system prioritizes preserving your local play state. The item is queued for a follow-up pass instead
of applying the backend state directly. To resolve this conflict and sync the backend with your local state:

- Go to the `Backends`.
- Under the relevant backend, find the **Quick operations** list.
- Select **3. Force export play and progress state**.

This operation will overwrite the backend's watch state with your current local state to bring them back in sync.

----

# How to Use Jellyfin or Emby OAuth Tokens

Due to limitations on the Jellyfin/Emby side, our implementation requires you to provide your credentials in the
`username:password` format. This is necessary because their API does not allow us to determine the currently
authenticated user directly.

When prompted for the API key, enter your credentials in this format:

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
- In the backend's **Edit > Sync direction** section, enable **Import** and click **Save settings**.
- Open **Activity > Tasks**, click **Run** on the **Import** task, then choose **Run in CLI**.
  Review the prefilled command in **Activity > Console**, then click **Run command**.
- Once the import is complete, **add the second backend** (the one with incorrect or outdated play state).
- Under the newly added backend, locate the **Quick operations** section.
- Select **3. Force export play and progress state**.

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
* Select **3. Force export play and progress state**.

This will sync your local database state to the backend, ignoring date comparisons.

----

# Watch state changes skipped with `stale_database_date`?

The usual log message

```
Ignoring 'main@backend' movie|episode '#0000: title (2025)'. Database date is older than backend date.
```

This usually means the backend is reporting the same event with a different time than WatchState. Make sure every
component uses the same timezone and synchronized clock.

* **WatchState**: set `WS_TZ` from **Settings > Environment**.
* **Docker fallback**: set `TZ` only in `compose.yaml` / `docker run` if `WS_TZ` is not available.
* **Plex / Jellyfin / Emby**: must use the same timezone as WatchState.
* **Host**: make sure the clock is correct and NTP is enabled.

After fixing the time settings, reset the local WatchState database and re-import. Existing data is tainted by the old
time mismatch, so force export will **not** fix the underlying problem.

----

# Is there support for Multi-user setup?

The tool is primarily designed for single-user use. Identity support is built on top of that.
From our testing, identity provisioning works well right now and behaves as expected in the majority of cases. Please
refer to the [identities](/guides/identities.md) guide for more information on how to set it up correctly.

----

# Does WatchState require Webhooks to work?

No, webhooks are **not required** for the tool to function. You can use the built-in **scheduled tasks** or manually run
**import/export operations** on demand.

> [!NOTE]
> There are problems with jellyfin API, which are fixed by using webhooks, please check out
> the [backend limitations guide](/guides/backend-limitations.md#jellyfin) to learn more.

---

# I'm Using Media Backends Hosted Behind HTTPS and See Errors Related to HTTP/2-3

In some cases, issues may arise due to HTTP/2-2 compatibility problems with our internal http client. Before submitting
a bug report, please try the following workaround:

* Go to the `Backends`.
* Find the backend where the issue occurs and click the **Edit** button.
* Expand **Advanced**.
* Under **Add new option**, select `client.http_version` from the dropdown list.
* Click **Add**.
* Once the option appears, set its value to `1.0`.
* Click **Save settings**.

This setting forces the internal HTTP client to use **HTTP/1.1** instead of HTTP/2. If the issue persists after making
this change, please open a bug report so it can be investigated further.

---

# Sync operations are failing due to request timeout?

If you're encountering request timeouts during sync operations, you can increase the timeout for a specific backend by
following these steps:

* Go to the `Backends`.
* Find the backend where the issue occurs and click the **Edit** button.
* Expand **Advanced**.
* Under **Add new option**, select `client.timeout` from the dropdown list.
* Click **Add**.
* Once the option appears, set its value to `600`.
* Click **Save settings**.

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

# Which provider `GUIDs` does Plex support?

WatchState parses the following Plex GUIDs:

* tvdb://(id)
* imdb://(id)
* tmdb://(id)
* tv.plex.agents.nfo.movie://movie/(provider)_(id) `Where the provider one of supported providers`
* tv.plex.agents.nfo.series://(show|episode)/(provider)_(id) `Where the provider one of supported providers`
  
## Legacy Plex Agents

* com.plexapp.agents.imdb://(id)?lang=en
* com.plexapp.agents.tmdb://(id)?lang=en
* com.plexapp.agents.themoviedb://(id)?lang=en
* com.plexapp.agents.thetvdb://(seriesId)?lang=en
* com.plexapp.agents.xbmcnfo://(id)?lang=en `(XBMC NFO Movies agent) -> imdb|tmdb://(movieId)?lang=en`
* com.plexapp.agents.xbmcnfotv://(id)?lang=en `(XBMC NFO TV agent) -> tvdb://(serisId)?lang=en`
* com.plexapp.agents.hama://(db)\d?-(id)?lang=en `(HAMA multi source db agent mainly for anime)`
* com.plexapp.agents.ytinforeader://(id)?lang=en [ytinforeader.bundle](https://github.com/arabcoders/plex-ytdlp-info-reader-agent) With [jp_scanner.py](https://github.com/arabcoders/plex-daily-scanner) as scanner.
* com.plexapp.agents.cmdb://(id)?lang=en [cmdb.bundle](https://github.com/arabcoders/cmdb.bundle) `(User custom metadata database)`.

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

Use **Settings > Environment** for `WS_` settings to catch invalid values before saving.

## WatchState specific environment variables.

Open **Settings > Environment** to see settings you've changed. Click **Add variable** to choose from the available variables.

## Container specific environment variable.

> [!IMPORTANT]
> This environment variable relates to the container itself, and MUST be added via container environment or by
> the `compose.yaml` file.

| Key           | Type    | Description                      | Default |
| ------------- | ------- | -------------------------------- | ------- |
| DISABLE_CACHE | integer | Disable included `Cache Server`. | `0`     |

> [!NOTE]
> You need to restart the container after changing this environment variable. It is managed by the container, not the
> WatchState tool.

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

<a id="how-to-check-if-the-container-able-to-communicate-with-the-media-backends"></a>

# How can I check connectivity to a media backend?

Open **Activity** > <!--i:i-lucide-link--> **URL Checker**. Requests run from WatchState, so this checks the connection
available to WatchState rather than your browser.

Choose a **Template** for your media server, then replace its placeholders:

* `[PLEX_TOKEN]`: your Plex token.
* `[API_KEY]`: your Jellyfin or Emby API key.
* `[ip:port]`: the backend hostname or IP address and port, such as `media.mydomain.ltd` or `172.23.0.11:8096`.

Click **Send request**. **Response** shows the status, request URL, headers, and body. A `200` status indicates a successful
request. For `401` or `403`, check the token and its permissions; for `404`, check the endpoint path.
Connection failures appear under **Response headers** as `WS-Error` and in **Body**.

----

# I keep receiving this warning in logs

## INFO: Ignoring [xxx] Episode range, and treating it as single episode. Backend says it covers [00-00]?

To increase the limit per backend, open <!--i:i-lucide-server--> **Backends > Edit > Advanced**.
Under **Add new option**, select `MAX_EPISODE_RANGE` and click **Add**.
Set its value to the number of episodes allowed per range, then click **Save settings**.

## I Keep receiving 'jellyfin' item 'id: name' is marked as 'played' vs local state 'unplayed'

Jellyfin can mark an item as played without updating `LastPlayedDate`. WatchState uses that date to detect changes, so
the `state:import` task cannot detect this change from the backend response.

### Workarounds

There are two workarounds:

### (1) Webhooks (Recommended)

Enable webhooks for the Jellyfin backend. Webhook events include the state change even when `LastPlayedDate` is not
updated.

### (2) Special handling

The `state:import` command has an experimental workaround. Enable it by adding `WS_CLIENTS_JELLYFIN_FIX_PLAYED` on the
**Settings > Environment** page. It is disabled by default because it has not been tested in production.

## DM001 (`error: dm001_stale_date`) - Item queued for re-processing.

This warning appears when the backend reports an older item state than the last recorded sync point. This usually
shows up in edge cases and can often be resolved by forcing a state export to the backend.

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

Open **Settings > Custom GUIDs**.

1. Choose **Add GUID** if the identifier provider isn't already supported. Supply its validation pattern and examples.
2. Choose **Add link** to map the client identifier to a WatchState GUID. The target can be a built-in GUID or one you added.

Client links apply to every backend of that client type. Deleting a custom GUID also deletes the links
that target it.

After adding or removing a custom GUID, run `db:index --force-reindex` in Console to rebuild the database indexes.

---

# Ignore rules or log suppression?

Use **Settings > Ignore Rules** when a backend reports an unwanted GUID. Rules belong to the selected identity and backend.
A scoped rule applies only to the specified source item; an unscoped rule ignores that GUID on any matching item from that
backend. Other GUIDs on the item can still be used for matching.

Use **Settings > Suppression** to stop recording log messages that match a rule. **Contains** matches case-sensitive text;
**Regex** uses a PHP regular expression. Suppression applies across all identities and does not remove existing log entries.

---

# Sync watch progress.

For best experience, first Enable the [webhook](/guides/webhooks.md) feature for the media backends you want to sync the
watch progress. However, if you are unable to do so, the **Import** task under **Activity > Tasks** also generates progress watch events.
However, it's not as reliable as the `Webhooks` or as fast it will only sync the progress when the import task is run.

To export resume positions, run `state:export --with-progress -u main -s DESTINATION` in **Activity > Console**.
Replace `DESTINATION` with your configured backend name. Use `--force-full` to include items outside the normal
export window, `--ignore-date` to skip date checks, or `--dry-run` to preview without writing.

Open **Activity > Events**, select **Any status**, open **Search events**, enter `on_progress` under
**Event name**, then click **Apply search**. Click an event name for its status, attached data, and saved logs.
**Filter this page** only narrows the current page. Check the user and backend in the event data or logs.
An event alone doesn't confirm that a destination backend accepted the update.

For work waiting in the transport queue, open **Activity > Transport**.
An item disappearing from the queue doesn't confirm delivery to a media backend.

In event details, **Cancel** stops a queued event. **Run again** repeats its action, and both actions clear its saved logs.
**Delete matching** can remove events across all pages. **Filter this page** doesn't limit deletion.
Pending events require **Include pending**; running events can't be deleted.

If this is set up and working you may be ok with changing the `WS_CRON_IMPORT_AT/WS_CRON_EXPORT_AT` schedule to
something less frequent as the sync progress working will update the progress near realtime. For example, you could
change these tasks to run daily to know how to do that please
check [this FAQ entry](#how-to-enable-scheduledautomatic-tasks).

---

# WatchState is hammering my media backend with requests?

By default, we do async requests to the media backends to speed up the sync process. However, in some cases this may
lead to overloading the media backend with requests, especially if you have a large library. To mitigate this, you can
instead switch the tasks to use synchronous requests. This will slow down the sync process by a lot, but will reduce the
load on your media backend. You have to manually edit the task arguments to include `--sync-requests` flag.

For example, to change the `Import` task to use synchronous requests:

1. Open **Activity > Tasks**.
2. Find the `Import` task and click its **Arguments** value.
3. This opens **Settings > Environment** to edit the relevant environment variable.
4. In the `WS_CRON_IMPORT_ARGS` value field, change the value from `-v` to `-v --sync-requests`.
5. Click **Save**.

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

1. Open **Settings > Environment**.
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
- `WS_TRUST_LOCAL_NET`
    - Replaces the default trusted local networks with a comma-separated list of IPv4/IPv6 addresses or CIDR subnets used by `WS_TRUST_LOCAL`.

You still have to create the initial admin user, however, once these settings are applied, anyone accessing WatchState
from the trusted sources will be granted access without further authentication.

## The default trusted local networks

- 10.0.0.0/8
- 127.0.0.1/32
- 172.16.0.0/12
- 192.168.0.0/16
- ::1/128

Set `WS_TRUST_LOCAL_NET` to replace these defaults. For example, `10.10.0.0/16, fd00::/8` trusts only the listed IPv4 and IPv6 networks when `WS_TRUST_LOCAL` is enabled.

> [!WARNING]
> Docker port forwarding can make outside callers appear to come from the bridge gateway. If that address is in
> `WS_TRUST_LOCAL_NET`, `WS_TRUST_LOCAL` logs them in without a password. Do not enable it on a publicly reachable port
> unless another access control protects the port.

> [!IMPORTANT]
> Setting both `WS_TRUST_PROXY` and `WS_TRUST_LOCAL` to true will disable all internal authentication mechanisms.
> Ensure that your external auth layer is secure and properly configured to prevent unauthorized access. Your entire
> WatchState instance will be open to anyone who access it via local network.

--- 

# Failing due to duplicate UUID

If you clone your media backend instance, your UUID might be duplicated as well. Unfortunately we cannot add a check
against that as it would break `3-way sync`. Duplicate UUID may lead to unforeseen issues such as automatic identity
creation failing to load. To fix the issue it's recommended to change the backend UUID to do so,

* For jellyfin: `config/data/device.txt`
* For Emby: `data/device.txt`
* For Plex: `Library/Application Support/Plex Media Server/Preferences.xml` key: `ProcessedMachineIdentifier`.

Those values need to be unique per instance.
