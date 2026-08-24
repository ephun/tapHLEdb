tapHLEdb API
============

The tapHLE deployment is mounted at `/compatibility`:

```
GET  https://taphle.ephun.net/compatibility/api/apps
POST https://taphle.ephun.net/compatibility/api/report
GET  https://taphle.ephun.net/compatibility/api/release-verifications?release=0.2.4&commit=<40-hex-commit>
```

Reads need no credential. Submission uses one configured bearer credential.

`GET /api/apps`
---------------

Returns approved apps and approved compatibility ratings only. Release
reconfirmations never affect either summary. `rating` remains the best
compatibility rating across all hosts for backward compatibility.
`ratings_by_platform` is the host-qualified view new clients should use. Reports created before platform was
stored are classified as Windows because production policy allowed Windows
reports only at that time.

```json
{
  "apps": [{
    "app_id": 4,
    "name": "Example",
    "rating": 3,
    "ratings_by_platform": {"Linux": 2, "Windows": 3},
    "extra": {"bundle_identifier": "com.example.app"},
    "url": "/compatibility/apps/4"
  }],
  "count": 1
}
```

`POST /api/report`
------------------

Send `Content-Type: application/json` and either:

```
Authorization: Bearer <credential>
```

or `X-Api-Key: <credential>` when a proxy does not forward Authorization.

Credentials are configured independently. A legacy token-to-identity string is
accepted and always lands pending moderation. A structured credential may carry
`trusted => TRUE`; only an Ethan-controlled credential may do that. Trust applies
to that exact secret, not globally. A trusted credential atomically approves the
report and any app/version rows the same request created. It does not raise the
agent rating cap.

```php
const API_TOKENS = [
    'ordinary-secret' => 'agent:external-worker',
    'operator-secret' => [
        'identity' => 'agent:taphle-lead',
        'trusted' => TRUE,
    ],
];
```

Request
-------

Use `app_id` or an `app` object, and `version_id` or a `version` object. New apps
are matched by bundle identifier; new versions are matched by name within the
app. The whole operation is one transaction.

```json
{
  "app": {
    "name": "Example",
    "extra": {"bundle_identifier": "com.example.app"}
  },
  "version": {
    "name": "1.0",
    "extra": {
      "bundle_version": "1.0",
      "short_version": "1.0",
      "minimum_os_version": "2.0"
    }
  },
  "report": {
    "rating": 3,
    "extra": {
      "source_type": "agent",
      "source_name": "tapHLE Lead",
      "platform": "Windows",
      "architecture": "x86_64",
      "os_version": "11 24H2",
      "taphle_commit": "0123456789abcdef0123456789abcdef01234567",
      "artifact_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "app_artifact_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
      "build_provenance": "clean checkout; rust 1.97.1; release workflow run 123",
      "build_profile": "release",
      "verification_type": "compatibility",
      "frontier": "gameplay loop starts and persists"
    },
    "screenshot": "data:image/jpeg;base64,..."
  }
}
```

Required report provenance:

* `source_type`: the token API accepts `agent` or `telemetry`, never `human`;
* `source_name`;
* `platform`: `Windows`, `Linux`, `macOS`, `Android`, or `iOS`;
* `architecture` and `os_version`;
* `taphle_commit`: full lowercase 40-hex commit;
* `artifact_sha256`: tested binary/package SHA-256;
* `app_artifact_sha256`: tested app file SHA-256;
* `build_provenance` and `build_profile` (`debug` or `release`);
* `verification_type`: `compatibility` or `release_verification`.

`release_verification` additionally requires a plain `release_version` such as
`0.2.4`. A compatibility report must omit it. Release reconfirmations remain
separate from rating-changing history even though both use the append-only
reports table. Agents and telemetry are capped at three stars.

Every extra-field value is a JSON string. Unknown fields, short commits, malformed
hashes, unsupported platforms and invalid option values are rejected.

Screenshot
----------

`screenshot` is optional. When present it is the same JPEG data URL accepted by
the web form and is limited to roughly 150 KB after decoding. Capture the visible
tapHLE/app window or a tight relevant crop and inspect it for sensitive material
before submission. Omit it when no safe image proves the result.

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

`status` is `approved` for a trusted credential and `pending_moderation` for an
ordinary one.

Errors
------

| Status | Error | Meaning |
|---|---|---|
| 400 | `bad_json` | Body is not a JSON object |
| 400 | `invalid_submission` | Validation failed; `detail` describes caller input |
| 401 | `unauthorized` | Credential absent or unknown |
| 405 | `method_not_allowed` | Wrong HTTP method |
| 413 | `payload_too_large` | Body exceeded 1 MB |
| 429 | `too_many_pending` | Ordinary identity has too many pending reports |
| 500 | `internal_error` | Storage failed; no schema detail is exposed |

Nothing is written unless the whole submission succeeds.

`GET /api/release-verifications`
--------------------------------

Requires exact `release=X.Y.Z` and full lowercase 40-hex `commit` query values.
Returns approved `release_verification` records whose release and commit both
match. Unapproved rows and ordinary compatibility reports are excluded.

```json
{
  "release": "0.2.4",
  "commit": "0123456789abcdef0123456789abcdef01234567",
  "verifications": [{
    "report_id": 90,
    "rating": 3,
    "app_id": 4,
    "app_name": "Example",
    "bundle_identifier": "com.example.app",
    "version_id": 7,
    "version_name": "1.0",
    "bundle_version": "1.0",
    "submitter_identity": "agent:taphle-lead",
    "source_type": "agent",
    "source_name": "tapHLE Lead",
    "platform": "Linux",
    "architecture": "x86_64",
    "os_version": "Ubuntu 26.04",
    "taphle_commit": "0123456789abcdef0123456789abcdef01234567",
    "artifact_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "app_artifact_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
    "build_provenance": "clean checkout; release workflow run 123",
    "build_profile": "release",
    "frontier": "gameplay loop starts and persists",
    "has_screenshot": false
  }],
  "count": 1
}
```

The release-readiness gate compares this read-back with the frozen cohort. A
record on one host never qualifies another host.

Operational security
--------------------

* Keep each secret out of URLs, logs and version control. Revoke it by removing
  its `API_TOKENS` entry.
* A trusted credential is a publish credential. Keep it on an operator-controlled
  machine only; a leak can publish false ratings immediately.
* `API_MAX_PENDING_REPORTS` bounds moderation noise from ordinary credentials.
* A config with no `API_TOKENS` returns 401 and leaves submission disabled.
* The endpoint never reads or sets a session cookie.
