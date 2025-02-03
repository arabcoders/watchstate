# FAQ

To see list of all the available commands run

```shell
$ docker exec -ti watchstate console list
```

This command will list all available commands, and each command has help document attached to it, simply run

```shell
$ docker exec -ti watchstate console help [COMMAND_NAME]
```

It will show you the relevant information regarding the command and some frequently asked question about that command.

> [!IMPORTANT]
> **The help document attached to each command is more up to date and precise. So, please read it.**

----

### How to turn on scheduled tasks for import/export?

Scheduled tasks are configured via specific environment variables refers
to [environment variables](#environment-variables) section,

#### Via WebUI

Simply go to `Tasks` page and enable the tasks you want to run.

#### Via CLI

```bash
$ docker exec -ti watchstate console system:env -k WS_CRON_IMPORT -e true
$ docker exec -ti watchstate console system:env -k WS_CRON_EXPORT -e true
```

By default, `import` is scheduled to run every `1hour` while `export` is scheduled to run every `1hour 30minutes`, you
can alter the time when the tasks are run via adding the following variables with valid cron expression. good source to
check your cron expression is [crontab.guru](https://crontab.guru/).

While we think they are reasonable defaults, you can change them by setting the following environment variables:

#### Via WebUI

Go to the `env` page, click on `+` button, then select the key in this case `WS_CRON_IMPORT_AT`, `WS_CRON_EXPORT_AT`
and set the value to a valid cron expression. Then click save to apply the new timer. This will be change later to be
included with
the tasks page.

#### Via CLI

Execute the following commands:

```bash
$ docker exec -ti watchstate console system:env -k WS_CRON_IMPORT_AT -e '"*/1 * * * *"'
$ docker exec -ti watchstate console system:env -k WS_CRON_EXPORT_AT -e '"30 */1 * * *"'
```

For Values with space, they must be enclosed in double quotes. to see the status of your scheduled tasks,

#### Via WebUI

Go to the `Tasks` page, you will see the status of each task.

#### Via CLI

```bash
$ docker exec -ti watchstate console system:tasks
```

> [!NOTE]
> All scheduled tasks are configured via the same variables style, refer
> to [Tool specific environment variables](#tool-specific-environment-variables) for more information.

----

### Container is crashing on startup?

This is likely due to misconfigured `user:` in `compose.yaml`, the container is rootless as such it will crash if
the tool unable to access the data path. to check permissions simply do the following

```bash
$ stat data/config/ | grep 'Uid:'
```

It should show something like

```
Access: (0755/drwxr-xr-x)  Uid: ( 1000/  user)   Gid: ( 1000/  user)
```

Use the ids as parameters for `user:` in this case it should be `user:"1000:1000"`.

----

### How to find the apikey?

You can find the apikey inside the following file `/config/config/.env`. The apikey is stored inside this
variable `WS_API_KEY=`.
Or you can run the following command to get it directly:

```bash
$ docker exec -ti console system:apikey
```

----

### What the API key used for?

The API key is used to authenticate the requests to the tool, it's used to prevent unauthorized access. The API key is
required for all endpoints except the `/v1/api/[backend_name]/webhook` endpoint which is open by default unless you have
enabled `WS_SECURE_API_ENDPOINTS` environment variable. which then you also need to use the apikey for it webhook
endpoint.

The new `WebUI` will also require the API key to access data as it's decoupled from the backend and run in standalone
mode.

----

### MAPPER: Watch state conflict detected in [BACKEND_NAME]...?

This warning occurs when the database has the movie/episode marked as played but a backend reporting the
item as unplayed and there is no metadata that indicates that the movie was previously imported from the backend.
So, Preserving your current watch state takes priority, and thus we mark the item as tainted and re-process it.
To Fix this conflict you should re-export your database state back to the problematic backend using the following
command:

```bash
$ docker exec -ti console state:export -fi -s [BACKEND_NAME]
```

----

### How to use Jellyfin, Emby oauth tokens?

Due to limitation on jellyfin/emby side, the way we implemented support for oauth token require that you provide the
username and password in `username:password` format, This is due to the API not providing a way for us to inquiry about
the current user.

Simply, when asked for API Key, provide the username and password in the following format `username:password`.
`WatchState` will then generate the token for you. and replace the username and password with the generated token. This
is a one time process, and you should not need to do it again. Your `username` and `password` will not be stored.

----

### My new backend overriding my old backend state / My watch state is not correct?

This likely due to the new backend reporting newer date than your old backend. as such the typical setup is to
prioritize items with newer date compared to old ones. This is what you want to happen normally. However, if the new
media backend state is not correct this might override your current watch state. The solution to get both in sync, and
to do so follow these steps:

Add your backend that has correct watch state and enable full import. Second, add your new backend as metadata source.

In `CLI` context Answer `N` to the question `Enable importing of metadata and play state from this backend?`. Make sure
to select yes
for export to get the option to select the backend as metadata source.

In `WebUI` if you disable import, you will get an extra option that is normally hidden to select the backend as metadata
source.

After that, do single backend export by using the following command:

```bash
$ docker exec -ti watchstate console state:export -vvif -s new_backend_name
```

Running this command will force full export your current database state to the selected backend. Once that done you can
turn on import from the new backend.

In `CLI` context you can enable import by running the following command:

```bash
$ docker exec -ti watchstate console config:manage -s backend_name
```

In `WebUI` you can enable import by going to the `backends` page and click on import for the new backend.

----

### My new backend watch state not being updated?

The likely cause of this problem is date related problem, as we check the date on backend object and compare that to the
date in local database, to make sure this is the error you are facing please do the following.

```
$ docker exec -ti watchstate console state:export -s new_backend_name --debug --logfile /config/export.txt
```

After running the command, open the log file and look for episode and movie that has the problem and read the text next
to it. The error usually looks like:

```
[YYYY-MM-DDTHH:MM:SS-ZZ] DEBUG: Ignoring [backend_name] [Title - (Year or episode)]. reason. { ..., (comparison: [ ... ]) }
```

In this case the error text should be `Backend date is equal or newer than database date.`

To bypass the date check you need to force ignore date comparison by using the `[-i, --ignore-date]` flag, so to get
your new backend in sync with your old database, do the following:

```bash
$ docker exec -ti watchstate console state:export -vvif -s new_backend_name
```

This command will ignore your `lastSync` date and will also ignore `object date comparison` and thus will mirror your
database state back to the selected backend.

----

### Is there support for Multi-user setup?

We are on early stage of supporting multi-user setups, initially few operations are supported. To get started, first you
need to create your own main user backends using admin token for Plex and api key for Jellyfin/Emby.

Once your own main user is added, make sure to turn on the `import` and `export` for all backends, as the sub users are
initial configuration is based on your own main user configuration. Once your own user is working, turn on the `import`
and `export` tasks in the Tasks page.

Now, to create the sub users configurations, you need to run `backend:create` command, which can be done via
`WebUI > Backends > Purple button (users) icon` or via CLI by running the following command:

```bash
$ docker exec -ti watchstate console backend:create -v
```

Once the sub users configuration is created, You can start using the multi-user functionality.

If your users usernames are different between the backends, you can use the `mapper.yaml` file to map the users between
the backends. For more information about the `mapper.yaml` file, please refer to
the [mapper.yaml](#whats-the-schema-for-the-mapperyaml-file) section.

#### Whats the schema for the `mapper.yaml` file?

The schema is simple, it's a list of users in the following format:

```yaml
# 1st user...
-   my_plex_server:
        name: "mike_jones"
        options: { }
    my_jellyfin_server:
        name: "jones_mike"
        options: { }
    my_emby_server:
        name: "mikeJones"
        options: { }
# 2nd user...
-   my_emby_server:
        name: "jiji_jones"
        options: { }
    my_plex_server:
        name: "jones_jiji"
        options: { }
    my_jellyfin_server:
        name: "jijiJones"
        options: { }
#.... more users
```

This yaml file helps map your users username in the different backends, so the tool can sync the correct user data. If
you added or updated mapping, you should delete `users` directory and generate new data. by running the `backend:create`
command as described in the previous section.

----

### How do i migrate invited friends i.e. (external user) data from from plex to emby/jellyfin?

As this tool is designed to work with single user, You have to treat each invited friend as a separate user. what is
needed, you need to contact that friend of yours and ask him/her to give you a copy of
the [X-Plex-Token](https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/),
then create new container and add the backend with the token you got from your friend.

After that, add your other backends like emby/jellyfin using your regular API key. jellyfin/emby differentiate between
users by using the userId which you should select at the start of the add process.

After that. run the `state:import -f -s [plex_server_name]` command to import the user watch state. After that, you can
run the `state:export -fi -s [emby/jellyfin_server_name]` to export the watch state to the new backend.

You have to repeat these steps for each user you want to migrate their data off the plex server.

> [!IMPORTANT]
> YOU MUST always start with fresh data for **EACH USER**, otherwise unexpected things might happen.
> Make sure to delete compose.yaml `./data` directory. to start fresh.

----

### Does this tool require webhooks to work?

No, You can use the `task scheduler` or on `on demand sync` if you want.

---

### I get tired of writing the whole command everytime is there an easy way run the commands?

Good News, There is a way to make your life easier, We recently added a `WebUI` which should cover most of the use
cases.
However, if you still want to use the `CLI` You can create a shell script to omit
the `docker exec -ti watchstate console`

```bash
$ echo 'docker exec -ti watchstate console "$@"' > ws
$ chmod +x ws
```

after that you can do `./ws command` for example, `./ws db:list`

---

### I am using media backends hosted behind HTTPS, and see errors related to HTTP/2?

Sometimes there are problems related to HTTP/2, so before reporting bug please try running the following command:

```bash
$ docker exec -ti watchstate console config:edit --key options.client.http_version --set 1.0 -s backend_name 
```

This will force set the internal http client to use `http/v1` if it does not fix your problem, please open bug report
about it.

---

### Sync operations are failing due to request timeout?

If you want to increase the timeout for specific backend you can run the following command:

```bash
$ docker exec -ti watchstate console config:edit --key options.client.timeout --set 600 -s backend_name
```

where `600` is the number of seconds before the timeout handler will kill the request.

---

### How to fix corrupt SQLite database?

Sometimes your SQLite database will be corrupted, and you will get an error similar to this
`General error: 11 database disk image is malformed`. To fix this error simply execute the following commands:

```bash
$ docker exec -ti watchstate bash
$ sqlite3 /config/db/watchstate_v01.db '.dump' | sqlite3 /config/db/watchstate_v01-repaired.db
```

After executing the previous command you should run `integrity check`, by running the following command:

```bash
$ sqlite3 /config/db/watchstate_v01-repaired.db 'PRAGMA integrity_check'
```

it should simply say `ok`. then you should run the following command to replace the corrupted database.

```bash
$ mv /config/db/watchstate_v01-repaired.db /config/db/watchstate_v01.db
```

---

### Which external db ids `GUIDS` supported for Plex Media Server?

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

### Which external db ids supported for Jellyfin and Emby?

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

### Environment Variables

The recommended approach is for keys that starts with `WS_` use the `WebUI > Env` page, or `system:env` command via CLI.
For other keys that aren't directly related to the tool, you **MUST** load them via container environment or
the `compose.yaml` file.

to see list of loaded environment variables

#### Via WebUI

Go to `Env` page, you will see all the environment variables loaded.

#### Via CLI

```shell
$ docker exec -ti watchstate console system:env
```

#### Tool specific environment variables.

These environment variables relates to the tool itself, You should manage them via `WebUI > Env` page or `system:env`
command via CLI.

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
> for environment variables that has `{TASK}` tag, you **MUST** replace it with one
> of `IMPORT`, `EXPORT`, `BACKUP`, `PRUNE`, `INDEXES`. To see tasks active settings run

```bash
$ docker exec -ti watchstate console system:tasks
```

> [!NOTE]
> To see all supported tool specific environment variables

#### Via WebUI

Go to the `Env` page, click `+` button, you will get list of all supported keys with description.

#### Via CLI

```bash
$ docker exec -ti watchstate console system:env --list
```

#### Container specific environment variables.

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

### How to add webhooks?

The Webhook URL is backend specific, the request path is `/v1/api/backend/[BACKEND_NAME]/webhook`,
Where `[BACKEND_NAME]` is the name of the backend you want to add webhook for. Typically, the full URL
is `http://localhost:8080/v1/api/backend/[BACKEND_NAME]/webhook`. Or simply go to the `WebUI > Backends` and click
on `Copy Webhook URL`.

> [!NOTE]
> You will keep seeing the `webhook.token` key, it's being kept for backward compatibility, and will be removed in the
> future. It has no effect except as pointer to the new method.

-----

#### Emby (you need `Emby Premiere` to use webhooks).

Go to your Manage Emby Server > Server > Webhooks > (Click Add Webhook)

##### Webhook/Notifications URL:

`http://localhost:8080/v1/api/backend/[BACKEND_NAME]/webhook`

* Replace `[BACKEND_NAME]` with the name you have chosen for your backend.

##### Request content type (Emby v4.9+):

`application/json`

##### Webhook Events:

#### v4.7.9 or higher

* New Media Added
* Playback
* Mark played
* Mark unplayed

#### Versions prior to 4.7.9

* Playback events
* User events

##### Limit user events to:

* Select your user.

##### Limit library events to:

* Select libraries that you want to sync or leave it blank for all libraries.

Click `Add Webhook / Save`

-----

#### Plex (You need `Plex Pass` to use webhooks)

Go to your Plex Web UI > Settings > Your Account > Webhooks > (Click ADD WEBHOOK)

##### URL:

`http://localhost:8080/v1/api/backend/[BACKEND_NAME]/webhook`

* Replace `[BACKEND_NAME]` with the name you have chosen for your backend.

> [!IMPORTANT]
> If you have enabled `WS_SECURE_API_ENDPOINTS`, you have to add `?apikey=yourapikey` to the end of the URL.

Click `Save Changes`

> [!NOTE]
> If you share your plex server with other users, i,e. `Home/managed users`, you have to enable match user id, otherwise
> their play state will end up changing your play state.
>
> If you use multiple plex servers and use the same PlexPass account for all of them, You have to add each backend
> using the same method above, while enabling `limit webhook events to` `selected user` and `backend unique id`.
> Essentially, this method replaced the old unified webhook token for backends.

-----

#### Jellyfin (Free)

go to your jellyfin dashboard > plugins > Catalog > install: Notifications > Webhook, restart your jellyfin. After that
go back again to dashboard > plugins > webhook. Add `Add Generic Destination`,

##### Webhook Name:

`Watchstate-Webhook`

##### Webhook Url:

`http://localhost:8080/v1/api/backend/[BACKEND_NAME]/webhook`

* Replace `[BACKEND_NAME]` with the name you have chosen for your backend.

##### Notification Type:

* Item Added
* User Data Saved
* Playback Start
* Playback Stop

##### User Filter:

* Select your user.

##### Item Type:

* Movies
* Episodes

### Send All Properties (ignores template)

Toggle this checkbox.

Click `Save`

---

### What are the webhook limitations?

Those are some webhook limitations we discovered for the following media backends.

#### Plex

* Plex does not send webhooks events for "marked as played/unplayed" for all item types.
* Sometimes does not send events if you add more than one item at time.
* When you mark items as unwatched, Plex reset the date on the object.

#### Emby

* Emby does not send webhooks events for newly added items.
  ~~[See feature request](https://emby.media/community/index.php?/topic/97889-new-content-notification-webhook/)~~
  implemented in `4.7.9` ~~still does not work as expected no metadata being sent when the item notification goes out.
* Emby webhook test event does not contain data~~. It seems to have been fixed in `4.9.0.37+` To test if your setup
  works, play something or do mark an item as played or unplayed you should see changes reflected in
  `docker exec -ti watchstate console db:list`.

#### Jellyfin

* If you don't select a user id, the plugin will send `itemAdd` event without user data, and will fail the check if you
  happen to enable `webhook.match.user` for jellyfin.
* Sometimes jellyfin will fire webhook `itemAdd` event without the item being matched.
* Even if you select user id, sometimes `itemAdd` event will fire without user data.
* Items might be marked as unplayed if Libraries > Display - `Date added behavior for new content:` is set
  to `Use date scanned into library`. This happens if the media file has been replaced.

---

### Sometimes newly added episodes or movies don't make it to webhook endpoint?

As stated in webhook limitation section sometimes media backends don't make it easy to receive those events, as such, to
complement webhooks, you should enable import/export tasks by settings their respective environment variables in
your `compose.yaml` file. For more information run help on `system:env` command as well as `system:tasks`
command.

---

### How to disable the included HTTP server and use external server?

Set this environment variable in your `compose.yaml` file `DISABLE_HTTP` with value of `1`. your external
server need to send correct fastcgi environment variables. Example caddy file:

```caddyfile
https://watchstate.example.org {
    # Change "172.23.1.2" to your watchstate container ip e.g. "172.23.20.20"
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

> [!IMPORTANT]
> If you change the FastCGI Process Manager TCP port via FPM_PORT environment variable, you should change the port in
> the caddy file as well.

---

### WS_API_AUTO

The purpose of this environment variable is to automate the configuration process. It's mainly used for people who use
many browsers to access the `WebUI` and want to automate the configuration process. as it's requires the API settings to
be configured before it can be used. This environment variable can be enabled by setting `WS_API_AUTO=true` in
`${WS_DATA_PATH}/config/.env`.

#### Why you should use it?

You normally should not use it, as it's a **GREAT SECURITY RISK**. However, if you are using the tool in a secure
environment and not worried about exposing your API key, you can use it to automate the configuration process.

#### Why you should not use it?

Because, by exposing your API key, you are also exposing every data you have in the tool. This is a **GREAT SECURITY
RISK**, any person or bot that are able to access the `WebUI` will also be able to visit `/v1/api/system/auto` and get
your API key. And with this key they can do anything they want with your data. including viewing your media servers API
keys. So, please while we have this option available, we strongly recommend not to use it if `WatchState` is exposed to
the internet.

> [!IMPORTANT]
> This environment variable is **GREAT SECURITY RISK**, and we strongly recommend not to use it if `WatchState` is
> exposed to the internet. I cannot stress this enough, please do not use it unless you are in a secure environment.

---

### How to disable the included cache server and use external cache server?

Set this environment variable in your `compose.yaml` file `DISABLE_CACHE` with value of `1`. to use external redis
server you need to alter the value of `WS_CACHE_URL` environment variable. the format for this variable is
`redis://host:port?password=auth&db=db_num`, for example to use redis from another container you could use something
like `redis://172.23.1.10:6379?password=my_secert_password&db=8`. We only support `redis` and API compatible
alternative.

Once that done, restart the container.

---

### How to get WatchState working with YouTube content/library?

Due to the nature on how people name their youtube files i had to pick something specific for it to work cross supported
media agents. Please visit [this link](https://github.com/arabcoders/jf-ytdlp-info-reader-plugin#usage) to know how to
name your files. Please be aware these plugins and scanners `REQUIRE`
that you have a `yt-dlp` `.info.json` files named exactly as your media file.

For example, if you have `20231030 my awesome youtube video [youtube-RandomString].mkv`you should
have `20231030 my awesome youtube video [youtube-RandomString].info.json` in the same directory. In the future,
I plan to make `.info.json` optional However at the moment the file is required for emby/jellyfin plugin to work.

#### Plex Setup

* Download this agent [ytinforeader.bundle](https://github.com/arabcoders/plex-ytdlp-info-reader-agent) please follow
  the instructions on how to install it from the link itself. It's important to use the specified scanner otherwise the
  syncing will not work.

#### Jellyfin Setup

* Download this plugin [jf-ytdlp-info-reader-plugin](https://github.com/arabcoders/jf-ytdlp-info-reader-plugin). Please
  refer to the link on how to install it.

#### Emby Setup

* Download this plugin [emby-ytdlp-info-reader-plugin](https://github.com/arabcoders/emby-ytdlp-info-reader-plugin).
  Please refer to the link on how to install it.

If your media is not matching correctly or not marking it as expected, it's most likely scanners issues as plex and
jellyfin/emby reports the GUID differently, and we try our best to match them. So, please hop on discord with the
relevant data if they are not matching correctly, and we hopefully can resolve it.

---

### How to check if the container able to communicate with the media backends?

If you having problem adding a backend to `WatchState`, it most likely network related problem, where the container
isn't able to communicate with the media backend. Thus, you will get errors. To make sure the container is able to
communicate with the media backend, you can run the following command and check the output.

If the command fails for any reason, then you most likely have network related problem or invalid apikey/token.

#### For Plex.

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

#### For Jellyfin & Emby.

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

### I keep receiving this warning in log

`INFO: Ignoring [xxx] Episode range, and treating it as single episode. Backend says it covers [00-00]`?

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

---

### I Keep receiving [jellyfin] item [id: name] is marked as [played] vs local state [unplayed], However due to the remote item date [date] being older than the last backend sync date [date]. it was not considered as valid state.

Sadly, this is due to bug in jellyfin, where it marks the item as played without updating the LastPlayedDate, and as
such, watchstate doesn't really know the item has changed since last sync. Unfortunately, there is no way to fix this
issue from our side for the `state:import` task as it working as intended.

However, we managed to somewhat implement a workaround for this issue using the webhooks feature as temporary fix. Until
jellyfin devs fixes the issue. Please take look at the webhooks section to enable it.

---

### Bare metal installation

We officially only support the docker container, however for the brave souls who want to install the tool directly on
their server, You can follow these steps.

#### Requirements

* [PHP 8.4](http://https://www.php.net/downloads.php) with both the `CLI` and `fpm` mode.
* PHP Extensions `pdo`, `pdo-sqlite`, `mbstring`, `json`, `ctype`, `curl`, `redis`, `sodium` and `simplexml`.
* [Composer](https://getcomposer.org/download/) for dependency management.
* [Redis-server](https://redis.io/) for caching or a compatible implementation that works
  with [php-redis](https://github.com/phpredis/phpredis).
* [Caddy](https://caddyserver.com/) for frontend handling. However, you can use whatever you like. As long as it has
  support for fastcgi.
* [Node.js v20+](https://nodejs.org/en/download/) for `WebUI` compilation.

#### Installation

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

### How does the file integrity feature works?

The feature first scan your entire history for reported media file paths. Depending on the results we do the following:

* If metadata reports a path, then we will run stat check on each component of the path from lowest to highest.
* If no metadata reports a path, then simply the record will be marked as OK.

#### Here is the break-down example

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

### How to use hardware acceleration for video transcoding in the WebUI?

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

### Advanced: How to extend the GUID parser to support more GUIDs or custom ones?

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

### Sync watch progress.

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


