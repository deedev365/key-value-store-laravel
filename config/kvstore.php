<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum request body size
    |--------------------------------------------------------------------------
    |
    | Writes are append-only, so every accepted body becomes a row that is
    | never reclaimed. This caps how much a single write can add. It is a
    | storage guard, not a parser guard: PHP has already read and decoded the
    | body by the time the application sees it, so the first line of defence
    | against genuinely huge uploads is post_max_size in php.ini and the web
    | server's own body limit.
    |
    */

    'max_body_bytes' => (int) env('KV_MAX_BODY_BYTES', 65536),

    /*
    |--------------------------------------------------------------------------
    | Maximum value nesting depth
    |--------------------------------------------------------------------------
    |
    | How deeply a stored value may nest arrays and objects. json_decode's own
    | limit is 512; this is a stricter policy limit, so that walking a stored
    | value stays cheap for anything that later consumes it.
    |
    */

    'max_value_depth' => (int) env('KV_MAX_VALUE_DEPTH', 20),

    /*
    |--------------------------------------------------------------------------
    | Records per page
    |--------------------------------------------------------------------------
    |
    | How many keys GET /object/get_all_records/{page?} returns at a time.
    | The front end's page size must match this, or its "Next" button will
    | never enable; RecordsPaginationTest pins the two together.
    |
    */

    'records_per_page' => (int) env('KV_RECORDS_PER_PAGE', 5),

    /*
    |--------------------------------------------------------------------------
    | Keys offered by the selector
    |--------------------------------------------------------------------------
    |
    | How many key names GET /object/get_all_records/keys returns, and so how
    | many the page's key selector can offer. A cap rather than a page: a
    | dropdown showing an arbitrary half of the store would be worse than one
    | that is honest about showing the first N. Raise it if the store outgrows
    | it — nothing breaks below the limit, and the listing table remains the
    | way to reach everything.
    |
    */

    'max_keys_listed' => (int) env('KV_MAX_KEYS_LISTED', 500),

    /*
    |--------------------------------------------------------------------------
    | Requests per minute
    |--------------------------------------------------------------------------
    |
    | Rolling per-IP limit across every /object route, reads and writes alike.
    | Exceeding it returns 429 with a Retry-After header. The limiter itself is
    | defined in AppServiceProvider; Laravel does not throttle API routes on
    | its own.
    |
    | Set with the front end in mind: a single save spends several requests
    | (the write, then a reload of the listing), and a rate-limited listing
    | retries itself every KV_RECORDS_RETRY_SECONDS, so a browsing editor needs
    | more headroom than the guard against scraping strictly requires.
    |
    */

    'max_requests_per_minute' => (int) env('KV_MAX_REQUESTS_PER_MINUTE', 120),

    /*
    |--------------------------------------------------------------------------
    | Listing retry interval
    |--------------------------------------------------------------------------
    |
    | How long the records table waits before re-requesting a page that came
    | back 429. The table would otherwise sit empty until the reader pressed
    | Refresh, which reads as "the store is broken" rather than "wait a moment".
    | RecordsTableTest pins this to the interval used in app.js.
    |
    */

    'records_retry_seconds' => (int) env('KV_RECORDS_RETRY_SECONDS', 10),

];
