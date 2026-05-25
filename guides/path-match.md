# Path Matching

Path matching adds a local GUID source named `guid_path`. When enabled, WatchState derives a stable hash from backend-reported media file paths and stores it alongside the normal GUIDs.

# When Path Matching Helps

Path matching is useful when your backends have missing, weak, or inconsistent external IDs, but they still point at the same local media files.

Examples that still match by path:

```text
/mnt/media/tv/Show Title/Season 01/S01E01.mkv
Z:\TV\Show Title\Season 01\S01E01.mkv
```

Examples that do not match by path:

```text
/mnt/media/tv/Show Title/Season 01/S01E01.mkv
/mnt/media/tv/Show Title (2024)/Season 1/Episode 01.mkv
```

# Enable Path Matching

To enable path matching:

1. Go to **Configuration** > <!--i:i-lucide-sliders-horizontal--> **Environment**.
2. Click the <!--i:i-lucide-plus--> **Add** button.
3. Select `WS_GUID_PATH_ENABLED`.
4. Toggle the switch to enable.
5. Save the change.

# Updating Existing Data

Enabling path matching only affects entities created or refreshed after the setting is turned on. Existing rows keep their current data until they are imported again. Existing rows without `guid_path` continue to work, but they cannot match by path until refreshed.

If all of your backends point to the same media files, it is usually enough to refresh just one backend. That one import seeds the local database with `guid_path`, and the other backends can continue operating in metadata-only mode while still matching against the stored path GUIDs.

For example:

1. Go to **Operations** > <!--i:i-lucide-terminal--> **Console**.
2. Run a full import for one backend that has the shared library:

```bash
state:import --user main --select-backend plex --force-full
```

If your backends do not all share the same media, run a full refresh across all imported backends instead:

```bash
state:import -v --force-full
```

Once the import is done, you can inspect the run log in **Operations** > <!--i:i-lucide-scroll-text--> **Logs**.

# Notes

> [!IMPORTANT]
> Do not use `--metadata-only` for this refresh. metadata-only mode does not rewrite GUIDs for existing rows.
> 
> Backends with `Import` disabled are treated as metadata-only during `state:import`. That is usually fine when all backends share the same media, because one imported backend is enough to seed `guid_path` for the shared items. Only temporarily enable `Import` from the <!--i:i-lucide-server--> **Backends** page if that backend has items or media paths that are not covered by the backend you refreshed.

> [!NOTE]
> Once the backfill is done, future imports and webhook events will store the new GUID.
