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
 * A date field and a time field as one UNIX timestamp, read as UTC. Used by
 * both pairs of pickers on the page: the one that schedules a content item and
 * the one that schedules a correction to an existing version.
 *
 * Date.UTC is what makes the zone true. Handing "2026-08-17T16:15" to the Date
 * constructor instead would apply the browser's own offset, so the same form
 * filled in by an editor in Bangkok and one in London would mean two different
 * instants — and neither would be the one the labels promise.
 *
 * Returns { unix } or { error }, so the caller decides how to report a refusal.
 * The messages name the date and the time rather than what they are for, since
 * two callers use them.
 */
function utcTimestampFrom(dateValue, timeValue) {
    const date = DATE_VALUE.exec(dateValue.trim());

    if (date === null) {
        return { error: 'Pick a date.' };
    }

    const time = TIME_VALUE.exec(timeValue.trim());

    if (time === null) {
        return { error: 'Pick a time.' };
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
const lookupTimestampEl = document.getElementById('lookup-timestamp');
const publishDateEl = document.getElementById('publish-date');
const publishTimeEl = document.getElementById('publish-time');
const lookupValueEl = document.getElementById('lookup-value');
const saveBtnEl = document.getElementById('save-btn');
const lookupResultEl = document.getElementById('lookup-result');

// Clear the highlight as soon as the user starts fixing the field.
for (const field of [writeKeyEl, writeValueEl, contentKeyEl, contentBodyEl, contentDateEl, contentTimeEl, lookupKeyEl, lookupValueEl, publishDateEl, publishTimeEl]) {
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

    const parsed = utcTimestampFrom(contentDateEl.value, contentTimeEl.value);

    previewEl.textContent = parsed.error
        ? parsed.error
        : `Active from ${formatUtcToMinute(parsed.unix)} — publish_time ${parsed.unix}`;
}

for (const field of [contentDateEl, contentTimeEl]) {
    field.addEventListener('input', refreshActiveTimePreview);
}

/**
 * The chosen key, or null if nothing usable is selected. "Get value", "Full
 * history" and "Delete key" all read the same box, so they refuse it in one
 * place rather than three — the delete path especially must not be the one
 * that drifts.
 *
 * The options come from the store, so the charset check can only fail if the
 * list was tampered with; it stays as the backstop it always was, and the
 * empty case is the placeholder option, which is the common one.
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

/**
 * When the correction should go live, as the API's `publish_time` query wants
 * it.
 *
 * Returns { publishTime: '' } for "leave the replaced version's own schedule
 * alone" — both boxes empty — { publishTime: '<unix>' } for a chosen instant,
 * or { error } when only one of the two was filled in. Half a moment is not a
 * moment: defaulting the box left empty would schedule an instant the editor
 * never picked.
 */
function editPublishTime() {
    clearFieldErrors([publishDateEl, publishTimeEl]);

    const date = publishDateEl.value.trim();
    const time = publishTimeEl.value.trim();

    if (date === '' && time === '') {
        return { publishTime: '' };
    }

    if (date === '') {
        return { error: 'Pick the date the correction becomes active, or clear the time to keep the current schedule.' };
    }

    if (time === '') {
        return { error: 'Pick the time the correction becomes active, or clear the date to keep the current schedule.' };
    }

    const parsed = utcTimestampFrom(date, time);

    if (parsed.error) {
        return { error: parsed.error };
    }

    return { publishTime: String(parsed.unix) };
}

/**
 * The two pickers as the calendar and clock values that spell one instant, so a
 * schedule the API returned can be shown in the boxes it was picked in.
 *
 * Read off the ISO string rather than through the local-time getters, for the
 * same reason every other reading on this page is: the boxes are labelled UTC.
 */
function utcPickerValues(unixSeconds) {
    const iso = new Date(unixSeconds * MS_PER_SECOND).toISOString();

    return { date: iso.slice(0, 10), time: iso.slice(11, 16) };
}

/**
 * Echo the pickers back as the instant and the number they resolve to, the way
 * the content form does — and say plainly when a correction is being scheduled
 * into the future, since that hides it until then.
 */
function refreshEditPublishPreview() {
    const previewEl = document.getElementById('publish-time-preview');
    const publish = editPublishTime();

    // Nothing picked is not a state worth describing: it means the replaced
    // version's own schedule stays, which is what the label already says.
    previewEl.hidden = publish.publishTime === '';

    if (previewEl.hidden) {
        return;
    }

    if (publish.error) {
        previewEl.textContent = publish.error;

        return;
    }

    const scheduled = Number(publish.publishTime) * MS_PER_SECOND > Date.now();

    previewEl.textContent = `Active from ${formatUtcToMinute(Number(publish.publishTime))} — publish_time ${publish.publishTime}`
        + (scheduled ? ' — that is in the future, so the correction stays hidden until then.' : '');
}

for (const field of [publishDateEl, publishTimeEl]) {
    field.addEventListener('input', refreshEditPublishPreview);
}

/**
 * Which version "Save changes" will replace: the key and the timestamp query
 * the last successful "Get value" actually used, or null when there is nothing
 * to save.
 *
 * Remembered rather than re-read at save time. Reading the timestamp box again
 * on click would let an edit land somewhere other than what is on screen: type
 * a different timestamp after looking up, and the box would still show the old
 * version's value while the request replaced another one.
 */
let editTarget = null;

function clearEditTarget() {
    editTarget = null;
    lookupValueEl.value = '';
    publishDateEl.value = '';
    publishTimeEl.value = '';
    document.getElementById('publish-time-preview').hidden = true;

    for (const field of [lookupValueEl, publishDateEl, publishTimeEl]) {
        field.disabled = true;
    }

    saveBtnEl.disabled = true;
}

/**
 * The looked-up record becomes the editable value. Stringified rather than
 * printed raw, because the box round-trips through the same parse the free-form
 * write box uses: every JSON type survives — an object stays an object,
 * "value1" keeps the quotes that tell it from the bare word, and "" is visibly
 * an empty string rather than an empty box.
 */
function armEditTarget(key, timestamp, record) {
    lookupValueEl.value = JSON.stringify(record.value);

    // The schedule is shown in the boxes it would be picked in, so a correction
    // keeps the version's own publish_time unless the editor changes it. A
    // version with no schedule leaves them empty, which means the same thing.
    const schedule = typeof record.publish_time === 'number'
        ? utcPickerValues(record.publish_time)
        : { date: '', time: '' };

    publishDateEl.value = schedule.date;
    publishTimeEl.value = schedule.time;

    // The schedule as loaded, so an untouched pair of pickers can be told from
    // one set to the same minute. The pickers stop at the minute while a stored
    // publish_time carries seconds, so re-sending what was loaded would round
    // 22:13:20 down to 22:13:00 — a silent edit of a schedule nobody changed.
    editTarget = { key, timestamp, schedule };

    for (const field of [lookupValueEl, publishDateEl, publishTimeEl]) {
        field.disabled = false;
    }

    saveBtnEl.disabled = false;
    refreshEditPublishPreview();
}

/**
 * The key selector's options, from the store rather than from memory: a key
 * written or deleted in another tab should appear or disappear here too, so
 * this runs beside every reload of the listing.
 *
 * The current choice survives a refresh if the key still exists, and is
 * dropped — along with anything armed for editing — if it does not.
 */
async function loadKeyOptions() {
    const chosen = lookupKeyEl.value;

    const res = await fetch(api('/get_all_records/keys'));
    const data = await res.json().catch(() => null);

    // A refused or malformed listing is not an empty store, so the options
    // already on screen are left alone rather than replaced with nothing.
    if (!res.ok || !Array.isArray(data)) {
        return;
    }

    lookupKeyEl.innerHTML = '';
    lookupKeyEl.appendChild(new Option('Choose a key…', ''));

    for (const key of data) {
        lookupKeyEl.appendChild(new Option(key, key));
    }

    lookupKeyEl.value = data.includes(chosen) ? chosen : '';

    if (lookupKeyEl.value !== chosen) {
        clearEditTarget();
        await loadVersionOptions();
    }
}

/**
 * The version selector for the chosen key: one option per published version,
 * newest first, on top of the "current value" the other buttons mean.
 *
 * Hidden unless there is more than one version — with a single version there is
 * nothing to choose between, and an empty selection already means it.
 */
async function loadVersionOptions() {
    const field = document.getElementById('lookup-timestamp-field');
    const key = lookupKeyEl.value;

    lookupTimestampEl.innerHTML = '';
    lookupTimestampEl.appendChild(new Option('Current value', ''));

    if (key === '' || !isValidKey(key)) {
        field.hidden = true;

        return;
    }

    const res = await fetch(api(`/${key}/history`));
    const versions = await res.json().catch(() => null);

    if (!res.ok || !Array.isArray(versions) || versions.length < 2) {
        field.hidden = true;

        return;
    }

    // Newest first: the recent versions are the ones an editor is looking for,
    // and "current value" sits directly above them.
    for (const version of [...versions].reverse()) {
        const option = new Option(
            `${formatFullUtc(version.timestamp)} — ${version.timestamp}`,
            String(version.timestamp)
        );

        lookupTimestampEl.appendChild(option);
    }

    field.hidden = false;
}

// Choosing another key changes which versions exist, and what is on screen is
// no longer the version the edit box was filled from.
lookupKeyEl.addEventListener('change', () => {
    clearEditTarget();
    loadVersionOptions();
});

// Choosing another version invalidates the target too: the value in the edit
// box belongs to the version that was resolved, not to the one now selected.
// The schedule pickers are part of the edit itself, so changing them leaves the
// target alone.
lookupTimestampEl.addEventListener('change', clearEditTarget);

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
    loadKeyOptions();
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

    const time = utcTimestampFrom(contentDateEl.value, contentTimeEl.value);

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
    loadKeyOptions();
});

