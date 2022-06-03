# WatchState

WatchState is a CLI based tool to sync your watch state between different media backends, without relying on 3rd parties
services, like trakt.tv, This tool support `Plex Media Server`, `Emby` and `Jellyfin` out of the box.

# Install

create your `docker-compose.yaml` file:

```yaml
version: '3.3'
services:
    watchstate:
        image: ghcr.io/arabcoders/watchstate:latest
        container_name: watchstate
        restart: unless-stopped
        # For more environment variables please read at the bottom of this page.
        # works for both global and container specific environment variables. 
        environment:
            - WS_UID=${UID:-1000} # Set container user id.
            - WS_GID=${GID:-1000} # Set container group id.
        ports:
            - "8081:80" # webhook listener port
        volumes:
            - ${PWD}/:/config:rw # mount current directory to container /config directory.
```

After creating your docker-compose file, start the container.

```bash
$ docker-compose pull && docker-compose up -d
```

# First time

Run the following command to see all available commands you can also run help on each command to get more info.

```bash
# Show all commands.
$ docker exec -ti watchstate console list

# Show help document for each command.
$ docker exec -ti watchstate console help state:import
```

---

After starting the container, you have to add your media backends, to do so run the following command:

```bash
$ docker exec -ti watchstate console servers:manage --add -- [SERVER_NAME]
```

This command is interactive and will ask you for some questions to add your backend, you can run the command as many
times as you want, if you want to edit the config again or if you made mistake just run the same command without `--add`
flag. After adding your backends, You should import your current watch state by running the following command.

```bash
$ docker exec -ti watchstate console state:import -vvf
```

---

# Pulling watch state.

Now that you have imported your current play state, you can stop manually running the command, and rely on the tasks
scheduler and webhooks to keep update your play state. To start receiving webhook events from backends you need to do
few more steps.

### Enable webhooks events for specific backend.

To see the backend specific webhook api key run the following command:

```bash
$ docker exec -ti watchstate console servers:view --servers-filter [SERVER_NAME] -- webhook.token 
```

If you see 'Not configured, or invalid key.' or empty value. run the following command

```bash
$ docker exec -ti watchstate console servers:edit --regenerate-webhook-token -- [SERVER_NAME] 
```

---

#### Notice:

If you have multiple plex servers and use the same PlexPass account for all of them, you have to unify the API key, by
running the following command:

```bash
$ docker exec -ti watchstate console servers:unify plex 
Plex global webhook API key is: [random_string]
```

The reason is due to the way plex handle webhooks, And to know which webhook request belong to which server we have to
identify the servers, The unify command will do the necessary adjustments to handle multi plex server setup. for more
information run.

```bash
$ docker exec -ti watchstate console help servers:unify 
```

---

If you don't want to/can't use webhooks and want to rely on task scheduler importing, then set the value
of `WS_CRON_IMPORT` to `1`. By default, we run the import command every hour. However, you can change the scheduled task
timer by adding another variable `WS_CRON_IMPORT_AT` and set its value to valid cron expression. for
example, `0 */2 * * *` it will run every two hours instead of 1 hour. If your backends and this tool are not on same
server it might consume a lot of bandwidth depending on how big is your library as it's pulls the entire server library
listing.

---

#### Notice

You should still have `WS_CRON_IMPORT` enabled to keep healthy relation between storage and backend changes.

---

# Export watch state

To manually export your watch state back to servers you can run the following command

```bash
$ docker exec -ti watchstate console state:export -vv
```

to sync specific server/s, use the `[-s, --servers-filter]` which accept comma seperated list of server names.

```bash
$ docker exec -ti watchstate console state:export -vv --servers-filter 'server1,server2' 
```

To enable export scheduled task set the value of `WS_CRON_EXPORT` to `1`. By default, we run export every 90 minutes.
However, you can change the timer by adding another variable called `WS_CRON_EXPORT_AT` and set its value to valid cron
expression. for example, `0 */3 * * *` it will run every three hours instead of 90 minutes.

# Start receiving webhook events.

By default, the official container includes http server exposed at port `80`, we officially don't support HTTPS
inside the container for the HTTP server. However, for the adventurous people we expose port 443 as well, as such you
can customize the `docker/files/nginx.conf` to support SSL. and do the necessary adjustments.

#### Example nginx reverse proxy.

```nginx
server {
    server_name watchstate.domain.example;
    
    location / {
        proxy_http_version 1.1;
        proxy_set_header Host                   $host;
        proxy_set_header X-Real-IP              $remote_addr;
        proxy_set_header X-Forwarded-For        $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto      $scheme;
        proxy_set_header X-Forwarded-Protocol   $scheme;
        proxy_set_header X-Forwarded-Host       $http_host;
        proxy_pass http://localhost:8081/;
    }
}
```

