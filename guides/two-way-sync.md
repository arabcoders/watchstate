# Two-Way Sync

Two-way sync lets WatchState exchange `play progress` and `watch state` between multiple backends. It uses the `import`
and `export` features, so each backend can provide data and receive updates.

# Use Cases

- If you watch a show on Plex and continue it on Jellyfin or Emby, two-way sync sends the progress between those
  backends.
- The configured backends exchange play state through the local WatchState database.

# How Sync Works

WatchState first pulls play and progress information from the backends and stores it locally. This is the `import`
process. Older data is retained as metadata and does not replace the latest local watch state.

During export, WatchState compares the backend's last sync date with local changes and builds a list of updates for each
backend. A small change uses `push mode`. Larger changes use a full export, which compares remote and local data. Keep
`Enable Import` enabled unless the backend should remain in metadata-only mode.

# Setting Up Two-Way Sync

To set up two-way sync, follow the steps below:

### Step 1: Setting Up The Backends.

First, make sure you have completed the [one-way sync guide](/guides/one-way-sync.md) to get your backends synced.

### Step 2: Enable Sync Sliders.

Go to the <!--i:i-lucide-server--> *Backends* page. Here, you'll see two switches for each backend: `Import` and `Export`.

- The `Import` switch brings data from the backend into WatchState.
- The `Export` switch sends data from WatchState to the backend.

When you're sure the data looks correct, turn on the `Export` switch for your main backend and the `Import` switch for
the others. This will keep your backends synced.

# Enable Scheduled Tasks

If everything looks good and you want WatchState to automatically sync your backends, do the following:

Go to the <!--i:i-lucide-list-checks--> **Tasks** page. Enable the two tasks by toggling the switches next to `Import` and `Export`.

### Tuning The run schedule

To control how often these tasks run, go to the **Configuration** > <!--i:i-lucide-sliders-horizontal--> **Environment** page, click the <!--i:i-lucide-plus--> **Add**
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
