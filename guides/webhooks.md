# Webhooks

## Getting started

Webhooks are a powerful and fast way to sync your backends nearly instantly. They are triggered by user actions, such as
play, pause, stop, etc., on a backend. When an action is performed, the backend sends a webhook to the WatchState API,
which processes the data, updates the system, and triggers events to the other backends.

Although webhooks are great for syncing data quickly, they should not be used as the sole method for synchronization.
Webhooks are not 100% reliable, they may be missed or delayed. To ensure your data is always up-to-date, it’s
recommended to use webhooks in combination with scheduled tasks with high scheduled time i.e. `every 12 hours`.

We have re-designed the webhook system to be generic rather than backend and user specific, so that means you only need
to use single webhook for all users and backends. This is a big improvement over the previous system, which required you
to create a separate webhook for each user and backend.

## Improvements over the old system

- **Generic**: The new webhook system is generic, meaning you can use a single webhook for all users and backends.
- **Improved performance**: The new webhook system is faster and more efficient than the old system.
- **Support generic events**: The new webhook system supports generic events which don't contain userdata, by triggering
  the webhook event to all users that match the same backend ID, Previously it wasn't possible to do this.
    - For example, jellyfin `ItemAdded` event doesn't contain user data, so the webhook event will be triggered to all
      users that match the same backend ID.
    - Events marked as generic are `jellyfin.ItemAdded`, `plex.library.new`, `tautulli.created`, and `emby.library.new`.

## Restrictions

- This endpoint enforces matching backend id regardless of the **Enable match backend id for webhook?** setting.
- This endpoint enforces matching user id for non-generic events regardless of the **Enable match user for webhook?**
  setting.

Eventually, we will be removing the old webhook system alongside the related settings. The new system is designed to be
more user-friendly and enforces good practices and defaults, as we noticed many users don't really understand the old
system and how to set it up correctly.

## The generic webhook URL

The new webhook generic URL is `/v1/api/webhook`, of course, if you have enabled secure all endpoints you need to
add `?apikey=your_ws_apikey` to the URL. which you can obtain by going to <!--i:fa-ellipsis-vertical-->
*More* > <!--i:fa-terminal--> *Terminal* and then write `system:apikey` in the box. You should get the apikey which is
hexadecimal string.

If you don't have `WS_SECURE_API_ENDPOINTS` enabled:

```
https://your_ws_url/v1/api/webhook
```

If you have enabled `WS_SECURE_API_ENDPOINTS` environment variable, then you need to add the apikey to the URL:

```
https://your_ws_url/v1/api/webhook?apikey=[api_key_you_got_from_terminal]
```

# Adding Webhooks to Your Backends

### Emby (Emby Premiere Required)

1. Go to your Emby Server:
    - **Old Emby Versions**: Go to *Server > Webhooks* and click *Add Webhook*.
    - **New Emby Versions**: Go to *username Preferences > Notifications > + Add Notification > Webhooks*.

2. **Name**:
    - Whatever you want, we recommend **WatchState Global Webhook**.

