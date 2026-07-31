# Key-Value Store

A version-controlled key-value store with an HTTP API, built with Laravel. Every
write is kept as a new, immutable version, so the API can answer both "what is
this key's value right now" and "what was this key's value at some point in
the past".

## 💻 Interface Screenshots

![Frontend Page](http://urlutm.ru/screenshot/key-value-store-laravel.jpg)

## Contents

- [How it works](#how-it-works)
- [API](#api)
- [Getting started](#getting-started)
- [Testing](#testing)
- [CI/CD](#cicd)
- [Deployment](#deployment)
- [Design decisions](#design-decisions)
- [AI tool usage](#ai-tool-usage)

## How it works

Writes are append-only. A `POST` never updates a row — it inserts a new one
stamped with the current UTC unix timestamp. "Current value" and "value as of
timestamp T" are both just queries over that history:

- **Current value** = the most recently inserted row for the key.
- **Value as of T** = the most recently inserted row for the key with
  `recorded_at <= T`.

This lives in a single table, `kv_entries`: `id`, `key`, `value` (JSON),
`recorded_at` (unix timestamp), `created_at`/`updated_at`. It's indexed on
`(key, recorded_at, id)`, which covers both lookup types directly.

The storage logic sits behind a `KeyValueRepositoryInterface`
([app/Repositories/Contracts/KeyValueRepositoryInterface.php](app/Repositories/Contracts/KeyValueRepositoryInterface.php)),
implemented by
[`EloquentKeyValueRepository`](app/Repositories/EloquentKeyValueRepository.php)
and bound in
[`AppServiceProvider`](app/Providers/AppServiceProvider.php). The HTTP layer
([`ObjectController`](app/Http/Controllers/Api/ObjectController.php)) only
talks to the interface, so swapping the storage engine (e.g. for Redis or
MongoDB) later doesn't touch routing or validation.

## API

All endpoints are JSON in, JSON out, and live under `/api` (Laravel's default
API route prefix).

### `POST /api/object`

Stores a new version of a key. The request body is a single-property JSON
object: the property name is the key, its value is what gets stored (any
valid JSON type — string, number, bool, null, array or object).

```bash
curl -X POST /api/object \
  -H "Content-Type: application/json" \
  -d '{"mykey": "value1"}'
```

```json
{ "key": "mykey", "value": "value1", "timestamp": 1440569400 }
```

`201 Created` on success. `422` if the body isn't a single-property JSON
object, or the key is empty/too long.

### `GET /api/object/{key}`

Returns the latest value for `key`.

```bash
curl /api/object/mykey
```

```json
{ "key": "mykey", "value": "value2", "timestamp": 1440569700 }
```

`404` if the key has never been written.

### `GET /api/object/{key}?timestamp={unix}`

Returns the value that was current for `key` at `{unix}` (a UTC unix
timestamp) — i.e. the latest version written at or before that time.

```bash
curl "/api/object/mykey?timestamp=1440569580"
```

```json
{ "key": "mykey", "value": "value1", "timestamp": 1440569400 }
```

`404` if no version of the key existed yet at that timestamp. `422` if
`timestamp` isn't a non-negative integer.

### `GET /api/object/get_all_records`

Returns a JSON array with the latest version of every key currently in the
store.

```bash
curl /api/object/get_all_records
```

```json
[
  { "key": "mykey", "value": "value2", "timestamp": 1440569700 },
  { "key": "otherkey", "value": 42, "timestamp": 1440569500 }
]
```

### `GET /api/object/{key}/history`

Returns every version ever recorded for `key`, oldest first. An unknown key
returns an empty array rather than `404`, since this is a listing endpoint
like `get_all_records`.

```bash
curl /api/object/mykey/history
```

```json
[
  { "key": "mykey", "value": "value1", "timestamp": 1440569400 },
  { "key": "mykey", "value": "value2", "timestamp": 1440569700 }
]
```

### `DELETE /api/object/{key}`

Deletes every recorded version of `key`.

```bash
curl -X DELETE /api/object/mykey
```

`204 No Content` on success. `404` if the key has never been written.

## Getting started

Requires PHP 8.4+ and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # local dev uses SQLite by default
php artisan migrate
php artisan serve
```

The API is then available at `http://127.0.0.1:8000/api/object`.

To use MySQL (or any other Eloquent-supported database) instead, set
`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in
`.env` — nothing in the application code is SQLite-specific.

## Testing

```bash
php artisan test
```

36 tests / 87 assertions. Each API endpoint has its own feature test file
under `tests/Feature/`:

- [`StoreObjectTest.php`](tests/Feature/StoreObjectTest.php) — `POST /object`
  (validation errors included).
- [`ShowObjectTest.php`](tests/Feature/ShowObjectTest.php) — `GET /object/{key}`,
  including the timestamp query.
- [`HistoryTest.php`](tests/Feature/HistoryTest.php) — `GET /object/{key}/history`.
- [`GetAllRecordsTest.php`](tests/Feature/GetAllRecordsTest.php) — `GET /object/get_all_records`.
- [`RemoveKeyObjectTest.php`](tests/Feature/RemoveKeyObjectTest.php) — `DELETE /object/{key}`.

- [`tests/Unit/EloquentKeyValueRepositoryTest.php`](tests/Unit/EloquentKeyValueRepositoryTest.php) —
  the repository's query logic in isolation, including tie-breaking when two
  writes land on the same unix second.

With coverage (requires Xdebug or PCOV locally):

```bash
vendor/bin/phpunit --coverage-text
```

## CI/CD

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push and
pull request against `master`:

1. Install dependencies, run migrations against SQLite.
2. Run the full test suite with coverage (`--coverage-clover`); the report is
   uploaded as a build artifact and, if `CODECOV_TOKEN` is set, to Codecov.
3. On a successful push to `master`, a `deploy` job runs and — if deploy
   secrets are configured (see below) — SSHes into the target server, pulls
   `master`, reinstalls dependencies, and runs migrations.

Every commit gets a pass/fail status check from this workflow (visible on the
commit, and as a required check on PRs).

## Deployment

The `deploy` job in the CI workflow deploys over SSH and is a no-op until you
add these repository secrets (Settings → Secrets and variables → Actions):

| Secret | Purpose |
| --- | --- |
| `DEPLOY_HOST` | Server hostname/IP |
| `DEPLOY_USER` | SSH user |
| `DEPLOY_SSH_KEY` | Private key with access to that user |
| `DEPLOY_PATH` | Path to the project checkout on the server |

A [`Dockerfile`](Dockerfile) is also included (PHP 8.4 + Apache) for
container-based hosts (Railway, Fly.io, Render, a bare VPS, etc.) that don't
need the SSH-based flow.

## Design decisions

- **Insert-only history, not a diff/patch log.** Simpler to reason about and
  query; it's the standard approach for this kind of "point-in-time" API.
- **`POST` body shape.** The single-property JSON body (`{"mykey": "value1"}`)
  puts the key as the JSON property name rather than under fixed
  `key`/`value` fields, so the body is restricted to exactly one property —
  anything else is a `422`. This is enforced in
  [`StoreObjectRequest`](app/Http/Requests/StoreObjectRequest.php).
- **`get_all_records` returns latest-per-key, not full history.** It reflects
  current state, not an audit log; full history is reachable per-key via
  `GET /object/{key}/history` or the timestamp query.
- **SQLite for local dev and tests, MySQL-ready for production.** No code
  depends on SQLite specifically; switching is an `.env` change.
- **`/api` prefix.** Laravel's default API route grouping.
- **Keys are a single URL path segment.** A key containing a literal `/`
  isn't supported as-is — keys are treated as opaque strings, and supporting
  slashes wasn't worth the added routing complexity.
