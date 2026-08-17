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
    | Requests per minute
    |--------------------------------------------------------------------------
    |
    | Rolling per-IP limit across every /object route, reads and writes alike.
    | Exceeding it returns 429 with a Retry-After header. The limiter itself is
    | defined in AppServiceProvider; Laravel does not throttle API routes on
    | its own.
    |
    */

    'max_requests_per_minute' => (int) env('KV_MAX_REQUESTS_PER_MINUTE', 60),

];
