# FAQ

### Q: How to update watched status for newly added server without overwriting my current watch state?

First, add the server and when asked, answer `n` for allow import from this server. when you finish, run the following
command:

```bash
$ docker exec -ti watchstate console state:export -vvrm --ignore-date --force-full --servers-filter [SERVER_NAME]
```

this command will force export your current database state back to the selected server. If the operation is successful
you can then enable the import feature if you want.

---

### Q: Is there support for Multi-user setup?

No, The database design centered on single user. However, It's possible to run container for each user.

Note: for Plex managed users run the following command to extract each managed user token.

```bash
$ docker exec -ti console servers:remote --list-users-with-tokens -- my_plex_1
```

For jellyfin/emby, you can use same api-token and just replace the userId.

---

### Q: Sometimes episodes/movies don't make to webhook receiver

as stated in webhook limitation sometimes servers don't make it easy to receive those events, as such, to complement
webhooks, its good idea enable the scheduled tasks of import/export and let them run once in a while to re-sync the
state of map of server guids, as webhook push support rely entirely on local data of each server.

----

### Q: Can this tool run without docker?

Yes, if you have the required PHP version and the needed extensions. to run this tool you need the following `php8.1`,
and the following extensions `php8.1-pdo`, `php8.1-mbstring`, `php8.1-ctype`, `php8.1-curl`, `php8.1-sqlite3` and
[composer](https://getcomposer.org/). once you have the required runtime for first time run:

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
environment variables. take look at the files inside docker directory to know how to run the scheduled tasks and ofc if
you want a webhook support you would need a frontend proxy for `php8.1-fpm` like nginx, caddy or apache.

---

### Q: Some records keep getting updated even through the state hasn't changed??

This most likely relates to incorrect GUID reported from servers, in our testing we noticed that at least few hundred
records in thetvdb that get reported by plex have incorrect imdb, which in turns conflicts sometimes with jellyfin/emby
there is nothing we can do beside have the problematic records reported to thetvdb site mods to fix their db entries.

----

### Q: I keep on seeing "No supported GUID was given." in logs?

This most likely means, the item being reported by the media server is not matched, in jellyfin/emby edit metadata and
make sure there are External IDs listed in the metadata. like tvdb/imdb etc. For plex click the (...), and click Fix
match.

---

### Q: I enabled strict user match to allow only my user to update the state, webhook requests are failing?

If this relates to jellyfin, then please make sure you have ticked "Send All Properties (ignores template)", if it's
plex and your account is main account then update the user id to 1 by running the following command:

```bash
$ docker exec -ti watchstate console servers:edit --key user --set 1 -- [PLEX_SERVER_NAME]
```

---

### Q: Does this tool require webhooks to work?

No, You can use the task scheduler or on demand sync if you want. However, we recommend the webhook method as it's the
most efficient method to gather play state.

--- 

### Q: When i use jellyfin, i sometimes see double events?

This likely a bug in resulted from [jf webhook. #113](https://github.com/jellyfin/jellyfin-plugin-webhook/issues/113),
Just reload the page make sure there is only one watchstate event.

---

### Q: I keep on seeing "..., entity state is tainted." what does that means?

Tainted events are events that are not used to update the watch state, but they are interesting enough for us to keep
around for other benefits like updating the GUID mapping for items. It's normal do not worry about it.

---

### Q: How can I see the database history?

```bash
$ docker exec -ti watchstate console db:list
```

This command will give you access to see the database entries. by default, it will show the last 20 events, however you
can run the same command with --help to see more options to extend the list or to filter the results.
