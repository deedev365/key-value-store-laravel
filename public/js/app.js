const api = (path) => '/object' + path;

// Keys are also used as URL path segments and rendered on this page,
// so they're restricted to a safe charset (must match the backend
// rule, which lives in App\ValueObjects\Key and is asserted against
// this line by InjectionSafetyTest).
const KEY_PATTERN = /^[A-Za-z0-9_.-]+$/;

function isValidKey(key) {
    return key.length > 0 && key.length <= 255 && KEY_PATTERN.test(key);
}

function parseValue(raw) {
    try { return JSON.parse(raw); } catch (e) { return raw; }
}

// A record, a history list or the whole store: the JSON is the answer the
// user asked for, so it is shown as JSON.
function showData(el, data) {
    el.hidden = false;
    el.textContent = JSON.stringify(data, null, 2);
}

// Anything that is only a sentence — a 404, a validation failure, a deletion
// confirmation — reads better as that sentence than as the object wrapping it.
function showMessage(el, text) {
    el.hidden = false;
    el.textContent = text;
}

/**
 * The sentence inside an API error body. Validation failures carry both a
 * summary `message` and a per-field `errors` map; when more than one field
 * failed, the summary only names the first, so every line is listed instead.
 */
function messageFrom(data) {
    if (!data || typeof data !== 'object') {
        return null;
    }

    const fieldErrors = data.errors && typeof data.errors === 'object'
        ? Object.values(data.errors).flat()
        : [];

    if (fieldErrors.length > 1) {
        return fieldErrors.join('\n');
    }

    return data.message || fieldErrors[0] || null;
}

// The API records timestamps as UNIX seconds; Date works in milliseconds.
const MS_PER_SECOND = 1000;

/**
 * A stored timestamp as a readable clock time, e.g. 6:00pm.
 *
 * Rendered in UTC, not the viewer's local zone: the stored timestamps are UNIX
 * seconds in UTC, so a local rendering would show a different hour than the
 * value it sits next to and turn "written at 6pm" into a guess about where the
 * reader is sitting.
 */
function formatTime(unixSeconds) {
    const date = new Date(unixSeconds * MS_PER_SECOND);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const hours = date.getUTCHours();
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');

    // 0 and 12 both map to 12 — midnight is 12am, noon is 12pm.
    const hour12 = hours % 12 === 0 ? 12 : hours % 12;

    return `${hour12}:${minutes}${hours < 12 ? 'am' : 'pm'}`;
}

/**
 * The same instant in full, for the cell's tooltip: a bare clock time cannot
 * tell yesterday's 6pm from today's.
 */
function formatFullUtc(unixSeconds) {
    const date = new Date(unixSeconds * MS_PER_SECOND);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toISOString().replace('T', ' ').replace(/\.\d+Z$/, ' UTC');
}

// The wire formats of <input type="date"> and <input type="time">. Both are
// fixed by the HTML spec regardless of how the picker displays them, so the
// value can be read apart rather than parsed loosely.
const DATE_VALUE = /^(\d{4})-(\d{2})-(\d{2})$/;

// Hours and minutes only. The picker is stepped to the minute, so a value
// carrying seconds did not come from it and is refused rather than silently
// rounded into a schedule the editor never chose.
const TIME_VALUE = /^(\d{2}):(\d{2})$/;

/**
 * The date and time fields as one UNIX timestamp, read as UTC.
 *
 * Date.UTC is what makes that true. Handing "2026-08-17T16:15" to the Date
 * constructor instead would apply the browser's own offset, so the same form
 * filled in by an editor in Bangkok and one in London would schedule two
 * different instants — and neither would be the one the labels promise.
 *
 * Returns { unix } or { error }, so the caller decides how to report a refusal.
 */
