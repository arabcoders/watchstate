# Watch State Sync

CLI based tool to sync watch state between different media servers.

# Introduction

Ever wanted to sync your watch state without having to rely on 3rd party service like trakt.tv? then this tool is for
you. I had multiple problems with Plex trakt.tv plugin which led to my account being banned at trakt.tv, and on top of
that the plugin no longer supported. And I like to keep my own data locally if possible.

# v1 tag.

The tool is already working, The reason why it's not tagged v1.x, is i haven't yet decided if I like the config style.

# Supported Media servers.

* Plex
* Emby
* Jellyfin

## Install

create your `docker-compose.yaml` file

```yaml
version: '3.3'
services:
    watchstate:
        image: arabcoders/watchstate:dev-latest
        container_name: watchstate
        restart: unless-stopped
        environment:
            # For more ENV variables please read at the bottom of README.md
            WS_UID: ${UID:-1000} # Set container operation user id.
            WS_GID: ${GID:-1000} # Set container operation group id.
        ports:
            - "8081:80" # webhook listener port
        volumes:
            - ${PWD}/:/config:rw # mount current directory to container /config directory.
```

After creating your docker-compose file, start the container.

```bash
$ docker-compose up -d
```

# First time

After starting the container, you have to add your media servers, to do so run the following command

```bash
$ docker exec -ti watchstate console servers:manage --add -- [SERVER_NAME]
```

This command will ask you for some questions to add your servers, you can run the command as many times as you want, if
you want to edit the config again or if you made mistake just run the same command without `--add` flag.

After adding your servers, You should import your current watch state by running the following command.

```bash
$ docker exec -ti watchstate console state:import -vvrm
```

---

# Pulling watch state.

now that you have imported your watch state, you can stop manually running the command again. and rely on the webhooks
to update the watch state. To start receiving webhook events from servers you need to do few more steps.

### Enable Webhooks events for specific server.

To see the server specific api key run the following command

```bash
$ docker exec -ti watchstate console servers:view --servers-filter [SERVER_NAME] -- webhook.token 
```

If you see 'Not configured, or invalid key.' or empty value. run the following command

```bash
$ docker exec -ti watchstate console servers:manage --regenerate-api-key -- [SERVER_NAME] 
```

Run the other command again to see your api key.

---

#### TIP:

If you have multiple plex servers and use the same plex account for all of them, you have to unify the API key, by
running the following command

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

This command is not limited to plex, you can unify API key for all supported backend servers.

---

If you don't want to use webhooks and want to rely only on scheduled task for importing, then set the value
of `WS_CRON_IMPORT` to `1`. By default, we run the import command every hour. However, you can change the scheduled task
timer by adding another variable `WS_CRON_IMPORT_AT` and set it value to valid cron expression. for
example, `0 */2 * * *` it will run every two hours instead of 1 hour. beware, this operation is somewhat costly as it's
pulls the entire server library.

---

#### TIP

You should still have `WS_CRON_IMPORT` enabled as sometimes plex does not really report new items, or report them in a
way that is not compatible with the way we handle webhooks events. running the import command regularly helps keep
healthy `GUIDS <> serverInternalID mapping` relations.

---

# Export watch state

To manually export your watch state back to servers you can run the following command

```bash
$ docker exec -ti watchstate console state:export --mapper-preload -vvr
```

to sync specific server/s, use the `--servers-filter` which accept comma seperated list of server names.

```bash
$ docker exec -ti watchstate console state:export -vvr --mapper-preload --servers-filter 'server1,server2' 
```

To enable the export scheduled task set the value of `WS_CRON_EXPORT` to `1`. By default, we run export every 90
minutes. However, you can change the schedule by adding another variable called `WS_CRON_EXPORT_AT` and set its value to
valid cron expression. for example, `0 */3 * * *` it will run every three hours instead of 90 minutes.

# Start receiving Webhook Events.

By default, the official container includes a small http server exposed at port `80`, we officially don't support HTTPS
inside the container for the HTTP server. However, for the adventurous people we expose port 443 as well, as such you
can customize the Caddyfile to support SSL. and do the necessary adjustments. However, do not expect us to help with it.

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

### Adding webhook to server

to your server the url will be dependent on how you expose the server, but typically it will be like this:

#### Webhook URL

Via reverse proxy : `https://watchstate.domain.example/?apikey=[WEBHOOK_TOKEN]`.

Directly to container: `https://localhost:8081/?apikey=[WEBHOOK_TOKEN]`

If your server support sending headers then omit the query parameter '?apikey=[WEBHOOK_TOKEN]', and add new this header

```http request
X-apikey: [WEBHOOK_TOKEN]
```

it's more secure that way.

#### [WEBHOOK_TOKEN]

Should match the server specific ``webhook.token`` value. in `server.yaml`. if the key does not exist please refer to
the steps described at **Steps to enable webhook servers**.

# Configuring Media servers to send webhook events.

#### Jellyfin (Free)

