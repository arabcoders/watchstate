# Two-Way Sync

Two-way sync in WatchState helps keep your `play progress` and `watch state` synchronized across multiple backends. It’s
called "many-to-many" sync, meaning you can sync data between several backends, and they all stay up to date with each
other. This sync is powered by WatchState's `import` and `export` features.

# Use Cases

- If you watch a show on Plex and want to continue it on Jellyfin or Emby, two-way sync ensures your progress is saved.
- Keep your media backends synced so you always know where you left off.

# How Sync Works

WatchState first pulls the latest play and progress information from your backends, then stores it locally this is the
`import` process. The system checks to ensure the data is up-to-date, and older data is saved as metadata without
overriding the most current watch state.

On the export side, we compare the backend's last sync date with any local changes. From there, we create a list of
items that need updating for each backend. If there are only a few changes, we trigger a quick sync operation
`push mode`. If the changes are more extensive, we perform a full export, which compares all remote data with the local
data. This full export only happens when there are many changes and/or metadata is missing from the backend, which is
why it's crucial to keep the `Import play and progress updates from this backend?` or, at a minimum, the
`Import metadata from this backend?` option enabled.

# Setting Up Two-Way Sync

To set up two-way sync, follow the steps below:

### Step 1: Setting Up The Backends.

First, make sure you have completed the [one-way sync guide](/guides/one-way-sync.md) to get your backends synced.

### Step 2: Enable Sync Sliders.

Go to the <!--i:fa-server--> *Backends* page. Here, you'll see two sliders for each backend: `Import` and `Export`.

- The `Import` slider brings data from the backend into WatchState.
- The `Export` slider sends data from WatchState to the backend.

When you're sure the data looks correct, turn on the `Export` slider for your main backend and the `Import` slider for
the others. This will keep your backends synced.

# Enable Scheduled Tasks

If everything looks good and you want WatchState to automatically sync your backends, do the following:

Go to the <!--i:fa-tasks--> **Tasks** page. Enable the two tasks by toggling the sliders next to `Import` and `Export`.

### Tuning The run schedule

To control how often these tasks run, go to the <!--i:fa-cogs--> **Env** page, click the <!--i:fa-plus--> **Add**
button, and select the environment variables `WS_CRON_EXPORT_AT` and `WS_CRON_IMPORT_AT`. These variables use CRON timer
expressions. For example, if you want the export task to run every 6 hours, set `WS_CRON_EXPORT_AT` to `0 */6 * * *`.
For more help with CRON expressions, visit [crontab.guru](https://crontab.guru/).

> [!IMPORTANT]  
> The `Import` task can be resource-intensive, especially for large libraries. It may take some time to complete and
> could use a lot of CPU power. It’s recommended to run it a few times a day, with every 6 hours being a good starting
> point.

# Enable Webhooks

For even faster sync operations, you can enable webhooks. For more details, check out
the [webhooks guide](/guides/webhooks.md).

# Troubleshooting

TBA
