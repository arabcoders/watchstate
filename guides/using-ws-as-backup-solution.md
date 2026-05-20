# Using WatchState as a backup solution

WatchState can be used as a backup solution to preserve and restore user playstate across your media servers. This
guide explains how to set up WatchState for backing up user playstate, specifically using single backend mode, which is
designed for this purpose.

## Benefits of single backend backup mode

- **Simplified setup**: No user mapping required. Each user from your backend gets their own identity configuration.
- **Dedicated purpose**: Focus purely on preserving playstate without syncing to multiple servers.
- **Individual user backups**: Back up each user's data independently, allowing selective restoration if needed.
- **Ideal for disaster recovery**: Restore individual user data without affecting others on your system.
- **Perfect for single server setups**: If you only use one media server, this mode is for you.

## How single backend backup works

When you enable single backend mode:

1. Each user from your media server (except the main identity) gets their own identity configuration.
2. WatchState imports and stores their playstate data locally.
3. If your server fails, you can restore the backed-up playstate to a new server instance.

# Setting up single backend backup mode

### Step 1: Add your backend

First, go to <!--i:i-lucide-server--> **Backends** and click on the <!--i:i-lucide-plus--> **Add Backend** button. Follow the
interactive setup guide. Configure your media server backend (Plex, Jellyfin, or Emby) with the following settings:

- *`Import play and progress updates`*: **Yes**
- *`Send play and progress updates`*: **No**
- *`Create backup for this backend data: Yes`*: **No**
- *`Force one time import from this backend: Yes`*: **No**

> [!NOTE]
> If using **Plex**, ensure your token is admin-level. Verify it at
> **Diagnostics** > <!--i:i-lucide-key-round--> **Plex Token**. For **Jellyfin** or **Emby**, use an API key from
> your server settings.

### Step 2: Enable single backend mode for identities

After your backend is configured:

1. Navigate to **Configuration** > <!--i:i-lucide-users--> **Identities** > **Match & Provision**.
2. You should see an **"Allow single backend identities"** switch.
3. Check this option to enable single backend mode.
4. Disable the option *`Generate remote backups`*.
5. All users from that backend (except the main identity) will be created as individual identities.

> [!NOTE]
> Single backend mode still respects PIN settings for protected Plex users. You can set PINs by clicking the lock icon
> next to each user.

### Step 3: Run the initial import

Now that you have added the all users, simply go to the <!--i:i-lucide-list-checks--> **Tasks** page and queue the `Import` task to
start importing playstate. To bring all existing playstate into WatchState.

The task will take some time depending on your library size. Monitor progress at the <!--i:i-lucide-globe--> **Logs** page by
checking the `task.YYYYMMDD.jsonl` file. Once the task completed you should see something like

```
[DD/MM HH:MM:SS]: SYSTEM: Import process completed in 'XXX's for all users.
```

# Backing up your data

Once WatchState has imported your playstate, make sure to keep both import task and backup task enabled. by visiting
the <!--i:i-lucide-list-checks--> **Tasks** page and toggling the switches next to `Import` and `Backup`.

### Automatic backups

WatchState creates automatic backups if you enabled the *`backup`* task. To access and use these backups go
to **Operations** > <!--i:i-lucide-hard-drive-download--> **Backups**.

### Manual backups

For additional safety, regularly back up watchstate `/config/` directory, which contains all the necessary data to
restore your playstate.

# Restoring from backup

If your server fails or data is lost, you have two options for restoring the playstate.

### Option 1: Restore from automatic backups

1. Go to **Operations** > <!--i:i-lucide-hard-drive-download--> **Backups**.
2. Select the desired backup from the list.
3. From the dropdown list select which users you want to restore the backup to.
4. Click the **Go** button.

### Option 2: Restore from active db

1. From the header select <!--i:i-lucide-users--> identities icon.
2. Select the desired identity you want to restore.
3. Click on the **Reload** button to switch to that identity context.
4. Go to the <!--i:i-lucide-server--> **Backends** page.
5. Under your backend in the `Quick operations` list, select *`3. Force export local playstate to this backend.`*
6. Execute the command to push all restored playstate to your server.
7. Repeat for each identity you want to restore.

# Multi-Backend Sync vs. Single Backend Backup

If you have multiple media servers and want to **synchronize playstate between them**, refer to the
[Two-Way Sync guide](/guides/two-way-sync.md) instead. Single backend backup mode is specifically for preserving
playstate from a single server.
