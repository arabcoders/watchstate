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
The help document attach to each command is more up to date and precise. So, please read it.

----

### Q: Is there support for Multi-user setup?

No, The tool is designed to work for single user. However, It's possible to run container for each user. You can also
use single container for all users, however it's not really easy refer
to [issue #136](https://github.com/ArabCoders/watchstate/issues/136).

**Note**: for Plex home/managed users run the following command to extract each managed user access token.

```bash
$ docker exec -ti console backend:users:list --with-tokens -- [BACKEND_NAME]
```

For Jellyfin/Emby, you can just generate new API tokens.

----

### Q: Can this tool run without container?

Yes, if you have the required PHP version and the needed extensions. to run this tool you need the following `php8.1`,
`php8.1-fpm` and `redis-server` and the following extensions `php8.1-pdo`, `php8.1-mbstring`, `php8.1-ctype`
, `php8.1-curl`,`php8.1-sqlite3`, `php8.1-redis`, and [composer](https://getcomposer.org/). once you have the required
runtime dependencies, for first time run:

```bash
cd ~/watchstate
composer install --optimize-autoloader
```

after that you can start using the tool via this command.

```bash
$ php console
```

The app should save your data into `./var` directory. If you want to change the directory you can export the environment
variable `WS_DATA_PATH` for console and browser. you can add a file called `.env` in main tool directory with the
environment variables. take look at the files inside `container/files` directory to know how to run the scheduled tasks
and if you want a webhook support you would need a frontend proxy for `php8.1-fpm` like nginx, caddy or apache.

---

### Q: Does this tool require webhooks to work?

No, You can use the task scheduler or on demand sync if you want.

---

### Q: I get tired of writing the whole command everytime is there an easy way run the commands?

Since there is no way to access the command interface outside the container, you can create small shell script to at
least omit part of command that you have to write for example, to create shortcut for docker command do the following:

```bash
$ echo 'docker exec -ti watchstate console "$@"' > ws
$ chmod +x ws
```

after that you can do `./ws command` for example, `./ws db:list`

---

### Q: I am using media backends hosted behind HTTPS, and see errors related to HTTP/2?

Sometimes there are problems related to HTTP/2, so before reporting bug please try running the following command:

```bash
$ docker exec -ti watchstate console config:edit --key options.client.http_version --set 1.0 -- [BACKEND_NAME] 
```

This will force set the internal http client to use http v1 if it does not fix your problem, please open bug report
about it.

---

### Q: Sync operations are failing due to request timeout?

If you want to increase the timeout for specific backend you can run the following command:

```bash
$ docker exec -ti watchstate console config:edit --key options.client.timeout --set 600 -- [BACKEND_NAME] 
```

where `600` is the number of secs before the timeout handler will kill the request.

---

### Q: Which external db ids supported for Plex Media Server?

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

### Q: Which external db ids supported for Jellyfin and Emby?

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

| Key                      | Type    | Description                                                                   | Default            |
|--------------------------|---------|-------------------------------------------------------------------------------|--------------------|
| WS_DATA_PATH             | string  | Where to store main data. (config, db).                                       | `${BASE_PATH}/var` |
| WS_TMP_DIR               | string  | Where to store temp data. (logs, cache)                                       | `${WS_DATA_PATH}`  |
| WS_TZ                    | string  | Set timezone.                                                                 | `UTC`              |
| WS_CRON_{TASK}           | bool    | Enable {task} task. Value casted to bool.                                     | `false`            |
| WS_CRON_{TASK}_AT        | string  | When to run {task} task. Valid Cron Expression Expected.                      | `*/1 * * * *`      |
| WS_CRON_{TASK}_ARGS      | string  | Flags to pass to the {task} command.                                          | `-v`               |
| WS_LOGS_CONTEXT          | bool    | Add context to console output messages.                                       | `false`            |
| WS_LOGGER_FILE_ENABLE    | bool    | Save logs to file.                                                            | `true`             |
| WS_LOGGER_FILE_LEVEL     | string  | File Logger Level.                                                            | `ERROR`            |
| WS_WEBHOOK_DEBUG         | bool    | If enabled, allow dumping request/webhook using `rdump` & `wdump` parameters. | `false`            |
| WS_EPISODES_DISABLE_GUID | bool    | Disable external id parsing for episodes and rely on relative ids.            | `true`             |
| WS_TRUST_PROXY           | bool    | Trust `WS_TRUST_HEADER` ip. Value casted to bool.                             | `false`            |
| WS_TRUST_HEADER          | string  | Which header contain user true IP.                                            | `X-Forwarded-For`  |
| WS_LIBRARY_SEGMENT       | integer | Paginate backend library items request. Per request get total X number.       | `8000`             |

**Note**: for environment variables that has `{TASK}` tag, you **MUST** replace it with one
of `IMPORT`, `EXPORT`, `PUSH`, `BACKUP`, `PRUNE`, `INDEXES`. To see tasks active settings run

```bash
$ docker exec -ti watchstate console system:tasks
```

#### Container specific environment variables.

These environment variables relates to the container itself, and it's recommended to load them
via the `docker-compose.yaml` file.

| Key              | Type    | Description                                  | Default |
|------------------|---------|----------------------------------------------|---------|
| WS_DISABLE_HTTP  | integer | Disable included `HTTP Server`.              | `0`     |
| WS_DISABLE_CRON  | integer | Disable included `Task Scheduler`.           | `0`     |
| WS_DISABLE_CACHE | integer | Disable included `Cache Server`.             | `0`     |

---

### Q: How to add webhooks?

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

* Playback events
* User events

##### Limit user events to:

* Select your user.

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

### Q: What are the webhook limitations?

Those are some Webhook limitations we discovered for the following media backends.

#### Plex

* Plex does not send webhooks events for "marked as played/unplayed".
* Sometimes does not send events if you add more than one item at time.
* If you have multi-user setup, Plex will still report the admin account user id as `1`.
* When you mark items as unwatched, Plex reset the date on the object.

#### Emby

* Emby does not send webhooks events for newly added
  items. [See feature request](https://emby.media/community/index.php?/topic/97889-new-content-notification-webhook/).
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

### Q: Sometimes newly added episodes or movies don't make it to webhook endpoint?

As stated in webhook limitation section sometimes media backends don't make it easy to receive those events, as such, to
complement webhooks, you should enable import/export tasks by settings their respective environment variables in
your `docker-compose.yaml` file. For more information run help on `system:env` command as well as `system:tasks`
command.
