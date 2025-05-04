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

First, go to <!--i:fa-server--> **Backends** and click on the <!--i:fa-plus--> **Add Backend** button. Follow the
interactive setup guide. When you reach the step asking *`Export data to this backend?`*, disable it, this applies to
your main backend as you don’t want to alter its data. Keep *`Import data from this backend?`* enabled, which will allow
you to import data from the backend.

Enable the *`Force one time import from this backend?`* option to import your current data into WatchState. This will
bring all the data from the backend into WatchState.

> [!NOTE]
> It’s recommended to keep *`Create backup for this backend data?`* enabled. This will create a
> snapshot of your backend data so you can restore it if anything goes wrong. You can access
> backups from <!--i:fa-tools--> **Tools** > <!--i:fa-sd-card--> **Backups**.

# Importing the Data

The import process may take some time, depending on the size of your library. To check the import status, go to
the <!--i:fa-server--> **Backends** page and check the `last import date` for the backend. If it shows the current time,
the import is finished.

Alternatively, visit the <!--i:fa-globe--> **Logs** page and look at the `task.YYYYMMDD.log` file, or go to
the <!--i:fa-ellipsis-vertical--> **More** > <!--i:fa-calendar-alt--> **Events** page and look for the *`run_console`*
events. The logs page is updated more frequently, so we recommend using it. Once the import is complete, you should see
a message like this:

```text
[DD/MM HH:mm:SS]: NOTICE: SYSTEM: Completed 'XXX' requests in 'XXX.XXX's for 'main' backends.

┌─────────┬───────┬─────────┬────────┐
│ Type    │ Added │ Updated │ Failed │
├─────────┼───────┼─────────┼────────┤
│ Movie   │ XXX   │ XXX     │ XXX    │
├─────────┼───────┼─────────┼────────┤
│ Episode │ XXX   │ XXX     │ XXX    │
└─────────┴───────┴─────────┴────────┘
```

Once the import is done, go to the <!--i:fa-history--> **History** page to view the imported items. You can also
navigate through the data to check its accuracy. Once satisfied, proceed to the next step.

# Adding Other Backends

For each backend, follow these steps:

### Step 1

Do exactly as you did for the main backend, but make the following changes:

- *`Import data from this backend?`*: No
- *`Import metadata only from this backend?`*: Yes
- *`Export data to this backend?`*: Yes
- *`Force Export local data to this backend?`*: This depends on your setup:
    - If you have only one extra backend and have already imported your main backend data, select *Yes* and skip Step 2.
    - If you have multiple backends, keep this option disabled and proceed to Step 2.

> [!IMPORTANT]  
> Selecting the correct options is crucial to avoid altering the data in the main backend. The
> *`Import metadata only from this backend?`* option will only appear if you have *`Import data from this backend?`*
> disabled.

### Step 2

You have two options:

##### Option 1 (One Extra Backend)

Go to the <!--i:fa-server--> **Backends** page, and under the backend, there is a `Quick operations` list. Select
*`2. Force export local play state to this backend.`* Once selected, you'll be redirected to
the <!--i:fa-ellipsis-vertical--> **More** > <!--i:fa-terminal--> **Console** page, where the command will be pre-filled
for you. Just hit *Enter* or click the <!--i:fa-terminal--> **Execute** button.

##### Option 2 (Multiple Backends)

If you have multiple backends, skip Step 2 for now and add all your backends. Once all backends are added, go to
the <!--i:fa-tools--> **Tools** > <!--i:fa-terminal--> **Console** page and run the following command:

```bash
state:export -fi -v -u main
```

This command will force export your locally stored play state to all **export enabled** backends. Once that’s done,
proceed to the next step.

# Enable Scheduled Tasks

If you’re happy with the setup and want to automate the process, go to the <!--i:fa-tasks--> **Tasks** page. Enable the
two tasks by toggling the sliders next to *`Import`* and *`Export`*.

To adjust how often the tasks run, go to the <!--i:fa-cogs--> **Env** page, click on the <!--i:fa-plus--> **Add**
button, and select the relevant environment variables: `WS_CRON_EXPORT_AT` and `WS_CRON_IMPORT_AT`. These variables
accept CRON timer expressions. For example, to run the export task every 6 hours, set `WS_CRON_EXPORT_AT` to
`0 */6 * * *`.  
For more info about CRON expressions, visit [crontab.guru](https://crontab.guru/).

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
