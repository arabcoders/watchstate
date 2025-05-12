# Webhooks

Webhooks are a powerful and fast way to sync your backends nearly instantly. They are triggered by user actions, such as play, pause, stop, etc., on a backend. When an action is performed, the backend sends a webhook to the WatchState API, which processes the data, updates the system, and triggers events to the other backends.

Although webhooks are great for syncing data quickly, they should not be used as the sole method for synchronization. Webhooks are not 100% reliable—they may be missed or delayed. To ensure your data is always up-to-date, it’s recommended to use webhooks in combination with scheduled tasks.

## Getting started

Webhook URLs are **user and backend-specific**, think of them as **identification ID** for that user and backend. So, you need to obtain the webhook URL for each user and backend. Start first by adding your main user webhooks urls and repeat the process for each user that you want to enable webhooks for. By switching to that user via the <!--i:fa-users--> **users** icon on the top right corner of the page.

To easily obtain the correct URL, go to the <!--i:fa-server--> *Backends* page and click **Copy Webhook URL** next to the relevant backend.

# Adding Webhooks to Your Backends

### Emby (Emby Premiere Required)

1. Go to your Emby Server:
   - **Old Emby Versions**: Go to *Server > Webhooks* and click *Add Webhook*.
   - **New Emby Versions**: Go to *username Preferences > Notifications > + Add Notification > Webhooks*.

2. **Name**:  
   - You can name it `user@backend` or something that reflects the user it belongs to.

3. **Webhook/Notifications URL**:  
   - Copy the URL from the WatchState <!--i:fa-server--> *Backends* page and paste it here.

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
   - Select your user.

7. **Limit Library Events to**:  
   - Select libraries you want to sync or leave it blank for all libraries.

Click *Add Webhook / Save*.

### Jellyfin (Free)

1. Go to your Jellyfin dashboard, then navigate to *Plugins > Catalog* and install *Notifications > Webhook*. Restart Jellyfin.
2. After the restart, go back to *Plugins > Webhook* and add `Add Generic Destination`.

3. **Webhook Name**:  
   - You can name it `user@backend` or something that reflects the user it belongs to.

4. **Webhook URL**:  
   - Copy the URL from the WatchState <!--i:fa-server--> *Backends* page and paste it here.

5. **Notification Type**:  
   - Item Added
   - User Data Saved
   - Playback Start
   - Playback Stop

6. **User Filter**:  
   - Select your user.

7. **Item Type**:  
   - Select *Movies* and *Episodes*.

8. **Send All Properties**:  
   - Toggle this checkbox to send all properties.

Click *Save*.

Note: It is best to disable "Webhook match user" option for Jellyfin backend as some events might not have a user associated with them. See [limitations](#jellyfin) for more info.

### Plex (PlexPass Required)

1. Go to your Plex Web UI and navigate to *Settings > Your Account > Webhooks*. Click *Add Webhook*.

2. **Webhook URL**:  
   - Copy the URL from the WatchState <!--i:fa-server--> *Backends* page and paste it here.

Click *Save Changes*.

### Plex via Tautulli

1. Go to *Options > Notification Agents* and click *Add a new notification agent > Webhook*.

2. **Webhook URL**:  
   - Copy the URL from the WatchState <!--i:fa-server--> *Backends* page and paste it here.

3. **Webhook Method**:  
   - Select `PUT`.

4. **Description**:  
   - Use something like `Webhook for user XX for backend XX`.

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
- If you share your Plex server with other users (e.g., home/managed users), you must enable **Webhook match user** to prevent their play state from affecting yours.
- If you use multiple Plex servers with the same PlexPass account, you must add each backend separately and enable both *Webhook Match User* and *Webhook Match Backend ID*. Plex webhooks are account-wide and not server or user specific.
- If you have multiple "Plex Home" users then you will see miss match reported for user ids. This is because Plex uses the same account to send webhooks for all users in "Plex Home". If you want to capture webhooks for these users then you would need to add them as sub users and set them up with individual webhooks.

### Plex via Tautulli

- Tautulli **does not send user IDs** with `itemAdd` (`created`) events. If *Match Webhook User* is enabled, the request will fail with: `Request user id '' does not match configured value`.  
  - A workaround is to manually hardcode the `Account.user_id` for that specific user.
- **Marking items as unplayed** is not reliable, as Tautulli’s webhook payload lacks the data needed to detect this change.

### Emby

- Emby **does not send webhook events** for newly added items.  
  - This feature was implemented in version `4.7.9`, but it still **does not include metadata**, which makes it ineffective.
- The **webhook test event** previously contained no data, but this issue appears to be fixed in version `4.9.0.37+`.
  - To verify if your Emby webhook setup works, try playing or marking an item as played/unplayed, and check if the changes appear in the database.

### Jellyfin

- If no user ID is selected in the plugin, the `itemAdd` event will be sent **without user data**, causing failures if `webhook.match.user` is enabled.
- Occasionally, Jellyfin will fire `itemAdd` events **without matching**.
- Even if a user ID is selected, Jellyfin may **still send events without user data**.
- Items may be marked as **unplayed** if the setting *Libraries > Display > Date Added Behavior for New Content: Use Date Scanned into Library* is enabled. 
  - This can happen when media files are replaced or updated.

# Sometimes Newly Added Content Does Not Show Up

As mentioned in the webhook limitations section, some media backends do not reliably send webhook events for newly added content. To address this, it’s recommended to enable **import/export tasks** to complement webhook functionality.

Simply go to the *Tasks* page and enable the *Import* and *Export* tasks.

# Troubleshooting

TBA
