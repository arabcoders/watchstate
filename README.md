# Watch State Sync

CLI based tool to sync watch state between different media servers.

# Introduction

Ever wanted to sync your watch state without having to rely on 3rd party service like trakt.tv? then this tool is for
you. I had multiple problems with Plex trakt.tv plugin which led to my account being banned at trakt.tv, and on top of
that the plugin no longer supported. And I like to keep my own data locally if possible.

# v1.0.0 tagging.

The features set is complete, and works, however the API still unstable as such there will be no v1 tag until we
finalize the API and finish writing tests.

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
            WS_WEBHOOK_ENABLE: 0 # Enable webhook listening server. Disabled by default.
            WS_CRON_IMPORT: 0 # Enable manual pulling of watch state from servers. Disabled by default.
            WS_CRON_EXPORT: 1 # Enable manual push of watch state back to servers. Disabled by default.
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
remove the unused servers examples.

after configuring your servers at `config/servers.yaml` you should import your current watch state by running the
following command.

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

To enable webhook listening server, Edit the `WS_WEBHOOK_ENABLE` variable by setting its value to `1`. If the variable
does not exist or is set to value other than `1` it will not start the included caddy server.

If you don't want to use webhook events to update the watch state, and want to rely on manual polling the servers for
state change, then set the value of `WS_CRON_IMPORT` to `1`. By default, we run import every hour. However, you can
change the schedule by adding another variable `WS_CRON_IMPORT_AT` and set it value to valid cron timing. for
example, `0 */2 * * *` it will run every two hours instead of 1 hour.

# Push watch state

To manually push your watch state back to servers you can run the following command

```bash
docker exec -ti watchstate console state:export -vvr 
```

to sync specific server/s, use the `--servers-filter` which accept comma seperated list of server names.

```bash
docker exec -ti watchstate console state:export -vvr --servers-filter 'server1,server2' 
```

If you want to automate the pushing of your watch state back to servers, set the value of `WS_CRON_EXPORT` to `1`. By
default, we run export every 90 minutes. However, you can change the schedule by adding another variable
called `WS_CRON_EXPORT_AT` and set it value to valid cron timing. for example, `0 */3 * * *` it will run every three
hours instead of 90 minutes.

# Memory usage (Import)

The default mapper we use is called `MemoryMapper` this mapper store the entire state in memory during import operation,
by doing so we gain massive speed up. However, this approach has drawbacks mainly **large memory usage**. Depending on
your media count, it might use 1GB+ or more of memory. Tests done on 2k movies and 70k episodes and 4 servers. Ofc the
memory will be freed as as as the comparison is done.

However, If you are on a memory constraint system, there is an alternative mapper implementation called `DirectMapper`
, this implementation works directly via db the only thing stored in memory is the api call body. which get removed as
soon as it's parsed. The drawback for this mapper is it's like really slow for large libraries. It has to do 3x call to
db for each time.

To see memory usage during the operation run the import command with following flags. `-vvvrm` these flags will redirect
logger output, log memory usage and print them to the screen.

``bash docker exec -ti watchstate console state:import -vvvrm
``

## How to change Import mapper.

Set the environment variable `WS_MAPPER_IMPORT` to `DirectMapper` or edit the ``config/config.yaml`` and add the
following lines

```yaml
mapper:
    import:
        type: DirectMapper
```

# Servers.yaml

Example of working server with all options. You can have as many servers as you want.

```yaml
my_home_server:
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
        http2: false # Enable HTTP/2 support for faster http requests (server must support http 2.0).
        importUnwatched: false # By default, We do not import unwatched state to enable it set to true. 
        exportIgnoreDate: false # By default, we respect the server watch date. To override the check, set this to true.
```

# Start Webhook Server.

The default container includes webserver to listen for webhook events, to enable the server. edit
your `docker-compose.yaml` file, and set the environment variable `WS_WEBHOOK_ENABLE` to `1`.

View the contents of your `config/config.yaml` and take note of the `webhook.apikey`. if the apikey does not exist run
the following command.

```bash
docker exec -ti watchstate console config:generate 
```

it should populate your `config.yaml` with randomly generated key, and it will be printed to your screen. Adding webhook
to your server the url will be dependent on how you expose the server, but typically it will be like this:

`http://localhost:8081/?type=[SERVER_TYPE]&apikey=[YOUR_API_KEY]`

### [SERVER_TYPE]

Change the parameter to one of supported backends. e.g. `emby, plex or jellyfin`. it should match the server type that
you are adding the link to, Each server type has different webhook payload.

### [YOUR_API_KEY]

Change this parameter to your api key you can find it by viewing `config/config.yaml` under the key of `webhook.apikey`.

# Configuring Media servers to send webhook events.

#### Jellyfin (Free)

go to your jellyfin dashboard > plugins > Catalog > install: Notifications > Webhook, restart your jellyfin. After that
go back again to dashboard > plugins > webhook. Add A `Add Generic Destination`,

##### Webhook Name:

Choose whatever name you want.

##### Webhook Url:

`http://localhost:8081/?type=jellyfin&apikey=[YOUR_API_KEY]`

##### Notification Type:

Select the following events

* Item Added
* User Data Saved

Click `save`

#### Emby (you need emby premiere to use webhooks)

Go to your Manage Emby Server > Server > Webhooks > (Click Add Webhook)

##### Webhook Url:

`http://localhost:8081/?type=emby&apikey=[YOUR_API_KEY]`

##### Webhook Events

Select the following events

* Playback events
* User events

Click `Add Webhook`

#### Plex (you need PlexPass to use webhooks)

Go to your plex WebUI > Settings > Your Account > Webhooks > (Click ADD WEBHOOK)

##### URL:

`http://localhost:8081/?type=plex&apikey=[YOUR_API_KEY]`

Click `Save Changes`

# Finally

after making sure everything is running smoothly, edit your `docker-compose.yaml` file and enable the exporting of your
watch state back to servers. by setting the value of `WS_CRON_EXPORT` to `1`.

restart your docker container for changes to take effect.

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
