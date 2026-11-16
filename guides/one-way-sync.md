# One-Way Sync

One-way sync in WatchState allows you to sync data from one backend to one or more other backends without affecting the
data in the source backend. This is useful for scenarios where you want to back up your data or sync it to a different
environment without modifying the original data.

# Use Cases

- You want to try Jellyfin, Plex, or Emby without changing your original data.
- You want to keep your Jellyfin, Emby, or Plex backend in sync with your preferred media backend.
- You’ve created a new media backend that doesn’t have your play state yet, and you want to sync it with your main
  backend.

# How to Set Up One-Way Sync

### Adding your backend with has the most accurate data

First, go to <!--i:i-lucide-server--> **Backends** and click on the <!--i:i-lucide-plus--> **Add Backend** button. Follow the
interactive setup guide. In **Sync & One-Time Operations**, disable *`Enable Export`*
for your main backend so its data isn't changed. Keep *`Enable Import`*
enabled to import data from the backend.

Enable the *`Force one time import from this backend`* switch to import all current data from the backend into WatchState.

> [!NOTE]
> It’s recommended to keep *`Create backup for this backend data`* enabled. This will create a
> snapshot of your backend data so you can restore it if anything goes wrong. You can access
> backups from **Settings** > <!--i:i-lucide-hard-drive-download--> **Backups**.

# Importing the Data

The import process may take some time, depending on the size of your library. The <!--i:i-lucide-server--> **Backends**
page shows the recorded import time under **Last Import (Full)**. A recent time alone doesn't confirm completion.

To check completion or failure, visit **Activity** > <!--i:i-lucide-scroll-text--> **Logs**, select **Task** under **Type**,
and click the `task.YYYYMMDD.jsonl` filename. Or open **Activity** > <!--i:i-lucide-calendar-days--> **Events**, set **Status** to
**Any status**, open **Search events**, enter `run_console` under **Event name**, then click **Apply search**.
Click the event name to inspect its status and saved logs. Check the user and backend to confirm it belongs to your import.
Check the outcome and any reported failures before continuing. A completion summary looks like this:

```text
Import process completed in 'XXX's for all users.
```

Once the import is done, go to <!--i:i-lucide-history--> **History** > **Records** to view the imported items. You can also
navigate through the data to check its accuracy. Once satisfied, proceed to the next step.

# Adding Other Backends

For each backend, follow these steps:

### Step 1

Do exactly as you did for the main backend, but make the following changes:

- *`Enable Import`*: No
- *`Enable Export`*: Yes
- *`Force export local data to this backend`*: This depends on your setup:
    - If you have only one extra backend and have already imported your main backend data, enable this switch and skip Step 2.
    - If you have multiple backends, keep this option disabled and proceed to Step 2.

> [!IMPORTANT]  
> These settings prevent this backend from importing data and changing the main backend. When *`Enable Import`* is
> disabled, WatchState keeps metadata refresh enabled for that backend.

### Step 2

You have two options:

##### Option 1 (One Extra Backend)

Go to the <!--i:i-lucide-server--> **Backends** page, and under the backend, there is a `Quick operations` list. Select
*`3. Force export local play state to this backend.`* Once selected, you'll be redirected to
the **Activity** > <!--i:i-lucide-terminal--> **Console** page, where the command will be pre-filled
for you. Press *Enter* or click the <!--i:i-lucide-send--> **Run command** button.

##### Option 2 (Multiple Backends)

If you have multiple backends, skip Step 2 for now and add all your backends. Once all backends are added, go to
the **Activity** > <!--i:i-lucide-terminal--> **Console** page and run the following command:

```bash
state:export -fi -v -u main
```

This command will force export your locally stored play state to all **export enabled** backends. Once that’s done,
proceed to the next step.

# Enable Scheduled Tasks

If you’re happy with the setup and want to automate the process, go to **Activity** > <!--i:i-lucide-list-checks--> **Tasks**. Turn on the
**Enabled** switches for the *`Import`* and *`Export`* tasks. These schedules apply across identities.

Click a task's **Timer** value to edit `WS_CRON_EXPORT_AT` or `WS_CRON_IMPORT_AT` in **Settings > Environment**.
For example, to run the export task every 6 hours, set `WS_CRON_EXPORT_AT` to `0 */6 * * *`, then click **Save**.
The **Schedule** link opens [crontab.guru](https://crontab.guru/) for help with cron expressions.

> [!IMPORTANT]  
> The `Import` task can be resource-intensive, especially for large libraries. It may take some time to complete and
> could use a lot of CPU power. It’s recommended to run it a few times a day, with every 6 hours being a good starting
> point.

# Enable Webhooks

For faster sync operations, you can enable webhooks. For more information, check out
the [webhooks guide](/guides/webhooks.md).

# Further Reading

If you are satisfied with the results and want to enable two-way sync, check out
the [two-way sync guide](/guides/two-way-sync.md).

# Troubleshooting

TBA
