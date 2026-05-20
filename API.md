# HTTP API Documentation

WatchState HTTP API reference. Examples use the default `/v1/api` prefix.

---

## Table of Contents

- [HTTP API Documentation](#http-api-documentation)
  - [Table of Contents](#table-of-contents)
  - [Authentication](#authentication)
  - [Global Notes](#global-notes)
  - [Endpoints](#endpoints)
    - [Backends](#backends)
      - [GET /v1/api/backends](#get-v1apibackends)
      - [POST /v1/api/backends](#post-v1apibackends)
      - [GET /v1/api/backends/spec](#get-v1apibackendsspec)
      - [GET|POST /v1/api/backends/uuid/{type}](#getpost-v1apibackendsuuidtype)
      - [GET|POST /v1/api/backends/users/{type}](#getpost-v1apibackendsuserstype)
      - [GET|POST /v1/api/backends/discover/{type}](#getpost-v1apibackendsdiscovertype)
      - [POST /v1/api/backends/accesstoken/{type}](#post-v1apibackendsaccesstokentype)
      - [POST /v1/api/backends/validate/token/{type}](#post-v1apibackendsvalidatetokentype)
      - [POST /v1/api/backends/plex/generate](#post-v1apibackendsplexgenerate)
      - [POST /v1/api/backends/plex/check](#post-v1apibackendsplexcheck)
    - [Configured Backend Endpoints](#configured-backend-endpoints)
      - [GET /v1/api/backend/{name}](#get-v1apibackendname)
      - [PUT /v1/api/backend/{name}](#put-v1apibackendname)
      - [PATCH /v1/api/backend/{name}](#patch-v1apibackendname)
      - [DELETE /v1/api/backend/{name}](#delete-v1apibackendname)
      - [GET /v1/api/backend/{name}/info](#get-v1apibackendnameinfo)
      - [GET /v1/api/backend/{name}/version](#get-v1apibackendnameversion)
      - [GET /v1/api/backend/{name}/users](#get-v1apibackendnameusers)
      - [POST /v1/api/backend/{name}/accesstoken](#post-v1apibackendnameaccesstoken)
      - [GET /v1/api/backend/{name}/sessions](#get-v1apibackendnamesessions)
      - [GET /v1/api/backend/{name}/discover](#get-v1apibackendnamediscover)
      - [GET /v1/api/backend/{name}/library](#get-v1apibackendnamelibrary)
      - [POST|DELETE /v1/api/backend/{name}/library/{id}](#postdelete-v1apibackendnamelibraryid)
      - [GET|POST|PATCH|DELETE /v1/api/backend/{name}/option\[/{option}\]](#getpostpatchdelete-v1apibackendnameoptionoption)
      - [GET /v1/api/backend/{name}/search\[/{id}\]](#get-v1apibackendnamesearchid)
      - [GET /v1/api/backend/{name}/unmatched\[/{id}\]](#get-v1apibackendnameunmatchedid)
      - [GET /v1/api/backend/{name}/mismatched\[/{id}\]](#get-v1apibackendnamemismatchedid)
      - [GET /v1/api/backend/{name}/stale/{id}](#get-v1apibackendnamestaleid)
      - [DELETE /v1/api/backend/{name}/stale/{id}](#delete-v1apibackendnamestaleid)
      - [GET /v1/api/backend/{name}/ignore](#get-v1apibackendnameignore)
      - [POST /v1/api/backend/{name}/ignore](#post-v1apibackendnameignore)
      - [DELETE /v1/api/backend/{name}/ignore](#delete-v1apibackendnameignore)
    - [History](#history)
      - [GET /v1/api/history](#get-v1apihistory)
      - [GET /v1/api/history/{id}](#get-v1apihistoryid)
      - [GET /v1/api/history/{id}/duplicates](#get-v1apihistoryidduplicates)
      - [DELETE /v1/api/history/{id}](#delete-v1apihistoryid)
      - [GET|POST|DELETE /v1/api/history/{id}/watch](#getpostdelete-v1apihistoryidwatch)
      - [GET /v1/api/history/{id}/validate](#get-v1apihistoryidvalidate)
      - [DELETE /v1/api/history/{id}/metadata/{backend}](#delete-v1apihistoryidmetadatabackend)
      - [GET /v1/api/history/{id}/images/{type}](#get-v1apihistoryidimagestype)
    - [Ignore Rules](#ignore-rules)
      - [GET /v1/api/ignore](#get-v1apiignore)
      - [POST /v1/api/ignore](#post-v1apiignore)
      - [DELETE /v1/api/ignore](#delete-v1apiignore)
    - [Logs](#logs)
      - [GET /v1/api/logs](#get-v1apilogs)
      - [GET /v1/api/logs/recent](#get-v1apilogsrecent)
      - [GET|DELETE /v1/api/log/{filename}](#getdelete-v1apilogfilename)
    - [Player Streaming](#player-streaming)
      - [GET /v1/api/player/playlist/{token}\[/{fake...}\]](#get-v1apiplayerplaylisttokenfake)
      - [GET /v1/api/player/m3u8/{token}\[/{fake...}\]](#get-v1apiplayerm3u8tokenfake)
      - [GET /v1/api/player/segments/{token}/{segment}\[.{type}\]](#get-v1apiplayersegmentstokensegmenttype)
      - [GET /v1/api/player/subtitle/{token}/{type}.{source}{index}.m3u8](#get-v1apiplayersubtitletokentypesourceindexm3u8)
      - [GET /v1/api/player/subtitle/{token}/{source}{index}.{ext}](#get-v1apiplayersubtitletokensourceindexext)
    - [System](#system)
      - [GET /v1/api/system/healthcheck](#get-v1apisystemhealthcheck)
      - [GET /v1/api/system/version](#get-v1apisystemversion)
      - [GET /v1/api/system/supported](#get-v1apisystemsupported)
      - [GET /v1/api/system/auth/test](#get-v1apisystemauthtest)
      - [GET /v1/api/system/auth/has\_user](#get-v1apisystemauthhas_user)
      - [GET /v1/api/system/auth/user](#get-v1apisystemauthuser)
      - [POST /v1/api/system/auth/signup](#post-v1apisystemauthsignup)
      - [POST /v1/api/system/auth/login](#post-v1apisystemauthlogin)
      - [POST /v1/api/system/auth/refresh](#post-v1apisystemauthrefresh)
      - [PUT /v1/api/system/auth/change\_password](#put-v1apisystemauthchange_password)
      - [DELETE /v1/api/system/auth/sessions](#delete-v1apisystemauthsessions)
      - [GET /v1/api/system/env](#get-v1apisystemenv)
      - [GET /v1/api/system/env/{key}](#get-v1apisystemenvkey)
      - [POST|DELETE /v1/api/system/env/{key}](#postdelete-v1apisystemenvkey)
      - [GET /v1/api/system/guids](#get-v1apisystemguids)
      - [GET /v1/api/system/guids/custom](#get-v1apisystemguidscustom)
      - [PUT /v1/api/system/guids/custom](#put-v1apisystemguidscustom)
      - [DELETE /v1/api/system/guids/custom/{id}](#delete-v1apisystemguidscustomid)
      - [GET /v1/api/system/guids/custom/{client}](#get-v1apisystemguidscustomclient)
      - [PUT /v1/api/system/guids/custom/{client}](#put-v1apisystemguidscustomclient)
      - [DELETE /v1/api/system/guids/custom/{client}/{id}](#delete-v1apisystemguidscustomclientid)
      - [GET /v1/api/system/guids/custom/{client}/{index}](#get-v1apisystemguidscustomclientindex)
      - [GET /v1/api/system/events](#get-v1apisystemevents)
      - [GET /v1/api/system/events/stats](#get-v1apisystemeventsstats)
      - [POST /v1/api/system/events](#post-v1apisystemevents)
      - [GET /v1/api/system/events/{id}](#get-v1apisystemeventsid)
      - [PATCH /v1/api/system/events/{id}](#patch-v1apisystemeventsid)
      - [DELETE /v1/api/system/events/{id}](#delete-v1apisystemeventsid)
      - [DELETE /v1/api/system/events](#delete-v1apisystemevents)
      - [POST /v1/api/system/command](#post-v1apisystemcommand)
      - [GET /v1/api/system/command](#get-v1apisystemcommand)
      - [GET /v1/api/system/command/{token}](#get-v1apisystemcommandtoken)
      - [DELETE /v1/api/system/command/{token}](#delete-v1apisystemcommandtoken)
      - [GET /v1/api/system/scheduler](#get-v1apisystemscheduler)
      - [POST /v1/api/system/scheduler/restart](#post-v1apisystemschedulerrestart)
      - [GET /v1/api/system/report](#get-v1apisystemreport)
      - [GET /v1/api/system/report/ini](#get-v1apisystemreportini)
      - [POST /v1/api/system/url/check](#post-v1apisystemurlcheck)
      - [POST /v1/api/system/yaml\[/{filename}\]](#post-v1apisystemyamlfilename)
      - [POST /v1/api/system/sign/{id}](#post-v1apisystemsignid)
      - [GET /v1/api/system/static/{file}](#get-v1apisystemstaticfile)
      - [GET /v1/api/system/images/{type}](#get-v1apisystemimagestype)
      - [GET /v1/api/system/backup](#get-v1apisystembackup)
      - [GET|DELETE /v1/api/system/backup/{filename}](#getdelete-v1apisystembackupfilename)
      - [GET /v1/api/system/processes](#get-v1apisystemprocesses)
      - [DELETE /v1/api/system/processes/{id}](#delete-v1apisystemprocessesid)
      - [DELETE /v1/api/system/cache](#delete-v1apisystemcache)
      - [DELETE /v1/api/system/reset](#delete-v1apisystemreset)
      - [POST /v1/api/system/reset/opcache](#post-v1apisystemresetopcache)
      - [GET /v1/api/system/integrity](#get-v1apisystemintegrity)
      - [DELETE /v1/api/system/integrity](#delete-v1apisystemintegrity)
      - [GET /v1/api/system/parity](#get-v1apisystemparity)
      - [DELETE /v1/api/system/parity](#delete-v1apisystemparity)
      - [GET /v1/api/system/duplicate](#get-v1apisystemduplicate)
      - [DELETE /v1/api/system/duplicate](#delete-v1apisystemduplicate)
      - [GET /v1/api/system/suppressor](#get-v1apisystemsuppressor)
      - [POST /v1/api/system/suppressor](#post-v1apisystemsuppressor)
      - [GET /v1/api/system/suppressor/{id}](#get-v1apisystemsuppressorid)
      - [PUT /v1/api/system/suppressor/{id}](#put-v1apisystemsuppressorid)
      - [DELETE /v1/api/system/suppressor/{id}](#delete-v1apisystemsuppressorid)
    - [Tasks](#tasks)
      - [GET /v1/api/tasks](#get-v1apitasks)
      - [GET /v1/api/tasks/{id}](#get-v1apitasksid)
      - [GET|POST|DELETE /v1/api/tasks/{id}/queue](#getpostdelete-v1apitasksidqueue)
    - [Identities](#identities)
      - [GET /v1/api/identities](#get-v1apiidentities)
      - [POST /v1/api/identities](#post-v1apiidentities)
      - [DELETE /v1/api/identities/{identity}](#delete-v1apiidentitiesidentity)
      - [GET /v1/api/identities/{identity}](#get-v1apiidentitiesidentity)
      - [PUT /v1/api/identities/{identity}](#put-v1apiidentitiesidentity)
      - [GET /v1/api/identities/provision](#get-v1apiidentitiesprovision)
      - [PUT /v1/api/identities/provision/mapping](#put-v1apiidentitiesprovisionmapping)
      - [POST /v1/api/identities/provision](#post-v1apiidentitiesprovision)
      - [POST /v1/api/identities/provision/sync-backends](#post-v1apiidentitiesprovisionsync-backends)
    - [Webhook](#webhook)
      - [POST|PUT /v1/api/webhook](#postput-v1apiwebhook)
  - [Error Responses](#error-responses)

---

## Authentication

Most routes require either an API key or a signed user token.

1. API key in a header:

   ```
   X-APIKEY: <api-key>
   ```

2. API key in the query string:

   ```
   ?apikey=<api-key>
   ```

3. Signed user token in the `Authorization` header for most authenticated routes:

   ```
   Authorization: Bearer <token>
   ```

   or

   ```
   Authorization: Token <token>
   ```

4. Signed user token in the query string:

   ```
   ?ws_token=<token>
   ```

---

## Global Notes

- **Content-Type**
  - Send `Content-Type: application/json` for JSON request bodies.
  - JSON auto-parsing only happens for `application/json` and `application/*+json`.

- **Identity Context**
  - Many endpoints operate on a per-identity config/database.
  - Those routes accept `X-User: <name>` or `?user=<name>`.
  - If omitted, WatchState uses the `main` identity context.

- **Response Format**
  - Successful endpoints usually return a JSON object or JSON array.
  - Error responses use:
    ```json
    {
      "error": {
        "code": 400,
        "message": "Description of the problem"
      }
    }
    ```
  - Informational success messages use:
    ```json
    {
      "info": {
        "code": 200,
        "message": "Human readable message"
      }
    }
    ```

- **Pagination**
  - Most paginated endpoints use `page` and `perpage`.
  - History, parity, duplicate, and events responses include paging metadata.

- **Raw Backend Responses**
  - Several backend endpoints accept `raw=true`.
  - `raw=true` exposes backend-specific upstream payloads and can be much larger than the normalized response.

- **Real-time APIs**
  - Real-time endpoints use Server-Sent Events (SSE), mainly for logs and command execution.

---

## Endpoints

### Backends

`GET /v1/api/backends` and `POST /v1/api/backends` honor `X-User` or `?user=`. Probe routes work without a saved backend.

#### GET /v1/api/backends
Lists configured backends for the current user.

**Response**:
```json
[
  {
    "name": "plex_main",
    "type": "plex",
    "url": "https://plex.example.com",
    "uuid": "...",
    "user": "owner",
    "import": {
      "enabled": true,
      "lastSync": "2026-03-28T12:00:00+00:00"
    },
    "export": {
      "enabled": false,
      "lastSync": null
    },
    "urls": {
      "webhook": "/v1/api/webhook?apikey=..."
    }
  }
]
```

**Notes**:
- External responses omit the stored `options` object except for `options.IMPORT_METADATA_ONLY`.
- The generated webhook URL includes `?apikey=...` when secure API mode is enabled.

---

#### POST /v1/api/backends
Creates and persists a new backend definition.

**Body**:
```json
{
  "name": "plex_main",
  "type": "plex",
  "url": "https://plex.example.com",
  "token": "secret-token",
  "user": "owner",
  "uuid": "optional-server-id",
  "import": {
    "enabled": true
  },
  "export": {
    "enabled": false
  },
  "options": {
    "client": {
      "verify_host": true
    }
  }
}
```

**Response**:
```json
{
  "name": "plex_main",
  "type": "plex",
  "url": "https://plex.example.com",
  "uuid": "...",
  "token": "secret-token",
  "...": "saved backend fields"
}
```

**Errors**:
- `400 Bad Request` if `type`, `name`, `url`, or `token` is missing or invalid.
- `404 Not Found` if the user does not exist.
- `409 Conflict` if the backend name already exists.

**Notes**:
- Backend names must use lowercase letters, numbers, and underscores only.
- If `uuid` is omitted, WatchState tries to fetch it from the remote backend.
- Only option keys defined in `config/servers.spec.php` are stored.

---

#### GET /v1/api/backends/spec
Returns the backend option specification.

**Response**:
```json
[
  {
    "key": "options.client.timeout",
    "type": "float",
    "description": "HTTP timeout in seconds"
  }
]
```

**Notes**:
- The response is generated from `config/servers.spec.php`.
- `choices` is included for enumerated fields when the spec defines it.

---

#### GET|POST /v1/api/backends/uuid/{type}
Probes an arbitrary backend connection and returns its type plus unique identifier.

**Path**:
- `type`: Backend type such as `plex`, `jellyfin`, or `emby`.

**Input**:
- Query parameters for `GET`, or JSON body for `POST`.
- Required fields: `url`, `token`
- Optional fields: `uuid`, `user`, and selected `options.*`

**Response**:
```json
{
  "type": "plex",
  "identifier": "..."
}
```

**Errors**:
- `400 Bad Request` if the backend type, URL, or token is invalid.
- `500 Internal Server Error` if the remote probe fails.

---

#### GET|POST /v1/api/backends/users/{type}
Returns users from an arbitrary backend connection without saving it first.

**Path**:
- `type`: Backend type.

**Input**:
- Query parameters for `GET`, or JSON body for `POST`.
- Required fields: `url`, `token`
- Optional fields:
  - `tokens` - Include backend-specific user tokens when supported.
  - `target_user` - Narrow the result to a specific backend user.
  - `no_cache` - Force a fresh fetch.

**Response**:
```json
[
  {
    "id": "...",
    "name": "...",
    "...": "backend user fields"
  }
]
```

**Errors**:
- `400 Bad Request` if the backend type, URL, or token is invalid.
- `500 Internal Server Error` if the remote backend request fails.

---

#### GET|POST /v1/api/backends/discover/{type}
Discovers available Plex servers for an arbitrary Plex connection.

**Path**:
- `type`: Must be `plex`.

**Input**:
- Query parameters for `GET`, or JSON body for `POST`.
- Required fields: `url`, `token`
- Optional fields:
  - `options.ADMIN_TOKEN` - Plex admin token used during discovery.

**Response**:
```json
[
  {
    "name": "...",
    "uri": "...",
    "...": "Plex discovery fields"
  }
]
```

**Errors**:
- `400 Bad Request` if the backend type is not `plex`, or if required connection data is missing.
- `500 Internal Server Error` if discovery fails.

---

#### POST /v1/api/backends/accesstoken/{type}
Generates a Jellyfin or Emby access token using username/password credentials.

**Path**:
- `type`: Must be `jellyfin` or `emby`.

**Body**:
```json
{
  "url": "https://jellyfin.example.com",
  "username": "alice",
  "password": "secret"
}
```

**Response**:
```json
{
  "AccessToken": "...",
  "...": "backend-specific token payload"
}
```

**Errors**:
- `400 Bad Request` if credentials are missing or the backend type is unsupported.
- `500 Internal Server Error` if token generation fails.

---

#### POST /v1/api/backends/validate/token/{type}
Validates a Plex token.

**Path**:
- `type`: Must resolve to the Plex client.

**Body**:
```json
{
  "token": "plex-token"
}
```

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Token is valid."
  }
}
```

**Errors**:
- `400 Bad Request` if the endpoint is used with a non-Plex backend or if `token` is missing.
- `401 Unauthorized` if the token is rejected.

---

#### POST /v1/api/backends/plex/generate
Starts the Plex PIN flow used for browser/device login.

**Access**:
- Open.

**Response**:
```json
{
  "id": 123456,
  "code": "ABCD",
  "...": "Plex pin payload"
}
```

**Errors**:
- Returns the upstream Plex status when the PIN request fails.

**Notes**:
- The response also includes the WatchState Plex client headers used for the request.

---

#### POST /v1/api/backends/plex/check
Polls the Plex PIN flow and returns the current PIN state.

**Access**:
- Open.

**Body**:
```json
{
  "id": 123456,
  "code": "ABCD"
}
```

**Response**:
```json
{
  "id": 123456,
  "authToken": "...",
  "...": "Plex pin status fields"
}
```

**Errors**:
- `400 Bad Request` if `id` or `code` is missing.
- Returns the upstream Plex status when the check fails.

---

### Configured Backend Endpoints

These routes operate on a saved backend name and honor `X-User` or `?user=`.

#### GET /v1/api/backend/{name}
Returns a single saved backend definition.

**Path**:
- `name`: Saved backend name.

**Response**:
```json
{
  "name": "plex_main",
  "type": "plex",
  "url": "https://plex.example.com",
  "token": "secret-token",
  "uuid": "...",
  "...": "backend fields"
}
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.

**Notes**:
- This endpoint returns the stored backend object and is not redacted like `GET /v1/api/backends`.

---

#### PUT /v1/api/backend/{name}
Replaces a saved backend configuration and revalidates it.

**Path**:
- `name`: Saved backend name.

**Body**:
```json
{
  "url": "https://plex.example.com",
  "token": "secret-token",
  "user": "owner",
  "uuid": "...",
  "import": {
    "enabled": true
  },
  "export": {
    "enabled": false
  },
  "options": {
    "client": {
      "timeout": 30
    }
  }
}
```

**Response**:
```json
{
  "name": "plex_main",
  "type": "plex",
  "...": "updated backend fields"
}
```

**Errors**:
- `400 Bad Request` if validation fails.
- `404 Not Found` if the user or backend does not exist.

**Notes**:
- Removed legacy keys are stripped automatically before the config is persisted.
- When `import.enabled=true`, `options.IMPORT_METADATA_ONLY` is removed as a sanity check.

---

#### PATCH /v1/api/backend/{name}
Partially updates a saved backend using a raw JSON patch list.

**Path**:
- `name`: Saved backend name.

**Body**:
```json
[
  {
    "key": "options.client.timeout",
    "value": 30
  },
  {
    "key": "import.enabled",
    "value": true
  }
]
```

**Response**:
```json
{
  "name": "plex_main",
  "type": "plex",
  "...": "updated backend fields"
}
```

**Errors**:
- `400 Bad Request` if the body is not valid JSON, if a key is missing, if a key is immutable, or if a value fails validation.
- `404 Not Found` if the user or backend does not exist.

**Notes**:
- The body must be a JSON array, not an object.
- Immutable keys include `name`, `type`, `options`, `import`, `export`, and removed legacy keys.
- This route validates fields against the server spec but does not perform the same remote context validation as `PUT`.

---

#### DELETE /v1/api/backend/{name}
Deletes a backend definition and removes its metadata references from history.

**Path**:
- `name`: Saved backend name.

**Response**:
```json
{
  "deleted": {
    "references": 42,
    "records": 7
  },
  "backend": {
    "name": "plex_main",
    "...": "deleted backend fields"
  }
}
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.

**Notes**:
- Metadata and extra blocks for the backend are removed from the `state` table before the backend config is deleted.
- Records with no remaining metadata are deleted.

---

#### GET /v1/api/backend/{name}/info
Returns backend info and capabilities.

**Path**:
- `name`: Saved backend name.

**Query**:
- `raw` (optional) - Return the backend's raw response.

**Response**:
```json
{
  "...": "backend info payload"
}
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if the backend request fails.

---

#### GET /v1/api/backend/{name}/version
Returns the backend server version.

**Path**:
- `name`: Saved backend name.

**Response**:
```json
{
  "version": "..."
}
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if the backend request fails.

---

#### GET /v1/api/backend/{name}/users
Returns users from a saved backend connection.

**Path**:
- `name`: Saved backend name.

**Query**:
- `tokens` (optional) - Include backend-specific tokens when supported.
- `target_user` (optional) - Return data for a single backend user.
- `raw` (optional) - Include the backend raw response.

**Response**:
```json
[
  {
    "id": "...",
    "name": "...",
    "...": "backend user fields"
  }
]
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if the backend request fails.

---

#### POST /v1/api/backend/{name}/accesstoken
Generates a per-user token from a saved backend.

**Path**:
- `name`: Saved backend name.

**Body**:
```json
{
  "id": "backend-user-id",
  "username": "optional-username"
}
```

**Response**:
```json
{
  "token": "...",
  "username": "optional-username"
}
```

**Errors**:
- `400 Bad Request` if `id` is missing.
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if token generation fails.

---

#### GET /v1/api/backend/{name}/sessions
Returns active sessions from a saved backend.

**Path**:
- `name`: Saved backend name.

**Query**:
- `raw` (optional) - Include the backend raw response.

**Response**:
```json
[
  {
    "id": "...",
    "user": "...",
    "...": "session fields"
  }
]
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if the backend request fails.

---

#### GET /v1/api/backend/{name}/discover
Discovers Plex servers using a saved Plex backend configuration.

**Path**:
- `name`: Saved backend name.

**Response**:
```json
[
  {
    "name": "...",
    "uri": "...",
    "...": "Plex discovery fields"
  }
]
```

**Errors**:
- `400 Bad Request` if the backend is not Plex.
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if discovery fails.

---

#### GET /v1/api/backend/{name}/library
Lists libraries exposed by a saved backend.

**Path**:
- `name`: Saved backend name.

**Response**:
```json
[
  {
    "id": "1",
    "name": "Movies",
    "supported": true,
    "ignored": false
  }
]
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if the backend request fails.

---

#### POST|DELETE /v1/api/backend/{name}/library/{id}
Marks or un-marks a library as ignored in backend config.

**Path**:
- `name`: Saved backend name.
- `id`: Library identifier.

**Method Behavior**:
- `POST` marks the library as ignored.
- `DELETE` removes the ignore flag.

**Response**:
```json
[
  {
    "id": "1",
    "name": "Movies",
    "ignored": true
  }
]
```

**Errors**:
- `404 Not Found` if the user, backend, or library does not exist.
- `409 Conflict` if the library is already in the requested state.

**Notes**:
- Ignored library IDs are persisted in `options.ignore`.

---

#### GET|POST|PATCH|DELETE /v1/api/backend/{name}/option[/{option}]
Gets, sets, or deletes a single backend option.

**Path**:
- `name`: Saved backend name.
- `option` (optional) - Option key. You can also send it as `key`.

**Method Behavior**:
- `GET` reads an option.
- `POST` and `PATCH` set an option value.
- `DELETE` removes the stored option value.

**Input**:
- `GET`: option key in the path or query string.
- `POST`, `PATCH`, `DELETE`: JSON body or form fields with `key` and optional `value`.

**Response**:
```json
{
  "key": "options.client.timeout",
  "value": 30,
  "real_val": "30",
  "type": "float",
  "description": "HTTP timeout in seconds"
}
```

**Errors**:
- `400 Bad Request` if the key is missing, invalid, outside the allowed namespace, or if validation fails.
- `404 Not Found` if the user, backend, or option does not exist.

**Notes**:
- External callers may only manage keys that start with `options.`.
- Internal requests can access non-`options.` keys.
- Boolean parsing accepts common values such as `true`, `false`, `on`, `off`, `yes`, and `no`.

---

#### GET /v1/api/backend/{name}/search[/{id}]
Searches backend content by backend item ID or free-text query.

**Path**:
- `name`: Saved backend name.
- `id` (optional) - Backend item ID.

**Query**:
- `id` (optional) - Alternative way to pass the backend item ID.
- `q` (optional) - Free-text search query.
- `limit` (optional) - Maximum number of results. Defaults to `25`.
- `raw` (optional) - Include raw backend payloads.

**Response**:
```json
[
  {
    "id": 123,
    "title": "Movie Title",
    "type": "movie",
    "via": "plex_main",
    "webUrl": "...",
    "...": "normalized entity fields"
  }
]
```

**Errors**:
- `400 Bad Request` if neither `id` nor `q` is provided.
- `404 Not Found` if the user or backend does not exist, or if no results are found.
- `500 Internal Server Error` if the backend request fails.

**Notes**:
- Results are normalized through the shared entity formatter.
- When `raw=true`, each item also includes the backend raw response.

---

#### GET /v1/api/backend/{name}/unmatched[/{id}]
Scans one library, or all supported non-ignored libraries, for items without supported GUIDs.

**Path**:
- `name`: Saved backend name.
- `id` (optional) - Library ID. If omitted, WatchState scans every supported non-ignored library.

**Query**:
- `timeout` (optional) - Override backend timeout.
- `raw` (optional) - Include raw backend payloads.

**Response**:
```json
[
  {
    "title": "Unknown Item",
    "type": "movie",
    "webUrl": "...",
    "library": "1",
    "path": "/media/movies/Unknown Item.mkv"
  }
]
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if the scan fails.

---

#### GET /v1/api/backend/{name}/mismatched[/{id}]
Scans one library, or all supported non-ignored libraries, for likely bad title/path matches.

**Path**:
- `name`: Saved backend name.
- `id` (optional) - Library ID. If omitted, WatchState scans every supported non-ignored library.

**Query**:
- `timeout` (optional) - Override backend timeout.
- `raw` (optional) - Include raw backend payloads.
- `percentage` (optional) - Threshold below which a result is returned. Defaults to `50`.
- `method` (optional) - `similarity` or `levenshtein`. Defaults to `similarity`.

**Response**:
```json
[
  {
    "title": "Movie Title",
    "percent": 32.7,
    "matches": [
      {
        "path": "movie title 2024",
        "title": "movie title",
        "methods": {
          "similarity": 32.7,
          "levenshtein": 88.1,
          "startWith": false
        }
      }
    ],
    "webUrl": "...",
    "library": "1"
  }
]
```

**Errors**:
- `400 Bad Request` if `method` is invalid.
- `404 Not Found` if the user or backend does not exist.
- `500 Internal Server Error` if the scan fails.

---

#### GET /v1/api/backend/{name}/stale/{id}
Compares local mapped records against one remote library and reports stale local references.

**Path**:
- `name`: Saved backend name.
- `id`: Library ID.

**Query**:
- `ignore` (optional) - Ignore the cached remote library snapshot and rebuild it.
- `timeout` (optional) - Override backend timeout.

**Response**:
```json
{
  "backend": {
    "name": "plex_main",
    "library": {
      "id": "1",
      "name": "Movies"
    }
  },
  "counts": {
    "remote": 1200,
    "local": 1220,
    "stale": 20
  },
  "items": [
    {
      "id": 101,
      "title": "Old Record",
      "...": "normalized entity fields"
    }
  ]
}
```

**Errors**:
- `400 Bad Request` if `id` is empty.
- `404 Not Found` if the user or backend does not exist.

---

#### DELETE /v1/api/backend/{name}/stale/{id}
Accepts a list of stale IDs to remove from the mapper workflow.

**Path**:
- `name`: Saved backend name.
- `id`: Library ID.

**Body**:
```json
{
  "ids": [101, 102, 103]
}
```

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Removed stale references."
  }
}
```

**Errors**:
- `400 Bad Request` if `id` is empty or if `ids` is missing or empty.
- `404 Not Found` if the user or backend does not exist.

**Notes**:
- The current implementation validates the request and loads mapper data, but does not yet delete the supplied IDs from storage.

---

#### GET /v1/api/backend/{name}/ignore
Lists ignore rules that are scoped to one backend.

**Path**:
- `name`: Saved backend name.

**Response**:
```json
[
  {
    "rule": "movie://tmdb:123@plex_main",
    "type": "Movie",
    "backend": "plex_main",
    "db": "tmdb",
    "id": "123",
    "scoped": "No",
    "created": "2026-03-28T12:00:00+00:00"
  }
]
```

**Errors**:
- `404 Not Found` if the user or backend does not exist.

---

#### POST /v1/api/backend/{name}/ignore
Adds a backend-scoped ignore rule.

**Path**:
- `name`: Saved backend name.

**Body**:
```json
{
  "type": "movie",
  "db": "tmdb",
  "id": "123"
}
```

**Alternative Body**:
```json
{
  "rule": "movie://tmdb:123@plex_main"
}
```

**Response**:
- `201 Created` with an empty body.

**Errors**:
- `400 Bad Request` if required parts are missing or the rule is invalid.
- `404 Not Found` if the user or backend does not exist.
- `409 Conflict` if the exact rule already exists or if a global rule already exists.

**Notes**:
- If `scoped` is provided, the rule becomes `...?id=<scoped>`.

---

#### DELETE /v1/api/backend/{name}/ignore
Removes a backend-scoped ignore rule.

**Path**:
- `name`: Saved backend name.

**Body**:
```json
{
  "rule": "movie://tmdb:123@plex_main"
}
```

**Response**:
- `200 OK` with an empty body.

**Errors**:
- `400 Bad Request` if `rule` is missing or invalid.
- `404 Not Found` if the user, backend, or rule does not exist.

---

### History

All history routes honor `X-User` or `?user=`.

#### GET /v1/api/history
Searches and paginates the local history database.

**Query**:
- Pagination:
  - `page` (optional) - Defaults to `1`.
  - `perpage` (optional) - Defaults to `12`.
- Output shaping:
  - `view` (optional) - Comma-separated field list. Only the requested fields are returned for each item.
  - `with_duplicates` (optional) - Include duplicate reference IDs.
- Sorting:
  - `sort` (optional, repeatable or array-style) - Sort expressions such as `updated_at:desc`.
- Filters:
  - `watched`
  - `id`
  - `via`
  - `year`
  - `type` (`movie` or `episode`)
  - `title`
  - `season`
  - `episode`
  - `parent` in `provider://id` format
  - `guids` in `provider://id` format
  - `rguid` in `guid://parentID/seasonNumber[/episodeNumber]` format
  - `metadata` with companion `key`, `value`, and optional `exact`
  - `extra` with companion `key`, `value`, and optional `exact`
  - `path`
  - `subtitle`
  - `genres`

**Response**:
```json
{
  "paging": {
    "total": 25,
    "perpage": 12,
    "current_page": 1,
    "first_page": 1,
    "next_page": 2,
    "prev_page": null,
    "last_page": 3
  },
  "filters": {
    "watched": 1
  },
  "history": [
    {
      "id": 101,
      "title": "Movie Title",
      "type": "movie",
      "via": "plex_main",
      "watched": 1,
      "webUrl": "...",
      "reported_by": ["plex_main", "jellyfin_main"],
      "not_reported_by": [],
      "duplicate_reference_ids": []
    }
  ],
  "links": {
    "self": "/v1/api/history?page=1",
    "first_url": "/v1/api/history?page=1",
    "next_url": "/v1/api/history?page=2",
    "prev_url": null,
    "last_url": "/v1/api/history?page=3"
  },
  "searchable": [
    {
      "key": "title",
      "description": "Search using the title.",
      "type": "string"
    }
  ]
}
```

**Errors**:
- `400 Bad Request` for invalid `rguid`, `parent`, `guids`, or JSON field query syntax.
- `404 Not Found` if the user does not exist or if no results match.

**Notes**:
- `metadata`, `extra`, `path`, `subtitle`, and `genres` searches can be slow.
- The normalized item format includes extra fields such as `content_path`, `content_title`, `content_overview`, `content_genres`, `reported_by`, `not_reported_by`, and `isTainted`.

---

#### GET /v1/api/history/{id}
Returns one local history record.

**Path**:
- `id`: Numeric local record ID.

**Query**:
- `files` (optional) - Include media file probes and sidecar subtitles.
- `with_duplicates` (optional) - Include duplicate reference IDs.

**Response**:
```json
{
  "id": 101,
  "title": "Movie Title",
  "type": "movie",
  "via": "plex_main",
  "content_path": "/media/movies/Movie Title (2024).mkv",
  "content_exists": true,
  "duplicate_reference_ids": [],
  "files": [
    {
      "path": "/media/movies/Movie Title (2024).mkv",
      "source": ["plex_main"],
      "ffprobe": {
        "streams": [],
        "format": {}
      },
      "subtitles": ["/media/movies/Movie Title (2024).en.srt"]
    }
  ],
  "hardware": {
    "codecs": [
      {
        "codec": "libx264",
        "name": "H.264 (CPU) (All)",
        "hwaccel": false
      }
    ],
    "devices": ["/dev/dri/renderD128"]
  }
}
```

**Errors**:
- `404 Not Found` if the user or item does not exist.

**Notes**:
- `files=1` performs filesystem checks and `ffprobe` calls, so it is heavier than a normal read.

---

#### GET /v1/api/history/{id}/duplicates
Returns duplicate local history IDs for the record.

**Path**:
- `id`: Numeric local record ID.

**Response**:
```json
{
  "duplicate_reference_ids": [102, 103]
}
```

**Errors**:
- `404 Not Found` if the user or item does not exist.
- `500 Internal Server Error` if duplicate lookup fails.

---

#### DELETE /v1/api/history/{id}
Deletes one local history record.

**Path**:
- `id`: Numeric local record ID.

**Response**:
- `200 OK` with an empty body.

**Errors**:
- `404 Not Found` if the user or item does not exist.

---

#### GET|POST|DELETE /v1/api/history/{id}/watch
Reads or changes the watched state of a history record.

**Path**:
- `id`: Numeric local record ID.

**Method Behavior**:
- `GET` returns the current watched flag.
- `POST` marks the item as watched and queues a sync push.
- `DELETE` marks the item as unwatched and queues a sync push.

**GET Response**:
```json
{
  "watched": true
}
```

**POST or DELETE Response**:
- Returns the same payload as `GET /v1/api/history/{id}` for the updated record.

**Errors**:
- `404 Not Found` if the user or item does not exist.
- `409 Conflict` if the item is already in the requested watched state.

**Notes**:
- The route records a `webui.markplayed` or `webui.markunplayed` event in the entity extra data.

---

#### GET /v1/api/history/{id}/validate
Validates that each backend reference for a local record still exists remotely.

**Path**:
- `id`: Numeric local record ID.

**Response**:
```json
{
  "plex_main": {
    "id": "12345",
    "status": true,
    "message": "Item found."
  },
  "jellyfin_main": {
    "id": "67890",
    "status": false,
    "message": "Item not found."
  }
}
```

**Errors**:
- `404 Not Found` if the user or item does not exist.

**Notes**:
- The response includes `X-Cache: HIT` or `X-Cache: MISS`.
- Results are cached for 10 minutes.

---

#### DELETE /v1/api/history/{id}/metadata/{backend}
Removes one backend metadata block from a local history record.

**Path**:
- `id`: Numeric local record ID.
- `backend`: Backend name.

**Response**:
- Returns the updated record payload, or:
```json
{
  "info": {
    "code": 200,
    "message": "Record deleted."
  }
}
```

**Errors**:
- `404 Not Found` if the user, item, or backend metadata block does not exist.

**Notes**:
- If the removed metadata block was the last one on the record, the entire local record is deleted.

---

#### GET /v1/api/history/{id}/images/{type}
Proxies a poster or background image for a history record.

**Path**:
- `id`: Numeric local record ID.
- `type`: `poster` or `background`

**Response**:
- Binary image stream with headers such as `Content-Type` and `X-Via`.

**Errors**:
- `304 Not Modified` if `If-Modified-Since` is present.
- `400 Bad Request` if image fetching fails.
- `404 Not Found` if the user, item, remote item, or requested image is unavailable.

**Notes**:
- Images are fetched from the item's `via` backend only.

---

### Ignore Rules

All ignore-rule routes honor `X-User` or `?user=`.

#### GET /v1/api/ignore
Lists ignore rules for the current user.

**Query**:
- `type` (optional)
- `db` (optional)
- `id` (optional)
- `backend` (optional)

**Response**:
```json
[
  {
    "rule": "movie://tmdb:123@plex_main",
    "id": "123",
    "type": "Movie",
    "backend": "plex_main",
    "db": "tmdb",
    "title": "Movie Title",
    "scoped": false,
    "scoped_to": null,
    "created": "2026-03-28T12:00:00+00:00"
  }
]
```

**Errors**:
- `404 Not Found` if the user does not exist.

---

#### POST /v1/api/ignore
Adds an ignore rule.

**Body**:
```json
{
  "rule": "movie://tmdb:123@plex_main"
}
```

**Alternative Body**:
```json
{
  "id": "123",
  "db": "tmdb",
  "backend": "plex_main",
  "type": "movie",
  "scoped": true,
  "scoped_to": 101
}
```

**Response**:
```json
{
  "rule": "movie://tmdb:123@plex_main?id=101",
  "id": "123",
  "type": "Movie",
  "backend": "plex_main",
  "db": "tmdb",
  "title": "Movie Title",
  "scoped": true,
  "scoped_to": 101,
  "created": "2026-03-28T12:00:00+00:00"
}
```

**Errors**:
- `400 Bad Request` if required fields are missing or the rule is invalid.
- `404 Not Found` if the user does not exist.
- `409 Conflict` if the rule already exists.

---

#### DELETE /v1/api/ignore
Removes an ignore rule.

**Body**:
```json
{
  "rule": "movie://tmdb:123@plex_main"
}
```

**Response**:
```json
{
  "rule": "movie://tmdb:123@plex_main",
  "id": "123",
  "type": "Movie",
  "backend": "plex_main",
  "db": "tmdb",
  "title": "Movie Title",
  "scoped": false,
  "scoped_to": null
}
```

**Errors**:
- `400 Bad Request` if `rule` is missing or invalid.
- `404 Not Found` if the user does not exist or the rule cannot be found.

---

### Logs

#### GET /v1/api/logs
Lists log, webhook dump, and debug files under WatchState's temp directories.

**Response**:
```json
[
  {
    "filename": "access.20260328.jsonl",
    "type": "access",
    "date": "20260328",
    "size": 12345,
    "modified": "2026-03-28T12:00:00+00:00"
  }
]
```

---

#### GET /v1/api/logs/recent
Returns the most recent raw lines from today's `.jsonl` log files.

**Query**:
- `limit` (optional) - Defaults to `50`.

**Response**:
```json
[
  {
    "filename": "access.20260328.jsonl",
    "type": "access",
    "date": "20260328",
    "size": 12345,
    "modified": "2026-03-28T12:00:00+00:00",
    "lines": [
      "{\"id\":\"...\",\"datetime\":\"2026-03-28T12:00:00+00:00\",\"level\":\"info\",\"logger\":\"access\",\"message\":\"Queuing main@plex_main request ...\"}"
    ]
  }
]
```

**Errors**:
- `500 Internal Server Error` if the log path is not configured.

**Notes**:
- Only today's `.jsonl` log files are returned, not older logs or JSON dump files.

---

#### GET|DELETE /v1/api/log/{filename}
Reads, downloads, streams, or deletes a single log, debug, or webhook file.

**Path**:
- `filename`: File name returned by `GET /v1/api/logs`.

**Query Parameters for GET**:
- `download` (optional) - Download the raw file.
- `stream` (optional) - Stream the file over SSE.
- `offset` (optional) - Reverse-pagination offset from the end of the file.

**Normal GET Response**:
```json
{
  "filename": "access.20260328.jsonl",
  "offset": 100,
  "next": 200,
  "max": 500,
  "type": "log",
  "lines": [
    "{\"id\":\"...\",\"datetime\":\"2026-03-28T12:00:00+00:00\",\"level\":\"info\",\"logger\":\"access\",\"message\":\"Some log line\"}"
  ]
}
```

**Stream Response**:
- `Content-Type: text/event-stream; charset=UTF-8`
- Emits:
  - `data` events containing `{"data":"<raw line>"}` payloads
  - `ping` keepalive events

**DELETE Response**:
- `200 OK` with an empty body.

**Errors**:
- `404 Not Found` if the file does not exist.

**Notes**:
- Path traversal is blocked with `realpath` and base path checks.
- `download=1` returns the raw file stream instead of JSON.
- In practice, bad or unresolvable file names usually surface as `404 Not Found`; `400 Bad Request` only applies when the route argument itself is missing.

---

### Player Streaming

All player routes are open. Access is gated by the playback token in the path. Tokens are created with `POST /v1/api/system/sign/{id}`.

#### GET /v1/api/player/playlist/{token}[/{fake...}]
Builds the top-level HLS playlist for a signed media file.

**Path**:
- `token`: Short-lived playback token.

**Query**:
- `debug` (optional) - Enable verbose debug behavior in downstream segment/subtitle routes.
- `sd` (optional) - Segment duration. Defaults to `6.000` seconds.

**Response**:
- `Content-Type: application/x-mpegurl`
- HLS master playlist text with video and subtitle tracks.

**Errors**:
- `400 Bad Request` if the token is invalid, the path is empty, or media duration is unavailable.
- `500 Internal Server Error` if playlist generation fails.

**Notes**:
- Sidecar subtitle files are auto-discovered when no subtitle is already selected.

---

#### GET /v1/api/player/m3u8/{token}[/{fake...}]
Builds the HLS segment playlist for a signed media file.

**Path**:
- `token`: Short-lived playback token.

**Response**:
- `Content-Type: application/x-mpegurl`
- VOD segment playlist referencing `/v1/api/player/segments/{token}/{segment}.ts`.

**Errors**:
- `304 Not Modified` if `If-Modified-Since` is present.
- `400 Bad Request` if the token is invalid or expired.

---

#### GET /v1/api/player/segments/{token}/{segment}[.{type}]
Returns one MPEG-TS segment, either direct-played or transcoded with ffmpeg.

**Path**:
- `token`: Short-lived playback token.
- `segment`: Zero-based segment number.
- `type` (optional) - Accepted by the route but not used by the segment generator.

**Query**:
- `sd` (optional) - Override the duration of the final segment.

**Response**:
- `Content-Type: video/mpegts`
- Streaming TS payload.

**Headers**:
- `X-Transcode-Time`
- `X-Ffmpeg` and `X-Transcode-Config` when debug mode is enabled

**Errors**:
- `304 Not Modified` if `If-Modified-Since` is present.
- `400 Bad Request` if the token is invalid, the path is empty, the path is not a file, or required hardware devices are missing.
- `404 Not Found` if the media path or external subtitle path is missing.
- `500 Internal Server Error` if ffprobe or ffmpeg fails.

**Notes**:
- Segment generation is serialized per playback token with a lock file.
- External subtitles can be burned in.
- Internal text subtitles can be extracted and burned in.

---

#### GET /v1/api/player/subtitle/{token}/{type}.{source}{index}.m3u8
Builds a one-track HLS subtitle playlist.

**Path**:
- `token`: Short-lived playback token.
- `type`: Subtitle type label used in the generated path, typically `webvtt`.
- `source`: `x` for external subtitle files, `i` for internal subtitle streams.
- `index`: Subtitle index.

**Response**:
- `Content-Type: application/x-mpegurl`
- HLS subtitle playlist pointing at the subtitle conversion endpoint.

**Errors**:
- `304 Not Modified` if `If-Modified-Since` is present.
- `400 Bad Request` if the token is invalid, there are no matching subtitles, or the selected subtitle cannot be found.

---

#### GET /v1/api/player/subtitle/{token}/{source}{index}.{ext}
Converts an external or internal subtitle track to WebVTT and streams it.

**Path**:
- `token`: Short-lived playback token.
- `source`: `x` for external subtitle files, `i` for internal subtitle streams.
- `index`: Single-digit subtitle index.
- `ext`: Requested extension in the URL path.

**Query**:
- `reload` (optional) - Bypass the subtitle conversion cache.

**Response**:
- `Content-Type: text/vtt`
- Converted subtitle body.
- `X-Cache: hit|miss`

**Errors**:
- `304 Not Modified` if `If-Modified-Since` is present.
- `400 Bad Request` if the token is invalid, the source is invalid, the subtitle codec is unsupported, or the target is not a subtitle stream.
- `404 Not Found` if the media file or subtitle file does not exist.
- `500 Internal Server Error` if conversion fails.

**Notes**:
- External formats such as `vtt`, `webvtt`, `srt`, and `ass` are supported.
- Internal conversion currently supports text subtitle codecs listed in the player implementation.
- The route includes `{ext}`, but the generated output is always WebVTT.

---

### System

#### GET /v1/api/system/healthcheck
Returns a simple liveness payload.

**Access**:
- Open.

**Response**:
```json
{
  "status": "ok",
  "message": "System is healthy"
}
```

---

#### GET /v1/api/system/version
Returns build and runtime version metadata.

**Response**:
```json
{
  "version": "dev-master",
  "build": "unknown",
  "sha": "unknown",
  "branch": "unknown",
  "container": true
}
```

---

#### GET /v1/api/system/supported
Returns the list of supported backend types.

**Response**:
```json
[
  "plex",
  "jellyfin",
  "emby"
]
```

---

All `/v1/api/system/auth/*` routes are open. Routes that need credentials validate them themselves.

#### GET /v1/api/system/auth/test
Returns `200 OK` if the auth route group is reachable.

**Response**:
- `200 OK` with an empty body.

---

#### GET /v1/api/system/auth/has_user
Returns whether the system account exists and may include an auto-login token for trusted local clients.

**Responses**:
- `200 OK` with an empty body when no auto-login token is issued.
- `204 No Content` when the system user/password is not configured.
- JSON payload below when auto-login is allowed.

```json
{
  "auto_login": true,
  "token": "..."
}
```

**Notes**:
- Returned user tokens expire after the configured auth token lifetime. Default: 2 days.

**Errors**:
- `500 Internal Server Error` if token encoding fails.

---

#### GET /v1/api/system/auth/user
Returns the decoded signed user token.

**Auth**:
- `Authorization: Token <token>`
- `?ws_token=<token>`

**Response**:
```json
{
  "username": "admin",
  "created_at": "2026-03-28T12:00:00+00:00",
  "expires_at": "2026-04-11T12:00:00+00:00",
  "refresh_required": false
}
```

**Errors**:
- `401 Unauthorized` for missing, invalid, or expired user tokens.
- `500 Internal Server Error` if the system account is not configured.

**Notes**:
- `refresh_required` becomes `true` when the token is close enough to expiry that the client should call the refresh endpoint.

---

#### POST /v1/api/system/auth/signup
Creates the initial WatchState admin account.

**Body**:
```json
{
  "username": "admin",
  "password": "secret"
}
```

**Response**:
- `201 Created` with an empty body.

**Errors**:
- `400 Bad Request` if `username` or `password` is missing.
- `403 Forbidden` if the system account is already configured.

---

#### POST /v1/api/system/auth/login
Exchanges username/password credentials for a signed user token.

**Body**:
```json
{
  "username": "admin",
  "password": "secret"
}
```

**Response**:
```json
{
  "token": "..."
}
```

**Notes**:
- Returned user tokens expire after the configured auth token lifetime. Default: 2 days.

---

#### POST /v1/api/system/auth/refresh
Re-issues the current signed user token when it is near expiry.

**Auth**:
- `Authorization: Token <token>`
- `?ws_token=<token>`

**Response**:
```json
{
  "token": "...",
  "username": "admin",
  "created_at": "2026-04-10T12:00:00+00:00",
  "expires_at": "2026-04-24T12:00:00+00:00",
  "refreshed": true
}
```

**Errors**:
- `401 Unauthorized` for missing, invalid, or expired user tokens.
- `500 Internal Server Error` if the system account is not configured.

**Errors**:
- `400 Bad Request` if credentials are missing.
- `401 Unauthorized` if the credentials are invalid.
- `429 Too Many Requests` if rate limited after repeated failures.
- `500 Internal Server Error` if the system account is not configured or token encoding fails.

---

#### PUT /v1/api/system/auth/change_password
Changes the configured system password.

**Body**:
```json
{
  "current_password": "old-secret",
  "new_password": "new-secret"
}
```

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Password changed successfully."
  }
}
```

**Errors**:
- `400 Bad Request` if required fields are missing.
- `401 Unauthorized` if the current password is invalid.
- `500 Internal Server Error` if the stored password is missing or cannot be updated.

---

#### DELETE /v1/api/system/auth/sessions
Invalidates all signed user sessions by rotating the signing secret.

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Sessions invalidated successfully."
  }
}
```

**Errors**:
- `500 Internal Server Error` if secret rotation fails.

---

#### GET /v1/api/system/env
Lists supported env keys and current values for non-protected entries.

**Query**:
- `set` (optional) - If true, only include keys present in the `.env` file.

**Response**:
```json
{
  "data": [
    {
      "key": "WS_API_KEY",
      "description": "API key used for X-APIKEY authentication",
      "type": "string",
      "mask": true,
      "danger": true,
      "value": "***",
      "config_value": "...",
      "config": "api.key"
    }
  ],
  "file": "/config/.env"
}
```

**Notes**:
- Protected keys are omitted from external responses.

---

#### GET /v1/api/system/env/{key}
Reads one env key and its metadata.

**Path**:
- `key`: Env key name.

**Response**:
```json
{
  "key": "WS_API_KEY",
  "value": "secret",
  "description": "API key used for X-APIKEY authentication",
  "type": "string",
  "mask": true,
  "danger": true,
  "config_value": "secret",
  "config": "api.key"
}
```

**Errors**:
- `400 Bad Request` if the key is invalid.
- `404 Not Found` if the key is unset or protected from external access.

---

#### POST|DELETE /v1/api/system/env/{key}
Sets or removes one env key.

**Path**:
- `key`: Env key name.

**POST Body**:
```json
{
  "value": "new-value"
}
```

**Response**:
- Returns the same metadata envelope as `GET /v1/api/system/env/{key}`.

**Errors**:
- `400 Bad Request` if the key is invalid, the value is missing, or validation fails.
- `404 Not Found` if the key is protected from external access.

**Notes**:
- This route edits `/config/.env`.
- `DELETE` removes the key from the `.env` file.
- Bool, int, and float values are coerced according to the env spec.
- Protected keys cannot be modified through external requests.

---

#### GET /v1/api/system/guids
Lists supported GUID types and validators.

**Response**:
```json
[
  {
    "guid": "imdb",
    "type": "movie",
    "validator": {
      "pattern": "..."
    }
  }
]
```

---

#### GET /v1/api/system/guids/custom
Reads the custom GUID configuration file.

**Response**:
```json
{
  "version": "0.0",
  "guids": [],
  "links": []
}
```

---

#### PUT /v1/api/system/guids/custom
Adds a custom GUID definition.

**Body**:
```json
{
  "name": "letterboxd",
  "type": "plex",
  "description": "Letterboxd movie GUID",
  "validator": {
    "pattern": "/^[a-z0-9-]+$/",
    "example": "movie-name",
    "tests": {
      "valid": ["movie-name"],
      "invalid": ["movie name"]
    }
  }
}
```

**Response**:
```json
{
  "id": "uuid",
  "type": "plex",
  "name": "guid_letterboxd",
  "description": "Letterboxd movie GUID",
  "validator": {
    "pattern": "/^[a-z0-9-]+$/",
    "example": "movie-name",
    "tests": {
      "valid": ["movie-name"],
      "invalid": ["movie name"]
    }
  }
}
```

**Errors**:
- `400 Bad Request` if required fields are missing, the regex is invalid, or the tests do not match the supplied pattern rules.

**Notes**:
- If the name does not start with `guid_`, WatchState adds the prefix automatically.
- `type` must be one of the configured supported backend/client names such as `plex`, `jellyfin`, or `emby`, not a media type like `movie`.

---

#### DELETE /v1/api/system/guids/custom/{id}
Deletes a custom GUID definition.

**Path**:
- `id`: Custom GUID UUID.

**Response**:
```json
{
  "id": "uuid",
  "name": "guid_letterboxd",
  "type": "plex"
}
```

**Errors**:
- `404 Not Found` if the GUID is not found.

**Notes**:
- Links that target the deleted GUID are removed automatically.

---

#### GET /v1/api/system/guids/custom/{client}
Lists custom GUID links for one backend client type.

**Path**:
- `client`: Backend type such as `plex`, `jellyfin`, or `emby`.

**Response**:
```json
[
  {
    "id": "uuid",
    "type": "plex",
    "map": {
      "from": "GuidField",
      "to": "guid_letterboxd"
    }
  }
]
```

**Errors**:
- `404 Not Found` if the client type is unsupported.

---

#### PUT /v1/api/system/guids/custom/{client}
Adds a custom GUID link for one backend client type.

**Path**:
- `client`: Backend type.

**Body**:
```json
{
  "type": "plex",
  "map": {
    "from": "GuidField",
    "to": "letterboxd"
  },
  "options": {
    "legacy": true
  },
  "replace": {
    "from": "old",
    "to": "new"
  }
}
```

**Response**:
```json
{
  "id": "uuid",
  "type": "plex",
  "map": {
    "from": "GuidField",
    "to": "guid_letterboxd"
  },
  "options": {
    "legacy": true
  }
}
```

**Errors**:
- `400 Bad Request` if required fields are missing, the target GUID is unsupported, or the mapping already exists.

**Notes**:
- For Plex links, `options.legacy` is currently required and must be truthy to satisfy the current validation logic.

---

#### DELETE /v1/api/system/guids/custom/{client}/{id}
Deletes a custom GUID link for one backend client.

**Path**:
- `client`: Backend type.
- `id`: Link UUID.

**Response**:
```json
{
  "id": "uuid",
  "type": "plex",
  "map": {
    "from": "GuidField",
    "to": "guid_letterboxd"
  }
}
```

**Errors**:
- `404 Not Found` if the link does not exist.

---

#### GET /v1/api/system/guids/custom/{client}/{index}
Returns the raw nested value stored at `{client}.{index}` in the custom GUID document.

**Path**:
- `client`: Backend type.
- `index`: Numeric index.

**Errors**:
- `404 Not Found` if the client or requested index does not exist.

**Notes**:
- The current implementation looks up a nested path inside the custom GUID document, not an item from the `links` array shown by `GET /v1/api/system/guids/custom/{client}`.
- In a typical custom GUID file this means the route often returns `404 Not Found` unless the document contains a matching top-level `{client}` object with a numeric child key.

---

#### GET /v1/api/system/events
Lists queued and historical events.

**Query**:
- `page` (optional) - Defaults to `1`.
- `perpage` (optional) - Defaults to `10`.
- `filter` (optional) - Partial match on the event name.

**Response**:
```json
{
  "paging": {
    "page": 1,
    "total": 25,
    "perpage": 10,
    "next": 2,
    "previous": null
  },
  "items": [
    {
      "id": "uuid",
      "event": "system:task",
      "status": 0,
      "status_name": "Pending",
      "event_data": {},
      "options": {},
      "attempts": 0,
      "created_at": "2026-03-28T12:00:00+00:00",
      "updated_at": "2026-03-28T12:00:00+00:00"
    }
  ],
  "statuses": [
    {
      "id": 0,
      "name": "Pending"
    }
  ]
}
```

---

#### GET /v1/api/system/events/stats
Returns event counts grouped by status.

**Query**:
- `only` (optional) - Comma-separated list of status names.

**Response**:
```json
{
  "pending": 3,
  "running": 1,
  "completed": 10,
  "cancelled": 0,
  "failed": 0
}
```

**Errors**:
- `400 Bad Request` if any status name is invalid.

---

#### POST /v1/api/system/events
Queues a new event manually.

**Body**:
```json
{
  "event": "system:task",
  "event_data": {
    "name": "index"
  },
  "DELAY_BY": 30
}
```

**Response**:
```json
{
  "info": {
    "code": 202,
    "message": "Event 'system:task' was queued."
  },
  "id": "uuid",
  "event": "system:task",
  "status": 0,
  "status_name": "Pending"
}
```

**Errors**:
- `400 Bad Request` if `event` is missing.

---

#### GET /v1/api/system/events/{id}
Returns an event.

**Path**:
- `id`: Event UUID.

**Response**:
```json
{
  "id": "uuid",
  "event": "system:task",
  "status": 0,
  "status_name": "Pending",
  "event_data": {}
}
```

**Errors**:
- `404 Not Found` if the event does not exist.

---

#### PATCH /v1/api/system/events/{id}
Updates an event's mutable fields.

**Path**:
- `id`: Event UUID.

**Body**:
```json
{
  "status": 3,
  "event": "system:task",
  "event_data": {
    "name": "index"
  },
  "reset_logs": true
}
```

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Updated"
  },
  "id": "uuid",
  "status_name": "Completed"
}
```

**Errors**:
- `400 Bad Request` if the event is running, `status` is not numeric, or the status value is invalid.
- `404 Not Found` if the event does not exist.

---

#### DELETE /v1/api/system/events/{id}
Deletes one event.

**Path**:
- `id`: Event UUID.

**Response**:
```json
{
  "id": "uuid",
  "event": "system:task",
  "status_name": "Cancelled"
}
```

**Errors**:
- `400 Bad Request` if the event is currently running.
- `404 Not Found` if the event does not exist.

---

#### DELETE /v1/api/system/events
Deletes all non-pending events.

**Response**:
- `200 OK` with an empty body.

**Notes**:
- Pending events are preserved.

---

#### POST /v1/api/system/command
Queues a one-time command for execution.

**Headers**:
- Standard API auth is still required.
- `X-Signature: sha256=<hmac>`
- `X-Sign-With: token|api`

`X-Sign-With` selects which presented credential is used to verify the HMAC over the raw JSON body:
- `token`: verify against the authenticated user token
- `api`: verify against the presented API key

If `X-Sign-With` is omitted, WatchState defaults to `api`.

**Body**:
```json
{
  "command": "db:index",
  "cwd": "/home/coders/apps/watchstate",
  "timeout": 120,
  "force_color": true
}
```

**Response**:
```json
{
  "token": "sha256-token",
  "tracking": "/v1/api/system/command/sha256-token",
  "expires": "2026-03-28T12:05:00+00:00"
}
```

**Errors**:
- `400 Bad Request` if the body is empty or `command` is missing/invalid.
- `400 Bad Request` if the request signature is missing, malformed, or uses an unsupported verifier/algorithm.
- `403 Forbidden` if the signature does not match the selected credential.

---

#### GET /v1/api/system/command
Lists recent command sessions that are still available for attach or replay.

**Response**:
```json
{
  "items": [
    {
      "token": "sha256-token",
      "command": "db:index",
      "status": "completed",
      "cwd": "/home/coders/apps/watchstate",
      "created_at": "2026-03-28T12:00:00+00:00",
      "updated_at": "2026-03-28T12:02:00+00:00",
      "started_at": "2026-03-28T12:00:01+00:00",
      "finished_at": "2026-03-28T12:02:00+00:00",
      "expires_at": "2026-03-28T12:05:00+00:00",
      "available_until": "2026-03-29T12:02:00+00:00",
      "exit_code": 0,
      "last_sequence": 42,
      "connections": 0
    }
  ]
}
```

**Notes**:
- Queued and running sessions use `expires_at` as their availability window.
- Completed sessions remain replayable for about 24 hours after `finished_at`.
- Expired queued and completed sessions are eventually automatically pruned.

---

#### GET /v1/api/system/command/{token}
Attaches to an available command session and streams or replays its output.

**Path**:
- `token`: Command token returned by `POST /v1/api/system/command`.

**Response**:
- `Content-Type: text/event-stream`

**Event Names**:
- `cmd`
- `cwd`
- `data`
- `ping`
- `exit_code`
- `close`

**Example `data` Event Payload**:
```json
{
  "data": "Console output line\n",
  "type": "out"
}
```

**Errors**:
- `404 Not Found` if the token is invalid, the queued request expired, or the completed session exceeded its replay window.

**Notes**:
- Sessions are resumable while the command is still active.
- Use `Last-Event-ID` or `?since=` to resume from a known event sequence.
- Completed sessions remain replayable for about 24 hours after `finished_at`.

---

#### DELETE /v1/api/system/command/{token}
Requests cancellation for a queued or running command.

**Path**:
- `token`: Command token returned by `POST /v1/api/system/command`.

**Response**:
```json
{
  "message": "Command cancellation requested."
}
```

**Errors**:
- `404 Not Found` if the token is invalid/expired.

**Notes**:
- Queued sessions are removed immediately.
- Expired queued or completed sessions return `404 Not Found` until prune removes the session directory.
- Running sessions are marked for cancellation and stop as soon as the worker loop observes the cancel request.
- Completed sessions return `202 Accepted` with `Command has already completed.`

---

#### GET /v1/api/system/scheduler
Returns task scheduler status.

**Response**:
```json
{
  "pid": "1234",
  "status": true,
  "restartable": true,
  "message": "Task scheduler is running."
}
```

**Notes**:
- When not running in a container, the endpoint still returns status metadata explaining the limitation.

---

#### POST /v1/api/system/scheduler/restart
Restarts the task scheduler.

**Response**:
```json
{
  "status": true,
  "restartable": true,
  "message": "Task scheduler restart has been requested."
}
```

**Errors**:
- `400 Bad Request` if `DISABLE_CRON` is set or WatchState is not running in a container.

**Notes**:
- Admin operation. Restarts the background scheduler inside the container.

---

#### GET /v1/api/system/report
Returns the output of the `system:report` command.

**Response**:
```json
{
  "...": "report payload"
}
```

---

#### GET /v1/api/system/report/ini
Returns `ini_get_all()` for development builds.

**Response**:
```json
{
  "content": {
    "memory_limit": {
      "local_value": "512M",
      "global_value": "512M"
    }
  }
}
```

**Errors**:
- `403 Forbidden` outside development builds.

---

#### POST /v1/api/system/url/check
Performs an outbound HTTP request for debugging connectivity, headers, and upstream responses.

**Body**:
```json
{
  "url": "https://example.com",
  "method": "GET",
  "headers": [
    {
      "key": "Authorization",
      "value": "Bearer ..."
    },
    {
      "key": "ws-timeout",
      "value": "15"
    }
  ]
}
```

**Response**:
```json
{
  "request": {
    "url": "https://example.com",
    "method": "GET",
    "headers": {
      "Authorization": "Bearer ..."
    }
  },
  "response": {
    "status": 200,
    "headers": {
      "content-type": "text/html"
    },
    "body": "..."
  }
}
```

**Errors**:
- `400 Bad Request` if `url` is missing, invalid, or if the HTTP method is invalid.

**Notes**:
- Transport failures still return HTTP `200`, but the embedded `response.status` becomes `500` and the embedded headers include `WS-Exception` and `WS-Error`.
- This is a high-risk admin endpoint because it can probe arbitrary URLs.

---

#### POST /v1/api/system/yaml[/{filename}]
Converts the parsed request body to YAML.

**Query**:
- `inline` (optional) - Inline nesting depth. Defaults to `4`.
- `indent` (optional) - Indent width. Defaults to `2`.

**Body**:
- Any parsed JSON payload.

**Response**:
- `Content-Type: text/yaml`
- YAML body rendered from the request payload.

**Errors**:
- `400 Bad Request` if YAML generation fails.

**Notes**:
- When `filename` is supplied, the response is returned as an attachment.

---

#### POST /v1/api/system/sign/{id}
Creates a short-lived playback token for a filesystem path associated with a history item.

**Path**:
- `id`: Numeric local history record ID.

**Body**:
```json
{
  "path": "/media/movies/Movie Title (2024).mkv",
  "time": "PT24H",
  "config": {
    "audio": 1,
    "subtitle": 2,
    "debug": false
  }
}
```

**Response**:
```json
{
  "token": "play-abcdef123456",
  "expires": "2026-03-29T12:00:00+00:00"
}
```

**Errors**:
- `400 Bad Request` if `path` is empty or the reference entity does not exist.
- `404 Not Found` if the filesystem path does not exist.

---

#### GET /v1/api/system/static/{file}
Serves exported UI assets and allowlisted documentation files.

**Access**:
- Open.

**Path**:
- `file`: Path relative to the static file root.

**Response**:
- File stream with `Content-Type`, `Content-Length`, and `Last-Modified`.

**Errors**:
- `400 Bad Request` if the path is invalid.
- `404 Not Found` if the file does not exist.

---

#### GET /v1/api/system/images/{type}
Returns a random poster or background image from the current history database.

**Path**:
- `type`: `poster` or `background`

**Query**:
- `force` (optional) - Ignore the cached random selection and choose a new item.

**Response**:
- Binary image stream.

**Errors**:
- `204 No Content` if no user context, no history rows, or no usable image can be found.

---

#### GET /v1/api/system/backup
Lists available backup files.

**Response**:
```json
[
  {
    "filename": "watchstate.20260328.json.zip",
    "type": "automatic",
    "size": 12345,
    "date": "2026-03-28T12:00:00+00:00"
  }
]
```

---

#### GET|DELETE /v1/api/system/backup/{filename}
Downloads or deletes one backup file.

**Path**:
- `filename`: Backup file name.

**GET Response**:
- Raw file stream with detected `Content-Type`.

**DELETE Response**:
- `200 OK` with an empty body.

**Errors**:
- `400 Bad Request` if the resolved file path escapes the backup directory.
- `404 Not Found` if the file does not exist.

**Notes**:
- `DELETE` permanently removes the backup file.

---

#### GET /v1/api/system/processes
Returns the current OS process list.

**Response**:
```json
{
  "processes": [
    {
      "user": "root",
      "pid": "123",
      "cpu": "0.0",
      "mem": "0.1",
      "command": "php-fpm"
    }
  ]
}
```

**Errors**:
- `500 Internal Server Error` if `ps aux` fails.

---

#### DELETE /v1/api/system/processes/{id}
Terminates one process by PID.

**Path**:
- `id`: Numeric PID.

**Response**:
- `200 OK` with an empty body.

**Errors**:
- `400 Bad Request` if the PID is invalid.
- `404 Not Found` if the process does not exist.
- `500 Internal Server Error` if `SIGTERM` or `SIGKILL` fails.

**Notes**:
- Admin operation. WatchState sends `SIGTERM`, waits up to 5 seconds, then escalates to `SIGKILL` if needed.

---

#### DELETE /v1/api/system/cache
Flushes the Redis cache database.

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Cache purged successfully."
  }
}
```

**Errors**:
- `500 Internal Server Error` if Redis flush fails.

**Notes**:
- Flushes the entire Redis database used by WatchState.

---

#### DELETE /v1/api/system/reset
Resets all user databases, clears sync timestamps, and flushes Redis.

**Response**:
```json
{
  "message": "System reset is complete."
}
```

**Notes**:
- One of the most destructive routes in the API. Resets every user database, clears sync timestamps, and flushes Redis.

---

#### POST /v1/api/system/reset/opcache
Resets PHP OPCache.

**Response**:
```json
{
  "message": "OPCache reset is complete."
}
```

**Notes**:
- Affects PHP opcode cache for the running environment.

---

#### GET /v1/api/system/integrity
Finds history items whose media paths or parent directories no longer exist.

**Query**:
- `limit` (optional) - Maximum number of broken items to return. Defaults to `1000`.

**Response**:
```json
{
  "items": [
    {
      "id": 101,
      "title": "Movie Title",
      "integrity": [
        {
          "backend": "plex_main",
          "path": "/media/missing/file.mkv",
          "status": false,
          "message": "File does not exist."
        }
      ]
    }
  ],
  "total": 1,
  "fromCache": false
}
```

**Errors**:
- `404 Not Found` if the user does not exist.

**Notes**:
- Directory and file existence checks are cached for 1 hour.

---

#### DELETE /v1/api/system/integrity
Clears the cached integrity scan state for the current user.

**Response**:
- `200 OK` with an empty body.

**Errors**:
- `404 Not Found` if the user does not exist.

---

#### GET /v1/api/system/parity
Returns records that are missing metadata on some configured backends.

**Query**:
- `page` (optional) - Defaults to `1`.
- `perpage` (optional) - Defaults to `1000`.
- `min` (optional) - Minimum number of backend metadata entries required. `0` means all configured backends.

**Response**:
```json
{
  "paging": {
    "total": 12,
    "perpage": 1000,
    "current_page": 1,
    "first_page": 1,
    "next_page": null,
    "prev_page": null,
    "last_page": 1,
    "params": {
      "min": 3
    }
  },
  "items": [
    {
      "id": 101,
      "title": "Movie Title"
    }
  ]
}
```

**Errors**:
- `400 Bad Request` if `min` is greater than the number of backends.
- `404 Not Found` if the user does not exist or the requested page is out of range.

---

#### DELETE /v1/api/system/parity
Deletes records that fall below a required metadata parity threshold.

**Input**:
- `min` is required in the parsed request data.

**Response**:
```json
{
  "deleted_records": 12
}
```

**Errors**:
- `400 Bad Request` if `min` is zero, invalid, or larger than the number of backends.
- `404 Not Found` if the user does not exist.

---

#### GET /v1/api/system/duplicate
Finds duplicate local records that point at the same media path.

**Query**:
- `page` (optional) - Defaults to `1`.
- `perpage` (optional) - Defaults to `50`.
- `no_cache` (optional) - Rebuild the duplicate cache instead of using the 30 minute cached result.

**Response**:
```json
{
  "paging": {
    "total": 3,
    "perpage": 50,
    "current_page": 1,
    "first_page": 1,
    "next_page": null,
    "prev_page": null,
    "last_page": 1,
    "params": []
  },
  "items": [
    {
      "id": 101,
      "title": "Movie Title",
      "duplicate_reference_ids": [102]
    }
  ]
}
```

**Errors**:
- `400 Bad Request` if the page number is invalid.
- `404 Not Found` if the user does not exist.

---

#### DELETE /v1/api/system/duplicate
Deletes the duplicate records found in the cached duplicate scan.

**Response**:
```json
{
  "deleted_records": 2
}
```

**Errors**:
- `404 Not Found` if the duplicate cache has expired or if no duplicates are cached.

**Notes**:
- This route deletes every cached duplicate record ID, so it is destructive and cache-dependent.

---

#### GET /v1/api/system/suppressor
Lists log suppressor rules.

**Response**:
```json
{
  "items": [
    {
      "id": "S1234567890",
      "type": "contains",
      "rule": "healthcheck",
      "example": "GET /healthcheck"
    }
  ],
  "types": ["regex", "contains"]
}
```

---

#### POST /v1/api/system/suppressor
Adds a log suppressor rule.

**Body**:
```json
{
  "rule": "healthcheck",
  "type": "contains",
  "example": "GET /healthcheck"
}
```

**Response**:
```json
{
  "id": "S1234567890",
  "type": "contains",
  "rule": "healthcheck",
  "example": "GET /healthcheck"
}
```

**Errors**:
- `400 Bad Request` if required fields are missing, the type is invalid, the regex is invalid, the example does not match, or another rule already suppresses the example.

---

#### GET /v1/api/system/suppressor/{id}
Returns a suppressor rule.

**Path**:
- `id`: 11 character suppressor rule ID.

**Response**:
```json
{
  "id": "S1234567890",
  "type": "contains",
  "rule": "healthcheck",
  "example": "GET /healthcheck"
}
```

**Errors**:
- `400 Bad Request` if the ID is invalid.
- `404 Not Found` if the rule does not exist.

---

#### PUT /v1/api/system/suppressor/{id}
Replaces one suppressor rule.

**Path**:
- `id`: 11 character suppressor rule ID.

**Body**:
```json
{
  "rule": "new-regex",
  "type": "regex",
  "example": "Some log line"
}
```

**Response**:
- Same payload shape as create/view.

**Errors**:
- Same validation errors as `POST /v1/api/system/suppressor`.

---

#### DELETE /v1/api/system/suppressor/{id}
Deletes one suppressor rule.

**Path**:
- `id`: 11 character suppressor rule ID.

**Response**:
```json
{
  "id": "S1234567890",
  "type": "contains",
  "rule": "healthcheck",
  "example": "GET /healthcheck"
}
```

**Errors**:
- `400 Bad Request` if the ID is invalid.
- `404 Not Found` if the rule does not exist.

---

### Tasks

#### GET /v1/api/tasks
Lists scheduled tasks and shows which ones are queued.

**Response**:
```json
{
  "tasks": [
    {
      "name": "index",
      "description": "Rebuild indexes",
      "enabled": true,
      "timer": "*/30 * * * *",
      "next_run": "2026-03-28T12:30:00+00:00",
      "prev_run": "2026-03-28T12:00:00+00:00",
      "command": "db:index",
      "args": [],
      "hide": false,
      "allow_disable": true,
      "queued": false
    }
  ],
  "queued": []
}
```

**Notes**:
- Hidden tasks are omitted from the list.

---

#### GET /v1/api/tasks/{id}
Returns a task definition.

**Path**:
- `id`: Task name.

**Response**:
```json
{
  "name": "index",
  "description": "Rebuild indexes",
  "enabled": true,
  "timer": "*/30 * * * *",
  "next_run": "2026-03-28T12:30:00+00:00",
  "prev_run": "2026-03-28T12:00:00+00:00",
  "command": "db:index",
  "args": [],
  "hide": false,
  "allow_disable": true,
  "queued": false
}
```

**Errors**:
- `404 Not Found` if the task is unknown.

---

#### GET|POST|DELETE /v1/api/tasks/{id}/queue
Gets queue state, queues a run, or cancels a queued run.

**Path**:
- `id`: Task name.

**GET Response**:
```json
{
  "task": "index",
  "is_queued": false
}
```

**POST Response**:
```json
{
  "id": "uuid",
  "event": "system:task",
  "status": 0,
  "...": "queued event payload"
}
```

**DELETE Response**:
- `200 OK` with an empty body.

**Errors**:
- `404 Not Found` if the task does not exist or, for `DELETE`, if it is not queued.
- `409 Conflict` if the task is already queued.
- `400 Bad Request` if you try to remove a running task.

**Notes**:
- `POST` returns `202 Accepted`.

---

### Identities

#### GET /v1/api/identities
Lists configured WatchState identities and each identity's backend names.

**Response**:
```json
{
  "identities": [
    {
      "identity": "main",
      "backends": ["plex_main", "jellyfin_main"]
    }
  ]
}
```

---

#### POST /v1/api/identities
Creates a new WatchState identity configuration set.

**Body**:
```json
{
  "identity": "family"
}
```

**Response**:
- `201 Created` with an empty body.

**Errors**:
- `400 Bad Request` if `identity` is missing or invalid.
- `409 Conflict` if the identity already exists.
- `500 Internal Server Error` if creation fails.

**Notes**:
- Identity names are normalized to lowercase.

---

#### DELETE /v1/api/identities/{identity}
Deletes one WatchState identity configuration set.

**Path**:
- `identity`: Identity name.

**Response**:
- `200 OK` with an empty body.

**Errors**:
- `400 Bad Request` if the identity name is missing or invalid.
- `403 Forbidden` if the identity is `main`.
- `404 Not Found` if the identity does not exist.
- `500 Internal Server Error` if deletion fails.

---

#### GET /v1/api/identities/{identity}
Returns the full backend config object for one identity.

**Path**:
- `identity`: Identity name.

**Response**:
```json
{
  "plex_main": {
    "type": "plex",
    "url": "https://plex.example.com",
    "token": "..."
  }
}
```

**Errors**:
- `400 Bad Request` if the identity name is missing or invalid.
- `404 Not Found` if the identity does not exist.

---

#### PUT /v1/api/identities/{identity}
Replaces the full backend config object for one identity.

**Path**:
- `identity`: Identity name.

**Body**:
- JSON object or YAML document describing the entire backend config file.

**JSON Example**:
```json
{
  "plex_main": {
    "type": "plex",
    "url": "https://plex.example.com",
    "token": "..."
  }
}
```

**Response**:
```json
{
  "plex_main": {
    "type": "plex",
    "url": "https://plex.example.com",
    "token": "..."
  }
}
```

**Errors**:
- `400 Bad Request` if the identity name is invalid, the body cannot be parsed, the body is not an object, or validation fails.
- `404 Not Found` if the identity does not exist.

**Notes**:
- Accepts JSON and YAML request bodies.
- Requests with `application/json` are parsed as JSON; other request bodies are parsed as YAML.
- Validation errors include an `errors` array in the response body.

---

#### GET /v1/api/identities/provision
Returns the current identity provisioning preview built from live backend member discovery and the saved mapper file.

**Query**:
- `force` (optional) - Bypass the 5 minute cache and rebuild the preview.

**Response**:
```json
{
  "has_identities": true,
  "has_mapping": true,
  "backends": ["plex_main", "jellyfin_main"],
  "matched": [
    {
      "identity": "alice",
      "members": [
        {
          "id": "123",
          "username": "alice",
          "backend": "plex_main",
          "real_name": "Alice",
          "type": "plex",
          "protected": false,
          "options": {}
        }
      ]
    }
  ],
  "unmatched": [
    {
      "id": "789",
      "username": "bob",
      "backend": "plex_main",
      "real_name": "Bob",
      "type": "plex",
      "protected": true,
      "options": {}
    }
  ],
  "expires": "2026-03-28T12:05:00+00:00"
}
```

**Notes**:
- `has_identities` means generated identity YAML files already exist under `users/*/*.yaml`.
- `has_mapping` means a non-empty mapper file was loaded.
- Matching can still happen with `has_mapping=false` through direct normalized username matching.
- `username` is the normalized internal name. `real_name` is the original backend-reported name.

---

#### PUT /v1/api/identities/provision/mapping
Creates or replaces the saved cross-backend identity mapping file.

**Body**:
```json
{
  "version": "1.6",
  "identities": [
    {
      "identity": "alice",
      "members": [
        {
          "backend": "plex_main",
          "username": "alice",
          "options": {}
        },
        {
          "backend": "jellyfin_main",
          "username": "alice",
          "options": {}
        }
      ]
    }
  ]
}
```

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Identity mapping successfully updated."
  },
  "version": "1.6",
  "identities": [
    {
      "identity": "alice",
      "members": [
        {
          "backend": "plex_main",
          "username": "alice",
          "options": {}
        }
      ]
    }
  ]
}
```

**Errors**:
- `400 Bad Request` if `identities` is missing, empty, not an array, or if `version` is lower than `1.5`.

---

#### POST /v1/api/identities/provision
Creates, updates, or recreates identities directly through the API.

**Body**:
```json
{
  "mode": "update",
  "dry_run": false,
  "generate_backup": true,
  "regenerate_tokens": false,
  "allow_single_backend_identities": false,
  "save_mapping": true,
  "mapping": {
    "version": "1.6",
    "identities": [
      {
        "identity": "alice",
        "members": [
          {
            "backend": "plex_main",
            "username": "alice",
            "options": {}
          },
          {
            "backend": "jellyfin_main",
            "username": "alice",
            "options": {}
          }
        ]
      }
    ]
  }
}
```

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Identities updated successfully."
  },
  "mode": "update",
  "dry_run": false,
  "save_mapping": true,
  "allow_single_backend_identities": false,
  "count": 1,
  "identities": [
    {
      "identity": "alice",
      "backends": ["plex_main", "jellyfin_main"],
      "members": [
        {
          "backend": "plex_main",
          "username": "alice"
        },
        {
          "backend": "jellyfin_main",
          "username": "alice"
        }
      ]
    }
  ]
}
```

**Errors**:
- `400 Bad Request` if `mode` is invalid or provisioning cannot produce identities.
- `409 Conflict` if `mode=create` is requested while local identities already exist.
- `500 Internal Server Error` if provisioning fails unexpectedly.

**Notes**:
- `mode` accepts `create`, `update`, or `recreate`.
- `save_mapping=true` persists the provided mapping before provisioning.
- `allow_single_backend_identities=true` requires exactly one configured backend and allows one-member identity groups.

---

#### POST /v1/api/identities/provision/sync-backends
Safely syncs already-linked identity backends from the current main backend configuration.

This route does not create, delete, or rematch identities. It only updates existing linked backend configs.

**Body**:
```json
{
  "dry_run": false
}
```

**Response**:
```json
{
  "info": {
    "code": 200,
    "message": "Synced 4 identity backend(s) successfully."
  },
  "dry_run": false,
  "updated_count": 4,
  "skipped_count": 2,
  "failed_count": 0,
  "updated": [
    {
      "identity": "alice",
      "backend": "plex_alice",
      "source_backend": "plex_main"
    }
  ],
  "skipped": [
    {
      "identity": "manual_profile",
      "backend": "custom_backend",
      "reason": "Backend is not linked to a source backend."
    }
  ],
  "failed": []
}
```

**Notes**:
- This is the safe maintenance path for propagating changes like backend URL, shared tokens, UUID, import/export settings, and shared backend options.
- Per-identity values such as backend user IDs, Plex child tokens, Plex user UUID/name, and protected user PINs are preserved.
- `dry_run=true` reports what would change without writing any identity config files.

---

### Webhook

#### POST|PUT /v1/api/webhook
Receives backend webhook payloads, matches them to configured users/backends, and queues import processing.

**Access**:
- Open when `WS_SECURE_API_ENDPOINTS=false`.
- Otherwise standard API auth.

**Input**:
- Backend-specific headers and payload.
- The body shape depends on the backend sending the webhook.

**Response**:
- `200 OK` when the webhook was parsed and queued successfully.
- `304 Not Modified` when the payload is intentionally ignored.
- `406 Not Acceptable` when import is disabled for the target backend.

**Errors**:
- `400 Bad Request` if no backend parser can recognize the payload.
- `400 Bad Request` if the payload lacks the backend unique ID.
- `400 Bad Request` if a non-generic payload lacks the backend user ID.
- `400 Bad Request` if the payload does not map to any configured user/backend pair.

**Notes**:
- The endpoint fans out across all matching user/backend pairs for generic webhook payloads.
- Unsupported or unusable items are ignored with `304 Not Modified`, for example:
  - items without supported GUIDs
  - episode events without season/episode numbers
  - generic requests that the backend parser cannot fully resolve

---

## Error Responses

Most endpoints return standard error codes (`400`, `401`, `403`, `404`, `409`, `500`, etc.) and a JSON envelope on failure. For example:

```json
{
  "error": {
    "code": 400,
    "message": "Description of the problem"
  }
}
```

Informational success responses use the same structure under `info`:

```json
{
  "info": {
    "code": 200,
    "message": "Human readable message"
  }
}
```

Some endpoints return a bare array, `204 No Content`, SSE, HLS text, or binary file content instead of a JSON envelope.

---