function publishTimeFrom(dateValue, timeValue) {
    const date = DATE_VALUE.exec(dateValue.trim());

    if (date === null) {
        return { error: 'Pick the date the item becomes active.' };
    }

    const time = TIME_VALUE.exec(timeValue.trim());

    if (time === null) {
        return { error: 'Pick the time the item becomes active.' };
    }

    const [year, month, day] = date.slice(1).map(Number);
    const [hours, minutes] = time.slice(1).map(Number);

    // Seconds are left at zero: a schedule is chosen to the minute, so the
    // stored instant lands exactly on the minute the editor picked.
    const ms = Date.UTC(year, month - 1, day, hours, minutes);

    // Date.UTC rolls overflow forward rather than refusing it — the 31st of
    // February silently becomes March. Reading the parts back is what catches a
    // typed date the picker would never have produced.
    const parsed = new Date(ms);

    if (
        parsed.getUTCFullYear() !== year
        || parsed.getUTCMonth() !== month - 1
        || parsed.getUTCDate() !== day
        || parsed.getUTCHours() !== hours
        || parsed.getUTCMinutes() !== minutes
    ) {
        return { error: 'That is not a real date and time.' };
    }

    return { unix: Math.floor(ms / MS_PER_SECOND) };
}

/**
 * The same instant as formatFullUtc, but only as far as the minute — the
 * pickers offer nothing finer, so echoing back a ":00" the editor cannot change
 * would suggest a precision the form does not have.
 */
function formatUtcToMinute(unixSeconds) {
    const date = new Date(unixSeconds * MS_PER_SECOND);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return `${date.toISOString().slice(0, 16).replace('T', ' ')} UTC`;
}

function clearFieldErrors(fields) {
    for (const field of fields) {
        field.classList.remove('field-error');
    }
}

function rejectField(field, resultEl, message) {
    field.classList.add('field-error');
    field.focus();
    showMessage(resultEl, message);
}

// What to wait when a refusal carries no retry_after to read. The API's
// window is one minute, so a whole window is the safe guess — guessing short
// would send the user straight back into the limit.
const RATE_LIMIT_FALLBACK_SECONDS = 60;

// The API allows a fixed number of requests per minute and answers 429
// with the seconds left on the window, so say that plainly rather than
// dumping the raw error at the user.
function throttleMessage(res, data) {
    if (res.status !== 429) {
        return null;
    }

    const wait = (data && data.retry_after) || RATE_LIMIT_FALLBACK_SECONDS;

    return `Rate limit reached — too many requests. Try again in ${wait} second${wait === 1 ? '' : 's'}.`;
}

async function showResponse(el, res) {
    const data = await res.json().catch(() => null);

    if (res.ok) {
        showData(el, data);
        return data;
    }

    showMessage(
        el,
        throttleMessage(res, data)
            || messageFrom(data)
            || `Request failed (HTTP ${res.status}).`
    );

    return data;
}

const PAGE_SIZE = 5;
let currentPage = 1;

// How long a rate-limited listing waits before asking again. Must match
// kvstore.records_retry_seconds, which RecordsTableTest pins.
const RECORDS_RETRY_SECONDS = 10;

/**
 * The pending re-request of a listing that was rate limited, so only one is
 * ever outstanding — every entry to loadAllRecords cancels it, which is also
 * what stops a manual Refresh from racing a scheduled retry.
 */
let recordsRetryTimer = null;

function cancelRecordsRetry() {
    if (recordsRetryTimer !== null) {
        clearTimeout(recordsRetryTimer);
        recordsRetryTimer = null;
    }
}

