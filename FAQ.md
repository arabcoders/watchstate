# FAQ

# How to find the API key?

There are two ways to locate the API key:

## Via `.env` file

The API key is stored in the following file `/config/config/.env`, Open the file and look for the line starting with:

```
WS_API_KEY=random_string
```

The value after the equals sign is your API key.

## Via command

You can also retrieve the API key by running the following command on the docker host machine:

```bash
docker exec watchstate console system:apikey
```

This command will show the following lines:

```
Current API key:
random_string
```

The `random_string` is your API key.

----

# What Is the API key used for?

The API key is used to authenticate requests to the system and prevent unauthorized access.

It is required for all API endpoints, **except** the following:

```
/v1/api/[user@backend_name]/webhook
```

This webhook endpoint is open by default, unless you have enabled the `WS_SECURE_API_ENDPOINTS` environment variable.  
If enabled, the API key will also be required for webhook access.

> [!IMPORTANT]
> The WebUI operates in standalone mode and is decoupled from the backend, so it requires the API key to fetch and
> display data.

----

# How to enable scheduled/automatic tasks?

To turn on automatic import or export tasks:

1. Go to the `Tasks` page in the WebUI.
2. Enable the tasks you want to schedule for automatic execution.

By default:

- **Import** task runs every **1 hour**
- **Export** task runs every **1 hour and 30 minutes**

If you want to customize the schedule, you can do so by adding environment variables with valid cron expressions:

- **WS_CRON_IMPORT_AT**
- **WS_CRON_EXPORT_AT**

You can set these variables from the <code>Env</code> page.

