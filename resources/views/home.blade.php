<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Key-Value Store') }}</title>

    {{--
        The stylesheet and script live in public/ rather than inline so the
        Content-Security-Policy sent by the SecurityHeaders middleware can use
        'self' instead of 'unsafe-inline'. Inline style="..." attributes are
        governed by style-src too, so those moved into app.css as classes.
    --}}
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/css/app.css">
    <script src="/js/app.js" defer></script>
</head>
<body>
    <h1>Key-Value Store</h1>
    <p class="subtitle">A simple front end for the <code>{{ config('app.name') }}</code> API. All requests go to <code>/object/*</code>.</p>

    <section>
        <h2>Write a value</h2>
        <div class="row">
            <div>
                <label for="write-key">Key</label>
                <input id="write-key" type="text" placeholder="mykey" pattern="[A-Za-z0-9_.\-]+" maxlength="255" required>
            </div>
            <div>
                <label for="write-value">Value (string or JSON)</label>
                <input id="write-value" type="text" placeholder="value1 or {&quot;a&quot;:1}" required>
            </div>
        </div>
        <button id="write-btn">Save</button>
        <pre id="write-result" class="muted" hidden></pre>
    </section>

    {{--
        A second writer for the content records the travel site reads, which are
        always an object carrying one message. It posts to the same POST /object
        as the free-form form above — the value it builds just happens to have a
        fixed shape, and the activation moment rides in the query string rather
        than in the value, since publish_time is a column of its own.
    --}}
    <section>
        <h2>Add a content item</h2>
        <p class="muted">Stores <code>{"message": "..."}</code> under one key.</p>
        <div class="row">
            <div>
                <label for="content-key">Key name</label>
                <input id="content-key" type="text" placeholder="route.bangkok-chiang-mai.banner" pattern="[A-Za-z0-9_.\-]+" maxlength="255" required>
            </div>
            <div>
                <label for="content-body">Content</label>
                <input id="content-body" type="text" placeholder="Put your message" required>
            </div>
        </div>
        {{--
            A date picker and a clock, rather than one datetime-local control:
            datetime-local yields a single local wall-clock string, and the two
            fields are read as UTC here — the zone the store and every other
            reading on this page use. The labels say so, and the line under them
            echoes the instant back before anything is written.
        --}}
        <div class="row">
            <div>
                <label for="content-date">Active from — date (UTC)</label>
                <input id="content-date" type="date" required>
            </div>
            <div>
                <label for="content-time">Active from — time (UTC)</label>
                <input id="content-time" type="time" step="60" required>
            </div>
        </div>
        <p id="content-time-preview" class="muted" hidden></p>
        <button id="content-btn">Save</button>
        <pre id="content-result" class="muted" hidden></pre>
    </section>

    <section>
        <h2>Look up by key</h2>
        <div class="row">
            <div>
                <label for="lookup-key">Key</label>
                <input id="lookup-key" type="text" placeholder="mykey" pattern="[A-Za-z0-9_.\-]+" maxlength="255" required>
            </div>
            <div>
                {{-- Deliberately not required: an absent timestamp means
                     "current value", which is the common case. --}}
                <label for="lookup-timestamp">Timestamp (optional)</label>
                <input id="lookup-timestamp" type="text" placeholder="1440569580">
            </div>
        </div>
        <div class="actions">
            <button id="lookup-btn">Get value</button>
            <button id="history-btn" class="secondary">Full history</button>
            <button id="delete-btn" class="danger">Delete key</button>
        </div>
        <pre id="lookup-result" class="muted" hidden></pre>
    </section>

    <section>
        <h2>All records <button id="refresh-btn" class="secondary heading-action">Refresh</button></h2>
        <table id="records-table">
            <thead>
                <tr><th>Key</th><th>Value</th><th>Timestamp</th><th>Time (UTC)</th><th></th></tr>
            </thead>
            <tbody></tbody>
        </table>
        <p id="records-empty" class="muted" hidden>No records yet.</p>
        <div class="actions pager">
            <button id="prev-page-btn" class="secondary">&larr; Prev</button>
            <span id="page-indicator" class="muted">Page 1</span>
            <button id="next-page-btn" class="secondary">Next &rarr;</button>
        </div>
    </section>
</body>
</html>
