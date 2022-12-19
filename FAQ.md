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
**The help document attached to each command is more up to date and precise. So, please read it.**

----

### How to turn on scheduled tasks for import/export?

Scheduled tasks are configured via specific environment variables refers to
[environment variables](#environment-variables) section, to turn on the import/export tasks add the following
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

**Note**: All scheduled tasks are configured via the same variables style, refer
to [Tool specific environment variables](#tool-specific-environment-variables) for more information.

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
$ docker exec -ti watchstate console state:export -vvf -s new_backend_name
```

Running this command will force full export your current database state to the selected backend. Once that done you can
turn on import from the new backend. by editing the backend setting
via `docker exec -ti watchstate console config:manage backend_name`

----

### Is there support for Multi-user setup?

No, The tool is designed to work for single user. However, It's possible to run container for each user. You can also
use single container for all users, however it's not really easy refer
to [issue #136](https://github.com/ArabCoders/watchstate/issues/136).

**Note**: for Plex home/managed users run the following command to extract each managed user access token.

```bash
$ docker exec -ti console backend:users:list --with-tokens -- [BACKEND_NAME]
```

For Jellyfin/Emby, you can just generate new API tokens. and associate them with users.

----

### Does this tool require webhooks to work?

No, You can use the task scheduler or on demand sync "manually running import and export" if you want.

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
$ docker exec -ti watchstate console config:edit --key options.client.http_version --set 1.0 -- [BACKEND_NAME] 
```

This will force set the internal http client to use http v1 if it does not fix your problem, please open bug report
about it.

---

### Sync operations are failing due to request timeout?

If you want to increase the timeout for specific backend you can run the following command:

```bash
$ docker exec -ti watchstate console config:edit --key options.client.timeout --set 600 -- [BACKEND_NAME] 
```

where `600` is the number of secs before the timeout handler will kill the request.

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

---

### Which external db ids supported for Jellyfin and Emby?

* imdb://(id)
* tvdb://(id)
* tmdb://(id)
* tvmaze://(id)
* tvrage://(id)
* anidb://(id)

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

| Key                      | Type    | Description                                                             | Default            |
|--------------------------|---------|-------------------------------------------------------------------------|--------------------|
| WS_DATA_PATH             | string  | Where to store main data. (config, db).                                 | `${BASE_PATH}/var` |
| WS_TMP_DIR               | string  | Where to store temp data. (logs, cache)                                 | `${WS_DATA_PATH}`  |
| WS_TZ                    | string  | Set timezone.                                                           | `UTC`              |
| WS_CRON_{TASK}           | bool    | Enable {task} task. Value casted to bool.                               | `false`            |
| WS_CRON_{TASK}_AT        | string  | When to run {task} task. Valid Cron Expression Expected.                | `*/1 * * * *`      |
| WS_CRON_{TASK}_ARGS      | string  | Flags to pass to the {task} command.                                    | `-v`               |
| WS_LOGS_CONTEXT          | bool    | Add context to console output messages.                                 | `false`            |
| WS_LOGGER_FILE_ENABLE    | bool    | Save logs to file.                                                      | `true`             |
| WS_LOGGER_FILE_LEVEL     | string  | File Logger Level.                                                      | `ERROR`            |
| WS_WEBHOOK_DUMP_REQUEST  | bool    | If enabled, will dump all received requests.                            | `false`            |
| WS_EPISODES_DISABLE_GUID | bool    | Disable external id parsing for episodes and rely on relative ids.      | `true`             |
| WS_TRUST_PROXY           | bool    | Trust `WS_TRUST_HEADER` ip. Value casted to bool.                       | `false`            |
| WS_TRUST_HEADER          | string  | Which header contain user true IP.                                      | `X-Forwarded-For`  |
| WS_LIBRARY_SEGMENT       | integer | Paginate backend library items request. Per request get total X number. | `1000`             |

**Note**: for environment variables that has `{TASK}` tag, you **MUST** replace it with one
of `IMPORT`, `EXPORT`, `PUSH`, `BACKUP`, `PRUNE`, `INDEXES`, `REQUESTS`. To see tasks active settings run

```bash
$ docker exec -ti watchstate console system:tasks
```

#### Container specific environment variables.

These environment variables relates to the container itself, and it's recommended to load them
via the `docker-compose.yaml` file.

| Key              | Type    | Description                        | Default |
|------------------|---------|------------------------------------|---------|
| WS_DISABLE_HTTP  | integer | Disable included `HTTP Server`.    | `0`     |
| WS_DISABLE_CRON  | integer | Disable included `Task Scheduler`. | `0`     |
| WS_DISABLE_CACHE | integer | Disable included `Cache Server`.   | `0`     |

---

### How to add webhooks?

To add webhook for your backend the URL will be dependent on how you exposed webhook frontend, but typically it will be
like this:

Directly to container: `http://localhost:8080/?apikey=[WEBHOOK_TOKEN]`

Via reverse proxy : `https://watchstate.domain.example/?apikey=[WEBHOOK_TOKEN]`.

If your media backend support sending headers then remove query parameter `?apikey=[WEBHOOK_TOKEN]`, and add this header

```
X-apikey: [WEBHOOK_TOKEN]
```

where `[WEBHOOK_TOKEN]` Should match the backend `webhook.token` value. To see your webhook token for each backend run:

```bash
$ docker exec -ti watchstate console config:view webhook.token
```

If you see 'Not configured, or invalid key.' or empty value. run the following command

```bash
$ docker exec -ti watchstate console config:edit --regenerate-webhook-token -- [BACKEND_NAME] 
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

**Note**: If you use multiple plex servers and use the same PlexPass account for all of them, you have to unify the API
key, by
running the following command:

```bash
$ docker exec -ti watchstate console config:unify plex 
Plex global webhook API key is: [random_string]
```

The reason is due to the way plex handle webhooks, And to know which webhook request belong to which backend we have to
identify the backends, The unify command will do the necessary adjustments to handle multiple plex servers setup. for
more
information run.

```bash
$ docker exec -ti watchstate console help config:unify 
```

**Note**: If you share your plex server with other users, i,e. `Home/managed users`, you have to enable match user id,
otherwise
their play state will end up changing your play state. Plex will still send their events. But with match user id they
will be ignored.

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

Key: `x-apikey`

Value: `[WEBHOOK_TOKEN]`

Click `save`

---

### What are the webhook limitations?

Those are some Webhook limitations we discovered for the following media backends.

#### Plex

* Plex does not send webhooks events for "marked as played/unplayed".
* Sometimes does not send events if you add more than one item at time.
* If you have multi-user setup, Plex will still report the admin account user id as `1`.
* When you mark items as unwatched, Plex reset the date on the object.

#### Emby

* Emby does not send webhooks events for newly added
  items.
  ~~[See feature request](https://emby.media/community/index.php?/topic/97889-new-content-notification-webhook/)~~
  implemented in `4.7.9` still does not work as expected no metadata being sent when the item notification goes out.
* Emby webhook test event does not contain data. To test if your setup works, play something or do mark an item as
  played or unplayed you should see changes reflected in `docker exec -ti watchstate console db:list`.

#### Jellyfin

* If you don't select a user id, the plugin will send `itemAdd` event without user data, and will fail the check if
  you happen to enable `webhook.match.user` for jellyfin.
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


