# Media Health

Media Health is an audit for the main WatchState database. It combines backend coverage, duplicate reference, 
identity, path, and optional file checks into one report focused on records that need attention.

# What It Checks

Media Health can report these states. Each state includes an action hint in the WebUI.

- `guid_conflict`: Backends attached different strong external IDs to the same local record.
- `duplicate_guid`: More than one local record has the same strong external ID.
- `duplicate_reference`: More than one local record points at the same media path.
- `metadata_disagreement`: Backends disagree on type, year, season, or episode number.
- `partial`: One or more expected backends did not report metadata for the record.
- `weak_match`: The record has no strong external GUID.
- `path_disagreement`: Backends reported paths with different normalized suffixes.
- `file_missing`: Optional file checks found a missing file or parent directory.
- `healthy`: No Media Health problem was found.

# Run An Audit

From the WebUI:

1. Go to **Diagnostics** > <!--i:i-lucide-radar--> **Media Health**.
2. Click **Queue Audit**.
3. Wait for the background task to finish.
4. Click **Reload**.

The WebUI reads the latest completed report. If state data changed after the report was generated, 
the page shows a stale warning and you should queue a fresh audit.

Use the **Export** menu to download the complete report. Exports include every cached item, not only 
the current page or selected filter, so you can process the report with your own tools.

# File Checks Are Optional

WatchState does not always have access to your media files. Many installations only mount `/config`. For that reason, 
local filesystem checks are disabled by default. Media Health will not produce `file_missing` results unless you explicitly enable them.

Only enable file checks when both are true:

1. WatchState can access the same media paths reported by your backends.
2. The container or host user running WatchState has permission to read those paths.

To enable file checks globally:

1. Go to **Configuration** > <!--i:i-lucide-sliders-horizontal--> **Environment**.
2. Click the <!--i:i-lucide-plus--> **Add** button.
3. Select `WS_MEDIA_HEALTH_CHECK_FILES`.
4. Toggle the switch to enable it.
5. Save the change.

> [!IMPORTANT]
> Do not enable file checks just because your media server can see the files. WatchState itself must be able to see the same paths. 
> Otherwise every inaccessible path can look missing.

# Using The Page

The default filter is **Unhealthy**, so healthy records are hidden. This keeps large libraries usable.

Useful controls:

- **Status filter** narrows the list to one health state.
- **Type filter** limits results to movies or episodes.
- **Filter** searches displayed records by title, status, backend name, GUID, path, file-check message, or reason.
- Backend badges open the source backend item when WatchState can build a direct item URL.
- Record badges open the linked WatchState history record.
- Path rows are grouped so identical paths from multiple backends are shown once.

# Fix And Refresh Workflow

1. Start with the highest-priority records, usually GUID conflicts and duplicate GUIDs.
2. Fix metadata in the backend that owns the bad item.
3. If you fixed one backend, go to **Configuration** > <!--i:i-lucide-server--> **Backends**, open that backend actions menu, and run **9. Force metadata-only import from this backend.**
4. If you fixed many backends, run one metadata-only import for all backends instead:

```bash
state:import -f -v --metadata-only
```

5. Queue a new Media Health audit.
6. Repeat until the remaining records are expected or harmless.

# Main Database Only

Media Health runs against the main user database. It is not generated separately for every identity. The report is meant to describe shared health, not per-user health.
