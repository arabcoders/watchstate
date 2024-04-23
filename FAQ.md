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
to [environment variables](#environment-variables) section, to turn on the import/export tasks add the following
environment variables:

* `WS_CRON_IMPORT=1`
* `WS_CRON_EXPORT=1`

By default, `import` is scheduled to run every `1hour` while `export` is scheduled to run every `1hour 30minutes`, you
can alter the time when the tasks are run via adding the following variables with valid cron expression. good source to
check your cron expression is [crontab.guru](https://crontab.guru/).

* `WS_CRON_IMPORT_AT="*/1 * * * *"`
* `WS_CRON_EXPORT_AT="30 */1 * * *"`

to see the status of your scheduled tasks, simply run the following command:

```bash
$ docker exec -ti watchstate console system:tasks
```

> [!NOTE]
> All scheduled tasks are configured via the same variables style, refer
> to [Tool specific environment variables](#tool-specific-environment-variables) for more information.

----

### Container is crashing on startup?

This is likely due to misconfigured `user:` in `docker-compose.yaml`, the container is rootless as such it will crash if
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

### My new backend overriding my old backend state / My watch state is not correct?

This likely due to the new backend reporting newer date than your old backend. as such the typical setup is to
prioritize items with newer date compared to old ones. This is what you want to happen normally. However, if the new
media backend state is not correct this might override your current watch state.

The solution to get both in sync, and to do so follow these steps:

1. Add your backend that has correct watch state and enable full import.
2. Add your new backend as metadata source only, when adding a backend you will get
   asked `Enable importing of metadata and play state from this backend?` answer with `N` for the new backend.

After that, do single backend export by using the following command:

```bash
$ docker exec -ti watchstate console state:export -vvif -s new_backend_name
```

Running this command will force full export your current database state to the selected backend. Once that done you can
turn on import from the new backend. by editing the backend setting
via `docker exec -ti watchstate console config:manage -s backend_name`

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

No, The tool is designed to work for single user. However, It's possible to run container for each user. You can also
use single container for all users, however it's not really easy refer
to [issue #136](https://github.com/arabcoders/watchstate/issues/136).

For `Jellyfin` and `Emby`, you can just generate new API tokens and link it to a user.

For Plex, You should use your admin token and by running the `config:add` command and selecting a user the tool will
attempt to generate a token for that user.

> [!Note]
> If the tool fails to generate an access token for the user, you can run the following command to generate the access
> token manually.

```bash
$ docker exec -ti console backend:users:list -s backend_name --with-tokens
```

----

### How do i migrate invited friends i.e. (external user) data from from plex to emby/jellyfin?

As this tool is designed to work with single user, You have to treat each invited friend as a separate user. what is
needed, you need to contact that friend of yours and ask him to give you a copy of
his [X-Plex-Token](https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/),
then create new container and add the backend with the token you got from your friend.

After that, add your other backends like emby/jellyfin using your regular API key. jellyfin/emby differentiate between
users by using the userId which
you should select at the start of the add process.

After that. run the `state:import -f -s [plex_server_name]` command to import the user watch state. After that, you can
run the `state:export -fi -s [emby/jellyfin_server_name]` to export the
watch state to the new backend.

You have to repeat these steps for each user you want to migrate their data off the plex server.

> [!IMPORTANT]
> YOU MUST always start with fresh data for **EACH USER**, otherwise unexpected things might happen.
> Make sure to delete docker-compose.yaml `./data` directory. to start fresh

----

### Does this tool require webhooks to work?

No, You can use the `task scheduler` or on `demand sync` if you want.

---

### I get tired of writing the whole command everytime is there an easy way run the commands?

Since there is no way to access the command interface outside the container, you can create small shell script to at
least omit part of command that you have to write for example, to create shortcut for docker command do the following:

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

This will force set the internal http client to use http v1 if it does not fix your problem, please open bug report
about it.

---

### Sync operations are failing due to request timeout?

If you want to increase the timeout for specific backend you can run the following command:

```bash
$ docker exec -ti watchstate console config:edit --key options.client.timeout --set 600 -s backend_name
```

where `600` is the number of secs before the timeout handler will kill the request.

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

### Which external db ids supported for Plex Media Server?

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
  id) [jellyfin](https://github.com/arabcoders/jf-ytdlp-info-reader-plugin) & [Emby](https://github.com/arabcoders/emby-ytdlp-info-reader-plugin). `(A yt-dlp info reader plugin)`.
* cmdb://(
  id) [jellyfin](https://github.com/arabcoders/jf-custom-metadata-db) & [Emby](https://github.com/arabcoders/emby-custom-metadata-db). `(User custom metadata database)`.

---

### Environment Variables

There are many ways to load the environment variables, However the recommended methods are:

* Via `docker-compose.yaml` file.
* Via `/config/config/.env` file. This file normally does not exist you have to created manually.

to see list of loaded environment variables run:

```shell
$ docker exec -ti watchstate console system:env
```

#### Tool specific environment variables.

These environment variables relates to the tool itself, you can load them via the recommended methods.

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

> [!IMPORTANT]
> for environment variables that has `{TASK}` tag, you **MUST** replace it with one
> of `IMPORT`, `EXPORT`, `PUSH`, `BACKUP`, `PRUNE`, `INDEXES`, `REQUESTS`. To see tasks active settings run

```bash
$ docker exec -ti watchstate console system:tasks
```

#### Container specific environment variables.

> [!IMPORTANT]
> These environment variables relates to the container itself, and must be added via the `docker-compose.yaml` file.

| Key              | Type    | Description                        | Default  |
|------------------|---------|------------------------------------|----------|
| WS_DISABLE_HTTP  | integer | Disable included `HTTP Server`.    | `0`      |
| WS_DISABLE_CRON  | integer | Disable included `Task Scheduler`. | `0`      |
| WS_DISABLE_CACHE | integer | Disable included `Cache Server`.   | `0`      |
| HTTP_PORT        | string  | Change the `HTTP` listen port.     | `"8080"` |

---

### How to add webhooks?

To add webhook for your backend the URL will be dependent on how you exposed webhook frontend, but typically it will be
like this:

Directly to container: `http://localhost:8080/?apikey=[WEBHOOK_TOKEN]`

Via reverse proxy : `https://watchstate.domain.example/?apikey=[WEBHOOK_TOKEN]`.

If your media backend support sending headers then remove query parameter `?apikey=[WEBHOOK_TOKEN]`, and add this header

```
x-apikey: [WEBHOOK_TOKEN]
```

where `[WEBHOOK_TOKEN]` Should match the backend `webhook.token` value. To see your webhook token for each backend run:

```bash
$ docker exec -ti watchstate console config:view webhook.token
```

If you see 'Not configured, or invalid key.' or empty value. run the following command

```bash
$ docker exec -ti watchstate console config:edit --regenerate-webhook-token -s backend_name 
```

-----

#### Emby (you need `Emby Premiere` to use webhooks).

Go to your Manage Emby Server > Server > Webhooks > (Click Add Webhook)

##### Webhook Url:

`http://localhost:8080/?apikey=[WEBHOOK_TOKEN]`

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

Click `Add Webhook`

-----

#### Plex (You need `Plex Pass` to use webhooks)

Go to your Plex Web UI > Settings > Your Account > Webhooks > (Click ADD WEBHOOK)

##### URL:

`http://localhost:8080/?apikey=[WEBHOOK_TOKEN]`

Click `Save Changes`

> [!IMPORTANT]
> If you use multiple plex servers and use the same PlexPass account for all of them, you have to unify the API key, by
> running the following command:

```bash
$ docker exec -ti watchstate console config:unify plex 
Plex global webhook API key is: [random_string]
```

The reason is due to the way plex handle webhooks, And to know which webhook request belong to which backend we have to
identify the backends.
The unify command will do the necessary adjustments to handle multiple plex servers setup. for more information run.

```bash
$ docker exec -ti watchstate console help config:unify 
```

> [!IMPORTANT]
> If you share your plex server with other users, i,e. `Home/managed users`, you have to enable match user id, otherwise
> their play state
> will end up changing your play state. Plex will still send their events. But with match user id they will be ignored.

-----

#### Jellyfin (Free)

go to your jellyfin dashboard > plugins > Catalog > install: Notifications > Webhook, restart your jellyfin. After that
go back again to dashboard > plugins > webhook. Add `Add Generic Destination`,

##### Webhook Name:

`Watchstate-Webhook`

##### Webhook Url:

`http://localhost:8080`

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

### Add Request Header

* Key: `x-apikey`
* Value: `[WEBHOOK_TOKEN]`

Click `save`

---

### What are the webhook limitations?

Those are some web hook limitations we discovered for the following media backends.

#### Plex

* Plex does not send webhooks events for "marked as played/unplayed" for all item types.
* Sometimes does not send events if you add more than one item at time.
* When you mark items as unwatched, Plex reset the date on the object.

#### Emby

* Emby does not send webhooks events for newly added items.
  ~~[See feature request](https://emby.media/community/index.php?/topic/97889-new-content-notification-webhook/)~~
  implemented in `4.7.9` still does not work as expected no metadata being sent when the item notification goes out.
* Emby webhook test event does not contain data. To test if your setup works, play something or do mark an item as
  played or unplayed you should see changes reflected in `docker exec -ti watchstate console db:list`.

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
your `docker-compose.yaml` file. For more information run help on `system:env` command as well as `system:tasks`
command.

---

### How to disable the included HTTP server and use external server?

Set this environment variable in your `docker-compose.yaml` file `WS_DISABLE_HTTP` with value of `1`. your external
server need to send correct fastcgi environment variables. Example caddy file

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

---

### How to disable the included cache server and use external cache server?

Set this environment variable in your `docker-compose.yaml` file `WS_DISABLE_CACHE` with value of `1`.
to use external redis server you need to alter the value of `WS_CACHE_URL` environment variable. the format for this
variable is `redis://host:port?password=auth&db=db_num`, for example to use redis from another container you could use
something like `redis://redis:6379?password=my_secert_password&db=8`. We only support `redis` at the moment.

Once that done, restart the container.

---

### There are weirdly named directories in my data path?

Unfortunately, That was due to a bug introduced in (2023-09-12 877a41a) and was fixed in (2023-09-19 a2f8c8a), if you
have happened to installation or update during this period, you will have those directories. To fix this issue, you
can simply delete those folders `%(tmpDir)` `%(path)` `{path}` `{tmpDir}`. I decided to not do it automatically to avoid
any data loss. you should check the directories to make sure they are empty. if not copy the directories to the correct
location and delete the empty directories.

---

### How to get WatchState working with YouTube content/library?

Due to the nature on how people name their youtube files i had to pick something specific for it to work cross supported
media agents. Please visit [this link](https://github.com/arabcoders/jf-ytdlp-info-reader-plugin#usage) to know how to
name your files. Please be aware these plugins and scanners `REQUIRE`
that you have a `yt-dlp` `.info.json` files named exactly as your media file. For example, if you
have `20231030 my awesome youtube video [youtube-RandomString].mkv`
you should have `20231030 my awesome youtube video [youtube-RandomString].info.json` in the same directory. In the
future, I plan to make `.info.json` optional However at the moment the file is required for emby/jellyfin plugin to
work.

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

If you having problem adding a backend to `WatchState`, it most likely network related problem, Where the container
isn't able to communicate with the media backend. Thus, you will get errors. To make sure the container is able to
communicate with the media backend, you can run the following command and check the output.

If the command fails for any reason, then you most likely have network related problem.

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

### I keep receiving this warning in log `INFO: Ignoring [xxx] Episode range, and treating it as single episode. Backend says it covers [00-00]`?

We recently added guard clause to prevent backends from sending possibly invalid episode ranges, as such if you see
this,
this likely means your backend mis-identified episodes range. By default, we allow an episode to cover up to 4 episodes.

If this is not enough for your library content. fear not we have you covered you can increase the limit by running the
following command:

```bash 
$ docker exec -ti watchstate console config:edit --key options.MAX_EPISODE_RANGE --set 10 -s backend_name 
```

where `10` is the new limit. You can set it to any number you want. However, Please do inspect the reported records as
it most likely you have incorrect metadata in your library.

In the future, we plan to reduce the log level to `DEBUG` instead of `INFO`. However, for now, we will keep it as is.
to inform you about the issue.

---

### I Keep receiving [jellyfin] item [id: name] is marked as [played] vs local state [unplayed], However due to the remote item date [date] being older than the last backend sync date [date]. it was not considered as valid state.

Sadly, this is due to bug in jellyfin, where it marks the item as played without updating the LastPlayedDate, and as such, watchstate doesn't really know the item has changed since last sync.  
Unfortunately, there is no way to fix this issue from our side for the `state:import` task as it working as intended.

However, we managed to somewhat implement a workaround for this issue using the webhooks feature as temporary fix. Until jellyfin devs fixes the issue. Please take look at
the webhooks section to enable it.

---

### Bare metal installation

#### Requirements

* [PHP](http://https://www.php.net/downloads.php) 8.3+ with fpm installed. with the following extensions `pdo`, `pdo-sqlite`, `mbstring`, `json`, `ctype`, `curl`, `redis`, `sodium` and `simplexml`
* [Composer](https://getcomposer.org/download/) for dependency management.
* [Redis-server](https://redis.io/) for caching or a compatible implementation that works with [php-redis](https://github.com/phpredis/phpredis).
* [Caddy](https://caddyserver.com/) for frontend handling. However, you can use whatever you like. As long as it has support for fastcgi.

#### Installation

1. Clone the repository.

```bash
$ git clone https://github.com/arabcoders/watchstate.git
```

2. Install the dependencies.

```bash
$ cd watchstate
$ composer install --no-dev 
```

3. Create `.env` inside `./var/config/` if you need to change any of the environment variables refer to[Tool specific environment variables](#tool-specific-environment-variables) for more information. For example,
   if you `redis` server is not on the same server or requires a password you can add the following to the `.env` file.

```dotenv
WS_CACHE_URL="redis://127.0.0.1:6379?password=your_password"
```

4. link the app to the frontend proxy. For caddy, you can use the following configuration.

> [!NOTE]
> frontend server is only needed for `webhooks` and the upcoming `API` & `Web UI`.

```Caddyfile
http://watchstate.example.org {
    # Change "[user]" to your user name.
    root * /home/[user]/watchstate/public
        
    # Change "unix//var/run/php/php8.3-fpm.sock" to your php-fpm socket.
    php_fastcgi unix//var/run/php/php8.3-fpm.sock
}
```

5. To access the console you can run the following command.

```bash
$ /home/[user]/watchstate/bin/console help
```

6. To make the tasks scheduler work you need to add the following to your crontab.

```crontab
* * * * * /home/[user]/watchstate/bin/console system:tasks --run --save-log
```

For more information, please refer to the [Dockerfile](/Dockerfile). On how we do things to get the tool running.
