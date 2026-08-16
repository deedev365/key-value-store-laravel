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
(the single-action controllers in
[`app/Http/Controllers/Api/`](app/Http/Controllers/Api)) only talks to the
interface, so swapping the storage engine (e.g. for Redis or MongoDB) later
doesn't touch routing or validation.

Each endpoint is its own invokable controller — one file per row of the table
below — with the request contracts in `app/Http/Requests/` and the shared
response shape in
[`KvEntryResource`](app/Http/Resources/KvEntryResource.php):

| Endpoint | Controller |
| --- | --- |
| `POST /object` | [`StoreObjectController`](app/Http/Controllers/Api/StoreObjectController.php) |
| `GET /object/{key}` | [`ShowObjectController`](app/Http/Controllers/Api/ShowObjectController.php) |
| `GET /object/get_all_records/{page?}` | [`GetAllRecordsController`](app/Http/Controllers/Api/GetAllRecordsController.php) |
| `GET /object/{key}/history` | [`ObjectHistoryController`](app/Http/Controllers/Api/ObjectHistoryController.php) |
| `DELETE /object/{key}` | [`DeleteObjectController`](app/Http/Controllers/Api/DeleteObjectController.php) |

## API

All endpoints are JSON in, JSON out, and live at the root — `/object`, not
`/api/object`.

Every `/object` route shares a rolling per-IP limit of
`KV_MAX_REQUESTS_PER_MINUTE` (60) requests a minute. Responses advertise
`X-RateLimit-Limit` and `X-RateLimit-Remaining`; going over returns `429` with
a `Retry-After` header and the seconds left in the body:

```json
{ "message": "Too many requests. Try again in 56 seconds.", "retry_after": 56 }
```

The window is rolling, so a limit reached late in the minute clears in
seconds — the count is what the caller is told to wait, not a flat 60.

### `POST /object`

Stores a new version of a key. The request body is a single-property JSON
object: the property name is the key, its value is what gets stored (any
valid JSON type — string, number, bool, null, array or object).

```bash
curl -X POST /object \
  -H "Content-Type: application/json" \
  -d '{"mykey": "value1"}'
```

```json
{ "key": "mykey", "value": "value1", "timestamp": 1440569400 }
```

`201 Created` on success. `422` if the body isn't a single-property JSON
object, the key is empty/too long/reserved, or the value nests deeper than
`KV_MAX_VALUE_DEPTH` (20) levels. `413` if the body exceeds
`KV_MAX_BODY_BYTES` (64 KB).

`get_all_records` is reserved as a key: the listing route below claims that
path segment, so a record stored under it could never be read back through
`GET /object/get_all_records`.

Both limits live in [`config/kvstore.php`](config/kvstore.php). They bound how
fast the store can grow, since history is append-only and a write is never
reclaimed. They are storage guards rather than parser guards: PHP has already
read and decoded the body by the time the application sees it, so
`post_max_size` in `php.ini` and the web server's own body limit remain the
first line of defence against genuinely large uploads.

### `GET /object/{key}`

Returns the latest value for `key`.

```bash
curl /object/mykey
```

```json
{ "key": "mykey", "value": "value2", "timestamp": 1440569700 }
```

`404` if the key has never been written.

### `GET /object/{key}?timestamp={unix}`

Returns the value that was current for `key` at `{unix}` (a UTC unix
timestamp) — i.e. the latest version written at or before that time.

```bash
curl "/object/mykey?timestamp=1440569580"
```

```json
{ "key": "mykey", "value": "value1", "timestamp": 1440569400 }
```

`404` if no version of the key existed yet at that timestamp. `422` if
`timestamp` isn't a non-negative integer.

### `GET /object/get_all_records`
### `GET /object/get_all_records/{page}`

Returns a JSON array with the latest version of every key currently in the
store, ordered by key, `KV_RECORDS_PER_PAGE` (10) at a time.

```bash
curl /object/get_all_records
```

```json
[
  { "key": "mykey", "value": "value2", "timestamp": 1440569700 },
  { "key": "otherkey", "value": 42, "timestamp": 1440569500 }
]
```

Later pages are a trailing path segment — `/object/get_all_records/2`. Page
`0` and a missing page both mean the first one, and a page past the end is an
empty array rather than a `404`: running off the end of a list is not an
error.

