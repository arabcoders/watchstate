# Watch State Sync

CLI based tool to sync watch state between different media servers.

# Introduction

Ever wanted to sync your watch state without having to rely on 3rd party service like trakt.tv? then this tool is for
you. I had multiple problems with Plex trakt.tv plugin which led to my account being banned at trakt.tv, and on top of
that the plugin no longer supported. And I like to keep my own data locally if possible.

# v1.x tagging.

The tool is already working, I personally started using it as early as v0.0.4-alpha, The reason we haven't tagged it
v1.x yet is the API not stable yet and i keep changing and refining the code to get slight performances upgrade and that
sometimes require breaking changes and tests and docs are not up yet. As such once we are satisfied with tool the API,
tests and docs We will tag it.

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
            WS_CRON_PUSH: 1      # Enable push scheduled task.
            WS_CRON_IMPORT: 1    # Enable import scheduled task.
            WS_CRON_EXPORT: 1    # Enable export scheduled task.
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
$ docker exec -ti watchstate console servers:manage [SERVER_NAME] --add
```

This command will ask you for some questions to add your servers, you can run the command as many times as you want, if
you want to edit the config again or if you made mistake just run the same command without `--add` flag.

After adding your servers, You should import your current watch state by running the following command.

```bash
$ docker exec -ti watchstate console state:import -vvrm --mapper-preload
```

---

#### TIP

To see the whole sync operation information you could run the command with `-vvvr` it will show all debug information,
be careful it might crash your terminal depending on how many servers and media you have. the output is excessive.

---

# Pulling watch state.

now that you have imported your watch state, you can stop manually running the command again. and rely on the webhooks
to update the watch state.

To start receiving webhook events from servers you need to do few more steps.

# Enable Webhooks events for specific server.

To see the server specific api key run the following command

```bash
$ docker exec -ti watchstate console servers:view --servers-filter [SERVER_NAME] -- webhook.token 
```

If you see 'Not configured, or invalid key.' or empty value. run the following command

```bash
$ docker exec -ti watchstate console servers:manage [SERVER_NAME] --regenerate-api-key
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
identify the servers, The unify command will do the necessary adjustments handle multi plex server setup. for more
information run.

```bash
$ docker exec -ti watchstate console help servers:unify 
```

This command is not limited to plex, you can unify API key for all supported backend servers.

---

If you don't want to use webhook and want to rely on scheduled task for importing, then set the value
of `WS_CRON_IMPORT` to `1`. By default, we run import the timer is set to run every hour. However, you can change the
scheduled task timer by adding another variable `WS_CRON_IMPORT_AT` and set it value to valid cron expression. for
example, `0 */2 * * *` it will run every two hours instead of 1 hour. beware, this operation is somewhat costly as it's
pulls the entire server library.

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

By default, the official container includes a small http server exposed at port `80`, we don't support SSL inside
container, so we recommend running a reverse proxy in front of the tool.

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

# FAQ

---

### Q1: How to update new server watched state without overwriting the existing watch state?

Add the server, disable the import operation, and enable export. Then run the following commands.

```bash
$ docker exec -ti watchstate console state:export -vvrm --ignore-date --force-full --servers-filter [SERVER_NAME]
```

### [SERVER_NAME]

Replace `[SERVER_NAME]` with what you have chosen to name your server in config e.g. my_home_server

this command will force export your current database state back to the selected server. If the operation is successful
you can then enable the import feature if you want.

### Q2: Is there support for Multi-user setup?

No, Not at this time. The database design centered on single user. However, It's possible to run container for each
user.

Note: for Plex managed users you can log in via managed user and then extract the user x-plex-token (this token acts as
userId for plex)

For jellyfin/emby, you can use same api-token and just replace the userId.

### Q3: Sometimes episodes/movies don't make to webhook receiver

as stated in webhook limitation sometimes servers don't make it easy to receive those events, as such, to complement
webhooks, its good idea enable the scheduled tasks of import/export and let them run once in a while to re-sync the
state of map of server guids, as webhook push support rely entirely on local data of each server.
