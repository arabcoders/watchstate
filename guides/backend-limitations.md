# Backend Limitations

Each backend has specific requirements and limitations to be aware of before adding it to WatchState.

## Plex

### Requirements

- **PlexPass** subscription is required for webhooks.
- An **admin-level token** is required if you plan to [provision identities](/guides/identities.md).
- Plex version **1.41.6.9606 or newer** is recommended. Older versions have a bug where marking an item as watched without prior progress does not show it in continue-watching [[reference](https://forums.plex.tv/t/continue-watching-is-buggy-unable-to-figure-out-why/869224/65)].

### Limitations

- **Limited tokens** cannot list or impersonate users. The users list will be empty, and you must enter the user UUID manually.
- **.plex.direct URLs** often fail in Docker. Add your custom domain via **Plex Settings > Network > Custom server access URLs**.
- Only **movie** and **show** libraries are imported for play state. Music, photos, and other library types are skipped.

### Webhook Limitations

- Plex does not send events for *marked as played/unplayed* actions.
- Webhook events may be **skipped** when multiple items are added at once.
- When items are marked as **unwatched**, Plex resets the date on the media object.
- Plex does not send watch progress update events during playback. It only sends progress updates during `play`, `pause`, `stop`, and `resume` events, so progress data from Plex will not be reflected until one of those events triggers.

### Plex via Tautulli

- **Marking items as unplayed** is not reliable, as Tautulli's webhook payload lacks the data needed to detect this change.
- Similarly to Plex, Tautulli does not send watch progress update events during playback.

## Jellyfin

### Requirements

- **v10.9.x or higher** is required for watch progress sync.
- The **Notifications > Webhook** plugin (free) must be installed from the plugin catalog for webhook support.
- An **API key** is required if you plan to [provision identities](/guides/identities.md). The `username:password` format will not work for identity provisioning.

### Limitations

- Only **movie** and **show** libraries are imported for play state. Music, photos, and other library types are skipped.

### Webhook Limitations

- Even if a user ID is selected, Jellyfin may **still send events without user data**.
- Items may be marked as **unplayed** if the setting *Libraries > Display > Date Added Behavior for New Content: Use Date Scanned into Library* is enabled. This can happen when media files are replaced or updated.

### Known Issues

- **Played without date bug**: Jellyfin marks items as played without updating the `LastPlayedDate`, causing export conflicts. The recommanded approch is to enable webhooks. However, if you prefer scheduled imports, you can enable the experimental `WS_CLIENTS_JELLYFIN_FIX_PLAYED` environment variable as a workaround, then run `state:import`.

## Emby

### Requirements

- **Emby Premiere** subscription is required for webhooks.
- An **API key** is required if you plan to [provision identities](/guides/identities.md). The `username:password` format will not work for identity provisioning.

### Limitations

- Only **movie** and **show** libraries are imported for play state. Music, photos, and other library types are skipped.
- The **webhook test event** previously contained no data, but this issue appears to be fixed in version `4.9.0.37+`. To verify if your Emby webhook setup works, try playing or marking an item as played/unplayed and check if the changes appear in the database.
