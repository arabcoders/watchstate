# FAQ

### Q: How to sync play state to a new backend without overwriting current play state?

Add the backend and when asked, answer `no` for `Enable Importing metadata and play state from this backend?`. when you
finish, then run the following command:

```bash
$ docker exec -ti watchstate console state:export -vvfis [BACKEND_NAME]
```

this command will force export your current local play state to the selected backend. If the operation is
successful you can then enable the full import from the backend.

---

### Q: Is there support for Multi-user setup?

No, The tool is designed to work for single user. However, It's possible to run container for each user. You can also
use single container for all users, however it's not really easy refer
to [issue #136](https://github.com/ArabCoders/watchstate/issues/136).

#### Note

Note: for Plex home/managed users run the following command to extract each managed user access token.

```bash
$ docker exec -ti console backend:users:list --with-tokens -- [BACKEND_NAME]
```

For Jellyfin/Emby, you can just generate new API tokens.

----

### Q: How to get library id?

Run the following command to get list of backend libraries.

```bash
$ docker exec -ti watchstate console backend:library:list [BACKEND_NAME] 
```

it should display something like

| Id  | Title       | Type   | Ignored | Supported |
|-----|-------------|--------|---------|-----------|
| 1   | Movies      | Movie  | No      | Yes       | 
| 2   | TV Shows    | Show   | No      | Yes       | 
| 3   | Audio Books | Artist | Yes     | No        |

The id column refers to backend side `library id`.

---

### Q: Can this tool run without docker?

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
environment variables. take look at the files inside `docker/files` directory to know how to run the scheduled tasks and
if you want a webhook support you would need a frontend proxy for `php8.1-fpm` like nginx, caddy or apache.

---

### Q: Some records keep getting updated even when the state did not change?

This most likely means your media backends have conflicting external ids for the reported items, and thus triggering an
update as the tool see different external ids on each event from the backends. In our testing we noticed that at least
few hundred records in thetvdb that get reported by plex have incorrect imdb external id, which in turns conflicts
sometimes with jellyfin/emby there is nothing we can do beside have the problematic records reported to thetvdb site
mods to fix their db entries.

----

### Q: What does "No valid/supported external ids." in logs means ?

This most likely means that the item is not matched in your media backend

* jellyfin/emby: Edit metadata and make sure there are external ids listed in the metadata.
* For plex click the (...), and click Fix match. after that refresh metadata.

For episodes, we support both external ids like movies and relative unique ids, To make episodes sync work at least one
of the following conditions has to be true:

* The episode should have external ids.
* The parent show should have external ids, to make relative unique id works.

---

### Q: Does this tool require webhooks to work?

No, You can use the task scheduler or on demand sync if you want.

---

### Q: How to see my data?

```bash
$ docker exec -ti watchstate console db:list
```

This command will give you access to see the database entries. by default, it will show the last 20 items, however you
can run the same command with `[-h, --help]` to see more options to extend the list or to filter the results.

---

### Q: How to ignore specific libraries from being processed?

Run the following command:

```bash
$ docker exec -ti watchstate console config:edit --key options.ignore --set 'id1,id2,id3' -- [BACKEND_NAME] 
```

where `id1,id2,id3` refers to backend library id

If you ignored a library by mistake you can run the same command again and omit the id, or you can just delete the key
entirely by running the following command

```bash
$ docker exec -ti watchstate console config:edit --delete --key options.ignore -- [BACKEND_NAME] 
```

##### Note

While this feature works for manual/task scheduler for all supported backends, jellyfin/emby does not report library id
via webhook events. So, this feature will not work for them in webhook context and the items will be processed.

---

### Q: I get tired of writing the whole command everytime is there an easy way run the commands?

Since there is no way to access the command interface outside docker, you can create small shell script to at least omit
part of command that you have to write for example, to create shortcut for docker command do the following:

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

### Q: How to perform search on backend libraries?

Use the following command:

```bash
$ docker exec -ti console backend:search:query [BACKEND_NAME] '[QUERY_STRING]'
```

where `[QUERY_STRING]` is the keyword that you want to search for

### Optional flags

* `[-l, --limit]` To limit returned results. Default to `25`.
* `[-o, --output]` Set output style, it can be `yaml`, `json` or `table`. Default to `table`.
* `[--include-raw-response]` will include backend response in main response body with `raw` key.

---

### Q: How to get metadata about specific item id?

Use the following command:

```bash
$ docker exec -ti watchstate console backend:search:id [BACKEND_NAME] [BACKEND_ITEM_ID]
```

where `[BACKEND_ITEM_ID]` refers to backend item id

### Optional flags

* `[-o, --output]` Set output style, it can be `yaml`, `json` or `table`. Default to `table`.
* `[--include-raw-response]` will include backend response in main response body with `raw` key.

---

### Q: How to look for mis-identified items?

Use the `backend:library:mismatch` command. For example,

```bash
$ docker exec -ti watchstate console backend:library:mismatch [BACKEND_NAME] [LIBRARY_ID]
```

where `[LIBRARY_ID]` refers to backend library id

### Optional flags

* `[-p, --percentage]` How much in percentage the title has to be in path to be marked as matched item. Default
  to `50.0%`.
* `[-o, --output]` Set output mode, it can be `yaml`, `json` or `table`. Default to `table`.
* `[-m, --method]` Which method to use, it can be `similarity`, or `levenshtein`. Default to `similarity`.
* `[--include-raw-response]` Will include backend response in main response body with `raw` key.

---

### Q: How to look for unmatched items?

Use the `backend:library:unmatched` command. For example,

```bash
$ docker exec -ti watchstate console backend:library:unmatched [BACKEND_NAME] [LIBRARY_ID]
```

where `[LIBRARY_ID]` refers to backend library id

### Optional flags

* `[-o, --output]` Set output mode, it can be `yaml`, `json` or `table`. Defaults to `table`.
* `[--show-all]` Will show all library items regardless of the match status.
* `[--include-raw-response]` Will include backend response in main response body with `raw` key.

---

### Q: Which external ids supported for Plex?

* tvdb://(id) `New Plex Agent`
* imdb://(id) `New Plex Agent`
* tmdb://(id) `New Plex Agent`
* com.plexapp.agents.imdb://(id)?lang=en `(Lagecy plex agent)`
* com.plexapp.agents.tmdb://(id)?lang=en `(Lagecy plex agent)`
* com.plexapp.agents.themoviedb://(id)?lang=en `(Lagecy plex agent)`
* com.plexapp.agents.thetvdb://(seriesId)?lang=en `(Lagecy plex agent)`
* com.plexapp.agents.xbmcnfo://(id)?lang=en `(XBMC NFO parser agent)`
* com.plexapp.agents.xbmcnfotv://(id)?lang=en `(XBMC NFO parser agent for tv)`
* com.plexapp.agents.hama://(db)\d?-(id)?lang=en `(hama agent is multi db source agent)`

---

### Q: Which external ids supported for Jellyfin/Emby?

* imdb://(id)
* tvdb://(id)
* tmdb://(id)
* tvmaze://(id)
* tvrage://(id)
* anidb://(id)

---

### Q: What does mapper mean?

A Mapper is class that have list of all external ids that point to record in database. think of it as dictionary that
reference to specific item.

#### Memory Mapper (Default)

Memory mapper is the Default mapper, it uses memory to load the entire state table into memory, which in turn leads
to better performance. you shouldn't use the other mapper unless you are running into memory problems.

#### Direct Mapper

Direct mapper is suitable for more memory constraint systems, it just loads the external ids mapping into memory,
however it does not keep the state in memory, thus uses less memory compared to `MemoryMapper`, But the trade-off is
it's slower than `MemoryMapper`.

#### Comparison between mappers

| Operation      | Memory Mapper | Direct Mapper   |
|----------------|---------------|-----------------|
| Memory Usage   | (✗) Higher    | (✓) Lower       |
| Matching Speed | (✓) Faster    | (✗) Slower      |
| DB Operations  | (✓) Faster    | (✗) Slower      |

---

### Q: What environment variables supported?

| Key                      | Type   | Description                                                                   | Default            |
|--------------------------|--------|-------------------------------------------------------------------------------|--------------------|
| WS_DATA_PATH             | string | Where to store main data. (config, db).                                       | `${BASE_PATH}/var` |
| WS_TMP_DIR               | string | Where to store temp data. (logs, cache)                                       | `${WS_DATA_PATH}`  |
| WS_TZ                    | string | Set timezone.                                                                 | `UTC`              |
| WS_CRON_{TASK}           | bool   | Enable {task} task. Value casted to bool.                                     | `false`            |
| WS_CRON_{TASK}_AT        | string | When to run {task} task. Valid Cron Expression Expected.                      | `*/1 * * * *`      |
| WS_CRON_{TASK}_ARGS      | string | Flags to pass to the {task} command.                                          | `-v`               |
| WS_LOGS_CONTEXT          | bool   | Add context to console output messages.                                       | `false`            |
| WS_LOGGER_FILE_ENABLE    | bool   | Save logs to file.                                                            | `true`             |
| WS_LOGGER_FILE_LEVEL     | string | File Logger Level.                                                            | `ERROR`            |
| WS_WEBHOOK_DEBUG         | bool   | If enabled, allow dumping request/webhook using `rdump` & `wdump` parameters. | `false`            |
| WS_EPISODES_DISABLE_GUID | bool   | Disable external id parsing for episodes and rely on relative ids.            | `true`             |

Note for environment variables that has `{TASK}` you should replace it with one
of `IMPORT`, `EXPORT`, `PUSH`, `BACKUP`, `PRUNE`, `INDEXES`. To see tasks active settings run

```bash
$ docker exec -ti watchstate console system:tasks
```

#### Container specific environment variables.

| Key              | Type    | Description                                                          | Default |
|------------------|---------|----------------------------------------------------------------------|---------|
| WS_DISABLE_CHOWN | integer | Do not change ownership for needed directories inside the container. | `0`     |
| WS_DISABLE_HTTP  | integer | Disable included HTTP Server.                                        | `0`     |
| WS_DISABLE_CRON  | integer | Disable included Task Scheduler.                                     | `0`     |
| WS_DISABLE_CACHE | integer | Disable included Cache Server.                                       | `0`     |
| WS_UID           | integer | Set container user id.                                               | `1000`  |
| WS_GID           | integer | Set container group id.                                              | `1000`  |

---

### Q: How to add webhooks?

To add webhook for your backend the URL will be dependent on how you exposed webhook frontend, but typically it will be
like this:

Directly to container: `http://localhost:8081/?apikey=[WEBHOOK_TOKEN]`

Via reverse proxy : `https://watchstate.domain.example/?apikey=[WEBHOOK_TOKEN]`.

If your media backend support sending headers then remove query parameter `?apikey=[WEBHOOK_TOKEN]`, and add this header

```http request
X-apikey: [WEBHOOK_TOKEN]
```

where `[WEBHOOK_TOKEN]` Should match the backend `webhook.token` value. To see your backends webhook tokens run:

```bash
$ docker exec -ti watchstate console config:view webhook.token
```

If you see 'Not configured, or invalid key.' or empty value. run the following command

```bash
$ docker exec -ti watchstate console config:edit --regenerate-webhook-token -- [BACKEND_NAME] 
```

#### Emby (you need "Emby Premiere" to use webhooks).

Go to your Manage Emby Server > Server > Webhooks > (Click Add Webhook)

##### Webhook Url:

`http://localhost:8081/?apikey=[WEBHOOK_TOKEN]`

##### Webhook Events

Select the following events

* Playback events
* User events

Click `Add Webhook`

#### Plex (you need "PlexPass" to use webhooks)

Go to your plex Web UI > Settings > Your Account > Webhooks > (Click ADD WEBHOOK)

##### URL:

`http://localhost:8081/?apikey=[WEBHOOK_TOKEN]`

Click `Save Changes`

#### Note:

If you have multiple plex backends and use the same PlexPass account for all of them, you have to unify the API key, by
running the following command:

```bash
$ docker exec -ti watchstate console config:unify plex 
Plex global webhook API key is: [random_string]
```

The reason is due to the way plex handle webhooks, And to know which webhook request belong to which backend we have to
identify the backends, The unify command will do the necessary adjustments to handle multi plex backend setup. for more
information run.

```bash
$ docker exec -ti watchstate console help config:unify 
```

#### Jellyfin (Free)

go to your jellyfin dashboard > plugins > Catalog > install: Notifications > Webhook, restart your jellyfin. After that
go back again to dashboard > plugins > webhook. Add `Add Generic Destination`,

##### Webhook Name:

Choose whatever name you want. For example, `Watchstate-Webhook`

##### Webhook Url:

`http://localhost:8081`

##### Notification Type:

Select the following events

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

* If you don't select a user id, the Plugin will send `itemAdd` event without user data, and will fail the check if
  you happen to enable `webhook.match.user` for jellyfin.
* Sometimes jellyfin will fire webhook `itemAdd` event without the item being matched.
* Even if you select user id, sometimes `itemAdd` event will fire without user data.

---

### Q: Sometimes newly added episodes or movies don't make it to webhook endpoint?

As stated in webhook limitation section sometimes media backends don't make it easy to receive those events, as such, to
complement webhooks, you should enable import/export tasks by settings their respective environment variables in
your `docker-compose.yaml` file.
