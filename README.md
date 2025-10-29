# WatchState

![Build Status](https://github.com/arabcoders/WatchState/actions/workflows/build.yml/badge.svg)
![MIT License](https://img.shields.io/github/license/arabcoders/WatchState.svg)
![Docker pull](https://img.shields.io/docker/pulls/arabcoders/watchstate.svg)
![ghcr pull](https://ghcr-badge.elias.eu.org/shield/arabcoders/watchstate/watchstate)

This tool primary goal is to sync your backends **users** play state without relying on third party services, out of the
box, this tool support `Jellyfin`, `Plex` and `Emby` media servers.

# Updates

### 2025-10-29

After more than **3.5 years**, **2.2k+ commits**, **900+ stars**, and **1 million+ downloads**, we’re happy to announce
the first stable release of **WatchState v1.0.0**.

This milestone marks the project’s maturity and reliability for production use. We extend our thanks to everyone who
provided feedback, reported bugs, and helped refine the tool your input has been invaluable.

The current feature set and stability meet our goals, so future work will focus on **maintenance and bug fixes**.
Feedback and suggestions remain welcome, but **major new features** may be limited as we prioritize **stability and
long-term reliability**.

Please refer to [NEWS](/NEWS.md) for the latest updates and changes.

------

# Features

* Management via WebUI.
* **Sub-users** support. `Multi-users`.
* Sync backends play state (`Many-to-many` or `One-way`).
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
> Note, For the first time, you will be prompted to create a new system user, this is a one time operation.

To add your backends, please click on the help button in the top right corner, and choose which method you
want [one-way](guides/one-way-sync.md) or [two-way](guides/two-way-sync.md) sync. and follow the instructions.

Once you have added your backends and imported your data you should see something like

![WebUI](/screenshots/index.png)

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
> understand webhooks limitations.

# FAQ

Take look at this [frequently asked questions](FAQ.md) page, or the [guides](/guides/) for more in-depth guides on how
to configure things.

# Social channels

If you have quick questions or would like to chat with other users, you can join
the [Discord server](https://discord.gg/haUXHJyj6Y). Please note that this is a solo project, so replies may take some
time. I’m based in the `UTC+3` timezone.

# Donate

If you’d like to show appreciation for my work, please note that I don’t accept donations. Instead, I encourage you to
donate to a children’s charity of your choice. For
example, [The International Make-A-Wish foundation](https://worldwish.org).
