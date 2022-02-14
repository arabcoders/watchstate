# Warning

This is an early release version, expect bugs and edge cases that we haven't encountered. Please keep that in mind
before running this tool. while its works for me, it might not work for your setup.

# Watch State Sync (Early Preview)

A CLI based app to sync watch state between different media servers.

# Introduction

Ever wanted to sync your watch state without having to rely on 3rd party service like trakt.tv? then this tool is for
you. I had multiple problems with Plex trakt.tv plugin which led to my account being banned at trakt.tv, and on top of
that the plugin no longer supported. And I like to keep my own data locally if possible.

# Supported Media servers.

* Plex
* Emby
* Jellyfin

## Install (Early Preview)

Clone this repo by

```bash
git clone https://github.com/ArabCoders/watchstate.git
```

after cloning the app, start the docker container

```bash
cd watchstate/docker/
docker-compose up -d
```

This docker container will expose port 80 by default to listen for webhooks calls. mapped to port 8081 on host.

# First time

You have to set up your servers in ``docker/config/config/servers.yaml`` you will have examples inside the file, after
editing the file remove the unused servers examples.

after configuring your servers at ``docker/config/config/servers.yaml`` you should import your current watch state by
running the following command.

```bash
docker exec -ti watchstate console state:import -vvrm
```

#### TIP

to watch lovely debug information you could run the command with -vvvrm it will show excessive information, be careful
it might crash your terminal depending on how many servers and media you have. the output is excessive.

---

now that you have imported your watch state, you can stop manually running the command again. and rely solely on the
webhooks to update the import state.

If however you don't want to run a webhook server, then you have to make a few adjustments edit ``docker-compose.yaml``
and enable the environment variable ``WS_CRON`` by changing its value to ``1``, you can also control whether you want to
run both import and export by using the other ``WS_CRON_*`` variables. All those variables can be edited
using ``config/config/config.yaml`` file, the options modified get to override the ``config/config.php``. After editing
the variables restart the docker container for the changes to take effect.

### You can manually export your watch state back to servers using the following command

```bash
docker exec -ti watchstate console state:export -vvrm 
```

# Memory usage (Import)

By default, We use something called ``MemoryMapper`` this mapper store the state in memory during import/export to
massively speed up the comparison. However, this approach has drawbacks which is large memory usage. Depending on your
media count, it might use 1GB or more of memory per sync operation.(tests done on 2k movies and 70k episodes and 4
servers). We recommend this mapper and use it as default.

However, If you are on a memory constraint system, there is an alternative mapper implementation called ``DirectMapper``
, this one work directly on db the only thing stored in memory is the api call body. which get removed as soon as it's
parsed. The drawback for this mapper is it's like 10x slower than the memory mapper. for large media servers.

To see memory usage during the operation run the import command with following flags. ``-vvvrm`` these flags will
redirect logger output, log memory usage and print them to the screen.

```bash
docker exec -ti watchstate console state:import -vvvrm
```

### How to change the Mapper

Edit ``config/config/config.yaml``

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
        importUnwatched: false # By default, We do not import unwatched state to enable support, Set this to true. Webhooks can set unwatched state as they are explicit user action. 
        exportIgnoreDate: false # By default, we respect the server watch date. To override the check, set this to true.
```

# Running Webhook server.

You should have a working webhook server already. view the contents of ``config/config/config.yaml`` and take note of
the ``webhook.apikey``. if the apikey does not exist run the following command.

```bash
docker exec -ti watchstate console config:generate 
```

it should update your ``config.yaml`` and add randomly generated key, and it will be printed to your screen.

Adding webhook to your server the url will be dependent on how you expose the server, but typically it will be like this
``http://localhost:8081/?type=[SERVER_TYPE]&apikey=[YOUR_API_KEY]``

### [SERVER_TYPE]

Change the parameter to one of those ``emby, plex or jellyfin``. it should the server that you are adding to, as each
server has different webhook payload.

### [YOUR_API_KEY]

Change this parameter to your api key you can find it by viewing ``config/config/config.yaml`` under the key
of ``webhook.apikey``.

# Configuring Media servers to send webhook events.

#### Jellyfin (Free)

go to your jellyfin dashboard > plugins > Catalog > install: Notifications > Webhook, restart your jellyfin. After that
go back again to dashboard > plugins > webhook. Add A ``Add Generic Destination``,

##### Webhook Name:

Choose whatever name you want.

##### Webhook Url:

``http://localhost:8081/?type=jellyfin&apikey=[YOUR_API_KEY]``

##### Notification Type:

Select the following events

* Item Added
* User Data Saved

Click ``save``

#### Emby (you need emby premiere to use webhooks)

Go to your Manage Emby Server > Server > Webhooks > (Click Add Webhook)

##### Webhook Url:

``http://localhost:8081/?type=emby&apikey=[YOUR_API_KEY]``

##### Webhook Events

Select the following events

* Playback events
* User events

Click ``Add Webhook``

#### Plex (you need PlexPass to use webhooks)

Go to your plex WebUI > Settings > Your Account > Webhooks > (Click ADD WEBHOOK)

##### URL:

``http://localhost:8081/?type=plex&apikey=[YOUR_API_KEY]``

Click ``Save Changes``

# Finally

after making sure everything is running smoothly, edit your ``docker-compose.yaml`` file and enable the exporting of
your watch state back to servers. by enabling the following options

```yaml
environment:
    WS_CRON: 1
    WS_CRON_EXPORT: 1
```

restart your docker container for changes to take effect.

# FAQ

---

### (Q01): How to update new server watched state without overwriting the existing watch state?

Add the server, disable the import operation, and enable export. Then run the following commands.

```bash
docker exec -ti watchstate console state:export --vvrm --ignore-date --force-full --servers-filter [SERVER_NAME]
```

### [SERVER_NAME]

Replace `[SERVER_NAME]` with what you have chosen to name your server in config e.g. my_home_server

this command will force export your current database state back to the selected server. If the operation is successful
you can then enable the import feature if you want.

### (Q02): Is there support for Multi-user setup?

No, Not at this time. The database design centered on single user. However, It's possible to run container for each
user.

Note: for Plex managed users you can log in via managed user and then extract the user x-plex-token (this token acts as
userId for plex)

For jellyfin/emby, you can use same api-token and just replace the userId.
