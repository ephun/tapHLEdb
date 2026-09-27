tapHLEdb API
============

Compatibility model v2 (authoritative)
--------------------------------------

The v2 write endpoints accept exact cumulative `compatibility_state` values,
not an invented numeric rating: `?????`, `*XXXX`, `*????`, `**XXX`, `**???`,
`***XX`, `***??`, `****X`, `****?`, `*****`.

They render as the ten specified ⭐/❓/❌ positions. `rating` in read responses
is only the derived count of leading stars for backward-compatible consumers.
The public summary is the latest approved `normal_release` compatibility run;
development and release-verification reports never overwrite it.

`POST /api/report` requires App/Version provenance (including the Version's
exact app SHA-256) and Report provenance: `source_class`, optional automated
`source_subtype`, source name, host/platform/OS/architecture, tapHLE
commit/artifact/build/release channel, app hash, verification type, test run ID,
RFC 3339 UTC timestamp, result, evidence description, visual-output flag, and
frontier/evidence as applicable. The server binds `source_identity` to the
authenticated credential. The three source classes are `human`, `agent`, and
`automated`; the token API cannot claim `human`. Automated subtype is exactly
`script/test_harness` or `telemetry`.

Agent reports require a screenshot and can establish at most three stars.
Controlled scripts require a screenshot when meaningful visual output exists
and can establish only deterministic thresholds through three. Telemetry needs
explicit `telemetry_consent=yes`, can establish execution only, and never infers
playability from duration. Crashes require `crash_evidence`. Release
verification additionally requires a normal release-profile candidate whose
`taphle_release` equals `release_version`.

`POST /api/catalog` accepts the same authenticated App/Version objects without
creating a Report. A new App requires an extracted PNG/JPEG data-URL `icon`.
Apps match case-insensitively by bundle ID; Versions match by bundle version and
exact app artifact hash. Trusted credentials can correct canonical metadata and
icon and approve only their own hierarchy. Ordinary credentials remain pending.
This is how reviewed known-but-untested entries are created; they read as
`?????` / ❓❓❓❓❓.

`GET /api/apps` adds `compatibility_state` and `states_by_platform`; its legacy
numeric fields are derived. `GET /api/release-verifications` adds a
`qualification` object and is qualified only when all configured required
platforms reference one immutable commit/build candidate.

`POST /api/note` accepts `body` and exactly one of `app_id`, `version_id`, or
`report_id`. Notes never contain a compatibility state. Ordinary credentials
create moderated notes; trusted credentials approve only their own note.

The tapHLE deployment is mounted at `/compatibility`:

```
GET  https://taphle.ephun.net/compatibility/api/apps
POST https://taphle.ephun.net/compatibility/api/report
GET  https://taphle.ephun.net/compatibility/api/release-verifications?release=0.2.4&commit=<40-hex-commit>
```

Reads need no credential. Submission uses one configured bearer credential.

`GET /api/apps`
---------------

Returns approved apps and their latest approved normal-release compatibility
state. Release verification and development results do not affect the summary.
Use `compatibility_state` and `states_by_platform`; numeric rating fields are
derived compatibility output only.

