# WatchState

![Build Status](https://github.com/arabcoders/WatchState/actions/workflows/build.yml/badge.svg)
![MIT License](https://img.shields.io/github/license/arabcoders/WatchState.svg)
![Docker pull](https://img.shields.io/docker/pulls/arabcoders/watchstate.svg)

This tool primary goal is to sync your backends **users** play state without relying on third party services, out of the
box, this tool support `Jellyfin`, `Plex` and `Emby` media servers.

# Updates

Please refer to [NEWS](/NEWS.md) for the latest updates and changes.

# Features

* Management via WebUI.
* **Sub-users** support.
* Sync backends play state (`Many-to-Many` or `One-Way`).
* Backup your backends play state into `portable` format.
* Receive [webhook](guides/webhooks.md) events from media backends.
* Find `un-matched` or `mis-matched` items.
* Search your backend metadata.
* Check if your media servers reporting same data via the parity checks.
* Sync your watch [progress/play](FAQ.md#sync-watch-progress) state via webhooks or scheduled tasks.
* Check if your media backends have stale references to old files.

If you like my work, you might also like my other project [YTPTube](https://github.com/arabcoders/ytptube), which is
simple and to the point yt-dlp frontend to help download content from all supported sites by yt-dlp.

# Install

If you prefer video format [AlienTech42 YouTube Channel](https://www.youtube.com/@AlienTech42) had a video about
installing WatchState using unraid [at this link](https://www.youtube.com/watch?v=XoztOwGHGxk). Much appreciated.

PS: I don't know the channel owner, but I appreciate the effort. There is small mistake in the video regarding the
webhook URL, please copy the URL directly from the backends page. And this tool does support multi-users.

----

First, start by creating a directory to store the data, to follow along with this setup, create directory called `data`
at your working directory. Then proceed to use your preferred method to install the tool.

### Via compose file.

create your `compose.yaml` next to the `data` directory, and add the following content to it.

```yaml
services:
    watchstate:
        image: ghcr.io/arabcoders/watchstate:latest
        # To change the user/group id associated with the tool change the following line.
        user: "${UID:-1000}:${GID:-1000}"
        container_name: watchstate
        restart: unless-stopped
        ports:
            - "8080:8080" # The port which the webui will be available on.
        volumes:
            - ./data:/config:rw # mount current directory to container /config directory.
```

Next, to run the container, use the following command

```bash
$ docker compose up -d
```

### Via docker command.

```bash
$ docker run -d --rm --user "${UID:-1000}:${GID:-1000}" --name watchstate --restart unless-stopped -p 8080:8080 -v ./data:/config:rw ghcr.io/arabcoders/watchstate:latest
```

> [!IMPORTANT]
> It's really important to match the `user:`, `--user` to the owner of the `data` directory, the container is rootless,
> as such it will crash if it's unable to write to the data directory.
>
> It's really not recommended to run containers as root, but if you fail to run the container you can try setting the
`user: "0:0"` or `--user '0:0'` if that works it means you have permissions issues. refer to [FAQ](FAQ.md) to
> troubleshoot the problem.

### Unraid users

For `Unraid` users You can install the `Community Applications` plugin, and search for  **watchstate** it comes
preconfigured. Otherwise, to manually install it, you need to add value to the `Extra Parameters` section in advanced
tab/view. add the following value `--user 99:100`.

This has to happen before you start the container, otherwise it will have the old user id, and
you then have to run the following command from terminal `chown -R 99:100 /mnt/user/appdata/watchstate`.

### Podman instead of docker

To use this container with `podman` set `compose.yaml` `user` to `0:0`. it will appear to be working as root inside the
container, but it will be mapped to the user in which the command was run under.

# Management

After starting the container, you can access the WebUI by visiting `http://localhost:8080` in your browser.

> [!NOTE]
> The very first time you access the WebUI, we will attempt to autoconfigure your API connection. Should this fail, you
> can manually configure the API connection by following the instructions below.

At the start you won't see anything as the `WebUI` is decoupled from the WatchState and need to be configured to be able
to access the API. In the top right corner, you will see a cogwheel icon, click on it and then Configure the connection
settings.

![Connection settings](screenshots/api_settings.png)

As shown in the screenshot, to get your `API Token`, you can do via two methods

### Method 1

view the contents of the `./data/config/.env` file, and copy the contents after `WS_API_KEY=` variable.

### Method 2

From the host machine, you can run the following command

```bash
# change docker to podman if you are using podman
$ docker exec watchstate console system:apikey
```

Insert the `API key` into the `API Token` field and make sure to set the `API URL` or click the `current page URL` link.
If everything is ok, the reset of the navbar will show up.

To add your backends, please click on the help button in the top right corner, and choose which method you
want [one-way](guides/one-way-sync.md) or [two-way](guides/two-way-sync.md) sync. and follow the instructions.

### Supported import methods

Currently, the tool supports three methods to import data from backends.

- **Scheduled Tasks**.
    - `A scheduled job that pull data from backends on a schedule.`
- **On demand**.
    - `Pull data from backends on demand. By running the import task manually.`
- **Webhooks**.
    - `Receive events from backends and update the database accordingly.`

> [!NOTE]
> Even if all your backends support webhooks, you should keep import task enabled. This help keep healthy relationship
> and pick up any missed events. For more information please check the [webhook guide](/guides/webhooks.md) to
> understand
> webhooks limitations.

# FAQ

Take look at this [frequently asked questions](FAQ.md) page, or the [guides](/guides/) for more in-depth guides on how
to
configure things.

# Social channels

If you have short or quick questions, or just want to chat with other users, feel free to join
this [discord server](https://discord.gg/haUXHJyj6Y), keep in mind it's solo project, as such it might take me a bit of
time to reply to questions, I operate in `UTC+3` timezone.

# Donate

If you feel like donating and appreciate my work, you can do so by donating to children charity. For
example the [International Make-A-Wish foundation](https://worldwish.org).
