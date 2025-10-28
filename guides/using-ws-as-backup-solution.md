# Using WatchState as a backup solution

WatchState can be used as a backup solution to preserve and restore user playstate across your media servers. This
guide explains how to set up WatchState for backing up user playstate, specifically using single backend mode, which is
designed for this purpose.

## Benefits of single backend backup mode

- **Simplified setup**: No user mapping required. Each user from your backend gets their own sub-user configuration.
- **Dedicated purpose**: Focus purely on preserving playstate without syncing to multiple servers.
- **Individual user backups**: Back up each user's data independently, allowing selective restoration if needed.
- **Ideal for disaster recovery**: Restore individual user data without affecting others on your system.
- **Perfect for single server setups**: If you only use one media server, this mode is for you.

## How single backend backup works

When you enable single backend mode:

1. Each user from your media server (except the main user) gets their own sub-user configuration.
2. WatchState imports and stores their playstate data locally.
3. If your server fails, you can restore the backed-up playstate to a new server instance.

# Setting up single backend backup mode

### Step 1: Add your backend

First, go to <!--i:fa-server--> **Backends** and click on the <!--i:fa-plus--> **Add Backend** button. Follow the
interactive setup guide. Configure your media server backend (Plex, Jellyfin, or Emby) with the following settings:

- *`Import play and progress updates from this backend?`*: **Yes**
- *`Send play and progress updates to this backend?`*: **No**
- *`Create backup for this backend data?`*: **No**
- *`Force one time import from this backend?`*: **No**

> [!NOTE]
> If using **Plex**, ensure your token is admin-level. Verify it at
> <!--i:fa-tools--> **Tools** > <!--i:fa-key--> **Plex Token**. For **Jellyfin** or **Emby**, use an API key from
> your server settings.

### Step 2: Enable single backend mode for sub-users

After your backend is configured:

1. Navigate to <!--i:fa-tools--> **Tools** > <!--i:fa-users--> **Sub Users**.
2. You should see an **"Allow single backend users"** checkbox.
3. Check this option to enable single backend mode.
4. Disable the option *`Create initial backup for each sub-user remote backend data.`*
5. All users from that backend (except the main user) will be created as individual sub-users.

> [!NOTE]
> Single backend mode still respects PIN settings for protected Plex users. You can set PINs by clicking the lock icon
> next to each user.

### Step 3: Run the initial import

Now that you have added the all users, simply go to the <!--i:fa-tasks--> **Tasks** page and queue the `Import` task to
start importing playstate. To bring all existing playstate into WatchState.

The task will take some time depending on your library size. Monitor progress at the <!--i:fa-globe--> **Logs** page by
checking the `task.YYYYMMDD.log` file. Once the task completed you should see something like

```
[DD/MM HH:MM:SS]: SYSTEM: Import process completed in 'XXX's for all users.
```

# Backing up your data

Once WatchState has imported your playstate, make sure to keep both import task and backup task enabled. by visiting
the <!--i:fa-tasks--> **Tasks** page and toggling the sliders next to `Import` and `Backup`.

### Automatic backups

WatchState creates automatic backups if you enabled the *`backup`* task. To access and use these backups go
to <!--i:fa-tools--> **Tools** > <!--i:fa-sd-card--> **Backups**.

### Manual backups

For additional safety, regularly back up watchstate `/config/` directory, which contains all the necessary data to
restore your playstate.

# Restoring from backup

If your server fails or data is lost, you have two options for restoring the playstate.

### Option 1: Restore from automatic backups

1. Go to <!--i:fa-tools--> **Tools** > <!--i:fa-sd-card--> **Backups**.
2. Select the desired backup from the list.
3. From the dropdown list select which users you want to restore the backup to.
4. Click the **Go** button.

### Option 2: Restore from active db

1. From the header select <!--i:fa-users--> users icon.
2. Select the desired user you want to restore.
3. Click on the **Reload** button to switch to that user's context.
4. Go to the <!--i:fa-server--> **Backends** page.
5. Under your backend in the `Quick operations` list, select *`3. Force export local playstate to this backend.`*
6. Execute the command to push all restored playstate to your server.
7. Repeat for each user you want to restore.

# Multi-Backend Sync vs. Single Backend Backup

If you have multiple media servers and want to **synchronize playstate between them**, refer to the
[Two-Way Sync guide](/guides/two-way-sync.md) instead. Single backend backup mode is specifically for preserving
playstate from a single server.