async function loadAllRecords(page = currentPage) {
    cancelRecordsRetry();

    const res = await fetch(api(`/get_all_records/${page}`));
    const data = await res.json().catch(() => null);
    const tbody = document.querySelector('#records-table tbody');
    const empty = document.getElementById('records-empty');
    tbody.innerHTML = '';

    // A failed load is not an empty store — saying "no records yet"
    // when the request was refused would be a lie.
    if (!res.ok) {
        empty.hidden = false;
        empty.textContent = throttleMessage(res, data)
            || messageFrom(data)
            || 'Could not load records.';

        // Only the listing retries itself, and only on 429. It is a read, so
        // asking again costs nothing but a request; the write handlers must
        // never do this, since a retried POST in an append-only store would
        // add a second version of the same value.
        if (res.status === 429) {
            empty.textContent += ` Retrying every ${RECORDS_RETRY_SECONDS} seconds…`;

            recordsRetryTimer = setTimeout(
                () => loadAllRecords(page),
                RECORDS_RETRY_SECONDS * MS_PER_SECOND
            );
        }

        return;
    }

    const records = Array.isArray(data) ? data : [];
    empty.textContent = 'No records yet.';
    empty.hidden = records.length !== 0;

    for (const rec of records) {
        const tr = document.createElement('tr');
        const cells = [
            { text: rec.key },
            { text: JSON.stringify(rec.value) },
            { text: rec.timestamp },
            { text: formatTime(rec.timestamp), title: formatFullUtc(rec.timestamp) },
        ];

        for (const cell of cells) {
            const td = document.createElement('td');
            td.textContent = cell.text;
            if (cell.title) {
                td.title = cell.title;
            }
            tr.appendChild(td);
        }

        const actionsTd = document.createElement('td');
        const deleteBtn = document.createElement('button');
        deleteBtn.textContent = 'Delete';
        deleteBtn.className = 'danger small';
        deleteBtn.addEventListener('click', () => deleteKey(rec.key));
        actionsTd.appendChild(deleteBtn);
        tr.appendChild(actionsTd);

        tbody.appendChild(tr);
    }

    currentPage = page;
    document.getElementById('page-indicator').textContent = `Page ${currentPage}`;
    document.getElementById('prev-page-btn').disabled = currentPage <= 1;
    document.getElementById('next-page-btn').disabled = records.length < PAGE_SIZE;
}

const writeKeyEl = document.getElementById('write-key');
const writeValueEl = document.getElementById('write-value');
const contentKeyEl = document.getElementById('content-key');
const contentBodyEl = document.getElementById('content-body');
const contentDateEl = document.getElementById('content-date');
const contentTimeEl = document.getElementById('content-time');
const lookupKeyEl = document.getElementById('lookup-key');
const lookupResultEl = document.getElementById('lookup-result');

// Clear the highlight as soon as the user starts fixing the field.
for (const field of [writeKeyEl, writeValueEl, contentKeyEl, contentBodyEl, contentDateEl, contentTimeEl, lookupKeyEl]) {
    field.addEventListener('input', () => field.classList.remove('field-error'));
}

/**
 * Echo the two pickers back as the instant and the timestamp they resolve to,
 * so the editor sees what will be sent before anything is written — the pickers
 * show a wall clock, and it is the number underneath that schedules the item.
 */
function refreshActiveTimePreview() {
    const previewEl = document.getElementById('content-time-preview');
    const both = contentDateEl.value !== '' && contentTimeEl.value !== '';

    previewEl.hidden = !both;

    if (!both) {
        return;
    }

    const parsed = publishTimeFrom(contentDateEl.value, contentTimeEl.value);

    previewEl.textContent = parsed.error
        ? parsed.error
        : `Active from ${formatUtcToMinute(parsed.unix)} — publish_time ${parsed.unix}`;
}

for (const field of [contentDateEl, contentTimeEl]) {
    field.addEventListener('input', refreshActiveTimePreview);
}

/**
 * The lookup key, or null if the field is unusable. "Get value", "Full
 * history" and "Delete key" all read the same box, so they refuse it in one
 * place rather than three — the delete path especially must not be the one
 * that drifts.
 */
function validatedLookupKey() {
    const key = lookupKeyEl.value.trim();

    clearFieldErrors([lookupKeyEl]);

    if (key === '') {
        rejectField(lookupKeyEl, lookupResultEl, 'Key is required.');
        return null;
    }

    if (!isValidKey(key)) {
        rejectField(lookupKeyEl, lookupResultEl, 'Key may only contain letters, digits, underscores, hyphens and dots.');
        return null;
    }

    return key;
}

document.getElementById('write-btn').addEventListener('click', async () => {
    const key = writeKeyEl.value.trim();
    const rawValue = writeValueEl.value;
    const resultEl = document.getElementById('write-result');

    clearFieldErrors([writeKeyEl, writeValueEl]);

    // Emptiness is refused here rather than at the API, which accepts "" as a
    // perfectly valid stored value. Only the form can tell an intentional
    // empty string from a box the user never filled in — to store one
    // deliberately, type the two characters "" and it parses as JSON.
    if (key === '') {
        rejectField(writeKeyEl, resultEl, 'Key is required.');
        return;
    }

    if (!isValidKey(key)) {
        rejectField(writeKeyEl, resultEl, 'Key may only contain letters, digits, underscores, hyphens and dots.');
        return;
    }

    // Not trimmed: "   " is a legitimate value and the API stores it verbatim.
    if (rawValue === '') {
        rejectField(writeValueEl, resultEl, 'Value is required. To store an empty string, type "" instead.');
        return;
    }

    const res = await fetch(api(''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ [key]: parseValue(rawValue) }),
    });
    await showResponse(resultEl, res);
    loadAllRecords(1);
});