The page is cut in SQL (`LIMIT`/`OFFSET` inside
[`EloquentKeyValueRepository`](app/Repositories/EloquentKeyValueRepository.php)),
not sliced in PHP, so serving page one does not hydrate the whole table into
models first. Paging counts *keys*, not rows: a key written a hundred times
still occupies one slot on one page.

### `GET /object/{key}/history`

Returns every version ever recorded for `key`, oldest first. An unknown key
returns an empty array rather than `404`, since this is a listing endpoint
like `get_all_records`.

```bash
curl /object/mykey/history
```

```json
[
  { "key": "mykey", "value": "value1", "timestamp": 1440569400 },
  { "key": "mykey", "value": "value2", "timestamp": 1440569700 }
]
```

### `DELETE /object/{key}`

Deletes every recorded version of `key`.

```bash
curl -X DELETE /object/mykey
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

The API is then available at `http://127.0.0.1:8000/object`.

To use MySQL (or any other Eloquent-supported database) instead, set
`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in
`.env` — nothing in the application code is SQLite-specific.

## Testing

```bash
php artisan test
```

The same suite through PHPUnit directly:

```bash
php vendor/bin/phpunit
```

277 tests / 973 assertions. Each API endpoint has its own feature test file
under `tests/Feature/`:

- [`StoreObjectTest.php`](tests/Feature/StoreObjectTest.php) — `POST /object`
  (validation errors included).
- [`ShowObjectTest.php`](tests/Feature/ShowObjectTest.php) — `GET /object/{key}`,
  including the timestamp query.
- [`HistoryTest.php`](tests/Feature/HistoryTest.php) — `GET /object/{key}/history`.
- [`GetAllRecordsTest.php`](tests/Feature/GetAllRecordsTest.php) — `GET /object/get_all_records`.
- [`RemoveKeyObjectTest.php`](tests/Feature/RemoveKeyObjectTest.php) — `DELETE /object/{key}`.

- [`RequestLimitsTest.php`](tests/Feature/RequestLimitsTest.php) — body size and
  value nesting limits, including a lying `Content-Length`.

- [`RateLimitTest.php`](tests/Feature/RateLimitTest.php) — the per-IP request
  quota, what a throttled caller is told, and that a refused write never
  reaches the database.

- [`RecordsPaginationTest.php`](tests/Feature/RecordsPaginationTest.php) — page
  boundaries, that paging counts keys rather than versions, that the cut
  happens in SQL, and that the front end's page size still matches the API's.

- [`RecordsTableTest.php`](tests/Feature/RecordsTableTest.php) — the records
  table's column contract, including that the readable time is formatted in
  UTC rather than the viewer's zone.

- [`FormValidationTest.php`](tests/Feature/FormValidationTest.php) — the
  required-field contract of both forms, that the optional timestamp stayed
  optional, that neither rule leaked into the API, and that messages and data
  are rendered differently.

- [`ContentSecurityPolicyTest.php`](tests/Feature/ContentSecurityPolicyTest.php) —
  the policy's directives, and that the page stays compatible with it: no
  inline script, no inline style, and every element the external script binds
  to still present in the markup.

- [`InjectionSafetyTest.php`](tests/Feature/InjectionSafetyTest.php) — hostile
  input across every entry point (body key, body value, `{key}` path segment,
  `?timestamp`, `{page}`): SQL, PHP code and object injection, shell
  metacharacters, path traversal in raw and encoded forms, HTML/JS payloads,
  CRLF header and log forging, JSON structure abuse (`__proto__`, duplicate
  properties, `_method` smuggling), encoding limits and boundaries.

`tests/Unit/` mirrors the same split one level down, covering the repository's
query logic without going through HTTP — including tie-breaking when two writes
land on the same unix second. Each file tests the storage call behind one
endpoint, and they share
[`RepositoryTestCase`](tests/Unit/RepositoryTestCase.php) for setup:

- [`StoreObjectRepositoryTest.php`](tests/Unit/StoreObjectRepositoryTest.php) — `store()`.
- [`ShowObjectRepositoryTest.php`](tests/Unit/ShowObjectRepositoryTest.php) — `findLatest()` and `findAtTimestamp()`.
- [`GetAllRecordsRepositoryTest.php`](tests/Unit/GetAllRecordsRepositoryTest.php) — `allLatest()`.
- [`ObjectHistoryRepositoryTest.php`](tests/Unit/ObjectHistoryRepositoryTest.php) — `history()`.
- [`DeleteObjectRepositoryTest.php`](tests/Unit/DeleteObjectRepositoryTest.php) — `deleteAll()`.

With coverage (requires Xdebug or PCOV locally):

```bash
vendor/bin/phpunit --coverage-text
```

### BDD suite

```bash
php vendor/bin/behat --no-snippets
```

Or through the Composer script, which runs the same command:

```bash
composer behat
```

118 scenarios / 525 steps covering the same API behaviour in Gherkin, under
`features/`. The suite is a second reading of the contract rather than extra
coverage: every scenario restates in plain language what a PHPUnit test asserts
in code, which is what makes it useful to read and what makes a drift between
the two worth investigating.

Scenarios run in-process. Each one boots its own application against an
in-memory SQLite database and dispatches real requests through the HTTP kernel,
so routing, middleware and validation all run exactly as they do under
`php artisan test` — the whole suite takes about seven seconds.

- `store_object.feature`, `show_object.feature`, `object_history.feature`,
  `get_all_records.feature`, `remove_object.feature` — one file per endpoint.
- `records_pagination.feature` — page boundaries, paging by key rather than by
  version, and that the cut happens in SQL.
- `request_limits.feature` — body size and nesting depth, including a lying
  `Content-Length`.
- `rate_limit.feature` — the per-IP quota and what a throttled caller is told.
- `injection_safety.feature` — the hostile-input matrix. Payload lists are
  Gherkin tables rather than `Examples` columns, because the payloads carry
  both kinds of quote and a quoted step argument cannot hold them.

Steps live in [`ApiContext.php`](tests/Behat/ApiContext.php). Values come in two
flavours there: a plain string, and a JSON literal in single quotes (`'null'`,
`'0'`, `'[]'`) for the scenarios where the value's JSON *type* is the thing
under test.

Behat is configured from PHP, not YAML — [`behat.dist.php`](behat.dist.php).
Strict mode is on, so an undefined step fails the run rather than passing
silently. The two front-end contract tests (`app.js` page size and key pattern)
stay in PHPUnit: they read a static file rather than exercising the API.

### Static analysis

```bash
php composer.phar run-script analyse
```

PHPStan with [larastan](https://github.com/larastan/larastan) at level 6 over
`app/`, `config/`, `database/`, `routes/` and `tests/` — configured in
[`phpstan.neon`](phpstan.neon). The suite currently reports no errors. If
Composer is installed globally, `composer analyse` does the same thing.

### Code style

Linting is [Laravel Pint](https://laravel.com/docs/pint) on the `laravel`
preset, configured in [`pint.json`](pint.json). To report violations without
touching any file:

```bash
php composer.phar run-script lint
```

To fix them in place:

```bash
php composer.phar run-script format
```

Both wrap `vendor/bin/pint` (`--test` for the first), so you can call the binary
directly if you prefer. The codebase is currently clean, and CI fails the build
on any violation.

## CI/CD

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push and
pull request against `master`:

1. Install dependencies, check code style (`pint --test`), run static analysis
   (`phpstan analyse`), run migrations against SQLite.
2. Run the full test suite with coverage (`--coverage-clover`); the report is
   uploaded as a build artifact and, if `CODECOV_TOKEN` is set, to Codecov.
3. Run the Behat suite (`behat --no-snippets`), which boots its own in-memory
   database and so needs no migration step of its own.
4. On a successful push to `master`, a `deploy` job runs and — if deploy
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
- **One controller per endpoint.** Each route points at its own invokable
  single-action controller rather than at a method on one shared class. The
  endpoints have nothing in common but the repository they call — different
  verbs, different inputs, different response shapes — so a class per endpoint
  keeps each file readable end to end and stops private helpers from quietly
  coupling unrelated endpoints. What *is* shared is shared explicitly: the
  response shape in [`KvEntryResource`](app/Http/Resources/KvEntryResource.php)
  and the input contracts in `app/Http/Requests/`.
- **`POST` body shape.** The single-property JSON body (`{"mykey": "value1"}`)
  puts the key as the JSON property name rather than under fixed
  `key`/`value` fields, so the body is restricted to exactly one property —
  anything else is a `422`. This is enforced in
  [`StoreObjectRequest`](app/Http/Requests/StoreObjectRequest.php).
- **Values are stored verbatim.** Laravel's global `TrimStrings` and
  `ConvertEmptyStringsToNull` middleware rewrite the parsed JSON body, which
  would turn `"  a  "` into `"a"` and `""` into `null` before the value ever
  reached the database. Both are skipped for the API's paths in
  [`bootstrap/app.php`](bootstrap/app.php) so a value round-trips unchanged.
  The `value` column is nullable for the same reason: `{"mykey": null}` is a
  valid write.
- **The write body is read from the raw request content.** Laravel copies a
  decoded JSON body into the Symfony request bag, where global middleware
  rewrites it and Symfony's `_method` override reads from it. Parsing
  `$this->getContent()` in
  [`StoreObjectRequest`](app/Http/Requests/StoreObjectRequest.php) keeps the
  stored data equal to what the client actually sent. Decoding to `stdClass`
  rather than to an associative array is what keeps `{"0":"a"}` (key `0`)
  distinct from the array body `["a"]`, and stops
  `{"0":"a","1":"b"}` from being re-encoded as `["a","b"]`.
- **The records table shows the time in UTC.** Alongside the raw
  `timestamp` there is a readable `Time (UTC)` column — `6:00pm` — rendered
  from the same number. It is deliberately not the viewer's local time:
  timestamps are stored as UNIX seconds in UTC, and a local rendering would
  print a different hour than the value in the column beside it, differing per
  reader. The cell's tooltip carries the full instant, since a bare clock time
  cannot tell yesterday's 6pm from today's.
- **The page shows messages as sentences and data as JSON.** A 404, a
  validation failure, a rate-limit refusal or a deletion confirmation is only
  a sentence, so it is printed as one — `No value found for key 'config1'.`
  rather than the object that carried it. A record, a history list or the
  store listing *is* the answer, so it stays JSON; flattening it into prose
  would lose the value's type and shape.
- **The forms refuse empty fields; the API does not.** `""` is a valid value
  to store, so the API accepts it — but a blank box on the page is far more
  likely to be an unfilled field than a deliberate empty string. Both write
  inputs and the lookup key are therefore required, the message names the
  field at fault, and typing `""` is the way to store an empty string on
  purpose. A whitespace-only value is accepted as typed, matching the
  verbatim-storage rule above. The lookup timestamp stays optional, since an
  absent one means "current value". "Get value", "Full history" and "Delete
  key" read the same field and share one guard, so the destructive button can
  never end up more permissive than the other two.
- **No inline CSS or JS on the page.** The front end's stylesheet and script
  live in [`public/css/app.css`](public/css/app.css) and
  [`public/js/app.js`](public/js/app.js) rather than inline in the Blade
  template, which is what lets the Content-Security-Policy use `'self'`
  instead of `'unsafe-inline'`. With `default-src 'none'` and no inline
  escape hatch, an injected `<script>` — inline by definition — does not run.
  `style="..."` attributes count as inline style too, so those became classes.
- **JSON responses escape markup.**
  [`SecurityHeaders`](app/Http/Middleware/SecurityHeaders.php) sends
  `nosniff`/`DENY`/`no-referrer` and re-encodes JSON with `JSON_HEX_TAG` and
  friends, so a stored `<script>` payload cannot be rendered as markup even if
  a response is served or embedded somewhere unexpected. Values still decode
  to exactly what was written.
- **Paging lives in the repository, not the controller.** `allLatest()` takes
  a mandatory `$limit` and an `$offset`, so there is deliberately no way to
  ask the store for everything — the unbounded call that used to load the
  whole table before slicing it in PHP no longer exists. The controller is
  left with the two lines that turn a page number into an offset.
- **`get_all_records` returns latest-per-key, not full history.** It reflects
  current state, not an audit log; full history is reachable per-key via
  `GET /object/{key}/history` or the timestamp query.
- **SQLite for local dev and tests, MySQL-ready for production.** No code
  depends on SQLite specifically; switching is an `.env` change.
- **No `/api` prefix.** The routes are mounted at the root, matching the
  endpoints as specified: `/object`, `/object/{key}`,
  `/object/get_all_records`. Laravel's `withRouting()` would otherwise put
  them under its default `/api` prefix, so
  [`bootstrap/app.php`](bootstrap/app.php) passes `apiPrefix: ''`. They still
  belong to the `api` middleware group, which is what carries the rate limit,
  the body-size cap and the stateless (session-free, CSRF-free) handling —
  the prefix and the group are separate things.
- **Keys are a single URL path segment.** A key containing a literal `/`
  isn't supported as-is — keys are treated as opaque strings, and supporting
  slashes wasn't worth the added routing complexity.
