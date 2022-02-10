# Watch State Sync (Early Preview)

A CLI based app to sync watch state between different media servers.

# Introduction

I created this app for my own personal use, I had multiple problems with Plex trakt.tv plugin which led to my account
being banned at trakt.tv, and on top of that the plugin no longer supported. And I like to keep my own data locally if
possible.

# Supported Media servers.

* Plex
* Emby
* Jellyfin

## Install (Early Preview)

Clone this repo

```bash
git clone https://github.com/ArabCoders/watchstate.git
```

after cloning the app, you have two choices

--------

# Run the CLI version only

```bash
cd watchstate/docker/cli
docker-compose up -d
```

# Run Full Version with Webhooks Support

```bash
cd watchstate/docker/full
docker-compose up -d
```

This docker container will expose port 8081 by default to listen for webhooks calls.

====

# First time

regardless of what container type you have used you have to set up your servers, to do so run the following command.

```bash
docker exec -ti watchstate console config:dump servers 
```

after running the command you should have a file called ``servers.yaml`` inside ``watchstate/var/config/``, with
examples of how to define servers to use.

## First time Import

after configuring your servers at ``watchstate/var/config/servers.yaml`` you should import your current watch state by
running the following command.

```bash
docker exec -ti watchstate console state:import 
```

#### TIP

to watch lovely debug information you could run the command with -vvv it will show excessive information, be careful it
might crash your shell depending on how many servers and media you have. the output is massive.
----

now that you have imported your watch state, you can stop manually running the command again. and rely solely on the
webhooks to update the state. If however you don't want to run a webhook server, then you have to make a cronjob that
will run the command as you see fit.

# Example for cronjob, (only for < v1.x)

```bash
0 */1 * * * docker exec -ti watchstate console state:import 
```

## Exporting watch state back to servers

```bash
docker exec -ti watchstate console state:export 
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

Edit ``var/config/config.yaml`` if it does not exist create it, Put the following instruction there

```yaml
mapper:
    import:
        type: DirectMapper
```

# Servers.yaml

Example of working servers. You can have as many servers as you want.

```yaml
# The following instruction works for both jellyfin and emby. 
jellyfin_basement_server:
    # What backend server is this can be jellyfin or emby
    type: jellyfin|emby #Choose one
    # The Url for api access.
    url: 'http://172.23.0.12:8096'
    # Create API token via jellyfin (Dashboard > Advanced > API keys > +)
    token: api-token
    options:
        # Get your user id from jellyfin (Dashboard > Server > Users > click your user > copy the userId= value from url)
        user: jellfin-user-id
    export:
        # Whether to enable exporting watch state back to this server.  
        enabled: true
    import:
        # Whether to enable importing watch state from this server.  
        enabled: false

# For plex.
my_plex_server:
    # What backend server is this
    type: plex
    # The Url for api access.
    url: 'http://172.23.0.12:8096'
    # Get your plex token, (see https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/)
    token: api-token
    export:
        # Whether to enable exporting watch state back to this server.  
        enabled: true
    import:
        # Whether to enable importing watch state from this server.  
        enabled: false
```

# Running Webhook server.

if you want to use webhooks, you have to generate an api key.

```bash
docker exec -ti watchstate console config:generate 
```

If you don't have an api key already set at ``watchstate/var/config/config.yaml`` it will generate new key and store it
there under the key of ``webhook.apikey``

Adding webhook to your server the url will be dependent on how you expose the server, but typically it will be like this
``http://localhost:8081/?type=[SERVER_TYPE]&apikey=[YOUR_API_KEY]``

### [SERVER_TYPE]

Change the parameter to one of those ``emby, plex or jellyfin``.

### [YOUR_API_KEY]

Change this parameter to your api key you can find it by viewing ``var/config/config.yaml`` under the key
of ``webhook.apikey``

# Configuring Media servers to send webhook events.

#### Jellyfin (Free)

go to your jellyfin dashboard > plugins > Catalog > install: Notifications > Webhook, restart your jellyfin. After that
go back again to dashboard> plugins > webhook. Add A ``Add Generic Destination``,

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
