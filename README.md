# Key-Value Store

A version-controlled key-value store with an HTTP API, built with Laravel. Every
write is kept as a new, immutable version, so the API can answer both "what is
this key's value right now" and "what was this key's value at some point in
the past".

## 💻 Interface Screenshots

![Frontend Page](http://urlutm.ru/screenshot/key-value-store-laravel.jpg)

## Contents

- [The brief: scheduled publishing](#the-brief-scheduled-publishing)
- [How it works](#how-it-works)
- [API](#api)
- [Getting started](#getting-started)
- [Testing](#testing)
- [CI/CD](#cicd)
- [Deployment](#deployment)
- [Design decisions](#design-decisions)
- [AI tool usage](#ai-tool-usage)

## The brief: scheduled publishing

### Business context

The key-value store is now used to publish customer-facing travel content, such
as:

```
route.bangkok-chiang-mai.banner
operator.srt.booking_notice
country.th.payment_message
```

Today, when an editor saves or adds a value, it becomes visible immediately.

The content team needs to prepare campaigns and service notices ahead of time,
then have them become visible automatically at a chosen UTC time.

Review the existing implementation and propose the smallest safe change that
supports this workflow. Before coding, clarify the expected behaviour, identify
risks in the current design, and agree on an API and data model.

**No background worker should be required for a scheduled value to become
active.**

### Worked example

At 10:00 UTC, `route.bangkok-chiang-mai.banner` is
`{"message":"Normal service"}`.

At 11:00 UTC, an editor schedules `{"message":"Songkran timetable now
available"}` to become active at 18:00 UTC.

| Time (UTC) | A customer querying the key receives |
| --- | --- |
| 12:00 | `Normal service` |
| 18:00 and later | `Songkran timetable now available` |

Editors may schedule several future changes for the same key.

### What was agreed

**Data model** — one nullable column, `kv_entries.publish_time` (UTC unix
seconds). `NULL` means "no schedule": live from the moment the row was written,
which is what every row predating the column is and what a plain write stays.
No other schema change; the existing `(key, recorded_at, id)` index still
serves the reads.

**API** — an optional query parameter on the existing write:

```bash
curl -X POST "/object?publish_time=1440569400" \
  -H "Content-Type: application/json" \
  -d '{"route.bangkok-chiang-mai.banner": {"message": "Songkran timetable now available"}}'
```

It rides in the query string because the request body is a single-property
object whose *property name is the key* — a second property would break that
invariant and make a key literally named `publish_time` unrepresentable.

**No worker** — activation is a property of the read, not of a job. Every read
compares `publish_time` against the clock on each request, so a version goes
live on its own. There is nothing to schedule, nothing to deploy alongside the
app, and nothing that can fall behind or double-fire.

**Risks found in the original design**, all now closed:

- `findLatest()` had no upper bound on `recorded_at`, so a future-stamped row
  was visible *immediately* — the store already had a time dimension but only
  one of its two reads used it.
- The listing selected each key by `MAX(id)` while the single-key read ordered
  by `recorded_at`: two different rules for "current", which agreed only while
  the two columns moved together.
- Filtering scheduled rows *after* the per-key grouping would have dropped the
  whole key from the listing, hiding the version that was still live.
- `?timestamp=` would otherwise have been a way to read an embargoed campaign
  early; it now travels through `recorded_at` while `publish_time` is still
  compared against the real clock.

See [How it works](#how-it-works) for the mechanics and the
[API](#api) section for each endpoint.

### Selection rule: the acceptance cases

A version is a candidate once its `publish_time` has passed, or if it has none
at all. Among the candidates, **the one written last wins** — highest `id`, not
the greatest publish time.

Each row below lists one key's versions in the order they were written, and
which of them a customer receives now. `null` means the version was saved with
no schedule.

| Versions written, in order | Clock | Current |
| --- | --- | --- |
| `(t1, t2)` | `t1 < now`, `t2 > now` | `t1` |
| `(t1, t2)` | `t1 < now`, `t2 < now` | `t2` |
| `(t1, t2, t3)` | `t1, t2 < now`, `t3 > now` | `t2` |
| `(t1, t2, t3)` | `t1, t2, t3 < now` | `t3` |
| `(null, t1, t2)` | `t1 > now`, `t2 > now` | `null` |
| `(null, t1, t2)` | `t1 < now`, `t2 > now` | `t1` |
| `(null, t1, t2)` | `t1 < now`, `t2 < now` | `t2` |
| `(t1, null, t2)` | `t1 > now`, `t2 > now` | `null` |
| `(t1, null, t2)` | `t1 < now`, `t2 > now` | `null` |
| `(t1, null, t2)` | `t1 < now`, `t2 < now` | `t2` |
| `(t1, t2, null)` | `t1 > now`, `t2 > now` | `null` |
| `(t1, t2, null)` | `t1 < now`, `t2 > now` | `null` |
| `(t1, t2, null)` | `t1 < now`, `t2 < now` | `null` |

In every case `t1 < t2 < t3`.

Three rows are what forces the rule to turn on `id` rather than on the publish
time — the ones where a version saved with **no schedule** was written after a
schedule that has already published:

```
(t1, null, t2)   t1 < now, t2 > now   -> null
(t1, t2, null)   t1 < now, t2 > now   -> null
(t1, t2, null)   t1 < now, t2 < now   -> null
```

Ordering by `MAX(publish_time)` would answer `t1`, `t1` and `t2` there. It
satisfies the other ten rows, which is why the distinction is easy to miss:
reverting the ordering to publish time leaves ten of the thirteen cases passing
and breaks exactly these three.

One correction to the original list: the third row above was written as
expecting `t3`, but `t3 > now` there means that version has not been published
yet, so it cannot be returned — the answer is `t2`.

Every case is pinned as a data provider in
[`PublishTimeSelectionTest`](tests/Unit/PublishTimeSelectionTest.php), asserted
against `findLatest()` *and* `allLatest()` so the two reads cannot drift apart.

## How it works

Writes are append-only. A `POST` never updates a row — it inserts a new one
stamped with the current UTC unix timestamp. "Current value" and "value as of
timestamp T" are both just queries over that history:

- **Current value** = the most recently inserted row for the key.
- **Value as of T** = the most recently inserted row for the key with
  `recorded_at <= T`.

Editing an existing version is expressed in those same terms: `PUT /object/{key}`
appends the correction and removes the one version it corrects, in a single
transaction. It is the only place a single row is deleted — `DELETE` drops a key
whole, and nothing anywhere runs an `UPDATE`. Since the current value is the
last-written version, correcting an older one also makes the correction current.

This lives in a single table, `kv_entries`: `id`, `key`, `value` (JSON),
`recorded_at` (unix timestamp), `publish_time` (nullable unix timestamp),
`created_at`/`updated_at`. It's indexed on `(key, recorded_at, id)`, which
covers both lookup types directly.

`recorded_at` is when a version was written; `publish_time` is when it becomes
visible, and `NULL` there means "immediately". **Every** read honours it, so no
endpoint can reveal a version another one is hiding — in particular
`?timestamp=<future>` is not a way to read a campaign before it goes live,
because that parameter moves `recorded_at` while `publish_time` is still
compared against the real clock.

Every query lives in
[`EloquentKeyValueRepository`](app/Repositories/EloquentKeyValueRepository.php),
which the HTTP layer (the single-action controllers in
[`app/Http/Controllers/Api/`](app/Http/Controllers/Api)) depends on directly —
no interface, since there is one storage engine and the repository tests run
against the real database rather than a double. Keeping the queries in one
class is what stops the "latest version" rule from being restated in each
controller; swapping the storage engine later means extracting an interface
from this class, which is a mechanical refactor.

Each endpoint is its own invokable controller — one file per row of the table
below — with the request contracts in `app/Http/Requests/` and the shared
response shape in
[`KvEntryResource`](app/Http/Resources/KvEntryResource.php):

| Endpoint | Controller |
| --- | --- |
| `POST /object` | [`StoreObjectController`](app/Http/Controllers/Api/StoreObjectController.php) |
| `PUT /object/{key}` | [`ReplaceObjectController`](app/Http/Controllers/Api/ReplaceObjectController.php) |
| `GET /object/{key}` | [`ShowObjectController`](app/Http/Controllers/Api/ShowObjectController.php) |
| `GET /object/get_all_records/{page?}` | [`GetAllRecordsController`](app/Http/Controllers/Api/GetAllRecordsController.php) |
| `GET /object/get_all_records/keys` | [`GetAllKeysController`](app/Http/Controllers/Api/GetAllKeysController.php) |
| `GET /object/{key}/history` | [`ObjectHistoryController`](app/Http/Controllers/Api/ObjectHistoryController.php) |
| `DELETE /object/{key}` | [`DeleteObjectController`](app/Http/Controllers/Api/DeleteObjectController.php) |

## API

All endpoints are JSON in, JSON out, and live at the root — `/object`, not
`/api/object`.

Every `/object` route shares a rolling per-IP limit of
`KV_MAX_REQUESTS_PER_MINUTE` (120) requests a minute. Responses advertise
`X-RateLimit-Limit` and `X-RateLimit-Remaining`; going over returns `429` with
a `Retry-After` header and the seconds left in the body:

```json
{ "message": "Too many requests. Try again in 56 seconds.", "retry_after": 56 }
```

The window is rolling, so a limit reached late in the minute clears in
seconds — the count is what the caller is told to wait, not a flat 60.

The limit is set with the front end in mind rather than at the lowest value a
scraping guard would need: one save spends several requests, and the records
table re-requests a rate-limited page every `KV_RECORDS_RETRY_SECONDS` (10)
instead of sitting empty until someone presses Refresh. Only that listing
retries itself, and only on `429` — a refused write is never repeated, since
writes append and a retry would store the value twice.

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

Returns the value that is live for `key` right now.

```bash
curl /object/mykey
```

```json
{ "key": "mykey", "value": "value2", "timestamp": 1440569700 }
```

`404` if the key has never been written — **or if none of its versions has
been published yet**. The two are deliberately the same answer: a distinct
message would confirm that embargoed content exists under that name.

Which version is live is decided by `publish_time`:

- A version is a candidate once its `publish_time` has **passed**
  (`publish_time < now`, so the named second itself is still pending), or if it
  has none at all — `NULL` means live from the moment it was written.
- Among the candidates, the one written **last** wins: highest `id`.

That second rule is about write order, not publish order, and the two differ.
Three versions written in this order — scheduled for noon, scheduled for 4pm,
then saved with no schedule — resolve after 4pm to the *unscheduled* one,
because it was saved last and so is the editor's most recent intent. A schedule
set earlier never overrides a correction saved after it.

For a key that has never been scheduled every `publish_time` is `NULL`, so this
reduces to "the version written last", exactly as before the column existed.

Nothing runs in the background: the query asks the clock per request, so a
version goes live on its own with no worker, queue or cron involved.

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

Two clocks are at work here, deliberately. `{unix}` selects by `recorded_at`,
answering the caller's question about a past moment, while `publish_time` is
still compared against the **real** current time. So travelling forward shows
what was current then among versions that are live now — a future timestamp
cannot be used to read a scheduled campaign early.

### `GET /object/get_all_records`
### `GET /object/get_all_records/{page}`

Returns a JSON array with the latest version of every key currently in the
store, ordered by key, `KV_RECORDS_PER_PAGE` (5) at a time.

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

**Scheduled versions are withheld.** A version may carry a `publish_time`
(UTC unix seconds); this listing returns it only once that second has arrived.
A `publish_time` of `NULL` means there was no schedule to wait for, so the
version is live from the moment it was written — which is what every row
predating the column is, and what a plain `POST /object` still produces.

The rule is applied per row, before the per-key grouping, so a key with a
scheduled version does not vanish from the listing: it keeps showing whichever
version is currently live, and switches to the scheduled one when its time
comes. A key whose *only* version is still scheduled is absent altogether
rather than present with an empty value.

Nothing runs in the background to make this happen. The listing asks the clock
on every request, so the same request simply answers differently after the
publish time passes — there is no worker, queue or cron to promote a row, and
none to fall behind.

This listing picks each key's row by highest `id` among the published ones —
the same rule `GET /object/{key}` applies, so the two cannot disagree about
what is current for a key.

### `GET /object/get_all_records/keys`

The name of every key that has something published, alphabetically. A flat array
of strings, not records — it exists to fill the page's key selector, and a
dropdown needs names rather than values.

```bash
curl /object/get_all_records/keys
```

```json
["config1", "mykey", "route.bangkok-chiang-mai.banner"]
```

Capped at `KV_MAX_KEYS_LISTED` (500) names, so this is not the unbounded read the
paged listing deliberately avoids being. A key whose only versions are still
waiting on their `publish_time` is absent, exactly as it is from every other
read.

It is a sub-path of the listing rather than a route of its own, so no second name
has to be reserved: `get_all_records` already is, and a key cannot contain a
slash. A key literally named `keys` is therefore still perfectly storable and
readable at `/object/keys`.

### `GET /object/{key}/history`

Returns every **published** version of `key`, oldest first. An unknown key
returns an empty array rather than `404`, since this is a listing endpoint
like `get_all_records`.

A version whose `publish_time` has not passed is absent: the log is public, so
listing a queued campaign here would announce it before its time. It joins the
history on its own once that time passes.

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

```json
{ "message": "Key 'mykey' and all its versions were deleted." }
```

`200 OK` on success. `404` if the key has never been written.
### `PUT /object/{key}`
### `PUT /object/{key}?timestamp={unix}&publish_time={unix}`

Corrects one stored version. The body is the same single-property envelope a
write takes, and its property name must be the key in the URL.

The version corrected is the one this URL would *read*: without `?timestamp` the
current version, with it the version that was current at that moment. It is
removed and the corrected value is appended in a single transaction — no row is
ever updated.

```bash
curl -X PUT /object/mykey \
  -H 'Content-Type: application/json' \
  -d '{"mykey":"corrected"}'
```

```json
{ "key": "mykey", "value": "corrected", "timestamp": 1440570000 }
```

`200 OK`, not `201`: no new address comes into being — the resource this URL
names simply has a new current value. `timestamp` in the response is when the
correction was written.

`publish_time` says when the correction goes live. Leaving it out carries the
replaced version's own time over, since a correction is a change of wording
rather than a reschedule by default — and no schedule is lost that way, because
every version this endpoint can reach is published already. Passing one wins
over the carried-over value:

```bash
curl -X PUT "/object/mykey?publish_time=1440574000" \
  -H 'Content-Type: application/json' \
  -d '{"mykey":"corrected"}'
```

A `publish_time` in the *future* is accepted and does what it says: the version
being corrected is removed and the correction is not readable until its time
comes, so the key can answer `404` (or fall back to an older version) in
between. That is a real choice with real consequences, so the page spells it out
above the Save button rather than hiding it.

`404` if the key has never been written, if no version was current at the given
timestamp, or if the only versions are still waiting on their `publish_time` —
the publish filter guards this write exactly as it guards every read, so a queued
campaign cannot be edited before it goes live. `422` for a body that is not a
single pair, a value nested too deeply, a non-integer `timestamp` or
`publish_time`, or a body key that differs from the key in the URL.

One consequence worth stating plainly: because the current value of a key is its
*last-written* version, correcting an older version also makes that correction
current. An append-only store has no way to write a version that is not the
newest one.

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

453 tests / 1411 assertions. Each API endpoint has its own feature test file
under `tests/Feature/`:

- [`StoreObjectTest.php`](tests/Feature/StoreObjectTest.php) — `POST /object`
  (validation errors included).
- [`ReplaceObjectTest.php`](tests/Feature/ReplaceObjectTest.php) — `PUT /object/{key}`,
  including which version an edit lands on, that the rest of the history
  survives, and that a version awaiting its `publish_time` cannot be edited.
- [`ShowObjectTest.php`](tests/Feature/ShowObjectTest.php) — `GET /object/{key}`,
  including the timestamp query.
- [`HistoryTest.php`](tests/Feature/HistoryTest.php) — `GET /object/{key}/history`.
- [`GetAllRecordsTest.php`](tests/Feature/GetAllRecordsTest.php) — `GET /object/get_all_records`.
- [`GetAllKeysTest.php`](tests/Feature/GetAllKeysTest.php) — `GET /object/get_all_records/keys`,
  including that the two listing routes stay out of each other's way.
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

- [`EditValueFormTest.php`](tests/Feature/EditValueFormTest.php) — the "Save
  changes" affordance: that it stays disabled until a lookup has resolved a
  version, that it replaces *that* version rather than whatever the timestamp box
  says at click time, that the schedule pickers are filled from the version found
  and read as UTC, and that it confirms before removing anything.

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
- [`GetAllKeysRepositoryTest.php`](tests/Unit/GetAllKeysRepositoryTest.php) — `allKeys()`.
- [`ObjectHistoryRepositoryTest.php`](tests/Unit/ObjectHistoryRepositoryTest.php) — `history()`.
- [`ReplaceObjectRepositoryTest.php`](tests/Unit/ReplaceObjectRepositoryTest.php) — `replace()`.
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

118 scenarios / 529 steps covering the same API behaviour in Gherkin, under
`tests/Behat/features/`, next to the `ApiContext` that implements their steps.
The suite is a second reading of the contract rather than extra
coverage: every scenario restates in plain language what a PHPUnit test asserts
in code, which is what makes it useful to read and what makes a drift between
the two worth investigating.

Scenarios run in-process. Each one boots its own application against an
in-memory SQLite database and dispatches real requests through the HTTP kernel,
so routing, middleware and validation all run exactly as they do under
`php artisan test` — the whole suite takes about seven seconds.

- `store_object.feature`, `show_object.feature`, `object_history.feature`,
  `get_all_records.feature`, `get_all_keys.feature`, `remove_object.feature`,
  `replace_object.feature` — one file per endpoint.
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

1. Validate the Composer manifest (`composer validate --strict`, which fails if
   `composer.lock` has drifted from `composer.json`), install dependencies,
   check code style (`pint --test`), run static analysis (`phpstan analyse`),
   run migrations against SQLite.
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
  query; it's the standard approach for this kind of "point-in-time" API. The
  log is still made only of inserts after `PUT` — see the next bullet for how
  editing fits.
- **Editing is replace-by-append, not `UPDATE`.** `PUT /object/{key}` appends
  the corrected value and removes the one version it corrects, inside a
  transaction in
  [`replace()`](app/Repositories/EloquentKeyValueRepository.php). The browser
  could have done this as two calls — a `POST` then a version-scoped `DELETE` —
  and deliberately does not: two requests share no transaction, so a refusal
  between them (a `429` is entirely plausible, the page already spends several
  requests per save) leaves the store holding both versions with nothing able to
  tell them apart. It would also mean exposing a public "delete just this
  version" endpoint — a sharper tool than the feature needs, since it lets any
  caller punch holes in the history — and the target would have to be re-resolved
  *after* the store changed, using a row id the API never publishes. Server-side,
  the `DELETE`'s affected-row count is the claim on the version: two callers
  correcting the same version serialise on that row and the loser is told the
  version is gone rather than appending a second correction of it. The reply is
  `200`, not `201`, because no new address comes into being. The body's key must
  equal the key in the URL, since two spellings of one identifier in one request
  is where a silent "the path won" bug lives — and a mismatch would let
  `PUT /object/a` delete a's version while writing b's. `publish_time` defaults
  to the replaced version's own, since a correction is a change of wording
  rather than a reschedule — and nothing is lost by that default, because every
  version the endpoint can reach is published already. Passing one overrides it,
  including a time in the future, which genuinely hides the key until then; the
  page warns about that above the button rather than the API refusing a thing an
  editor may well mean.
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
  [`WriteBody`](app/ValueObjects/WriteBody.php), which
  [`ParsesWriteBody`](app/Http/Requests/Concerns/ParsesWriteBody.php) plugs into
  the validator for both writes and edits — the envelope is one contract, so the
  `POST` and the `PUT` cannot drift apart on what a body may look like.
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
  [`ParsesWriteBody`](app/Http/Requests/Concerns/ParsesWriteBody.php) keeps the
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
  verbatim-storage rule above. The lookup version stays optional, since an
  absent one means "current value". "Get value", "Full history" and "Delete
  key" read the same field and share one guard, so the destructive button can
  never end up more permissive than the other two.
- **The lookup block chooses; it does not type.** A key and a version are exact
  identifiers that must match something stored, so a free-text box can only ever
  produce a 404 or a silent miss — the key comes from a `<select>` filled by
  `GET /object/get_all_records/keys`, and the version from a second one filled
  by that key's own history. The version selector is hidden unless the key has
  more than one published version: with a single version there is nothing to
  choose between, and the empty "current value" option already means it. Versions
  are listed newest first and labelled with the instant as well as the raw
  timestamp, since one unix number cannot be told from another by eye. The lists
  are refreshed wherever the store changes rather than only at load, and a
  refused refresh leaves the options on screen alone — emptying the selector on
  a `429` would read as "the store has no keys".
- **"Save changes" edits the version that was looked up.** The edit box, the two
  schedule pickers and the Save button in the lookup block start disabled and
  are armed only by a successful "Get value", which fills the box with the value
  it found — as JSON, so an object survives being edited — and the pickers with
  that version's own `publish_time`, so a correction keeps its schedule unless
  the editor changes it. Save then replaces *that* version: the key and
  timestamp the lookup actually used are remembered rather than re-read from the
  boxes on click, because typing a new timestamp afterwards would otherwise send
  the edit to a version other than the one on screen. The schedule pickers are
  read at click time instead, since they belong to the edit being written rather
  than to the version being replaced. Editing the key or the timestamp box,
  listing the history or deleting the key disarms it again. Because the old
  version is removed for good, saving confirms first, exactly as deleting does.
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