go to your jellyfin dashboard > plugins > Catalog > install: Notifications > Webhook, restart your jellyfin. After that
go back again to dashboard > plugins > webhook. Add A `Add Generic Destination`,

##### Webhook Name:

Choose whatever name you want.

##### Webhook Url:

`http://localhost:8081/?apikey=[YOUR_API_KEY]`

##### Notification Type:

Select the following events

* Item Added
* User Data Saved
* Playback Start
* Playback Stop

##### Item Type:

* Movies
* Episodes

### Send All Properties (ignores template)

Enable this one as well.

Click `save`

#### Emby (you need emby premiere to use webhooks)

Go to your Manage Emby Server > Server > Webhooks > (Click Add Webhook)

##### Webhook Url:

`http://localhost:8081/?&apikey=[YOUR_API_KEY]`

##### Webhook Events

Select the following events

* Playback events
* User events

Click `Add Webhook`

#### Plex (you need PlexPass to use webhooks)

Go to your plex WebUI > Settings > Your Account > Webhooks > (Click ADD WEBHOOK)

##### URL:

`http://localhost:8081/?&apikey=[YOUR_API_KEY]`

Click `Save Changes`

# Webhook limitations

# Plex

Does not send webhooks events for "marked as watched/unwatched", or you added more than 1 item at time i.e. folder
import.

# Emby

Emby does not send webhooks events for newly added items.

# Jellyfin

None that we are aware of.

# Globally supported environment variables.

- (string) `WS_DATA_PATH` Where key data stored (config|db).
- (string) `WS_TMP_DIR` Where temp data stored. (logs|cache). Defaults to `WS_DATA_PATH` if not set.
- (string) `WS_STORAGE_PDO_DSN` PDO Data source Name, if you want to change from sqlite.
- (string) `WS_STORAGE_PDO_USERNAME` PDO username
- (string) `WS_STORAGE_PDO_PASSWORD` PDO password
- (bool) `WS_WEBHOOK_DEBUG` enable debug mode for webhook events.
- (integer) `WS_WEBHOOK_TOKEN_LENGTH` how many bits for the webhook api key generator.
- (bool) `WS_LOGGER_STDERR_ENABLED` enable stderr output logging.
- (string) `WS_LOGGER_STDERR_LEVEL` level to log (DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY,
  100|200|250|300|400|500|550|600).
- (bool) `WS_LOGGER_FILE_ENABLE` enable file logging.
- (string) `WS_LOGGER_FILE_LEVEL` level to log (DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY,
  100|200|250|300|400|500|550|600).
- (string) `WS_LOGGER_FILE` fullpath for log file for example, by default, it's `/config/logs/app.log`
- (bool) `WS_LOGGER_SYSLOG_ENABLED` enable syslog logger.
- (int) `WS_LOGGER_SYSLOG_FACILITY` syslog logging facility
- (string) `WS_LOGGER_SYSLOG_LEVEL` level to log (DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY,
  100|200|250|300|400|500|550|600).
- (string) `WS_LOGGER_SYSLOG_NAME` What name should logs be under.
- (int) `WS_CRON_IMPORT` enable import scheduled task.
- (int) `WS_CRON_EXPORT` enable export scheduled task.
- (int) `WS_CRON_PUSH` enable push scheduled task.
- (string) `WS_CRON_IMPORT_AT` cron expression timer.
- (string) `WS_CRON_EXPORT_AT` cron expression timer.
- (string) `WS_CRON_PUSH_AT` cron expression timer.

# Container specific environment variables

- (int) `WS_NO_CHOWN` do not change ownership of `/config` inside container.
- (int) `WS_DISABLE_HTTP` disable included http server.
- (int) `WS_UID` Container user ID
- (int) `WS_GID` Container group ID

# FAQ

### Q1: How to update new server watched state without overwriting the existing watch state?

Add the server, disable the import operation, and enable export. Then run the following commands.

```bash
$ docker exec -ti watchstate console state:export -vvrm --ignore-date --force-full --servers-filter [SERVER_NAME]
```

### [SERVER_NAME]

Replace `[SERVER_NAME]` with what you have chosen to name your server in config e.g. my_home_server

this command will force export your current database state back to the selected server. If the operation is successful
you can then enable the import feature if you want.

---

### Q2: Is there support for Multi-user setup?

No, The database design centered on single user. However, It's possible to run container for each user.

Note: for Plex managed users run the following command to extract each managed user token.

```bash
$ docker exec -ti console servers:remote --list-users-with-tokens -- my_plex_1
```

For jellyfin/emby, you can use same api-token and just replace the userId.

---

### Q3: Sometimes episodes/movies don't make to webhook receiver

as stated in webhook limitation sometimes servers don't make it easy to receive those events, as such, to complement
webhooks, its good idea enable the scheduled tasks of import/export and let them run once in a while to re-sync the
state of map of server guids, as webhook push support rely entirely on local data of each server.
