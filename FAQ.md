# FAQ

### Q: How to update play state for newly added media backend without overwriting my current play state?

Add the backend and when asked, answer `no` for allow import. when you finish, then run the following command:

```bash
$ docker exec -ti watchstate console state:export -vv --ignore-date --force-full --servers-filter [SERVER_NAME]
```

this command will force export your current local play state to the selected media backend. If the operation is
successful you can then enable the import feature if you want.

---

### Q: Is there support for Multi-user setup?

No, The tool is designed to work for single user. However, It's possible to run container for each user.

Note: for Plex managed users run the following command to extract each managed user access token.

```bash
$ docker exec -ti console backend:users:list --with-tokens -- [BACKEND_NAME]
```

For Jellyfin/Emby, you can use same api token and just replace the user id.

---

### Q: Sometimes newly added episodes or movies don't make it to webhook endpoint?

As stated in webhook limitation section sometimes media backends don't make it easy to receive those events, as such, to
complement webhooks, its good idea enable the scheduled tasks of import/export and let them run once in a while to
remap the data.

----

### Q: How to get library id?

Run the following command to get list of backend libraries.

```bash
$ docker exec -ti watchstate console backend:library:list [SERVER_NAME] 
```

it should display something like

| Id  | Title       | Type   | Ignored | Supported |
|-----|-------------|--------|---------|-----------|
| 2   | Movies      | movie  | No      | Yes       | 
| 1   | shows       | show   | No      | Yes       | 
| 17  | Audio Books | artist | Yes     | No        |

The id column refers to backend side id.

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

### Q: I keep on seeing "Ignoring 'XXX'. No valid/supported external ids." in logs?

This most likely means that the item is not matched in your media backend

* jellyfin/emby: Edit metadata and make sure there are external ids listed in the metadata.
* For plex click the (...), and click Fix match. after that refresh metadata.

For episodes, we support both external ids like movies and relative unique ids, To make episodes sync work at least one
of the following conditions has to be met:

* The episode should have external ids.
* The series should have external ids, to make relative unique id works.

---

### Q: Does this tool require webhooks to work?

No, You can use the task scheduler or on demand sync if you want. However, we recommend the webhook method as it's the
most efficient method to update play state.

--- 

### Q: When i use jellyfin, i sometimes see double events?

This most likely a bug in the plugin [jf-webhook #113](https://github.com/jellyfin/jellyfin-plugin-webhook/issues/113),
Just reload the page make sure there is only one added watchstate endpoint.

---

### Q: How to see my data?

```bash
$ docker exec -ti watchstate console db:list
```

This command will give you access to see the database entries. by default, it will show the last 20 events, however you
can run the same command with `[-h, --help]` to see more options to extend the list or to filter the results.

---

### Q: How to ignore specific libraries from being processed?

Run the following command:

```bash
$ docker exec -ti watchstate console servers:edit --key options.ignore --set 'id1,id2,id3' -- [SERVER_NAME] 
```

where `id1,id2,id3` refers to backend library id

If you ignored a library by mistake you can run the same command again and omit the id, or you can just delete the key
entirely by running the following command

```bash
$ docker exec -ti watchstate console servers:edit --delete --key options.ignore -- [SERVER_NAME] 
```

##### Notice

While this feature works for manual/task scheduler for all supported backends, Jellyfin/Emby does not report library id
on webhook event. So, this feature will not work for them in webhook context and the items will be processed.

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
$ docker exec -ti watchstate console servers:edit --key options.client.http_version --set 1.0 -- [SERVER_NAME] 
```

This will force set the internal http client to use http v1 if it does not fix your problem, please open bug report
about it.

---

### Q: Sync operations are failing due to request timeout?

If you want to increase the timeout for specific backend you can run the following command:

```bash
$ docker exec -ti watchstate console servers:edit --key options.client.timeout --set 600 -- [SERVER_NAME] 
```

where `600` is the number of secs before the timeout handler kill the request.

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

### Q: How to the metadata about specific item?

Use the following command:

```bash
$ docker exec -ti console server backend:search:id [BACKEND_NAME] [ITEM_ID]
```

where `[ITEM_ID]` refers to backend item id

### Optional flags

* `[-o, --output]` Set output style, it can be `yaml`, `json` or `table`. Default to `table`.
* `[--include-raw-response]` will include backend response in main response body with `raw` key.

---

### Q: How to look for mis-identified items?

Use the `backend:library:mismatch` command. For example,

```bash
$ docker exec -ti console server backend:library:mismatch [BACKEND_NAME] [LIBRARY_ID]
```

where `[LIBRARY_ID]` refers to backend library id

### Optional flags

* `[-p, --percentage]` How much in percentage the title has to be in path to be marked as matched item. Default
  to `50.0%`.
* `[-o, --output]` Set output mode, it can be `yaml`, `json` or `table`. Default to `table`.
* `[-m, --method]` Which algorithm to use, it can be `similarity`, or `levenshtein`. Default to `similarity`.
* `[--include-raw-response]` Will include backend response in main response body with `raw` key.

---

### Q: Is it possible to look for unmatched items?

Use the `backend:library:unmatched` command. For example,

```bash
$ docker exec -ti console server backend:library:unmatched [BACKEND_NAME] [LIBRARY_ID]
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

A Mapper is class that have list of all external ids that point to record in storage. think of it as dictionary that
reference to specific item.

#### Memory Mapper (Default)

Memory mapper is the Default mapper, it uses memory to load the entire state table into memory, which in turn leads
to better performance. you shouldn't use the other mapper unless you are running into memory problems.

#### Direct Mapper

Direct mapper is suitable for more memory constraint systems, it just loads the external ids mapping into memory,
however it does not keep the state into memory, thus uses less memory compared to `MemoryMapper`, But the trade-off is
it's slower than `MemoryMapper`.

#### Comparison between mappers

| Operation      | Memory Mapper | Direct Mapper   |
|----------------|---------------|-----------------|
| Memory Usage   | (✗) Higher    | (✓) Lower       |
| Matching Speed | (✓) Faster    | (✗) Slower      |
| DB Operations  | (✓) Faster    | (✗) Slower      |
