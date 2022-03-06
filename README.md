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
        image: arabcoders/watchstate:latest
        container_name: watchstate
        restart: unless-stopped
        environment:
            WS_UID: ${UID:-1000} # Set container operation user id.
            WS_GID: ${GID:-1000} # Set container operation group id.
        ports:
            - "8081:80" # webhook listener port
        volumes:
            - ./:/config:rw # mount current directory to container /config directory.
```

After creating your docker-compose file, start the container.

```bash
docker-compose up -d
```

# First time

You have to set up your servers in `config/servers.yaml` you will have examples inside the file, after editing the file
remove the unused servers examples. after configuring your servers you should import your current watch state by running
the following command.

```bash
docker exec -ti watchstate console state:import -vvrm --mapper-preload
```

#### TIP

To see the whole sync operation information you could run the command with `-vvvr` it will show all debug information,
be careful it might crash your terminal depending on how many servers and media you have. the output is excessive.

---

# Pull watch state.

now that you have imported your watch state, you can stop manually running the command again. and rely on the webhooks
to update the watch state.

To start receiving webhook events from servers you need to do few more steps.

# Steps to enable webhook servers

Run the following commands to generate api key for each server

```sh
$ docker exec -ti watchstate console servers:edit [SERVER_NAME] --webhook-import-status=enable --webhook-push-status=enable --webhook-key-generate

Server '[SERVER_NAME]' Webhook API key is: random_string
```

---

#### TIP:

If you have multiple plex servers and use the same account for all, Then you have to make all plex servers point to the
same API key, you still keep them separate but have the same `webhook.token` value.

The reason for these workarounds is that Plex link webhook to user account instead of server, as such all servers you
have linked your account into will use the same token. This limitation we cannot change from our side. So Use of the
listed workarounds. You have to do this changes for ALL plex servers added. you can use both workarounds. but one should
suffice.

### Workaround 1 (Whitelist IPS)

Whitelist IPs for each server by running the following command

```bash
docker exec -ti watchstate console servers:edit [PLEX_SERVER_1] --webhook-require-ips 'comma seperated list of ips/CIDR, 10.0.0.0/8,172.23.0.1'
```

### Workaround 2 (Use Server Unique ID)

To identify server via UUID, you have to get the server ID manually by visiting plex > settings > General then look at
the ID in the URL bar for example:

`https://app.plex.tv/desktop/#!/settings/server/[RANDOM_STRING]/settings/general`

##### [RANDOM_STRING]

Will be a randomly generated string. Then run the following command

```bash
docker exec -ti watchstate console servers:edit [PLEX_SERVER_1] --webhook-server-uuid '[RANDOM_STRING]'
```

The reason for these workarounds is, because Plex link webhook to user account instead of server, as such all servers
you have linked your account into will use the same token. This limitation we cannot change from our side. So Use of the
listed workarounds.

---

If you don't want to use webhook and want to rely on manual polling the servers for state change, then set the value
of `WS_CRON_IMPORT` to `1`. By default, we run import every hour. However, you can change the schedule by adding another
variable `WS_CRON_IMPORT_AT` and set it value to valid cron timing. for example, `0 */2 * * *` it will run every two
hours instead of 1 hour.

# Export watch state

To manually export your watch state back to servers you can run the following command

```bash
docker exec -ti watchstate console state:export --mapper-preload -vvr
```

to sync specific server/s, use the `--servers-filter` which accept comma seperated list of server names.

```bash
docker exec -ti watchstate console state:export -vvr --mapper-preload --servers-filter 'server1,server2' 
```

If you want to automate the exporting of your watch state back to servers, then set the value of `WS_CRON_EXPORT` to `1`
. By default, we run export every 90 minutes. However, you can change the schedule by adding another variable
called `WS_CRON_EXPORT_AT` and set its value to valid cron expression. for example, `0 */3 * * *` it will run every
three hours instead of 90 minutes.

# Memory usage (Import)

The default mapper we use is called `MemoryMapper` this mapper store the entire state in memory during import operation,
by doing so we gain massive speed up. However, this approach has drawbacks mainly **large memory usage**. Depending on
your media count, it might use 1GB+ or more of memory. Tests done on 2k movies and 70k episodes and 4 servers. Of course
the memory will be freed as as as the comparisons is done.

However, If you are on a memory constraint system, there is an alternative mapper implementation called `DirectMapper`
, this implementation works directly via db the only thing stored in memory is the api call body. which get removed as
soon as it's parsed. The drawbacks for this mapper is that it's really slow for large libraries. It has to do 3x call to
db for each item. you can use '--storage-pdo-single-transaction' to speed it up a little, but it is still slower by
factor of 2x or more compared to `MemoryMapper`.

To see memory usage during the operation run the import command with following flags. `-vvvrm` these flags will redirect
logger output, log memory usage and print them to the screen.

``bash docker exec -ti watchstate console state:import -vvvrm
``

## How to change Import mapper.

Set the environment variable `WS_MAPPER_IMPORT` to `DirectMapper`

# Servers.yaml

Example of working server with all options. You can have as many servers as you want.

```yaml
my_home_server: # *DO NOT* use spaces for server name,
    type: jellyfin|emby|plex # Choose one
    url: 'https://mymedia.example.com' # The URL for the media server api
    # User API Token.
    # Jellyfin: Create API token via (Dashboard > Advanced > API keys > +)
    # Emby: Create API token via (Manage Emby server > Advanced > API keys > + New Api Key)
    # Plex: see on how to get your plex-token https://support.plex.tv/articles/204059436
    token: user-api-token
    # Get your user ID. For Jellyfin/emby only.
    # Jellyfin : Dashboard > Server > Users > click your user > copy the userId= value
    # Emby: Manage Emby server > Server > Users > click your user > copy the userId= value
    # Plex: for plex managed users the X-Plex-Token acts as userId.
    user: user-id
    export:
        enabled: true # Enable export.
    import:
        enabled: true # Enable import.
    options:
        importUnwatched: true|false # By default, We do not import unwatched state to enable it set to true. 
        exportIgnoreDate: true|false # By default, we respect the server watch date. To override the check, set this to true.
        client: # underlying http client settings https://symfony.com/doc/current/reference/configuration/framework.html#http-client
            http_version: 1.0|2.0 # Change HTTP Protocol used.
    webhook:
        enable: true|false # enable receiving webhook events from this server.
        token: 'random_string' # Server specific api key.
        push: true|false # Enable push state back to this server when watchstate tool receive webhook event.
        ips: # optional, mostly for Plex Multi server setups.
            - '10.0.0.0/8' # whitelist ips that are allowed to use this API key.

```

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
        proxy_set_header Upgrade                $http_upgrade;
        proxy_pass http://localhost:8081/;
    }
}
```

### Adding webhook to server

to your server the url will be dependent on how you expose the server, but typically it will be like this:

#### Webhook URL

Via reverse proxy : `https://watchstate.domain.example/?apikey=[YOUR_API_KEY]`.

Via Direct container: `https://localhost:8081/?apikey=[YOUR_API_KEY]`

### [YOUR_API_KEY]

Change this parameter to your server specific ``webhook.token`` value. You can find it by viewing `config/config.yaml`
under the key of `webhook.token`. if the key does not exist please refer to the steps described at **Steps to enable
webhook servers**.

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

# FAQ

---

### Q1: How to update new server watched state without overwriting the existing watch state?

Add the server, disable the import operation, and enable export. Then run the following commands.

```bash
docker exec -ti watchstate console state:export -vvrm --ignore-date --force-full --servers-filter [SERVER_NAME]
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
