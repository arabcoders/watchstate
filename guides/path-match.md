# Path Matching

Path matching adds a local GUID source named `guid_path`. When enabled, WatchState derives a stable hash from backend-reported media file paths and stores it alongside the normal GUIDs.

This feature becomes available in v1.8.5+ and is disabled by default for now.

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

# FAQ

### What path parts are used?

Path matching does not try to understand your library root, movie folder, show folder, or season folder names. It normalizes the backend-reported file path by replacing backslashes with `/`, collapsing duplicate separators, and lowercasing path segments. It then hashes fixed suffixes.

| Stored field      | Example backend path           | Suffix used for matching   | Rule                                                 |
| ----------------- | ------------------------------ | -------------------------- | ---------------------------------------------------- |
| for movies        | `/foo/bar/movies/movie.mkv`    | `/movies/movie.mkv`        | Final 2 path segments                                |
| for episodes      | `/foo/bar/tv/show/episode.mkv` | `/tv/show/episode.mkv/1/1` | Final 3 path segments plus logical season/episode    |
| for episodes show | `/foo/bar/tv/show/episode.mkv` | `/tv/show`                 | The 2 directory segments immediately before the file |

The paths do not need to have the same leading root across backends. They only need the relevant trailing suffix to match.

### What happens with badly made libraries?

Path matching is not meant to rescue every possible broken or non-standard library layout.

The important part is whether the suffix WatchState hashes is stable across backends.

| Case                                                                    | Backend A suffix     | Backend B suffix          | Result   |
| ----------------------------------------------------------------------- | -------------------- | ------------------------- | -------- |
| Same flat movie parent                                                  | `/movies/movie.mkv`  | `/movies/movie.mkv`       | Match    |
| Different flat movie parent                                             | `/movies/movie.mkv`  | `/films/movie.mkv`        | No match |
| Episode without season folder, same final 3 segments                    | `/tv/anime1/ep1.mkv` | `/tv/anime1/ep1.mkv`      | Match    |
| Episode without season folder, different library folder in final suffix | `/tv/anime1/ep1.mkv` | `/tvshows/anime1/ep1.mkv` | No match |
| Too few movie segments                                                  | `/movie.mkv`         | N/A                       | Skipped  |
| Too few episode segments                                                | `/anime1/ep1.mkv`    | N/A                       | Skipped  |

Path GUID generation is skipped when there are not enough path segments to build the required suffix.

This is intentional. Path matching is an extra fallback for reasonably organized libraries, not a full library-repair system.

### Why not strip the library root or support base-path remapping?

WatchState does not support per-backend root stripping, base-path mapping, or manual path replacement for path matching.

That choice is intentional for three reasons:

1. The current implementation already ignores leading path segments by hashing only fixed trailing suffixes.
2. Adding per-backend root detection or remapping would make the matching path more complex for a feature that is supposed to behave like a normal GUID source.
3. Path matching is on a hot import path, so avoiding extra backend-specific lookup and rewrite logic keeps it simpler and cheaper to run.

Example:

| Backend  | Full path                       | Movie suffix used |
| -------- | ------------------------------- | ----------------- |
| Plex     | `/media/movies/foo/foo.mkv`     | `/foo/foo.mkv`    |
| Jellyfin | `/a/b/c/d/whatever/foo/foo.mkv` | `/foo/foo.mkv`    |

These already match because WatchState only cares about `/foo/foo.mkv`, not the different leading roots.

### So when would root stripping help but still not be supported?

Root stripping could help libraries where the configured library root appears inside the final suffix and differs between backends. For example, `/mnt/tv/anime1/ep1.mkv` and `\\nas\tvshows\anime1\ep1.mkv` would only match if WatchState knew to strip `/mnt/tv` and `\\nas\tvshows` first.

That tradeoff is acceptable. Path matching is intentionally optimized for the common case, not every uncommon or poorly structured layout. For more info, see [this discussion](https://github.com/arabcoders/watchstate/discussions/829).
