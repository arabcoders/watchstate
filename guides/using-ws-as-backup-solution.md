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

- *`Enable Import`*: **Yes**
- *`Enable Export`*: **No**
- *`Create backup for this backend data`*: **No**
- *`Force one time import from this backend`*: **No**

These switches are in the add-backend wizard's **Sync & One-Time Operations** section. For an existing backend,
click **Edit**, open **Sync direction**, enable **Import** and disable **Export**, then click **Save settings**.

> [!NOTE]
> If using **Plex**, use an admin-level token. Open **Backends**, click the backend name, then open **Users**
> to check that WatchState can load the expected users. Limited Plex tokens cannot load this list.
> For **Jellyfin** or **Emby**, use an API key from your server settings.

### Step 2: Enable single backend mode for identities

After your backend is configured:

1. Open **Settings** > <!--i:i-lucide-users--> **Identities** in the sidebar, then click **Match & Provision**.
2. Under **Execution options**, find **Allow single backend identities**.
3. Check this option to enable single backend mode.
4. Disable the option *`Generate remote backups`*.
5. Click **Create identities**. Users from that backend (except the main identity) will be created as individual identities.

> [!NOTE]
> Single backend mode still respects PIN settings for protected Plex users. You can set PINs by clicking the lock icon
> next to each user.

### Step 3: Run the initial import

After adding the users, open **Activity** > <!--i:i-lucide-list-checks--> **Tasks** and click **Run** for
the `Import` task. Choose **Queue task** in the dialog to import the existing play state into WatchState.

The task will take some time depending on your library size. To monitor it, open **Activity** >
<!--i:i-lucide-scroll-text--> **Logs**, select **Task** under **Type**, and click the
`task.YYYYMMDD.jsonl` filename. Check for failures before continuing. A completion summary looks like this:

```
Import process completed in 'XXX's for all users.
```

# Backing up your data

Once WatchState has imported your playstate, keep both import and backup tasks enabled. Open **Activity** >
<!--i:i-lucide-list-checks--> **Tasks** and turn on **Enabled** for `Import` and `Backup`. These schedules apply across identities.

### Automatic backups

WatchState creates automatic backups if you enabled the *`backup`* task. To access and use these backups go
to **Settings** > <!--i:i-lucide-hard-drive-download--> **Backups**.

Click a filename to download a copy. Scheduled cleanup removes automatic backups according to `WS_BACKUP_PRUNE_AFTER`,
so download files you want to keep.

Click **Queue backup** to queue the global backup task.
While it is pending, **Cancel queued backup** cancels the queued task, not a running backup.

### Manual backups

For additional safety, regularly back up watchstate `/config/` directory, which contains all the necessary data to
restore your playstate.

# Restoring from backup

If your server fails or data is lost, you have two options for restoring the playstate.

### Option 1: Restore from automatic backups

1. Open **Settings** > <!--i:i-lucide-hard-drive-download--> **Backups**.
2. Find the desired backup using **Type** or **Find files**.
3. Click **Prepare restore** for that file.
4. In the dialog's **Restore to** picker, select the target backend under the desired identity.
5. Click **Continue to Console** to open **Activity > Console** with a prefilled restore command. Nothing runs yet.
6. Review the file, identity, and backend, then click **Run command** to change playstate on the selected media server.

Use this option to restore playstate on the media server you selected, not to recover your WatchState installation.
If **Import** is enabled, later imports can bring the restored state into WatchState and sync it to other backends
for that identity.

### Option 2: Restore from active db

1. Click the <!--i:i-lucide-users--> **Identity** button in the header to open **Change Identity**.
2. Select the desired identity under **Browse as**.
3. Click on the **Reload** button to switch to that identity context.
4. Go to the <!--i:i-lucide-server--> **Backends** page.
5. Under your backend in the **Quick operations** list, select **3. Force export local play state to this backend.**
6. **Activity > Console** opens with a prefilled export command. Review the identity and backend, then click **Run command**
   to push the local playstate to your server.
7. Repeat for each identity you want to restore.

# Multi-Backend Sync vs. Single Backend Backup

If you have multiple media servers and want to **synchronize playstate between them**, refer to the
[Two-Way Sync guide](/guides/two-way-sync.md) instead. Single backend backup mode is specifically for preserving
playstate from a single server.