3. **Webhook/Notifications URL**:
    - see [The generic webhook URL](#the-generic-webhook-url) section.

4. **Request Content Type (Emby v4.9+)**:
    - Select `application/json`.

5. **Webhook Events (v4.7.9 or higher)**:
    - New Media Added
    - Playback
    - Mark Played
    - Mark Unplayed

   For versions prior to v4.7.9:
    - Playback events
    - User events

6. **Limit User Events to**:
    - Select all users, otherwise events will not contain any user data.

7. **Limit Library Events to**:
    - Select libraries you want to sync or leave it blank for all libraries.

Click *Add Webhook / Save*.

### Jellyfin (Free)

1. Go to your Jellyfin dashboard, then navigate to *Plugins > Catalog* and install *Notifications > Webhook*. Restart
   Jellyfin.
2. After the restart, go back to *Plugins > Webhook* and add `Add Generic Destination`.

3. **Webhook Name**:
    - Whatever you want, we recommend **WatchState Global Webhook**.

4. **Webhook URL**:
    - see [The generic webhook URL](#the-generic-webhook-url) section.

5. **Notification Type**:
    - Item Added
    - User Data Saved
    - Playback Start
    - Playback Stop

6. **User Filter**:
    - Select all users, otherwise events will not contain any user data.

7. **Item Type**:
    - Select *Movies* and *Episodes*.

8. **Send All Properties**:
    - Toggle this checkbox.

9. **Trim leading and trailing whitespace from message body before sending*:
    - Toggle this checkbox.

10. **Do not send when message body is empty**:
    - Toggle this checkbox.

11. **Click on Add Request Header** and add the following header:
    - **Key**: `Content-Type`
    - **Value**: `application/json`

Click *Save*.

### Plex (PlexPass Required)

1. Go to your Plex Web UI and navigate to *Settings > Your Account > Webhooks*. Click *Add Webhook*.

2. **Webhook URL**:
    - see [The generic webhook URL](#the-generic-webhook-url) section.

Click *Save Changes*.

### Plex via Tautulli

1. Go to *Options > Notification Agents* and click *Add a new notification agent > Webhook*.

2. **Webhook URL**:
    - see [The generic webhook URL](#the-generic-webhook-url) section.

3. **Webhook Method**:
    - Select `PUT`.

4. **Description**:
    - Whatever you want, we recommend **WatchState Global Webhook**.

5. **Triggers**:  
   Select the following events:
    - Playback Start
    - Playback Stop
    - Playback Pause
    - Playback Resume
    - Watched
    - Recently Added
6. **Data**:
    - For each event, you will need to set the corresponding headers/data fields using the following format.

> [!IMPORTANT]  
> It’s important that you copy the headers and data as they are, without modifying them if you're unsure.

**JSON Headers**:

```json
{
    "user-agent": "Tautulli/{tautulli_version}"
}
```

**JSON Data**:

```json
{
    "event": "tautulli.{action}",
    "Account": {
        "id": "{user_id}",
        "thumb": "{user_thumb}",
        "title": "{username}"
    },
    "Server": {
        "title": "{server_name}",
        "uuid": "{server_machine_id}",
        "version": "{server_version}"
    },
    "Player": {
        "local": "{stream_local}",
        "publicAddress": "{ip_address}",
        "title": "{player}",
        "uuid": "{machine_id}"
    },
    "Metadata": {
        "librarySectionType": null,
        "ratingKey": "{rating_key}",
        "key": null,
        "parentRatingKey": "{parent_rating_key}",
        "grandparentRatingKey": "{grandparent_rating_key}",
        "guid": "{guid}",
        "parentGuid": null,
        "grandparentGuid": null,
        "grandparentSlug": null,
        "type": "{media_type}",
        "title": "{episode_name}",
        "grandparentKey": null,
        "parentKey": null,
        "librarySectionTitle": "{library_name}",
        "librarySectionID": "{section_id}",
        "librarySectionKey": null,
        "grandparentTitle": "{show_name}",
        "parentTitle": "{season_name}",
        "contentRating": "{content_rating}",
        "summary": "{summary}",
        "index": "{episode_num}",
        "parentIndex": "{season_num}",
        "audienceRating": "{audience_rating}",
        "viewOffset": "{view_offset}",
        "skipCount": null,
        "lastViewedAt": "{last_viewed_date}",
        "year": "{show_year}",
        "thumb": "{poster_thumb}",
        "art": "{art}",
        "parentThumb": "{parent_thumb}",
        "grandparentThumb": "{grandparent_thumb}",
        "grandparentArt": null,
        "grandparentTheme": null,
        "duration": "{duration_ms}",
        "originallyAvailableAt": "{air_date}",
        "addedAt": "{added_date}",
        "updatedAt": "{updated_date}",
        "audienceRatingImage": null,
        "userRating": "{user_rating}",
        "Guids": {
            "imdb": "{imdb_id}",
            "tvdb": "{thetvdb_id}",
            "tmdb": "{themoviedb_id}",
            "tvmaze": "{tvmaze_id}"
        },
        "file": "{file}",
        "file_size": "{file_size_bytes}"
    }
}
```

Click *Save*.

# Media Backends Webhook Limitations

Here are some known limitations and issues when using webhooks with different media backends:

### Plex

- Plex doesn't send events for *marked as played/unplayed* actions.
- Webhook events may be **skipped** when multiple items are added at once.
- When items are marked as **unwatched**, Plex resets the date on the media object.
- In old version of plex i.e. pre `1.41.6.9606` marking items as watched and if you didn't have progress on the show
  will not show the item in continue watching, this is a limitation of the old plex version and not
  watchstate. [reference](https://forums.plex.tv/t/continue-watching-is-buggy-unable-to-figure-out-why/869224/65)
- Plex doesn't send watch progress update events during playback, it only sends the progress update during `play`,
  `pause`, `stop`, `resume` events. So the progress update from plex will not be reflected until one of those events
  kicks in.

### Plex via Tautulli

- **Marking items as unplayed** is not reliable, as Tautulli’s webhook payload lacks the data needed to detect this
  change.
- Similarly to plex, Tautulli doesn't send watch progress update events during playback.

### Emby

- The **webhook test event** previously contained no data, but this issue appears to be fixed in version `4.9.0.37+`.
    - To verify if your Emby webhook setup works, try playing or marking an item as played/unplayed, and check if the
      changes appear in the database.

### Jellyfin

- Even if a user ID is selected, Jellyfin may **still send events without user data**.
- Items may be marked as **unplayed** if the setting *Libraries > Display > Date Added Behavior for New Content: Use
  Date Scanned into Library* is enabled.
    - This can happen when media files are replaced or updated.

# Sometimes Newly Added Content Does Not Show Up

As previously mentioned, webhooks aren't 100% reliable, thus it's recommended to enable **import/export tasks** to
complement webhook functionality.

Simply go to the *Tasks* page and enable the *Import* and *Export* tasks. and set the schedule to `every 12 hours` or
`every 24 hours` depending on your needs.

# Troubleshooting

TBA