document.getElementById('content-btn').addEventListener('click', async () => {
    const key = contentKeyEl.value.trim();
    const content = contentBodyEl.value;
    const resultEl = document.getElementById('content-result');

    clearFieldErrors([contentKeyEl, contentBodyEl, contentDateEl, contentTimeEl]);

    if (key === '') {
        rejectField(contentKeyEl, resultEl, 'Key is required.');
        return;
    }

    if (!isValidKey(key)) {
        rejectField(contentKeyEl, resultEl, 'Key may only contain letters, digits, underscores, hyphens and dots.');
        return;
    }

    // Not trimmed, for the same reason the free-form value is not: what the
    // editor typed is what the site will render.
    if (content === '') {
        rejectField(contentBodyEl, resultEl, 'Content is required.');
        return;
    }

    if (contentDateEl.value === '') {
        rejectField(contentDateEl, resultEl, 'Pick the date the item becomes active.');
        return;
    }

    if (contentTimeEl.value === '') {
        rejectField(contentTimeEl, resultEl, 'Pick the time the item becomes active.');
        return;
    }

    const time = publishTimeFrom(contentDateEl.value, contentTimeEl.value);

    if (time.error) {
        rejectField(contentDateEl, resultEl, time.error);
        return;
    }

    // The instant goes in the query string only. It used to be copied into the
    // value as well, from the days before publish_time was a column; keeping
    // both would store one moment twice with nothing keeping them in step.
    const res = await fetch(`${api('')}?publish_time=${encodeURIComponent(time.unix)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ [key]: { message: content } }),
    });
    await showResponse(resultEl, res);
    loadAllRecords(1);
});

document.getElementById('lookup-btn').addEventListener('click', async () => {
    const key = validatedLookupKey();
    if (key === null) {
        return;
    }

    const timestamp = document.getElementById('lookup-timestamp').value.trim();

    const url = timestamp ? `${api('/' + key)}?timestamp=${encodeURIComponent(timestamp)}` : api('/' + key);
    const res = await fetch(url);
    await showResponse(lookupResultEl, res);
});

document.getElementById('history-btn').addEventListener('click', async () => {
    const key = validatedLookupKey();
    if (key === null) {
        return;
    }

    const res = await fetch(api(`/${key}/history`));
    await showResponse(lookupResultEl, res);
});

// Also called from the per-row Delete buttons, which always pass a key that
// came back from the API — the guard stays as a backstop for that path.
async function deleteKey(key) {
    if (!isValidKey(key)) {
        showMessage(lookupResultEl, 'Key may only contain letters, digits, underscores, hyphens and dots.');
        return;
    }
    if (!confirm(`Delete all versions of "${key}"? This cannot be undone.`)) {
        return;
    }

    const res = await fetch(api('/' + key), { method: 'DELETE' });
    if (res.ok) {
        // A deletion is a sentence, not a record, so the API's own message is
        // shown as one rather than as the object wrapping it.
        const data = await res.json().catch(() => null);
        showMessage(lookupResultEl, messageFrom(data) || `Key "${key}" deleted.`);
    } else {
        await showResponse(lookupResultEl, res);
    }
    loadAllRecords(currentPage);
}

document.getElementById('delete-btn').addEventListener('click', () => {
    const key = validatedLookupKey();
    if (key === null) {
        return;
    }

    deleteKey(key);
});

document.getElementById('refresh-btn').addEventListener('click', () => loadAllRecords(1));
document.getElementById('prev-page-btn').addEventListener('click', () => loadAllRecords(currentPage - 1));
document.getElementById('next-page-btn').addEventListener('click', () => loadAllRecords(currentPage + 1));

loadAllRecords();
