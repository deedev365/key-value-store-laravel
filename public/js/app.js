const api = (path) => '/object' + path;

// Keys are also used as URL path segments and rendered on this page,
// so they're restricted to a safe charset (must match the backend
// rule in StoreObjectRequest / routes/api.php).
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

/**
 * A stored timestamp as a readable clock time, e.g. 6:00pm.
 *
 * Rendered in UTC, not the viewer's local zone: the stored timestamps are UNIX
 * seconds in UTC, so a local rendering would show a different hour than the
 * value it sits next to and turn "written at 6pm" into a guess about where the
 * reader is sitting.
 */
function formatTime(unixSeconds) {
    const date = new Date(unixSeconds * 1000);

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
    const date = new Date(unixSeconds * 1000);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toISOString().replace('T', ' ').replace(/\.\d+Z$/, ' UTC');
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

// The API allows a fixed number of requests per minute and answers 429
// with the seconds left on the window, so say that plainly rather than
// dumping the raw error at the user.
function throttleMessage(res, data) {
    if (res.status !== 429) {
        return null;
    }

    const wait = (data && data.retry_after) || 60;

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

const PAGE_SIZE = 10;
let currentPage = 1;

async function loadAllRecords(page = currentPage) {
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
const lookupKeyEl = document.getElementById('lookup-key');
const lookupResultEl = document.getElementById('lookup-result');

// Clear the highlight as soon as the user starts fixing the field.
for (const field of [writeKeyEl, writeValueEl, lookupKeyEl]) {
    field.addEventListener('input', () => field.classList.remove('field-error'));
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
    if (res.status === 204) {
        showMessage(lookupResultEl, `Key "${key}" deleted.`);
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
