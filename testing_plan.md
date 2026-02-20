Work Plan: MediaBrowser backend testing
Goal: add a shared test suite for Jellyfin + Emby backends while keeping backend-specific behavior isolated.

Scope:
- src/Backends/Jellyfin
- src/Backends/Emby
- tests/Backends/MediaBrowser (new shared suite)

Out of Scope:
- src/Backends/Plex

Files to Modify:
- tests/Backends/MediaBrowser/**
- tests/Backends/Jellyfin/**
- tests/Backends/Emby/**
- tests/Fixtures/mediabrowser_*.json

Chunk 1: Inventory shared vs unique behavior
Steps:
- List Emby actions that extend Jellyfin actions and mark them as shared test targets.
- Identify Emby-only and Jellyfin-only actions (InspectRequest, ParseWebhook, Progress, GetImagesUrl, GUID differences).
Success Criteria:
- A concrete shared/unique action matrix is documented and agreed.

Chunk 2: Shared MediaBrowser test scaffolding
Steps:
- Create tests/Backends/MediaBrowser base test case with helpers for Context, Cache, DB, and MockHttpClient.
- Add data providers for Jellyfin vs Emby (client name, action class, URL expectations).
- Add shared fixtures for libraries, metadata, sessions, users, and errors.
Success Criteria:
- Base helpers can spin up Jellyfin and Emby contexts deterministically with fixtures.

Chunk 3: Shared action tests (MediaBrowser suite)
Steps:
- Implement shared tests for actions Emby inherits from Jellyfin:
  GetLibrariesList, GetLibrary, GetMetaData, GetInfo, GetVersion, GetUsersList,
  GetSessions, SearchQuery, SearchId, ToEntity, Import, Export, Backup, Push,
  UpdateState, Proxy.
- Use data providers to run each test for both Jellyfin and Emby.
- Assert shared behaviors: response mapping, error handling, caching, web URL formatting.
Success Criteria:
- Each shared action has at least one success and one error-path test for both backends.

Chunk 4: Backend-specific tests
Steps:
- Jellyfin-only: InspectRequest JSON fallback, ParseWebhook event/type validation,
  Progress version gating, GetImagesUrl path, JellyfinGuid behaviors.
- Emby-only: InspectRequest version bounds + legacy payload, ParseWebhook event/type validation,
  Progress without version gating, GetImagesUrl path, EmbyGuid behaviors.
Success Criteria:
- Unique behaviors are covered without duplication in the shared suite.

Chunk 5: Execution and stability
Steps:
- Run vendor/bin/phpunit tests/Backends/MediaBrowser and then full tests.
- Stabilize time-dependent assertions (fixed timestamps or helper wrappers).
- Document how to run the MediaBrowser suite quickly.
Success Criteria:
- MediaBrowser tests pass reliably and integrate cleanly with existing suites.
