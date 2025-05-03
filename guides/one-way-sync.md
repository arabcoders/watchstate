# One-way sync

One-way sync in WatchState is the ability to sync data from one backend to one or more backends without
effecting the data in the source backend. This is useful for scenarios where you want to keep a backup of your data or
sync data to a different environment without modifying the original data.

# Use cases

- you want to try jellyfin/plex/emby without altering your original data.
- you want to keep your jellyfin/emby/plex backend in sync with your preferred media backend.
- You made new media backend that doesn't have your play state yet, and you want to get it in sync with your main
  backend.

# How to set up one-way sync

First, go to <!--i:fa-server--> Backends and click on <!-- i:fa-plus --> plus button. follow the interactive setup
guide, when you reach the step with `Export data to this backend?`, select `No`, this instruction applies to the main
backend only. as you don't want to alter its data. Keep `Import data from this backend?` to `Yes`, this will allow you
to import data from the backend.

> [!NOTE]
> It's recommended to keep `Create backup for this backend data?` to `Yes`, this will create a snapshot of your
> backend data, so that you may return to it should something happens. via <!--i:fa-tools--> Tools > <!--i:fa-sd-card-->
> Backups.


Enable `Force one time import from this backend?` option to get your current data into WatchState. This will import all
the data from the backend into WatchState.

# Importing the data

It will take a while to import the data, depending on the size of your library.

To see the import status, you can go to <!--i:fa-globe--> Logs page, and check the `task.XXXXXXX.log` file. Or
via <!--i:fa-ellipsis-vertical--> More > <!--i:fa-calendar-alt--> Events page, and look for `run_console` event. We
recommend the tasks log as it's updated more frequently.

To know if it has finished, you can look for the following message:

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

Or go to the <!--i:fa-server--> Backends page, and check the backend last import date, if it shows the current time it
means the import is done.

After you see the message, you can go to <!--i:fa-history--> History page and see the import history. Navigate around to
check the data, once you are satisfied with the data, proceed to the next step.

# Adding the other backends

For each backend do these steps

## Step 1

Do exactly as you did for the main backend, But change the follow options:

- `Import data from this backend?`: No
- `Import metadata only from this backend?`: Yes
- `Export data to this backend?`: Yes
- `Force Export local data to this backend?`: depends
    - If you only have 1 extra backend and already have imported your main backend data, then select `Yes`, and skip
      step 2.
    - Otherwise, keep it disabled and proceed to step 2.

> [!IMPORTANT]
> It's really important that you select those options, otherwise you might inadvertently alter the data in the main
> backend.
>
> The option `Import metadata only from this backend?` only shows up when you select `No` for
`Import data from this backend?`.

## Step 2

You have two options,

### Option 1 (1 Extra backend)

Go to <!--i:fa-server--> Backends page beneath the backend there is `Quick operations` list, select
`2. Force export local play state to this backend.`. Once you select the option, you will be redirected to
the <!--i:fa-ellipsis-vertical--> More > <!--i:fa-terminal--> Console page. Once you are there, the command will be
pre-filled for you, just hit enter to run it. Or click on <!--i:fa-terminal--> Execute button.

### Option 2 (Multiple backends)

If you have multiple backends that you want to sync, then skip step 2 for now, and add all your backends.

Once you are done, go to <!--i:fa-tools--> Tools > <!--i:fa-terminal--> Console page, and run the following command:

```bash
state:export -fi -v -u main
```

This command will force export your locally stored play state to all export enabled backends. Once that is done proceed
to the next step.

## Enable Automation

If you are satisfied with the results, and you want to automate the process from now on, go to <!--i:fa-tasks--> Tasks
page. There are two tasks that you need to enable by clicking on the slider next to the name `Import` and `Export`.

To change how often the tasks runs, you have to go to <!--i:fa-cogs--> Env page, click on <!--i:fa-plus--> add button,
select the relevant environment variable. In this case, `WS_CRON_EXPORT_AT` and `WS_CRON_IMPORT_AT`. These two variables
accept valid CRON timer expressions. if you want to run the export task every 6 hours for example, you can set the
variable `WS_CRON_EXPORT_AT` value to `0 */6 * * *`. For more information about CRON expressions, check
out [crontab.guru](https://crontab.guru/).

## Enabling webhooks

To know how to enable webhooks for faster sync operations, Please check out the webhooks guide.