document.getElementById('lookup-btn').addEventListener('click', async () => {
    const key = validatedLookupKey();
    if (key === null) {
        clearEditTarget();
        return;
    }

    const timestamp = lookupTimestampEl.value.trim();

    const url = timestamp ? `${api('/' + key)}?timestamp=${encodeURIComponent(timestamp)}` : api('/' + key);
    const res = await fetch(url);
    const data = await showResponse(lookupResultEl, res);

    // A 404, a 429 or a malformed answer leaves nothing to edit, so Save must
    // not be armed by it.
    if (res.ok && data && typeof data === 'object') {
        armEditTarget(key, timestamp, data);
    } else {
        clearEditTarget();
    }
});

document.getElementById('save-btn').addEventListener('click', async () => {
    if (editTarget === null) {
        showMessage(lookupResultEl, 'Press "Get value" first, so the edit knows which version it replaces.');
        return;
    }

    clearFieldErrors([lookupValueEl]);

    // Untrimmed, and refused here rather than at the API — the same rule the
    // free-form write box applies, for the same reason: only the form can tell
    // an unfilled box from a deliberate empty string, which is typed "".
    if (lookupValueEl.value === '') {
        rejectField(lookupValueEl, lookupResultEl, 'Value is required. To store an empty string, type "" instead.');
        return;
    }

    // Read now rather than remembered with the target: the schedule is part of
    // the edit being written, not of the version being replaced.
    const publish = editPublishTime();

    if (publish.error) {
        rejectField(publishDateEl, lookupResultEl, publish.error);
        return;
    }

    const { key, timestamp, schedule } = editTarget;

    const rescheduled = publishDateEl.value !== schedule.date || publishTimeEl.value !== schedule.time;

    // Spelled out, because this is not an update: the version being edited is
    // removed and the correction takes its place at the end of the history,
    // which also makes it the key's current value.
    const which = timestamp
        ? `the version current at ${timestamp}`
        : 'the current version';
    if (!confirm(`Replace ${which} of "${key}"? The old version is removed and cannot be recovered.`)) {
        return;
    }

    // Both are optional and independent: `timestamp` names the version being
    // corrected, `publish_time` when the correction goes live. An absent
    // publish_time leaves the replaced version's own schedule in place.
    const query = new URLSearchParams();

    if (timestamp) {
        query.set('timestamp', timestamp);
    }

    // Only when it was actually changed: an untouched pair means "keep what the
    // replaced version had", which the API does by itself when the parameter is
    // absent — and does to the second.
    if (rescheduled && publish.publishTime) {
        query.set('publish_time', publish.publishTime);
    }

    const url = query.size > 0 ? `${api('/' + key)}?${query}` : api('/' + key);

    const res = await fetch(url, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ [key]: parseValue(lookupValueEl.value) }),
    });
    await showResponse(lookupResultEl, res);

    // The version that was named is gone either way — replaced by this request,
    // or by someone else if this one was refused — so the next save has to
    // resolve its target again rather than reuse a stale one.
    clearEditTarget();
    loadAllRecords(currentPage);

    // A correction can be the first version of a key the selector has never
    // shown, and the version list of the edited key has certainly changed.
    await loadKeyOptions();
    loadVersionOptions();
});

document.getElementById('history-btn').addEventListener('click', async () => {
    const key = validatedLookupKey();
    if (key === null) {
        return;
    }

    // A history list is not one version, so there is nothing for Save to
    // replace while it is on screen.
    clearEditTarget();

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

    // Every version is about to go, including whichever one Save was aimed at.
    clearEditTarget();

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

    // The key itself may be gone now, so the selector must not keep offering it.
    await loadKeyOptions();
}

document.getElementById('delete-btn').addEventListener('click', () => {
    const key = validatedLookupKey();
    if (key === null) {
        return;
    }

    deleteKey(key);
});

document.getElementById('refresh-btn').addEventListener('click', () => {
    loadAllRecords(1);
    loadKeyOptions();
});
document.getElementById('prev-page-btn').addEventListener('click', () => loadAllRecords(currentPage - 1));
document.getElementById('next-page-btn').addEventListener('click', () => loadAllRecords(currentPage + 1));

loadAllRecords();
loadKeyOptions();