### Adding webhook

To add webhook for your server the URL will be dependent on how you exposed webhook frontend, but typically it will be
like this:

#### Webhook URL

Via reverse proxy : `https://watchstate.domain.example/?apikey=[WEBHOOK_TOKEN]`.

Directly to container: `https://localhost:8081/?apikey=[WEBHOOK_TOKEN]`

If your media backend support sending headers then remove query parameter `?apikey=[WEBHOOK_TOKEN]`, and add this header

```http request
X-apikey: [WEBHOOK_TOKEN]
```

where `[WEBHOOK_TOKEN]` Should match the backend specific `webhook.token` value. Refer to the steps described
at **[Steps to enable webhook servers](#enable-webhooks-events-for-specific-backend)**.

# Configuring media backends to send webhook events.

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

#### Emby (you need "Emby Premiere" to use webhooks)

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

---

# Known Webhook limitations

# Plex

* Plex does not send webhooks events for "marked as Played/Unplayed".
* Sometimes does not send events if you add more than one item at time.
* If you have multi-user setup, Plex will still report the admin account user id as `1`.
* When you mark items as unwatched, Plex reset the date on the object.

# Emby

* Emby does not send webhooks events for newly added
  items. [See feature request](https://emby.media/community/index.php?/topic/97889-new-content-notification-webhook/)
* Emby webhook test event does not contain data. To test if your setup works, play something or do mark an item as played/unplayed you
  should see changes reflected in `docker exec -ti watchstate console db:list`.

# Jellyfin

* If you don't select a user id, the Plugin will sometimes send `itemAdd` event without user info, and thus will fail
  the check if you happen to enable `strict user match` for jellyfin.

----

# Environment variables.

- (string) `WS_DATA_PATH` Where key data stored (config|db).
- (string) `WS_TMP_DIR` Where temp data stored. (logs|cache). Defaults to `WS_DATA_PATH` if not set.
- (string) `WS_TZ` Set timezone for example, `UTC`
- (bool) `WS_WEBHOOK_DEBUG` enable debug mode for webhook events.
- (bool) `WS_REQUEST_DEBUG` enable debug mode for pre webhook request.
- (integer) `WS_WEBHOOK_TOKEN_LENGTH` how many bits for the webhook api key generator.
- (bool) `WS_LOGGER_FILE_ENABLE` enable file logging.
- (string) `WS_LOGGER_FILE` full path for log file. By default, it's stored at `$(WS_TMP_DIR)/logs/app.log`
- (string) `WS_LOGGER_FILE_LEVEL` level to log (DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY).
- (bool) `WS_LOGGER_SYSLOG_ENABLED` enable syslog logger.
- (int) `WS_LOGGER_SYSLOG_FACILITY` syslog logging facility
- (string) `WS_LOGGER_SYSLOG_LEVEL` level to log (DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY).
- (string) `WS_LOGGER_SYSLOG_NAME` What name should logs be under.
- (int) `WS_CRON_IMPORT` enable import scheduled task.
- (string) `WS_CRON_IMPORT_AT` cron expression timer.
- (string) `WS_CRON_IMPORT_ARGS` set import command flags. Defaults to `-v`
- (int) `WS_CRON_EXPORT` enable export scheduled task.
- (string) `WS_CRON_EXPORT_AT` cron expression timer.
- (string) `WS_CRON_EXPORT_ARGS` set export command flags. Defaults to `-v`
- (int) `WS_CRON_PUSH` enable push scheduled task.
- (string) `WS_CRON_PUSH_AT` cron expression timer.
- (string) `WS_CRON_PUSH_ARGS` set push command flags. Defaults to `-v`
- (string) `WS_LOGS_PRUNE_AFTER` Delete logs older than specified time, set to `disable` to disable logs pruning. it
  follows php [strtotime](https://www.php.net/strtotime) function rules.
- (bool) `WS_DEBUG_IMPORT` Whether to log invalid GUID items from server in `${WS_TMP_DIR}/debug`.

# Container specific environment variables.

- (int) `WS_DISABLE_CHOWN` Do not change ownership for `/app/, /config/` directories inside the container.
- (int) `WS_DISABLE_HTTP` Disable included HTTP Server.
- (int) `WS_DISABLE_CRON` Disable included Task Scheduler.
- (int) `WS_DISABLE_CACHE` Disable included Cache Server.
- (int) `WS_UID` Container app user id.
- (int) `WS_GID` Container app group id.

---

# FAQ

For some common questions, Take look at this [frequently asked questions](FAQ.md) page.
