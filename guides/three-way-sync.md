# Three-Way Sync

Three-way sync allows you to sync two separate accounts with a shared account between them. This is useful when you have
a family account and want to keep it synchronized with individual user accounts without those individual accounts
conflicting with each other.

# Use Cases

- You have a family account and separate accounts for yourself and your spouse, and you want to sync the family account
  with both individual accounts.
- You want play state from the shared account to flow to both individual accounts without those accounts overwriting
  each other.
- You need to maintain separate viewing histories while keeping a central family account synchronized.

# How to Set Up Three-Way Sync

## Step 1: Set Up the Shared Account

Follow the [one-way sync guide](./one-way-sync.md) Once the setup is complete you need to remove the shared user's data
folder to prevent conflicts:

1. Access your WatchState shell or terminal
2. Navigate to `/config/users/`
3. Delete the shared user's folder

> [!IMPORTANT]
> Removing the shared user folder is necessary because the shared account will be manually configured in both individual
> user profiles to prevent data conflicts.

## Step 2: Add the Shared Account to Individual Backends

For each individual account (yours and your spouse's)

> [!NOTE]
> you can switch via the <!--i:fa-users--> user dropdown in the top-right corner of the interface.

1. Go to <!--i:fa-server--> **Backends** and click on the <!--i:fa-plus--> **Add Backend** button
2. Follow the usual add backend process to add the share account.
3. Configure the settings as follows:
    - *`Name`*: Choose a unique name that isn't already used. e.g. `shared_family_one` or `shared_family_two`
    - *`Import play and progress updates from this backend?`*: Yes
    - *`Send play and progress updates to this backend?`*: No

> [!IMPORTANT]
> Keeping *`Import play and progress updates from this backend?`* enabled ensures the shared account's watch state
> overrides the individual accounts. Disabling *`Send play and progress updates to this backend?`* prevents individual
> accounts from modifying the shared account's data.

# Verify the Setup

Once configured, your sync flow should work as follows:

- The shared account imports data to WatchState
- Both individual accounts receive updates from the shared account
- Individual accounts do not send updates back to the shared account
- Individual accounts do not interfere with each other

To verify everything is working correctly:

1. Go to the <!--i:fa-history--> **History** page
2. Check that watch state from the shared account appears in both individual accounts
3. Verify that changes in individual accounts do not affect the shared account

# Side Effects and Considerations

- If you force import the shared account data it will override your existing playstate. DO NOT force import if you want
  to keep existing data.
- It's not possible to sync old data as it will override your existing playstate. Only new watch states will be synced.
- Be cautious when modifying settings in individual accounts, as changes may affect how data is imported from
  the shared account.
- Before making the setup always have a backup of your data in case you need to restore it.
