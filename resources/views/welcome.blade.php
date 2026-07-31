<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Key-Value Store') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 720px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #1b1b18;
            background: #fdfdfc;
        }
        h1 { font-size: 1.4rem; margin-bottom: 0.25rem; }
        p.subtitle { color: #706f6c; margin-top: 0; margin-bottom: 1.5rem; }
        section {
            border: 1px solid #e3e3e0;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
        }
        h2 { font-size: 1rem; margin: 0 0 0.75rem; }
        label { display: block; font-size: 0.85rem; margin-bottom: 0.25rem; color: #444; }
        input {
            width: 100%;
            padding: 0.4rem 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.9rem;
            margin-bottom: 0.6rem;
        }
        .row { display: flex; gap: 0.75rem; }
        .row > div { flex: 1; }
        button {
            background: #1b1b18;
            color: #fff;
            border: none;
            padding: 0.45rem 0.9rem;
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        button:hover { background: #3a3a36; }
        button:disabled { opacity: 0.4; cursor: not-allowed; }
        button.secondary {
            background: #fff;
            color: #1b1b18;
            border: 1px solid #ccc;
        }
        button.danger {
            background: #fff;
            color: #c0392b;
            border: 1px solid #e0b4ae;
        }
        button.danger:hover { background: #fff2f2; }
        button.danger.small {
            padding: 0.15rem 0.5rem;
            font-size: 0.8rem;
        }
        pre {
            background: #f5f5f4;
            border-radius: 4px;
            padding: 0.6rem 0.75rem;
            font-size: 0.8rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
            margin-top: 0.75rem;
        }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #eee; }
        th { color: #706f6c; font-weight: 600; }
        .muted { color: #706f6c; font-size: 0.85rem; }
        .actions { display: flex; gap: 0.5rem; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <h1>Key-Value Store</h1>
    <p class="subtitle">A simple front end for the <code>{{ config('app.name') }}</code> API. All requests go to <code>/api/object/*</code>.</p>

    <section>
        <h2>Write a value</h2>
        <div class="row">
            <div>
                <label for="write-key">Key</label>
                <input id="write-key" type="text" placeholder="mykey" pattern="[A-Za-z0-9_.\-]+" maxlength="255">
            </div>
            <div>
                <label for="write-value">Value (string or JSON)</label>
                <input id="write-value" type="text" placeholder="value1 or {&quot;a&quot;:1}">
            </div>
        </div>
        <button id="write-btn">Save</button>
        <pre id="write-result" class="muted" hidden></pre>
    </section>

    <section>
        <h2>Look up by key</h2>
        <div class="row">
            <div>
                <label for="lookup-key">Key</label>
                <input id="lookup-key" type="text" placeholder="mykey" pattern="[A-Za-z0-9_.\-]+" maxlength="255">
            </div>
            <div>
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
        <h2>All records <button id="refresh-btn" class="secondary" style="float:right">Refresh</button></h2>
        <table id="records-table">
            <thead>
                <tr><th>Key</th><th>Value</th><th>Timestamp</th><th></th></tr>
            </thead>
            <tbody></tbody>
        </table>
        <p id="records-empty" class="muted" hidden>No records yet.</p>
        <div class="actions" style="justify-content: space-between; align-items: center;">
            <button id="prev-page-btn" class="secondary">&larr; Prev</button>
            <span id="page-indicator" class="muted">Page 1</span>
            <button id="next-page-btn" class="secondary">Next &rarr;</button>
        </div>
    </section>

    <script>
        const api = (path) => '/api/object' + path;

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

        function showResult(el, data) {
            el.hidden = false;
            el.textContent = JSON.stringify(data, null, 2);
        }

        const PAGE_SIZE = 10;
        let currentPage = 1;

        async function loadAllRecords(page = currentPage) {
            const res = await fetch(api(`/get_all_records/${page}`));
            const data = await res.json();
            const tbody = document.querySelector('#records-table tbody');
            const empty = document.getElementById('records-empty');
            tbody.innerHTML = '';

            const records = Array.isArray(data) ? data : [];
            empty.hidden = records.length !== 0;

            for (const rec of records) {
                const tr = document.createElement('tr');
                for (const text of [rec.key, JSON.stringify(rec.value), rec.timestamp]) {
                    const td = document.createElement('td');
                    td.textContent = text;
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

        document.getElementById('write-btn').addEventListener('click', async () => {
            const key = document.getElementById('write-key').value.trim();
            const rawValue = document.getElementById('write-value').value;
            const resultEl = document.getElementById('write-result');
            if (!isValidKey(key)) {
                showResult(resultEl, { error: 'Key may only contain letters, digits, underscores, hyphens and dots.' });
                return;
            }

            const res = await fetch(api(''), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ [key]: parseValue(rawValue) }),
            });
            showResult(resultEl, await res.json());
            loadAllRecords(1);
        });

        document.getElementById('lookup-btn').addEventListener('click', async () => {
            const key = document.getElementById('lookup-key').value.trim();
            const timestamp = document.getElementById('lookup-timestamp').value.trim();
            const resultEl = document.getElementById('lookup-result');
            if (!isValidKey(key)) {
                showResult(resultEl, { error: 'Key may only contain letters, digits, underscores, hyphens and dots.' });
                return;
            }

            const url = timestamp ? `${api('/' + key)}?timestamp=${encodeURIComponent(timestamp)}` : api('/' + key);
            const res = await fetch(url);
            showResult(resultEl, await res.json());
        });

        document.getElementById('history-btn').addEventListener('click', async () => {
            const key = document.getElementById('lookup-key').value.trim();
            const resultEl = document.getElementById('lookup-result');
            if (!isValidKey(key)) {
                showResult(resultEl, { error: 'Key may only contain letters, digits, underscores, hyphens and dots.' });
                return;
            }

            const res = await fetch(api(`/${key}/history`));
            showResult(resultEl, await res.json());
        });

        async function deleteKey(key) {
            if (!isValidKey(key)) {
                showResult(document.getElementById('lookup-result'), { error: 'Key may only contain letters, digits, underscores, hyphens and dots.' });
                return;
            }
            if (!confirm(`Delete all versions of "${key}"? This cannot be undone.`)) {
                return;
            }

            const res = await fetch(api('/' + key), { method: 'DELETE' });
            const resultEl = document.getElementById('lookup-result');
            if (res.status === 204) {
                showResult(resultEl, { message: `Key "${key}" deleted.` });
            } else {
                showResult(resultEl, await res.json());
            }
            loadAllRecords(currentPage);
        }

        document.getElementById('delete-btn').addEventListener('click', () => {
            deleteKey(document.getElementById('lookup-key').value.trim());
        });

        document.getElementById('refresh-btn').addEventListener('click', () => loadAllRecords(1));
        document.getElementById('prev-page-btn').addEventListener('click', () => loadAllRecords(currentPage - 1));
        document.getElementById('next-page-btn').addEventListener('click', () => loadAllRecords(currentPage + 1));

        loadAllRecords();
    </script>
</body>
</html>