```json
{
  "apps": [{
    "app_id": 4,
    "name": "Example",
    "rating": 3,
    "compatibility_state": "***??",
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
are matched by bundle identifier; Versions match by `CFBundleVersion` and exact
app artifact SHA-256. The whole operation is one transaction.

```json
{
  "app": {
    "name": "Example",
    "icon": "data:image/png;base64,...",
    "extra": {"bundle_identifier": "com.example.app"}
  },
  "version": {
    "name": "1.0",
    "extra": {
      "bundle_version": "1.0",
      "short_version": "1.0",
      "minimum_os_version": "2.0",
      "app_artifact_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
    }
  },
  "report": {
    "compatibility_state": "***??",
    "extra": {
      "source_class": "agent",
      "source_identity": "agent:taphle-lead",
      "source_name": "tapHLE Lead",
      "platform": "Windows",
      "architecture": "x86_64",
      "os_version": "11 24H2",
      "taphle_commit": "0123456789abcdef0123456789abcdef01234567",
      "artifact_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "app_artifact_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
      "build_provenance": "clean checkout; rust 1.97.1; release workflow run 123",
      "build_profile": "release",
      "taphle_release": "0.2.4",
      "release_channel": "normal_release",
      "verification_type": "compatibility",
      "test_run_id": "workflow-123/example/windows",
      "tested_at": "2026-09-26T12:00:00Z",
      "result": "completed",
      "evidence_description": "Visible primary activity remained interactive.",
      "visual_output": "meaningful",
      "frontier": "gameplay loop starts and persists"
    },
    "screenshot": "data:image/jpeg;base64,..."
  }
}
```

Required report provenance:

* `source_class`: the token API accepts `agent` or `automated`, never `human`;
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
reports table. The source/evidence caps in the authoritative section apply.

Every extra-field value is a JSON string. Unknown fields, short commits, malformed
hashes, unsupported platforms and invalid option values are rejected.

Screenshot
----------

`screenshot` is optional only where source policy permits. When present it is the same JPEG data URL accepted by
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
    "source_class": "agent",
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

Authenticated browser prefill
-----------------------------

`GET /compatibility/reports/new` accepts the versioned, bracket-encoded query
contract `prefill[v]=1`. This is the browser handoff for tapHLE. Each value must
be URL-encoded normally (for example with an application/x-www-form-urlencoded
builder); parameter names below are exact.

| Namespace | Accepted keys |
|---|---|
| `prefill[app]` | `bundle_identifier`, `display_name` |
| `prefill[version]` | `bundle_version`, `short_version`, `minimum_os_version`, `app_artifact_sha256` |
| `prefill[report]` | `source_name`, `platform`, `architecture`, `os_version`, `taphle_commit`, `artifact_sha256`, `app_artifact_sha256`, `build_provenance`, `build_profile`, `taphle_release`, `release_channel`, `verification_type`, `release_version`, `test_run_id`, `tested_at`, `result`, `evidence_description`, `crash_evidence`, `visual_output`, `duration_seconds`, `execution_states`, `termination`, `logs`, `cpu`, `gpu`, `device`, `frontier` |

The version's `app_artifact_sha256` is copied into the report draft, so clients
normally send it only under `prefill[version]`. If both copies are sent they
must match. `short_version`, falling back to `bundle_version`, supplies the
editable version label for a new Version.

Example (shown across lines for readability; an actual URL has one query
string):

```text
https://taphle.ephun.net/compatibility/reports/new?
prefill%5Bv%5D=1&
prefill%5Bapp%5D%5Bbundle_identifier%5D=com.example.game&
prefill%5Bapp%5D%5Bdisplay_name%5D=Example&
prefill%5Bversion%5D%5Bbundle_version%5D=42&
prefill%5Bversion%5D%5Bshort_version%5D=1.2&
prefill%5Bversion%5D%5Bminimum_os_version%5D=2.0&
prefill%5Bversion%5D%5Bapp_artifact_sha256%5D=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb&
prefill%5Breport%5D%5Bplatform%5D=Windows&
prefill%5Breport%5D%5Bos_version%5D=11%2024H2&
prefill%5Breport%5D%5Barchitecture%5D=x86_64&
prefill%5Breport%5D%5Btaphle_commit%5D=0123456789abcdef0123456789abcdef01234567&
prefill%5Breport%5D%5Bartifact_sha256%5D=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa&
prefill%5Breport%5D%5Bbuild_profile%5D=release&
prefill%5Breport%5D%5Brelease_channel%5D=normal_release&
prefill%5Breport%5D%5Bverification_type%5D=compatibility&
prefill%5Breport%5D%5Btested_at%5D=2026-09-26T12%3A00%3A00Z
```

The legacy `app=<positive-id>` and `version=<positive-id>` conveniences remain
available. When combined with prefill, the IDs must agree with the canonical
bundle identifier and with the Version's exact CFBundleVersion/app SHA-256.
Without IDs, the server matches App case-insensitively by bundle identifier and
Version by exact CFBundleVersion plus app SHA-256. A match selects the existing
canonical record; draft display metadata never overwrites it. An unknown
identity leaves editable new-App/new-Version fields, and an empty catalog still
renders the ordinary contribution form.

All prefill is untrusted draft input. It never submits by GET, creates a row,
selects a compatibility state, approves content, or changes trust/moderation.
The user must be signed in, may edit new-record and report fields, must choose a
compatibility state, and must pass the normal POST validation and moderation
flow. If sign-in is needed, a short-lived signed OAuth state returns the user to
the same prefilled form; external return URLs are not allowed. Unknown fields,
arrays where scalars are required, oversized values,
malformed hashes/commits/timestamps, unsupported options, mismatched canonical
IDs, and inconsistent release-verification combinations are rejected.

The contract intentionally does **not** accept an icon: data URLs are too large
and sensitive for browser URLs, so a new App's icon remains a user-selected
upload. It also does not accept `compatibility_state`, numeric `rating`,
`source_class`, `source_subtype`, `source_identity`, telemetry consent, API/OAuth
credentials, approval/trust flags, or moderator fields. Browser reports are
always rebound server-side to source class `human` and the authenticated GitHub
identity. Never put a secret in this URL; URLs may be retained in browser,
proxy, and server logs.