> [!NOTE]
> A great tool to validate your cron expression is [crontab.guru](https://crontab.guru/)

After making changes, visit the `Tasks` page again to see the updated schedule next to `Next Run`.

---

# Container is crashing on start-up?

This usually happens due to a misconfigured `user:` in your `compose.yaml` file or an incorrect `--user` argument. The
container runs in **rootless mode**, so it will crash if it doesn’t have permission to access the data directory.

## Check permissions

Run the following command to inspect ownership of your data directory:

```bash
$ stat data/config/ | grep 'Uid:'
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
re-processed accordingly.

## How to resolve the conflict?

To resolve this conflict and sync the backend with your local state:

* Go to the `WebUI > Backends`.
* Under the relevant backend, find the **Frequently used commands** list.
* Select **3. Force export local play state to this backend.**

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

## How to Fix the the play state

To synchronize both backends correctly:

* **Add the backend** that contains the correct watch state first.
* Enable **Full Import** for that backend.
* Go to `Tasks` page, and run the **Import** task via `Run via console` button.
* Once the import is complete, **add the second backend** (the one with incorrect or outdated play state).
* Under the newly added backend, locate the **Frequently used commands** section.
* Select **3. Force export local play state to this backend.**

This will push your local watch state to the backend and ensure both are in sync.

----

# My New Backend Watch State Is Not Being Updated?

This issue is most likely caused by a **date mismatch**. When exporting watch state, the system compares the date of the
item on the backend with the date stored in the local database. If the backend's date is equal to or newer than the
local one, the export may be skipped.

To confirm if this is the issue, follow these steps:

1. Go to the `WebUI > Backends`.
2. Under the relevant backend, locate the **Frequently Used Commands** section.
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

* Go to the `WebUI > Backends`.
* Under the relevant backend, find the **Frequently used commands** list.
* Select **3. Force export local play state to this backend.**

This will sync your local database state to the backend, ignoring date comparisons.

----

# Is there support for Multi-user setup?

There is **basic** support for multi-user setups, but it's not fully developed yet. The tool is primarily designed for
single-user use, and multi-user functionality is built on top of that. Because of this, you might encounter some issues
when using it with multiple users.

## Getting started with a multi-user setup

1. **Add your backends** as you normally would. Make sure to include the backends for your main user.
2. For the `Plex` backend, you must use an **Admin-level `X-Plex-Token`**. Without it, we won’t be able to retrieve the
   list of users.  
   You can check your token by going to `Tools > Plex Token`.
    - If you see a success message, you’re good to go.
    - If you see an error message, it likely means your token has limited permissions and can’t be used to access the
      user list.
3. For `Jellyfin` and `Emby` backends, use an **API key**, which can be generated from your server settings:
    - Go to `Dashboard > Advanced > API Keys` and create a new key.
4. After setting up your backends and verifying they work, go to `Tools > Sub Users`.  
   The system will attempt to automatically group users based on their names. However, because naming can vary between
   setups, not all users may be matched correctly. You can manually organize the groups by dragging and dropping
   users.
5. Once you're satisfied with the setup, click the `Create Sub-users` button to generate the configuration.

> [!NOTE]  
> The sub-user configurations are based on your current main user settings. If you change the main configuration (e.g.,
> backend URL), you must either:
> * Manually update the sub-user backends, or
> * Click `Update Sub-users`, which will try to update them automatically. This action can also **create new sub-users**
    if they don’t already exist—so use it carefully.

Once your sub-user setup is ready, you can start using the multi-user features.

## Important

We enforce a strict naming convention for both backend names and usernames:

**Format:** `^[a-z_0-9]+$`

Which means

* Allowed: lowercase letters, numbers, and underscores (`_`)
* Not allowed: spaces, uppercase letters, or special characters

If any username doesn’t follow this convention, we’ll **automatically normalize** it, if the name is made entirely of
digits, we’ll automatically prefix it with `user_`.

----

# Does WatchState requires Webhooks to work?

No, webhooks are **not required** for the tool to function. You can use the built-in **scheduled tasks** or manually run
**import/export operations** on demand through the WebUI or console.

---

# I'm Using Media Backends Hosted Behind HTTPS and See Errors Related to HTTP/2

In some cases, issues may arise due to HTTP/2 compatibility problems with our internal http client. Before submitting a
bug report, please try the following workaround:

* Go to the `WebUI > Backends`.
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

* Go to the `WebUI > Backends`.
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
$ docker exec -ti watchstate bash
$ sqlite3 /config/db/watchstate_v01.db '.dump' | sqlite3 /config/db/watchstate_v01-repaired.db
```

Once the dump and rebuild are complete, perform an integrity check:

```bash
$ sqlite3 /config/db/watchstate_v01-repaired.db 'PRAGMA integrity_check'
```

If the output is simply `ok`, the repaired database is valid. You can then replace the corrupted database with the
repaired one:

```bash
$ mv /config/db/watchstate_v01-repaired.db /config/db/watchstate_v01.db
```

Your system should now use the repaired database without errors.

---

# Which external db ids `GUIDS` supported for Plex Media Server?

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

# Which external db ids supported for Jellyfin and Emby?

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

The recommended approach is for keys that starts with `WS_` use the `WebUI > Env` page, or `system:env` command via CLI.
For other keys that aren't directly related to the tool, you **MUST** load them via container environment or
the `compose.yaml` file.

to see list of loaded environment variables, click on `Env` page in the WebUI.

## Tool specific environment variables.

These environment variables relates to the tool itself, You should manage them via `WebUI > Env` page

| Key                     | Type    | Description                                                             | Default                  |
|-------------------------|---------|-------------------------------------------------------------------------|--------------------------|
| WS_DATA_PATH            | string  | Where to store main data. (config, db).                                 | `${BASE_PATH}/var`       |
| WS_TMP_DIR              | string  | Where to store temp data. (logs, cache)                                 | `${WS_DATA_PATH}`        |
| WS_TZ                   | string  | Set timezone. Fallback to to `TZ` variable if `WS_TZ` not set.          | `UTC`                    |
| WS_CRON_{TASK}          | bool    | Enable {task} task. Value casted to bool.                               | `false`                  |
| WS_CRON_{TASK}_AT       | string  | When to run {task} task. Valid Cron Expression Expected.                | `*/1 * * * *`            |
| WS_CRON_{TASK}_ARGS     | string  | Flags to pass to the {task} command.                                    | `-v`                     |
| WS_LOGS_CONTEXT         | bool    | Add context to console output messages.                                 | `false`                  |
| WS_LOGGER_FILE_ENABLE   | bool    | Save logs to file.                                                      | `true`                   |
| WS_LOGGER_FILE_LEVEL    | string  | File Logger Level.                                                      | `ERROR`                  |
| WS_WEBHOOK_DUMP_REQUEST | bool    | If enabled, will dump all received requests.                            | `false`                  |
| WS_TRUST_PROXY          | bool    | Trust `WS_TRUST_HEADER` ip. Value casted to bool.                       | `false`                  |
| WS_TRUST_HEADER         | string  | Which header contain user true IP.                                      | `X-Forwarded-For`        |
| WS_LIBRARY_SEGMENT      | integer | Paginate backend library items request. Per request get total X number. | `1000`                   |
| WS_CACHE_URL            | string  | Cache server URL.                                                       | `redis://127.0.0.1:6379` |
| WS_SECURE_API_ENDPOINTS | bool    | Disregard the open route policy and require API key for all endpoints.  | `false`                  |

> [!IMPORTANT]
> for environment variables that has `{TASK}` tag, you **MUST** replace it with one of `IMPORT`, `EXPORT`, `BACKUP`,
`PRUNE`, `INDEXES`.

## Add tool specific environment variables

Go to the `Env` page, click `+` button, you will get list of all supported keys with description.

## Container specific environment variables.

> [!IMPORTANT]
> These environment variables relates to the container itself, and MUST be added via container environment or by
> the `compose.yaml` file.

| Key           | Type    | Description                          | Default  |
|---------------|---------|--------------------------------------|----------|
| WEBUI_ENABLED | bool    | Enable WebUI. Value casted to a bool | `true`   |
| DISABLE_HTTP  | integer | Disable included `HTTP Server`.      | `0`      |
| DISABLE_CRON  | integer | Disable included `Task Scheduler`.   | `0`      |
| DISABLE_CACHE | integer | Disable included `Cache Server`.     | `0`      |
| HTTP_PORT     | string  | Change the `HTTP` listen port.       | `"8080"` |
| FPM_PORT      | string  | Change the `PHP-FPM` listen port.    | `"9000"` |

> [!NOTE]
> You need to restart the container after changing these environment variables. those variables are not managed by the
> WatchState tool, they are managed by the container itself.

---

# How to Add Webhooks

Webhook URLs are **backend-specific** and follow this structure:

```
/v1/api/backend/[USER]@[BACKEND_NAME]/webhook
```

- `[USER]` should be the username of the sub-user, or `main` for the main user.
- `[BACKEND_NAME]` is the name of the backend you want to configure the webhook for.

A typical full URL might look like:

```
http://localhost:8080/v1/api/backend/main@plex_foo/webhook
```

To get the correct URL easily:

* Go to `WebUI > Backends`.
* Click on **Copy Webhook URL** next to the relevant backend.

> [!IMPORTANT]
> If you have enabled `WS_SECURE_API_ENDPOINTS`, you have to add `?apikey=yourapikey` to the end of the the webhook URL.

> [!NOTE]  
> You may see a `webhook.token` key in older configurations. This is retained only for backward compatibility and has no
> effect. It will be removed in future versions.
>
> If you're using Plex and have sub-users, make sure to enable **Webhook Match User** to prevent sub-user activity from
> affecting the main user's watch state.

-----

## Emby (you need `Emby Premiere` to use webhooks).

Go to your Manage Emby Server:

* Old Emby versions: Server > Webhooks > (Click Add Webhook) `Old version`
* New Emby versions: username Preferences > Notifications > + Add Notification > Webhooks

### Name (Emby v4.9+):

`user@backend` or whatever you want, i simply prefer the name to reflect which user it belongs to.

### Webhook/Notifications URL:

`http://localhost:8080/v1/api/backend/[USER]@[BACKEND_NAME]/webhook`

* Replace `[BACKEND_NAME]` with the name you have chosen for your backend.
* Replace `[USER]` with the `main` for main user or the sub user username.

### Request content type (Emby v4.9+):

`application/json`

### Webhook Events:

#### v4.7.9 or higher

* New Media Added
* Playback
* Mark played
* Mark unplayed

#### Versions prior to 4.7.9

* Playback events
* User events

### Limit user events to:

* Select your user.

### Limit library events to:

* Select libraries that you want to sync or leave it blank for all libraries.

Click `Add Webhook / Save`

-----

## Jellyfin (Free)

go to your jellyfin dashboard > plugins > Catalog > install: Notifications > Webhook, restart your jellyfin. After that
go back again to dashboard > plugins > webhook. Add `Add Generic Destination`,

### Webhook Name:

`user@backend` or whatever you want, i simply prefer the name to reflect which user it belongs to.

#### Webhook Url:

`http://localhost:8080/v1/api/backend/[USER]@[BACKEND_NAME]/webhook`

* Replace `[BACKEND_NAME]` with the name you have chosen for your backend.
* Replace `[USER]` with the `main` for main user or the sub user username.

#### Notification Type:

* Item Added
* User Data Saved
* Playback Start
* Playback Stop

#### User Filter:

* Select your user.

#### Item Type:

* Movies
* Episodes

### Send All Properties (ignores template)

Toggle this checkbox.

Click `Save`

-----

## Plex (You need `Plex Pass` to use webhooks)

Go to your Plex Web UI > Settings > Your Account > Webhooks > (Click ADD WEBHOOK)

### URL:

`http://localhost:8080/v1/api/backend/[USER]@[BACKEND_NAME]/webhook`

* Replace `[BACKEND_NAME]` with the name you have chosen for your backend.
* Replace `[USER]` with the `main` for main user or the sub user username.

Click `Save Changes`

> [!NOTE]
> If you share your plex server with other users, i,e. `Home/managed users`, you have to enable match user id, otherwise
> their play state will end up changing your play state.
>
> If you use multiple plex servers and use the same PlexPass account for all of them, You have to add each backend
> using the same method above, while enabling `limit webhook events to` `selected user` and `backend unique id`.
> Essentially, this method replaced the old unified webhook token for backends.

---- 

## Plex Via tautulli

Go to options > Notification Agents > Add a new notification agent > Webhook

### Webhook URL:

`http://localhost:8080/v1/api/backend/[USER]@[BACKEND_NAME]/webhook`

* Replace `[BACKEND_NAME]` with the name you have chosen for your backend.
* Replace `[USER]` with the `main` for main user or the sub user username.

> [!IMPORTANT]
> If you have enabled `WS_SECURE_API_ENDPOINTS`, you have to add `?apikey=yourapikey` to the end of the URL.

### Webhook Method

`PUT`

### Description

it's recommended to use something like `webhook for user XX for backend XX`.

### Triggers

Select the following events.

- Playback Start
- Playback Stop
- Playback Pause
- Playback Resume
- Watched
- Recently Added

### Data

For each event there is a corresponding headers/data fields that you need to set using the following format.

> [!IMPORTANT]
> It's extremely important that you copy the headers and data as it is, don't alter them if you don't know what you are
> doing.

### JSON headers

```json
{
    "user-agent": "Tautulli/{tautulli_version}"
}
```

### JSON Data

```json
{
    "event": "tautulli.{action}",
    "Account": {
        "id": "{user_id}",
        "thumb": "{user_thumb}",
        "title": "{username}"
    },
    "Server": {
        "title": "{server_name}",
        "uuid": "{server_machine_id}",
        "version": "{server_version}"
    },
    "Player": {
        "local": "{stream_local}",
        "publicAddress": "{ip_address}",
        "title": "{player}",
        "uuid": "{machine_id}"
    },
    "Metadata": {
        "librarySectionType": null,
        "ratingKey": "{rating_key}",
        "key": null,
        "parentRatingKey": "{parent_rating_key}",
        "grandparentRatingKey": "{grandparent_rating_key}",
        "guid": "{guid}",
        "parentGuid": null,
        "grandparentGuid": null,
        "grandparentSlug": null,
        "type": "{media_type}",
        "title": "{episode_name}",
        "grandparentKey": null,
        "parentKey": null,
        "librarySectionTitle": "{library_name}",
        "librarySectionID": "{section_id}",
        "librarySectionKey": null,
        "grandparentTitle": "{show_name}",
        "parentTitle": "{season_name}",
        "contentRating": "{content_rating}",
        "summary": "{summary}",
        "index": "{episode_num}",
        "parentIndex": "{season_num}",
        "audienceRating": "{audience_rating}",
        "viewOffset": "{view_offset}",
        "skipCount": null,
        "lastViewedAt": "{last_viewed_date}",
        "year": "{show_year}",
        "thumb": "{poster_thumb}",
        "art": "{art}",
        "parentThumb": "{parent_thumb}",
        "grandparentThumb": "{grandparent_thumb}",
        "grandparentArt": null,
        "grandparentTheme": null,
        "duration": "{duration_ms}",
        "originallyAvailableAt": "{air_date}",
        "addedAt": "{added_date}",
        "updatedAt": "{updated_date}",
        "audienceRatingImage": null,
        "userRating": "{user_rating}",
        "Guids": {
            "imdb": "{imdb_id}",
            "tvdb": "{thetvdb_id}",
            "tmdb": "{themoviedb_id}",
            "tvmaze": "{tvmaze_id}"
        },
        "file": "{file}",
        "file_size": "{file_size_bytes}"
    }
}
```

You need to do this for each event that you enabled in `Triggers` section.

Click `Save`

> [!NOTE]
> Tautulli Doesn't support sending user id with `created` event. as such if you enabled `Match webhook user`, new items
> will not be added and fail with `Request user id '' does not match configured value`.
>
> Marked as unplayed will most likely not work with Tautulli webhook events as it's missing critical data we need to
> determine if the item is marked as unplayed.

---

# Webhook Limitations by Media Backend

Below are known limitations and issues when using webhooks with different media backends:

## Plex

- Webhooks are **not sent** for "marked as played/unplayed" actions on all item types.
- Webhook events may be **skipped** if multiple items are added at once.
- When items are marked as **unwatched**, Plex resets the date on the media object.

## Plex via Tautulli

- Tautulli does **not send user IDs** with `itemAdd` (`created`) events. If `Match webhook user` is enabled, the request
  will fail with: `Request user id '' does not match configured value`.
- **Marking items as unplayed** is not reliable, as Tautulli's webhook payload lacks critical data required to detect
  this change.

## Emby

- Emby does **not send webhook events** for newly added items.
  ~~[See feature request](https://emby.media/community/index.php?/topic/97889-new-content-notification-webhook/)~~ This
  was implemented in version `4.7.9`, but still does **not include metadata**, making it ineffective.
- The Emby **webhook test event** previously contained no data. This appears to be **fixed in `4.9.0.37+`**.
- To verify if your Emby webhook setup is working, try playing or marking an item as played/unplayed, go to the history
  page after a min or two and check if the changes are reflected in the database.

## Jellyfin

- If no user ID is selected in the plugin, the `itemAdd` event will be sent **without user data**, which will cause a
  failure if `webhook.match.user` is enabled.
- Occasionally, Jellyfin will fire `itemAdd` events that **without it being matched**.
- Even if a user ID is selected, Jellyfin may **still send events without user data**.
- Items may be marked as **unplayed** if the following setting is enabled **Libraries > Display > Date added behavior
  for new content: `Use date scanned into library`** This often happens when media files are replaced or updated.

---

# Sometimes newly added episodes or movies don't reach the webhook endpoint?

As noted in the webhook limitations section, some media backends do not reliably send webhook events for newly added
content. To address this, you should enable **import/export tasks** to complement webhook functionality.

Simply, visit the `Tasks` page and enable the `import` and `export` task.

---

# How to disable the included HTTP server and use external server?

To disable the built-in HTTP server, set the following environment variable and restart the container:

```
DISABLE_HTTP=1
```

Your external web server must forward requests using the correct **FastCGI** environment variables.

## Example: Caddy Configuration

```caddyfile
https://watchstate.example.org {
    # Replace "172.23.1.2" with the actual IP address of your WatchState container
    reverse_proxy 172.23.1.2:9000 {
        transport fastcgi {
            root /opt/app/public
            env DOCUMENT_ROOT /opt/app/public
            env SCRIPT_FILENAME /opt/app/public/index.php
            env X_REQUEST_ID "{http.request.uuid}"
            split .php
        }
    }
}
```

> [!NOTE]
> If you change the FastCGI Process Manager port using the `FPM_PORT` environment variable, make sure to update the port
> in the reverse proxy configuration as well.

> [!IMPORTANT]
> This configuration mode is **not officially supported** by WatchState. If issues arise, please verify your web server
> setup. Support does not cover external web server configurations.

---

# WS_API_AUTO

The `WS_API_AUTO` environment variable is designed to **automate the initial configuration process**, particularly
useful for users who access the WebUI from multiple browsers or devices. Since the WebUI requires API settings to be
configured before use, enabling this variable allows the system to auto-configure itself.

To enable it, write `WS_API_AUTO=true` to `/config/.env` file, note the file may not exist, and you may need to create
it.

## Why You Might Use It

You may consider using this if:

- You're operating in a **secure, local environment**.
- You want to **automate setup** across multiple devices or browsers without repeatedly entering API details.

## Why You Should **NOT** Use It (Recommended)

Enabling this poses a **serious security risk**:

- It **exposes your API key** publicly through the endpoint `/v1/api/system/auto`.
- Anyone (or any bot) that can access the WebUI can retrieve your API key and gain **access** to any and all data that
  is exposed by the API including your media servers API keys.

**If WatchState is exposed to the internet, do not enable this setting.**

> [!IMPORTANT]  
> The `WS_API_AUTO` variable is a **major security risk**. It should only be used in isolated or trusted environments.  
> We strongly recommend keeping this option disabled.

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

* Only **Redis** and **API-compatible alternatives** are supported.

After updating the environment variables, **restart the container** to apply the changes.

---

# How to get WatchState working with YouTube content/library?

Due to the nature on how people name their youtube files i had to pick something specific for it to work cross supported
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

If you having problem adding a backend to `WatchState`, it most likely network related problem, where the container
isn't able to communicate with the media backend. Thus, you will get errors. To make sure the container is able to
communicate with the media backend, you can run the following command and check the output.

If the command fails for any reason, then you most likely have network related problem or invalid apikey/token.

## For Plex.

```bash
$ docker exec -ti watchstate bash
$ curl -H "Accept: application/json" -H "X-Plex-Token: [PLEX_TOKEN]" http://[PLEX_URL]/
```

* Replace `[PLEX_TOKEN]` with your plex token.
* Replace `[PLEX_URL]` with your plex url. The one you selected when prompted by the command.

```
{"MediaContainer":{"size":25,...}}
```

If everything is working correctly you should see something like this previous json output.

## For Jellyfin & Emby.

```bash
$ docker exec -ti watchstate bash
$ curl -v -H "Accept: application/json" -H "X-MediaBrowser-Token: [BACKEND_API_KEY]" http://[BACKEND_HOST]/System/Info
```

* Replace `[BACKEND_API_KEY]` with your jellyfin/emby api key.
* Replace `[BACKEND_HOST]` with your jellyfin/emby host. it can be a host or ip:port i.e. `jf.mydomain.ltd`
  or `172.23.0.11:8096`

```
{"OperatingSystemDisplayName":"Linux","HasPendingRestart":false,"IsShuttingDown":false,...}}
```

If everything is working correctly you should see something like this previous json output.

----

# I keep receiving this warning in logs

## INFO: Ignoring [xxx] Episode range, and treating it as single episode. Backend says it covers [00-00]?

We recently added guard clause to prevent backends from sending possibly invalid episode ranges, as such if you see
this,
this likely means your backend mis-identified episodes range. By default, we allow an episode to cover up to `3`
episodes.

If this is not enough for your library content. fear not we have you covered you can increase the limit by running the
following command:

```bash 
$ docker exec -ti watchstate console config:edit --key options.MAX_EPISODE_RANGE --set 10 -s backend_name 
```

where `10` is the new limit. You can set it to any number you want. However, Please do inspect the reported records as
it most likely you have incorrect metadata in your library.

## I Keep receiving 'jellyfin' item 'id: name' is marked as 'played' vs local state 'unplayed', However due to the remote item date 'date' being older than the last backend sync date 'date'. it was not considered as valid state.

Sadly, this is due to bug in jellyfin, where it marks the item as played without updating the LastPlayedDate, and as
such, watchstate doesn't really know the item has changed since last sync. Unfortunately, there is no way to fix this
issue from our side for the `state:import` task as it working as intended.

However, we managed to somewhat implement a workaround for this issue using the webhooks feature as temporary fix. Until
jellyfin devs fixes the issue. Please take look at the webhooks section to enable it.

---

# Bare metal installation

We officially only support the docker container, however for the brave souls who want to install the tool directly on
their server, You can follow these steps.

## Requirements

* [PHP 8.4](http://https://www.php.net/downloads.php) with both the `CLI` and `fpm` mode.
* PHP Extensions `pdo`, `pdo-sqlite`, `mbstring`, `json`, `ctype`, `curl`, `redis`, `sodium` and `simplexml`.
* [Composer](https://getcomposer.org/download/) for dependency management.
* [Redis-server](https://redis.io/) for caching or a compatible implementation that works
  with [php-redis](https://github.com/phpredis/phpredis).
* [Caddy](https://caddyserver.com/) for frontend handling. However, you can use whatever you like. As long as it has
  support for fastcgi.
* [Node.js v20+](https://nodejs.org/en/download/) for `WebUI` compilation.

## Installation

* Clone the repository.

```bash
$ git clone https://github.com/arabcoders/watchstate.git
```

* Install the dependencies.

```bash
$ cd watchstate
$ composer install --no-dev 
```

* If you your redis server on external server, run the following command

```bash
$ ./bin/console system:env -k WS_CACHE_URL -e '"redis://127.0.0.1:6379?password=your_password"'
```

Change the connection string to match your redis server.

* Compile the `WebUI`.

First you need to install `yarn` as it's our package manager of choice.

```bash
$ npm -g install yarn
```

Once that is done you are ready to compile the `WebUI`.

```bash
$ cd frontend
$ yarn install --production --prefer-offline --frozen-lockfile && yarn run generate
```

There should be a new directory called `exported`, you need to move that folder to the `public` directory.

```bash
$ mv exported ../public
```

If you do `ls ../public` you should see the following contents

```bash
ws:/opt/app/public$ ls
exported   index.php
```

There must be exactly one `index.php` file and one `exported` directory. inside that directory, or if you prefer, you
can add `WS_WEBUI_PATH` environment variable to point to the `exported` directory.

* link the app to the frontend proxy. For caddy, you can use the following configuration.

> [!NOTE]
> frontend server is needed All the `API`, `WebUI` and `Webhooks` operations.

```Caddyfile
http://watchstate.example.org {
    # Change "[user]" to your user name.
    root * /home/[user]/watchstate/public
        
    # Change "unix//var/run/php/php8.3-fpm.sock" to your php-fpm socket.
    php_fastcgi unix//var/run/php/php8.3-fpm.sock
}
```

* To access the console you can run the following command.

```bash
$ ./bin/console help
```

* To make the tasks scheduler work you need to add the following to your crontab.

```crontab
* * * * * /home/[user]/watchstate/bin/console system:tasks --run --save-log
```

For more information, please refer to the [Dockerfile](/Dockerfile). On how we do things to get the tool running.

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

# How to use hardware acceleration for video transcoding in the WebUI?

As the container is rootless, we cannot do the necessary changes to the container to enable hardware acceleration.
However, We do have the drivers and ffmpeg already installed and the CPU transcoding should work regardless. To enable
hardware acceleration You need to alter your `compose.yaml` file to mount the necessary devices to the container. Here
is an example of how to do it for debian based systems.

```yaml
services:
    watchstate:
        container_name: watchstate
        image: ghcr.io/arabcoders/watchstate:latest   # The image to use. you can use the latest or dev tag.
        user: "${UID:-1000}:${GID:-1000}"             # user and group id to run the container under. 
        group_add:
            - "44"                                    # Add video group to the container.
            - "105"                                   # Add render group to the container.
        restart: unless-stopped
        ports:
            - "8080:8080"                             # The port which will serve WebUI + API + Webhooks
        devices:
            - /dev/dri:/dev/dri                       # mount the dri devices to the container.
        volumes:
            - ./data:/config:rw                       # mount current directory to container /config directory.
            - /storage/media:/media:ro                # mount your media directory to the container.
```

This setup should work for VAAPI encoding in `x86_64` containers, There are currently an issue with nvidia h264_nvenc
encoding, the alpine build for`ffmpeg` doesn't include the codec. i am looking for a way include the codec without
ballooning the image size by 600MB+. If you have a solution please let me know.

Please know that your `video`, `render` group id might be different then mine, you can run the follow command in docker
host server to get the group ids for both groups.

```bash
$ cat /etc/group | grep -E 'render|video'

video:x:44:your_docker_username
render:x:105:your_docker_username
```

In my docker host the group id for `video` is `44` and for `render` is `105`. change what needed in the `compose.yaml`
file to match your setup.

Note: the tip about adding the group_add came from the user `binarypancakes` in discord.

---

# Advanced: How to extend the GUID parser to support more GUIDs or custom ones?

By going to `More > Custom GUIDs` in the WebUI, you can add custom GUIDs to the parser. We know not all people,
like using GUI, as such You can extend the parser by creating new file at `/config/config/guid.yaml` with the following
content.

```yaml
# (Optional) The version of the guid file. If omitted, it will default to the latest version.
version: 1.0

# The key must be in lower case. and it's an array.
guids:
    -   id: universally-unique-identifier       # the guid id. Example, 1ef83f5d-1686-60f0-96d6-3eb5c18f2aed
        type: string                            # must be exactly string do not change it.
        name: guid_mydb                         # the name must start with guid_ with no spaces and lower case.
        description: "My custom database guid"  # description of the guid. For informational purposes only.
        # Validator object. to validate the guid.
        validator:
            pattern: "/^[0-9\/]+$/i"  # regex pattern to match the guid. The pattern must also support / being in the guid. as we use the same object to generate relative guid.
            example: "(number)"     # example of the guid. For informational purposes only.
            tests:
                valid:
                    - "1234567"     # valid guid examples.
                invalid:
                    - "1234567a"    # invalid guid examples.
                    - "a111234567"  # invalid guid examples.

links:
    # mapping the com.plexapp.agents.foo guid from plex backends into the guid_mydb in WatchState.
    # plex legacy guids starts with com.plexapp.agents., you must set options.legacy to true.
    -   id: universally-unique-identifier # the link id. example, 1ef83f5d-1686-60f0-96d6-3eb5c18f2aed
        type: plex    # the client to link the guid to. plex, jellyfin, emby.
        options: # options used by the client.
            legacy: true  # Tag the mapper as legacy GUID for mapping.
            # (Optional) Replace helper. Sometimes you need to replace the guid identifier to another.
            # The replacement happens before the mapping, so if you replace the guid identifier, you should also
            # update the map.from to match the new identifier.
            # This "replace" object only works with plex legacy guids.
            replace:
                from: com.plexapp.agents.foobar://  # Replace from this string
                to: com.plexapp.agents.foo://       # Into this string.
        # Required map object. to map the new guid to WatchState guid.
        map:
            from: com.plexapp.agents.foo # map.from this string.
            to: guid_mydb                # map.to this guid.

    # mapping the foo guid from jellyfin backends into the guid_mydb in WatchState.
    -   id: universally-unique-identifier # the link id. example, 1ef83f5d-1686-60f0-96d6-3eb5c18f2aed
        type: jellyfin # the client to link the guid to. plex, jellyfin, emby.
        map:
            from: foo     # map.from this string.
            to: guid_mydb # map.to this guid.

    # mapping the foo guid from emby backends into the guid_mydb in WatchState.
    -   id: universally-unique-identifier # the link id. example, 1ef83f5d-1686-60f0-96d6-3eb5c18f2aed
        type: emby    # the client to link the guid to. plex, jellyfin, emby.
        map:
            from: foo     # map.from this string.
            to: guid_mydb # map.to this guid.
```

As you can see from the config, it's roughly how we expected it to be. The `guids` array is where you define your new
custom GUIDs. the `links` array is where you map from client/backends GUIDs to the custom GUID in `WatchState`.

Everything in this file should be in lower case. If error occurs, the tool will log a warning and ignore the guid,
By default, we only show `ERROR` levels in log file, You can lower it by setting `WS_LOGGER_FILE_LEVEL` environment
variable
to `WARNING`.

If you added or removed a guid from the `guid.yaml` file, you should run `system:index --force-reindex` command to
update the
database indexes with the new guids.

---

# Sync watch progress.

In order to sync the watch progress between media backends, you need to enable the following environment variable
`WS_SYNC_PROGRESS` in `(WebUI) > Env` page or via the cli using the following command:

```bash
$ docker exec -ti watchstate console system:env -k WS_SYNC_PROGRESS -e true
```

For best experience, you should enable the `Webhooks` feature for the media backends you want to sync the watch
progress,
however, if you are unable to do so, the `Tasks > import` task will also generate progress watch events. However, it's
not as reliable as the `Webhooks` or as fast. using `Webhooks` is the recommended way and offers the best experience.

To check if there is any watch progress events being registered, You can go to `(WebUI) > More > Events` and check
`on_progress` events, if you are seeing those, this means the progress is being synced. Check the `Tasks logs` to see
the event log.

---

# Sub users support.

### API/WebUI endpoints that supports sub users.

These endpoints support sub-users via `?user=username` query parameter, or via `X-User` header. The recommended
approach is to use the header.

* `/v1/api/backend/*`.
* `/v1/api/system/parity`.
* `/v1/api/system/integrity`.
* `/v1/api/ignore/*`.
* `/v1/api/history/*`.

### CLI commands that supports sub users.

These commands sub users can via `[-u, --user]` option flag.

* `state:import`.
* `state:export`.
* `state:backup`.
* `db:list`.
* `backend:restore`.
* `backend:ignore:*`.

