tapHLEdb API
============

Two endpoints: `GET /api/apps` reads the public list, `POST /api/report` submits
a result. Reading needs no credential; submitting needs a token. Both are tapHLE
additions and are not present upstream in
[app-compatibility-db](https://github.com/hikari-no-yume/app-compatibility-db).

Both move with the mount point. When `SITE_BASE_PATH` is set in `config.php` the
endpoints sit under it; the tapHLE deployment uses `/compatibility`, so the real
URLs are:

```
GET  https://taphle.ephun.net/compatibility/api/apps
POST https://taphle.ephun.net/compatibility/api/report
```

Paths below are written app-relative; prepend `SITE_BASE_PATH` to each.

`GET /api/apps`
---------------

The approved app list as JSON, for agents choosing what to work on. No
authentication — it returns strictly what the public web page already shows.

```json
{
  "apps": [
    {
      "app_id": 3,
      "name": "Baby Monkey (going backwards on a pig)",
      "rating": 2,
      "extra": {"bundle_identifier": "com.kihon.babymonkey", "release_year": "2011"},
      "url": "/compatibility/apps/3"
    }
  ],
  "count": 1
}
```

`rating` is the best **approved** rating across the app's approved versions, or
`null` when it has no approved report yet. Unapproved apps, versions and reports
are never returned, nor are individual reports or submitter identities.

Two questions this is meant to answer:

* **Which apps are worst off?** Sort by `rating`; `1` and `null` need the most
  help.
* **Which apps are unclaimed?** An app here with no `compat/<slug>` branch in
  the tapHLE repository is work nobody has started.

It deliberately does not carry the frontier — where an app currently stops lives
in `dev-docs/app-notes/<app>.md`, which is version-controlled alongside the code
that moves it. Duplicating it here would create two copies that drift.

`POST /api/report`
------------------

`POST /api/report` submits one compatibility report without the interactive
GitHub sign-in, so that tapHLE telemetry and coding agents can report results
programmatically. It reuses the same model and validation as the web form, and
**every submission lands unapproved, pending moderator review** — the API is a
convenience, not a way to bypass moderation.

Authentication
--------------

Send a token from `API_TOKENS` in `config.php`:

```
Authorization: Bearer <token>
```

`X-Api-Key: <token>` is accepted as a fallback, because nginx/PHP-FPM does not
always forward the `Authorization` header. If you use the `Authorization` form
behind nginx, make sure the header is passed through, e.g.:

```
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

Each token maps to an external identity in `service:name` form (for example
`telemetry:taphle` or `agent:claude-code`). Use one token per source so a single
source can be revoked without disturbing the others. Tokens are secrets: keep
them out of URLs, logs, and version control.

Request
-------

`Content-Type: application/json`, body up to 1 MB.

```jsonc
{
  // Either "app_id" (an existing app) or "app" (create/match one).
  "app": {
    "name": "Baby Monkey (going backwards on a pig)",
    "extra": {
      "bundle_identifier": "com.kihon.babymonkey",   // required (identity)
      "developer_publisher": "Kihon",
      "release_year": "2011"
    }
  },

  // Either "version_id" (an existing version) or "version".
  "version": {
    "name": "1.01",
    "extra": {
      "bundle_version": "1.01",                       // required
      "short_version": "1.01",
      "minimum_os_version": "4.2"
    }
  },

  "report": {
    "rating": 2,                                      // integer 1-5
    "extra": {
      "source_type": "telemetry",                     // required: human|agent|telemetry
      "source_name": "auto-uptime",
      "taphle_version": "f0947bc4",                   // required
      "cpu": "Intel Core i7-13700H",
      "gpu": "Intel Iris Xe Graphics",
      "frontier": "onTouchesBegan: sent to _tapHLE_NSArray"
    },
    // Optional, same limits as the web form: JPEG data URL, <= ~200 KB.
    "screenshot": "data:image/jpeg;base64,..."
  }
}
```

Notes on the fields:

* Every value inside an `extra` object must be a **JSON string** — numbers and
  nested objects are rejected. (`"release_year": "2011"`, not `2011`.)
* Which `extra` keys are allowed, and which are required, is entirely defined by
  `APP_EXTRA_FIELDS` / `VERSION_EXTRA_FIELDS` / `REPORT_EXTRA_FIELDS` in
  `config.php`. An unknown key is rejected.
* Fields declared with `options` (such as `source_type`) must use one of the
  configured option keys.
* `rating` must be a JSON integer from 1 to 5. Coding agents may report up to 3;
  4 and 5 require human testing.

Duplicate handling
------------------

Telemetry submits repeatedly, so the endpoint attaches to existing rows instead
of piling up duplicates:

1. If `app_id` is given it is used directly.
2. Otherwise, if an app already exists whose `APP_IDENTITY_FIELD` (by default
   `bundle_identifier`) matches the one submitted, that app is reused.
3. Otherwise a new, unapproved app is created.

Versions follow the same pattern, matched by `name` within the app. Matching
also finds rows that are still awaiting moderation, so a burst of telemetry
about a new app produces one app, one version, and N reports — not N of each.

The report itself is always created; there is no report de-duplication.

Response
--------

`201 Created`:

```json
{
  "status": "pending_moderation",
  "app_id": 12,
  "app_created": false,
  "version_id": 34,
  "version_created": true,
  "report_id": 56
}
```

Errors are JSON with an `error` code and usually a `detail`:

| Status | `error` | Meaning |
|---|---|---|
| 400 | `bad_json` | Body was not a JSON object |
| 400 | `invalid_submission` | Validation failed; see `detail` |
| 401 | `unauthorized` | Missing or unrecognised token |
| 405 | `method_not_allowed` | Use `POST` |
| 413 | `payload_too_large` | Body exceeded 1 MB |
| 429 | `too_many_pending` | This token has `API_MAX_PENDING_REPORTS` reports awaiting moderation |
| 500 | `internal_error` | The submission could not be stored (e.g. a database error) |

Nothing is written unless the whole submission succeeds: the app, version and
report are created in one transaction that is rolled back on any error.

`detail` only ever describes what the caller got wrong. A database failure is
always a flat `500 internal_error`, never a `400` — its message could carry
SQLSTATE codes and schema details, so it is never returned. Treat `500` as
retryable and `400` as a payload to fix; a `400` will never become valid on a
retry.

Example
-------

```sh
curl -sS -X POST https://taphle.ephun.net/compatibility/api/report \
  -H "Authorization: Bearer $TAPHLEDB_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
        "app":     {"name":"Ricky","extra":{"bundle_identifier":"com.nabilchatbi.Ricky"}},
        "version": {"name":"2.1","extra":{"bundle_version":"2.1"}},
        "report":  {"rating":3,"extra":{"source_type":"agent","source_name":"Claude Code","taphle_version":"e2d51c6c"}}
      }'
```

Operational notes
-----------------

* Submissions are unapproved, so a leaked token cannot publish anything — but it
  can create moderation noise. Revoke by deleting the entry from `API_TOKENS`.
* `API_MAX_PENDING_REPORTS` bounds that noise per token. Because the cap is
  checked before anything is written, it also bounds how many unapproved apps
  and versions a token can create: each accepted request adds at most one of
  each, and once the cap is reached no request is accepted at all.
* A `config.php` with no `API_TOKENS` at all (for example one written before this
  endpoint existed) simply answers `401` — the endpoint is off until it is
  configured.
* The endpoint never reads or sets a session cookie.
* Matching an existing app by identity uses SQLite's `json_extract`, from the
  JSON1 extension. It is compiled in by default in modern SQLite builds; if it
  is missing, matching degrades to "no match" (a new app is created) instead of
  failing the submission, so duplicates become a moderation problem rather than
  lost data.
* Deleting a bot's last remaining item removes its `users` row; the next
  submission recreates it. This is harmless.
