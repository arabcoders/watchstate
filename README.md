# WatchState

![Build Status](https://github.com/arabcoders/WatchState/actions/workflows/build.yml/badge.svg)
![MIT License](https://img.shields.io/github/license/arabcoders/WatchState.svg)
![Docker pull](https://img.shields.io/docker/pulls/arabcoders/watchstate.svg)
![ghcr pull](https://ghcr-badge.elias.eu.org/shield/arabcoders/watchstate/watchstate)

This tool primary goal is to sync your backends **users** play state without relying on third party services, out of the
box, this tool supports `Jellyfin`, `Plex` and `Emby` media servers.

# Updates

### 2026-07-20

Media Health is now the central place to audit media record problems. It replaces the 
old separate parity, duplicate-reference, and file-integrity views with one report for backend 
metadata coverage, GUID conflicts, duplicate GUIDs, duplicate file references, structural metadata disagreement, 
path disagreement, weak matches, and optional local file checks. See the [Media Health guide](/guides/media-health.md) for details.

### 2026-05-25

Path matching is now available in v1.8.5+. It lets items match using a GUID source derived from the media path, which helps when your backends share the same media files but have unreliable or inconsistent external IDs. See the [path matching guide](/guides/path-match.md) for setup and backfill instructions.

Please refer to [NEWS](/NEWS.md) for the latest updates and changes.

------

# Features

* **Multi-users** support via `identities`.
* Sync backends play state (`many-to-many` or `one-way`).
* Backup your backends play state into `portable` format.
* Receive [webhook](guides/webhooks.md) events from media backends.
* Find record issues with [Media Health](guides/media-health.md).
* Search your backend metadata.
* Sync your watch [progress/play](FAQ.md#sync-watch-progress) state via webhooks or scheduled tasks.

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
        user: "${UID:-1000}:${UID:-1000}"
        container_name: watchstate
        restart: unless-stopped
        ports:
            - "8080:8080" # The port which the watchstate will listen on.
        volumes:
            - ./data:/config:rw # mount ./data in current directory to container /config directory.
```

Next, to run the container, use the following command

```bash
mkdir -p ./data && docker compose up -d
```

### Via docker command.

```bash
mkdir -p ./data && docker run -itd --name watchstate \
          --user "${UID:-1000}:${GID:-${UID:-1000}}"  \
          --restart unless-stopped -p 8080:8080 \
          -v ./data:/config:rw \
          ghcr.io/arabcoders/watchstate:latest
```

> [!IMPORTANT]
> Match `user:` and `--user` to the owner of the `data` directory. The container runs rootless and exits if it cannot
> write to that directory.
>
> Running the container as root is not recommended. If the container fails to start, try `user: "0:0"` or
> `--user '0:0'`. If that works, the problem is a permissions issue. See the [FAQ](FAQ.md) for troubleshooting steps.

### Unraid users

For `Unraid` users, install the `Community Applications` plugin and search for **watchstate**. It is preconfigured.
To install it manually, add `--user 99:100` to the `Extra Parameters` section in the advanced tab.

Set this before starting the container. If the container already created files with another user ID, run
`chown -R 99:100 /mnt/user/appdata/watchstate` from a terminal.

### Podman instead of docker

To use this container with `podman`, set `user` to `0:0` in `compose.yaml`. The container appears to run as root, but
Podman maps it to the user who ran the command.

# Management

After starting the container, you can access the WebUI by visiting `http://localhost:8080` in your browser.

> [!NOTE]
> On first access, you will be prompted to create a system user. This is a one-time operation.

If you want WatchState to match items using local media paths, see the [path matching guide](guides/path-match.md).

To add your backends, click the help button in the top-right corner and choose [one-way](guides/one-way-sync.md) or
[two-way](guides/two-way-sync.md) sync. Follow the instructions in the selected guide.

Once you have added your backends and imported your data you should see something like

![WebUI](/screenshots/index.jpg)

### Supported import methods

Currently, the tool supports three methods to import data from backends.

- **Scheduled Tasks**.
    - `A scheduled job that pulls data from backends.`
- **On demand**.
    - `Pull data from backends on demand. By running the import task manually.`
- **Webhooks**.
    - `Receive events from backends and update the database accordingly.`

> [!NOTE]
> Keep the import task enabled even when all your backends support webhooks. It can pick up missed events. See the
> [webhook guide](/guides/webhooks.md) for backend limitations.

# FAQ

Take look at this [frequently asked questions](FAQ.md) page, or the [guides](/guides/) for more in-depth guides on how
to configure things.

# Social channels

If you have questions or want to chat with other users, join the [Discord server](https://discord.gg/haUXHJyj6Y).
This is a solo project, so replies may take some time. I’m based in the `UTC+3` timezone.

# Donate

I don’t accept donations. If you want to support the project, consider donating to a children’s charity such as
[Make-A-Wish](https://worldwish.org).

# Disclaimer

AI-based tools may have been used to assist with parts of this project. Regardless of how a change is produced, every 
change is reviewed and approved by the human maintainer before it is included.
