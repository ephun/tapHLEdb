tapHLEdb — the tapHLE app compatibility database
================================================

A small web app for the public [tapHLE](https://github.com/ephun/tapHLE) app
compatibility database: where each early iPhone OS app currently stands when run
under tapHLE on Windows. It is a live database (humans and coding agents submit
results, a moderator approves them), not a file in the emulator repository.

It tracks three kinds of items in a hierarchy:

* **Apps** — an application tested in tapHLE (identity from its `Info.plist`).
* **Versions** — a specific version of an app.
* **Reports** — a compatibility boundary or release reconfirmation for one exact
  app artifact, host, tapHLE commit and tested product hash, with a 1–5 star
  rating and frontier.

Ratings and every extra field are defined in `config.php` (see
`config.example.php`). Ratings follow tapHLE's scale; a coding agent may confirm
up to 3 stars, and 4–5 stars require human testing. The database holds structured
data only — narrative debugging notes live in the emulator repository under
`dev-docs/app-notes/`.

Provenance and licence
----------------------

This is a fork of **[app-compatibility-db](https://github.com/hikari-no-yume/app-compatibility-db)**
by hikari\_no\_yume, the web app that also powers touchHLE's
[app database](https://appdb.touchhle.org/).

Copyright © 2023 hikari\_no\_yume, and © 2026 the tapHLE contributors for
modifications. All the code here is licensed under
[MPL-2.0](https://spdx.org/licenses/MPL-2.0.html). All HTML and CSS that the app
can output is additionally licensed under
[CC0-1.0](https://spdx.org/licenses/CC0-1.0.html) so databases produced with it
can be freely archived and redistributed. Submitted database *content* is under
CC BY 4.0 (see `SITE_CONTENT_LICENSE_*` in the config).

tapHLE's own compatibility ratings are always produced by a tapHLE run; touchHLE
or HyperHLE results are testing leads only and are never imported as ratings.

What tapHLE changed from upstream
---------------------------------

* `config.example.php`: tapHLE branding, the 1–5 scale, platform/product/app
  provenance fields, verification type, optional screenshot policy, and exact
  per-credential trust for Ethan-controlled agents.
* A JSON API for platform-aware ratings, token-authenticated submission, and
  approved release-verification read-back. Ordinary credentials land pending;
  an explicitly trusted credential approves only its own transaction. See
  `API.md`.
* `printExternalUsername()` in `include/util.php` only links to GitHub for a
  `github:` identity. API identities such as `telemetry:taphle` have no profile
  page, so linking them would point at a GitHub account that does not exist.

Pull upstream fixes with the `upstream` remote:
`git fetch upstream && git log --oneline HEAD..upstream/main`.

Setting up
----------

Requires git, PHP 7.4/8, and the SQLite 3 CLI.

1. `cp config.example.php config.php`, then edit `config.php`: fill the GitHub
   OAuth keys and any API tokens (keep them secret — `config.php` is git-ignored).
2. Create the database: `sqlite3 app_db.sqlite3 '.read schema.sql'`
3. Serve locally: `cd htdocs && php -S localhost:8000`

Run the API/security regression test before deployment:

```sh
php tests/api_features.php
```

It uses an in-memory SQLite database and does not touch the configured site data.

Deployment
----------

Use the example nginx + PHP-FPM config. The app can serve from a domain root or
from a subpath — set `SITE_BASE_PATH` in `config.php` (the tapHLE deployment uses
`/compatibility`) and register the GitHub OAuth callback at the matching URL.

The SQLite database file **and the directory containing it** must be writeable
by the web server: SQLite creates journal files alongside the database, so a
writeable file in a read-only directory fails on every write.

Unlike upstream, `htdocs/privacy.html` is committed rather than git-ignored.
This is a single deployment, so version-controlling the policy keeps it
reviewable and means a deploy is just a pull. Upstream ignores it because each
operator needs their own; anyone forking this repo should replace it. It still
carries bracketed placeholders (contact, jurisdiction, retention periods) that
must be filled in before it is truthful.

Source layout
-------------

* [`schema.sql`](schema.sql): SQL schema (apps, versions, reports, screenshots, users)
* [`config.example.php`](config.example.php): configuration example/documentation
* [`API.md`](API.md): platform-aware reads, report submission, and release-verification read-back
* [`privacy.example.html`](privacy.example.html): example privacy policy
* [`nginx-config-example.conf`](nginx-config-example.conf): example nginx config
* [`htdocs/index.php`](htdocs/index.php): sole entry point and router
* [`templates/`](templates/): templates and view/controller code
* [`include/`](include/): utility functions and model code
