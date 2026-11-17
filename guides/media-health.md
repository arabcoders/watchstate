# Media Health

Media Health checks stored records in the main WatchState database for conflicting IDs, duplicate records,
backend coverage, and path differences. File checks are optional.

# What It Checks

- `guid_conflict`: Backends attached different strong external IDs to the same local record.
- `duplicate_guid`: More than one local record has the same strong external ID.
- `duplicate_reference`: More than one local record points at the same media path.
- `metadata_disagreement`: Backends disagree on type, year, season, or episode number.
- `partial`: One or more expected backends did not report metadata for the record.
- `weak_match`: The record has no strong external GUID.
- `path_disagreement`: Backends reported paths with different normalized suffixes.
- `file_missing`: Optional file checks found an empty path, missing file, or missing parent directory.
- `healthy`: No issue was detected in the audit.

# Run An Audit

From the WebUI:

1. Open **Activity** > <!--i:i-lucide-radar--> **Media Health**.
2. Click **Run audit**.
3. Wait for the background task to finish.
4. Click **Reload report**.

Results remain visible while a new audit is queued or running. **Reload report** checks for a completed audit;
it does not start another one.

Use **Export report** to download the latest completed report. Exports include the whole report, not just the current page,
filters, or selection.

# File Checks Are Optional

WatchState does not always have access to your media files. Many installations only mount `/config`. For that reason, 
local filesystem checks are disabled by default. Media Health will not produce `file_missing` results unless you explicitly enable them.

Only enable file checks when both are true:

1. WatchState can access the same media paths reported by your backends.
2. The container or host user running WatchState has permission to read those paths.

To enable file checks globally:

1. Open **Settings** > <!--i:i-lucide-sliders-horizontal--> **Environment**.
2. Click the <!--i:i-lucide-plus--> **Add variable** button.
3. Select `WS_MEDIA_HEALTH_CHECK_FILES`.
4. Toggle the switch to enable it.
5. Save the change.

> [!IMPORTANT]
> Do not enable file checks just because your media server can see the files. WatchState itself must be able to see the same paths. 
> Otherwise every inaccessible path can look missing.

# Using The Page

- **Main issue** narrows the audit to a record's main issue, not every finding shown for that record.
- **Filter this page** searches only the records loaded on the current page.
- **Delete from history** removes the selected records from
  main database history, not backend items or media files.

# Fix And Refresh Workflow

1. Start with the highest-priority records, usually GUID conflicts and duplicate GUIDs.
2. Fix metadata in the backend that owns the bad item.
3. If you fixed one backend, open <!--i:i-lucide-server--> **Backends** and select **9. Force metadata-only import from this backend**
   from its **Quick operations** list. Review the prefilled command in **Activity > Console**, then click **Run command**.
4. If you fixed many backends, open **Activity > Console** and run one metadata-only import for all backends instead:

```bash
state:import -f -v --metadata-only
```

5. Return to **Media Health** and click **Run audit**.
6. Repeat until the remaining records are expected or harmless.

# Main Database Only

Media Health runs against the main WatchState database.
