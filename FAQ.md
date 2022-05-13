# FAQ

### Q: How to update play state for newly added media backend without overwriting my current play state?

First, add the media backend and when asked, answer `n` for allow import from this server. when you finish, run the
following
command:

```bash
$ docker exec -ti watchstate console state:export -vvr --ignore-date --force-full --servers-filter [SERVER_NAME]
```

this command will force export your current local play state to the selected media backend. If the operation is
successful you can then enable the import feature if you want.

---

### Q: Is there support for Multi-user setup?

No, The tool is designed as to work for single user. However, It's possible to run container for each user.

Note: for Plex managed users run the following command to extract each managed user token.

```bash
$ docker exec -ti console servers:remote --list-users-with-tokens -- my_plex_1
```

For jellyfin/emby, you can use same api-token and just replace the userId.

---

### Q: Sometimes newly added episodes or movies don't make it to webhook server?

As stated in webhook limitation section sometimes media backends don't make it easy to receive those events, as such, to
complement webhooks, its good idea enable the scheduled tasks of import/export and let them run once in a while to
remap the data.

----

### Q: Can this tool run without docker?

Yes, if you have the required PHP version and the needed extensions. to run this tool you need the following `php8.1`,
and `php8.1-fpm` and the following extensions `php8.1-pdo`, `php8.1-mbstring`, `php8.1-ctype`, `php8.1-curl`,
`php8.1-sqlite3` and[composer](https://getcomposer.org/). once you have the required runtime dependencies, for first
time run:

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
ofc if you want a webhook support you would need a frontend proxy for `php8.1-fpm` like nginx, caddy or apache.

---

### Q: Some records keep getting updated even when the state did not change?

This most likely means your media backends have conflicting external ids for the reported items, and thus triggering an
update as the tool see different external ids on each event from the backends. In our testing we noticed that at least
few hundred records in thetvdb that get reported by plex have incorrect imdb external id, which in turns conflicts
sometimes with jellyfin/emby there is nothing we can do beside have the problematic records reported to thetvdb site
mods to fix their db entries.

----

### Q: I keep on seeing "Ignoring 'XXX'. No valid/supported external ids." in logs?

This most likely means that the episode/movie is not matched in your backend server, In jellyfin/emby edit metadata and
make sure there are external ids listed in the metadata. like tvdb/imdb etc. For plex click the (...), and click Fix
match.

If this relates to plex, and you are using custom agents then please refer
to [Supported Custom plex agents](#q-can-this-tool-work-with-alternative-plex-agents)

---

### Q: I enabled strict user match to allow only my user to update the play state, and now webhook requests are failing to be processed?

#### For Jellyfin backend

If this relates to jellyfin backend, then please make sure you have selected "Send All Properties (ignores template)".

#### For Plex backend

if your account is the admin account then update the `user` to `1` by running the following command, just change
the `[SERVER_NAME]` to your server config name.

```bash
$ docker exec -ti watchstate console servers:edit --key user --set 1 -- [SERVER_NAME]
```

---

### Q: Does this tool require webhooks to work?

No, You can use the task scheduler or on demand sync if you want. However, we recommend the webhook method as it's the
most efficient method to update play state.

--- 

### Q: When i use jellyfin, i sometimes see double events?

This most likely a bug in the plugin [jf-webhook #113](https://github.com/jellyfin/jellyfin-plugin-webhook/issues/113),
Just reload the page make sure there is only one added watchstate endpoint.

---

### Q: I keep on seeing "..., entity state is tainted." what does that means?

Tainted events are events that are not used to update the play state, but are interesting enough for us to keep around
for other benefits like updating the external ids mapping for movies/episodes. It's normal do not worry about it.

---

### Q: How can I see my play state list?

```bash
$ docker exec -ti watchstate console db:list
```

This command will give you access to see the database entries. by default, it will show the last 20 events, however you
can run the same command with `[-h, --help]` to see more options to extend the list or to filter the results.

---

### Q: Can I ignore specific libraries from being processed?

Yes, First run the following command

```bash
$ docker exec -ti watchstate console servers:remote --list-libraries -- [SERVER_NAME] 
```

it should show you list of given server libraries, you are mainly interested in the ID column. take note of the library
id, after that run the following command to ignore the libraries. The `options.ignore` accepts comma seperated list of
ids to ignore.

```bash
$ docker exec -ti watchstate console servers:edit --key options.ignore --set 'id1,id2,id3' -- [SERVER_NAME] 
```

If you ignored a library by mistake you can run the same command again and omit the id, or you can just delete the key
entirely by running the following command

```bash
$ docker exec -ti watchstate console servers:edit --delete --key options.ignore -- [SERVER_NAME] 
```

---

### Q: I get tired of writing the whole command everytime is there an easy way run the commands?

Since there is no way to access the command interface outside docker, you can create small shell script to at least omit
part of command that you have to write for example create new file named

```bash
$ echo 'docker exec -ti watchstate console "$@"' > ws
$ chmod +x ws
```

after that you can do `ws command` for example, `ws db:list`

---

### Q: I am using media backends hosted behind HTTPS, and see errors related to HTTP/2?

Sometimes there are problems related to HTTP/2 in the underlying library we use, so before reporting bug please try
running the following command

```bash
$ docker exec -ti watchstate console servers:edit --key options.client.http_version --set 1.0 -- [SERVER_NAME] 
```

if it does not fix your problem, please open issue about it.

---

### Q: My sync operations are failing due to timeout can I increase that?

We use [symfony/httpClient](https://symfony.com/doc/current/http_client.html) internally, So any options available in [
configuration](https://symfony.com/doc/current/http_client.html#configuration) section, can be used
under `options.client.` key for example if you want to increase the timeout you can do

```bash
$ docker exec -ti watchstate console servers:edit --key options.client.timeout --set 600 -- [SERVER_NAME] 
```

---

### Q: Can I search my server remote libraries?

Yes, Run the following command

```bash
$ docker exec -ti console server servers:remote --search '[searchTerm]' -- [SERVER_NAME]
```

Flags:

* (required) `--search` Search query. For example, `GUNDAM`.
* (optional) `--search-limit` To limit returned results. Defaults to `25`.
* (optional) `--search-output` Set output style, it can be `yaml` or `json`. Defaults to `json`.

---

### Q: Can this tool work with alternative Plex agents?

These are the agents we support for plex media server.

* plex://(type)/(id) `New Plex Agent`
* tvdb://(id) `New Plex Agent`
* imdb://(id) `New Plex Agent`
* tmdb://(id) `New Plex Agent`
* com.plexapp.agents.imdb://(id)?lang=en `(Lagecy plex agent "id" can be movie or series id)`
* com.plexapp.agents.tmdb://(id)?lang=en `(Lagecy plex agent "id" can be movie or series id)`
* com.plexapp.agents.themoviedb://(id)?lang=en `(Lagecy plex agent "id" can be movie or series id)`
* com.plexapp.agents.thetvdb://(seriesId)?lang=en `(Lagecy plex agent "id" can be movie or series id)`
* com.plexapp.agents.xbmcnfo://(id)?lang=en `(XBMC NFO parser agent, "id" refers to movie id in imdb)`
* com.plexapp.agents.xbmcnfotv://(id)?lang=en `(XBMC NFO parser agent for tv. "id" refers to series id)`
* com.plexapp.agents.hama://(db)-(id)?lang=en `(Anime agent "anidb, tvdb" as db source only. "id" refers to series id)`
