<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>CivoraX Site</title>
    <link rel="manifest" href="{{ asset('site-manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('site-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('site-icon.svg') }}">
    <style>
        :root {
            --bg: #f4f5fb; --card: #fff; --text: #111827; --muted: #6b7280; --line: #e5e7eb;
            --primary: #4f46e5; --primary-soft: #eef2ff; --ok: #16a34a; --warn: #d97706; --bad: #dc2626;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1020; --card: #151b2e; --text: #e5e7eb; --muted: #9ca3af; --line: #26304a; --primary-soft: #1e1b4b; }
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        body { padding-bottom: calc(76px + env(safe-area-inset-bottom)); }
        header { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 10px; padding: 12px 16px; padding-top: calc(12px + env(safe-area-inset-top)); background: var(--card); border-bottom: 1px solid var(--line); }
        header .title { font-weight: 800; letter-spacing: -.01em; }
        header .title span { color: var(--primary); }
        header .sub { font-size: 12px; color: var(--muted); }
        .net { margin-left: auto; display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 999px; background: var(--primary-soft); }
        .net i { width: 8px; height: 8px; border-radius: 50%; background: var(--ok); }
        .net.off i { background: var(--bad); }
        main { max-width: 720px; margin: 0 auto; padding: 14px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px; margin-bottom: 12px; }
        .card h2 { font-size: 15px; margin: 0 0 10px; }
        label.f { display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin: 10px 0 4px; }
        input, select, textarea { width: 100%; font: inherit; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; }
        textarea { min-height: 84px; resize: vertical; }
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; font: inherit; font-weight: 700; border: 0; border-radius: 12px; padding: 12px 16px; background: var(--primary); color: #fff; cursor: pointer; }
        .btn.full { width: 100%; }
        .btn.ghost { background: var(--primary-soft); color: var(--primary); }
        .btn.small { padding: 7px 11px; font-size: 13px; border-radius: 9px; }
        .btn.danger { background: transparent; color: var(--bad); border: 1px solid var(--line); }
        .btn:disabled { opacity: .5; }
        .lab { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; padding: 10px 0; border-bottom: 1px solid var(--line); }
        .lab:last-child { border-bottom: 0; }
        .lab .who { flex: 1 1 170px; min-width: 0; }
        .lab .who b { display: block; }
        .lab .who small { color: var(--muted); }
        .pills { display: inline-flex; gap: 6px; }
        .pill { width: 44px; height: 44px; border-radius: 11px; border: 1.5px solid var(--line); background: var(--card); color: var(--muted); font-weight: 800; font-size: 15px; }
        .pill.on.present { background: var(--ok); border-color: var(--ok); color: #fff; }
        .pill.on.half_day { background: var(--warn); border-color: var(--warn); color: #fff; }
        .pill.on.absent { background: var(--bad); border-color: var(--bad); color: #fff; }
        .ot { width: 70px; padding: 9px 8px; text-align: center; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px; }
        .stats div { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 10px; font-size: 12px; color: var(--muted); }
        .stats b { display: block; font-size: 18px; color: var(--primary); }
        .note { font-size: 13px; padding: 10px 12px; border-radius: 10px; background: var(--primary-soft); margin-bottom: 12px; }
        .note.warn { background: #fef3c7; color: #92400e; }
        .note.bad { background: #fee2e2; color: #991b1b; }
        .thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 8px; margin-top: 8px; }
        .thumbs div { position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: var(--bg); }
        .thumbs img { width: 100%; height: 100%; object-fit: cover; }
        .thumbs button { position: absolute; top: 4px; right: 4px; width: 26px; height: 26px; border-radius: 50%; border: 0; background: rgba(0,0,0,.6); color: #fff; font-weight: 700; }
        .mp { display: grid; grid-template-columns: 1fr 80px 36px; gap: 8px; margin-bottom: 8px; }
        .mp button { border: 0; background: none; color: var(--muted); font-size: 20px; }
        .ob { display: flex; gap: 10px; align-items: flex-start; }
        .ob .grow { flex: 1; min-width: 0; }
        .badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--primary-soft); color: var(--primary); }
        .badge.bad { background: #fee2e2; color: #991b1b; }
        .muted { color: var(--muted); font-size: 13px; }
        .search-results { max-height: 260px; overflow: auto; border: 1px solid var(--line); border-radius: 10px; margin-top: 6px; }
        .search-results button { display: block; width: 100%; text-align: left; padding: 10px 12px; border: 0; border-bottom: 1px solid var(--line); background: var(--card); color: var(--text); font: inherit; }
        nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 5; display: grid; grid-template-columns: repeat(3, 1fr); background: var(--card); border-top: 1px solid var(--line); padding-bottom: env(safe-area-inset-bottom); }
        nav button { border: 0; background: none; color: var(--muted); font: inherit; font-size: 12px; font-weight: 700; padding: 10px 0 12px; display: flex; flex-direction: column; align-items: center; gap: 3px; }
        nav button.on { color: var(--primary); }
        nav svg { width: 22px; height: 22px; }
        nav .count { background: var(--bad); color: #fff; border-radius: 999px; font-size: 10px; padding: 0 6px; margin-left: 2px; }
        .toast { position: fixed; left: 50%; bottom: calc(90px + env(safe-area-inset-bottom)); transform: translateX(-50%); background: #111827; color: #fff; padding: 10px 16px; border-radius: 12px; font-size: 14px; z-index: 9; max-width: 90vw; box-shadow: 0 8px 24px rgba(0,0,0,.25); }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<header>
    <svg width="30" height="30" viewBox="0 0 34 34" fill="none" aria-hidden="true"><rect width="34" height="34" rx="9" fill="#4f46e5"/><path d="M9 25V13.5L17 8l8 5.5V25" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/><path d="M13.5 25v-6.5h7V25" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/></svg>
    <div>
        <div class="title">CivoraX <span>Site</span></div>
        <div class="sub" id="who">Loading…</div>
    </div>
    <div class="net" id="net"><i></i><span>Online</span></div>
</header>

<main>
    <div class="note bad" id="authNote" hidden>
        Your login has expired. Saved entries are safe on this phone.
        <a href="{{ route('site.app') }}">Log in again</a> to send them.
    </div>
    <div class="note" id="firstRun" hidden>Connect to the internet once to download your sites and labourers. After that this app works offline.</div>

    {{-- Attendance --}}
    <section id="tab-attendance">
        <div class="card">
            <div class="row2">
                <div><label class="f" for="aProject">Site</label><select id="aProject"></select></div>
                <div><label class="f" for="aDate">Date</label><input type="date" id="aDate"></div>
            </div>
        </div>
        <div class="note warn" id="aLocked" hidden>This day's muster roll is already submitted/approved. Changes will be refused.</div>
        <div class="stats">
            <div><b id="sHead">0</b>on site</div>
            <div><b id="sDays">0</b>man-days</div>
            <div><b id="sWage">0</b>wages (Rs)</div>
        </div>
        <div class="card">
            <h2>Labourers <span class="muted" id="aCount"></span></h2>
            <div id="aList"></div>
            <label class="f" for="aSearch">Add labourer</label>
            <input type="search" id="aSearch" placeholder="Search name, father's name…" autocomplete="off">
            <div class="search-results" id="aResults" hidden></div>
            <p class="muted">New labourers are added in the team panel (needs internet). Tap a letter again to clear it.</p>
        </div>
        <button class="btn full" id="aSave">Save attendance</button>
    </section>

    {{-- Daily report --}}
    <section id="tab-report" hidden>
        <div class="card">
            <div class="row2">
                <div><label class="f" for="rProject">Site</label><select id="rProject"></select></div>
                <div><label class="f" for="rDate">Date</label><input type="date" id="rDate"></div>
            </div>
            <label class="f" for="rWeather">Weather</label>
            <select id="rWeather"></select>
        </div>
        <div class="card">
            <h2>Manpower</h2>
            <div id="rManpower"></div>
            <button class="btn ghost small" id="rMpAdd" type="button">+ Add trade</button>
            <button class="btn ghost small" id="rMpFill" type="button">Fill from attendance</button>
        </div>
        <div class="card">
            <label class="f" for="rWork">Work done today *</label>
            <textarea id="rWork" placeholder="e.g. Ground floor column casting completed (C1–C12)."></textarea>
            <label class="f" for="rIssues">Problems / delays</label>
            <textarea id="rIssues" placeholder="Material shortage, rain, waiting for client decision…"></textarea>
            <label class="f" for="rPlan">Plan for tomorrow</label>
            <textarea id="rPlan"></textarea>
            <label class="f" for="rVisitors">Visitors / instructions received</label>
            <textarea id="rVisitors"></textarea>
        </div>
        <div class="card">
            <h2>Photos</h2>
            <input type="file" id="rPhotos" accept="image/*" capture="environment" multiple>
            <div class="thumbs" id="rThumbs"></div>
            <p class="muted">Photos are shrunk on the phone to save data. The client sees them after approval.</p>
        </div>
        <button class="btn full" id="rSubmit">Submit report</button>
    </section>

    {{-- Outbox --}}
    <section id="tab-outbox" hidden>
        <div class="card">
            <h2>Waiting to send</h2>
            <p class="muted" id="oInfo"></p>
            <div id="oList"></div>
        </div>
        <button class="btn full" id="oSync">Send now</button>
        <p class="muted" style="text-align:center;margin-top:14px;">
            <a href="{{ url('/team') }}">Open full team panel</a>
        </p>
    </section>
</main>

<nav>
    <button data-tab="attendance" class="on"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>Attendance</button>
    <button data-tab="report"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z"/></svg>Daily report</button>
    <button data-tab="outbox"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"/></svg><span>Outbox<span class="count" id="oCount" hidden></span></span></button>
</nav>

<div class="toast" id="toast" hidden></div>

<script>
(() => {
    const URLS = {
        data: @json(route('site.app.data')),
        attendance: @json(route('site.app.attendance')),
        reports: @json(route('site.app.reports')),
        worker: @json(asset('site-sw.js')),
        scope: @json(url('/site/')),
    };
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const uuid = () => crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); });
    const money = (n) => Math.round(n).toLocaleString('en-IN');

    // ── Storage (IndexedDB keeps data, queued entries and photos while offline) ──
    const dbReady = new Promise((resolve, reject) => {
        const open = indexedDB.open('civorax-site', 1);
        open.onupgradeneeded = () => {
            open.result.createObjectStore('kv');
            open.result.createObjectStore('outbox', { keyPath: 'id' });
        };
        open.onsuccess = () => resolve(open.result);
        open.onerror = () => reject(open.error);
    });
    const store = async (name, mode, work) => {
        const db = await dbReady;
        return new Promise((resolve, reject) => {
            const tx = db.transaction(name, mode);
            const request = work(tx.objectStore(name));
            tx.oncomplete = () => resolve(request ? request.result : undefined);
            tx.onerror = () => reject(tx.error);
        });
    };
    const kvGet = (key) => store('kv', 'readonly', (s) => s.get(key));
    const kvSet = (key, value) => store('kv', 'readwrite', (s) => s.put(value, key));
    const outboxAll = () => store('outbox', 'readonly', (s) => s.getAll());
    const outboxPut = (item) => store('outbox', 'readwrite', (s) => s.put(item));
    const outboxDelete = (id) => store('outbox', 'readwrite', (s) => s.delete(id));

    let data = null;
    let rows = {};          // attendance being edited: labourerId => { status, overtime_hours }
    let dirty = false;      // unsaved attendance changes: background refreshes must not wipe them
    let photos = [];        // report photos: { blob, url }
    let syncing = false;

    const toast = (message) => {
        const el = $('toast');
        el.textContent = message;
        el.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => (el.hidden = true), 3200);
    };

    // ── Online status ──
    const renderNet = () => {
        const on = navigator.onLine;
        $('net').classList.toggle('off', !on);
        $('net').querySelector('span').textContent = on ? 'Online' : 'Offline';
    };
    addEventListener('online', () => { renderNet(); sync(); });
    addEventListener('offline', renderNet);

    // ── Tabs ──
    document.querySelectorAll('nav button').forEach((button) => button.addEventListener('click', () => {
        document.querySelectorAll('nav button').forEach((b) => b.classList.toggle('on', b === button));
        ['attendance', 'report', 'outbox'].forEach((tab) => ($('tab-' + tab).hidden = tab !== button.dataset.tab));
        if (button.dataset.tab === 'outbox') renderOutbox();
        if (button.dataset.tab === 'report') prefillManpower(false);
    }));

    // ── Data ──
    const labourer = (id) => data?.labourers.find((l) => l.id === Number(id));
    const project = (id) => data?.projects.find((p) => p.id === Number(id));
    const today = () => data?.today ?? new Date().toISOString().slice(0, 10);

    const fillSelects = () => {
        const options = (data?.projects ?? []).map((p) => `<option value="${p.id}">${esc(p.title)}</option>`).join('');
        for (const id of ['aProject', 'rProject']) {
            const keep = $(id).value;
            $(id).innerHTML = options || '<option value="">No sites assigned to you</option>';
            if (keep && project(keep)) $(id).value = keep;
        }
        const weather = $('rWeather').value;
        $('rWeather').innerHTML = Object.entries(data?.weather ?? {}).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
        if (weather) $('rWeather').value = weather;
        for (const id of ['aDate', 'rDate']) {
            $(id).max = today();
            if (!$(id).value) $(id).value = today();
        }
        $('who').textContent = data ? `${data.user} · ${data.today_bs ?? ''}` : 'Not downloaded yet';
        $('firstRun').hidden = !!data;
    };

    async function refreshData() {
        const response = await fetch(URLS.data, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (response.status === 401 || response.status === 403 || response.redirected) {
            $('authNote').hidden = false;
            throw new Error('auth');
        }
        if (!response.ok) throw new Error('server');
        data = await response.json();
        $('authNote').hidden = true;
        await kvSet('data', data);
        fillSelects();
        if (!dirty) loadAttendance();
    }

    // ── Attendance ──
    const attendanceKey = () => `${$('aProject').value}|${$('aDate').value}`;

    async function loadAttendance() {
        if (!data) return;
        const pending = (await outboxAll()).filter((i) => i.kind === 'attendance' && i.key === attendanceKey()).pop();
        const saved = pending?.payload.rows ?? data.attendance?.[attendanceKey()] ?? {};
        rows = {};
        dirty = false;
        const crew = project($('aProject').value)?.crew ?? [];
        for (const id of [...Object.keys(saved).map(Number), ...crew]) {
            if (!labourer(id) || rows[id]) continue;
            rows[id] = { status: saved[id]?.status ?? null, overtime_hours: saved[id]?.overtime_hours ?? 0 };
        }
        const lockedUntil = project($('aProject').value)?.locked_until;
        $('aLocked').hidden = !(lockedUntil && $('aDate').value <= lockedUntil.slice(0, 10));
        renderAttendance();
    }

    function renderAttendance() {
        const list = Object.keys(rows).map(Number).map(labourer).filter(Boolean).sort((a, b) => (a.type + a.name).localeCompare(b.type + b.name));
        $('aCount').textContent = list.length ? `(${list.length})` : '';
        $('aList').innerHTML = list.length ? list.map((l) => {
            const r = rows[l.id];
            const pill = (value, code) => `<button type="button" class="pill ${value} ${r.status === value ? 'on' : ''}" data-id="${l.id}" data-status="${value}">${code}</button>`;
            return `<div class="lab">
                <div class="who"><b>${esc(l.name)}</b><small>${esc(l.type)} · Rs ${money(l.wage)}/day${l.father ? ' · s/o ' + esc(l.father) : ''}</small></div>
                <div class="pills">${pill('present', 'P')}${pill('half_day', 'H')}${pill('absent', 'A')}</div>
                <input class="ot" type="number" min="0" max="16" step="0.5" inputmode="decimal" placeholder="OT h" data-ot="${l.id}" value="${r.overtime_hours || ''}" ${r.status && r.status !== 'absent' ? '' : 'disabled'}>
            </div>`;
        }).join('') : '<p class="muted">No labourers yet. Search below to add the crew working here.</p>';
        renderSummary();
    }

    function renderSummary() {
        let head = 0, days = 0, wages = 0;
        for (const [id, r] of Object.entries(rows)) {
            const l = labourer(id);
            if (!l || !r.status) continue;
            const factor = r.status === 'present' ? 1 : r.status === 'half_day' ? 0.5 : 0;
            if (r.status !== 'absent') head++;
            days += factor;
            wages += l.wage * factor + (Number(r.overtime_hours) || 0) * l.wage / (data?.hours_per_day || 8);
        }
        $('sHead').textContent = head;
        $('sDays').textContent = days;
        $('sWage').textContent = money(wages);
    }

    $('aList').addEventListener('click', (event) => {
        const button = event.target.closest('.pill');
        if (!button) return;
        const r = rows[button.dataset.id];
        r.status = r.status === button.dataset.status ? null : button.dataset.status;
        if (r.status === 'absent' || !r.status) r.overtime_hours = 0;
        dirty = true;
        renderAttendance();
    });
    $('aList').addEventListener('input', (event) => {
        if (event.target.dataset.ot) {
            dirty = true;
            rows[event.target.dataset.ot].overtime_hours = Math.min(16, Math.max(0, Number(event.target.value) || 0));
            renderSummary();
        }
    });
    $('aProject').addEventListener('change', loadAttendance);
    $('aDate').addEventListener('change', loadAttendance);

    $('aSearch').addEventListener('input', () => {
        const q = $('aSearch').value.trim().toLowerCase();
        const matches = q.length < 2 ? [] : (data?.labourers ?? [])
            .filter((l) => !rows[l.id] && (l.name + ' ' + (l.father ?? '')).toLowerCase().includes(q))
            .slice(0, 20);
        $('aResults').hidden = !matches.length;
        $('aResults').innerHTML = matches.map((l) => `<button type="button" data-add="${l.id}"><b>${esc(l.name)}</b> <span class="muted">${esc(l.type)}${l.father ? ' · s/o ' + esc(l.father) : ''}</span></button>`).join('');
    });
    $('aResults').addEventListener('click', (event) => {
        const button = event.target.closest('[data-add]');
        if (!button) return;
        rows[button.dataset.add] = { status: 'present', overtime_hours: 0 };
        dirty = true;
        $('aSearch').value = '';
        $('aResults').hidden = true;
        renderAttendance();
    });

    $('aSave').addEventListener('click', async () => {
        if (!$('aProject').value) return toast('Choose a site first.');
        const marked = Object.fromEntries(Object.entries(rows).map(([id, r]) => [id, { status: r.status, overtime_hours: Number(r.overtime_hours) || 0 }]));
        if (!Object.values(marked).some((r) => r.status)) return toast('Mark at least one labourer.');
        const key = attendanceKey();
        // One queued entry per site and day: saving again replaces the unsent one.
        for (const item of await outboxAll()) {
            if (item.kind === 'attendance' && item.key === key) await outboxDelete(item.id);
        }
        await outboxPut({
            id: uuid(), kind: 'attendance', key, createdAt: Date.now(),
            title: `Attendance · ${project($('aProject').value)?.title} · ${$('aDate').value}`,
            payload: { project_id: Number($('aProject').value), date: $('aDate').value, rows: marked },
        });
        if (data) {
            data.attendance = data.attendance || {};
            data.attendance[key] = marked;
            await kvSet('data', data);
        }
        dirty = false;
        toast(navigator.onLine ? 'Saved. Sending…' : 'Saved on this phone. It will be sent when you are online.');
        updateCount();
        sync();
    });

    // ── Daily report ──
    const manpowerRow = (trade = '', count = '') => {
        const div = document.createElement('div');
        div.className = 'mp';
        div.innerHTML = `<input placeholder="Trade" list="trades" value="${esc(trade)}"><input type="number" min="0" inputmode="numeric" placeholder="No." value="${esc(count)}"><button type="button" aria-label="Remove">×</button>`;
        div.querySelector('button').onclick = () => div.remove();
        $('rManpower').appendChild(div);
    };
    function prefillManpower(force) {
        if (!force && $('rManpower').children.length) return;
        $('rManpower').innerHTML = '';
        const marks = data?.attendance?.[`${$('rProject').value}|${$('rDate').value}`] ?? {};
        const counts = {};
        for (const [id, r] of Object.entries(marks)) {
            if (!r.status || r.status === 'absent') continue;
            const type = labourer(id)?.type ?? 'Labour';
            counts[type] = (counts[type] || 0) + 1;
        }
        Object.entries(counts).forEach(([trade, count]) => manpowerRow(trade, count));
        if (!Object.keys(counts).length) manpowerRow();
    }
    $('rMpAdd').onclick = () => manpowerRow();
    $('rMpFill').onclick = () => prefillManpower(true);
    $('rProject').addEventListener('change', () => prefillManpower(true));
    $('rDate').addEventListener('change', () => prefillManpower(true));

    async function shrink(file) {
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            return await new Promise((resolve) => canvas.toBlob((blob) => resolve(blob || file), 'image/jpeg', 0.8));
        } catch {
            return file;
        }
    }
    $('rPhotos').addEventListener('change', async () => {
        for (const file of $('rPhotos').files) {
            if (photos.length >= 15) { toast('Up to 15 photos per report.'); break; }
            const blob = await shrink(file);
            photos.push({ blob, url: URL.createObjectURL(blob) });
        }
        $('rPhotos').value = '';
        renderThumbs();
    });
    function renderThumbs() {
        $('rThumbs').innerHTML = photos.map((p, i) => `<div><img src="${p.url}" alt="Photo ${i + 1}"><button type="button" data-remove="${i}" aria-label="Remove photo">×</button></div>`).join('');
    }
    $('rThumbs').addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove]');
        if (!button) return;
        URL.revokeObjectURL(photos[button.dataset.remove].url);
        photos.splice(Number(button.dataset.remove), 1);
        renderThumbs();
    });

    const position = () => new Promise((resolve) => {
        if (!navigator.geolocation) return resolve(null);
        navigator.geolocation.getCurrentPosition((p) => resolve(p.coords), () => resolve(null), { enableHighAccuracy: true, timeout: 8000, maximumAge: 120000 });
    });

    $('rSubmit').addEventListener('click', async () => {
        if (!$('rProject').value) return toast('Choose a site first.');
        if (!$('rWork').value.trim()) return toast('Write what work was done today.');
        $('rSubmit').disabled = true;
        const coords = await position();
        const manpower = [...$('rManpower').children]
            .map((row) => ({ trade: row.children[0].value.trim(), count: Number(row.children[1].value) || 0 }))
            .filter((m) => m.trade);
        await outboxPut({
            id: uuid(), kind: 'report', createdAt: Date.now(),
            title: `Report · ${project($('rProject').value)?.title} · ${$('rDate').value}`,
            payload: {
                client_uuid: uuid(), project_id: Number($('rProject').value), date: $('rDate').value,
                weather: $('rWeather').value, work_done: $('rWork').value.trim(), issues: $('rIssues').value.trim(),
                next_day_plan: $('rPlan').value.trim(), visitors: $('rVisitors').value.trim(), manpower,
                latitude: coords?.latitude ?? null, longitude: coords?.longitude ?? null,
            },
            photos: photos.map((p) => p.blob),
        });
        for (const id of ['rWork', 'rIssues', 'rPlan', 'rVisitors']) $(id).value = '';
        photos.forEach((p) => URL.revokeObjectURL(p.url));
        photos = [];
        renderThumbs();
        $('rManpower').innerHTML = '';
        $('rSubmit').disabled = false;
        toast(navigator.onLine ? 'Report saved. Sending…' : 'Report saved on this phone. It will be sent when you are online.');
        updateCount();
        sync();
    });

    // ── Outbox & sync ──
    async function updateCount() {
        const count = (await outboxAll()).length;
        $('oCount').hidden = !count;
        $('oCount').textContent = count;
    }

    async function renderOutbox() {
        const items = (await outboxAll()).sort((a, b) => a.createdAt - b.createdAt);
        $('oInfo').textContent = items.length
            ? `${items.length} waiting. They are sent automatically when the phone is online.`
            : `Everything is sent.${data?.synced_at ? ' Last update from the office: ' + new Date(data.synced_at).toLocaleString() : ''}`;
        $('oList').innerHTML = items.map((item) => `<div class="lab ob">
            <div class="grow"><b>${esc(item.title)}</b>
                <div class="muted">Saved ${new Date(item.createdAt).toLocaleString()}${item.photos?.length ? ' · ' + item.photos.length + ' photos' : ''}</div>
                ${item.error ? `<span class="badge bad">Not accepted</span> <span class="muted">${esc(item.error)}</span>` : '<span class="badge">Waiting</span>'}
            </div>
            <button class="btn danger small" data-discard="${item.id}">Discard</button>
        </div>`).join('');
        updateCount();
    }
    $('oList').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-discard]');
        if (!button || !confirm('Discard this entry? It has not been sent.')) return;
        await outboxDelete(button.dataset.discard);
        renderOutbox();
    });
    $('oSync').addEventListener('click', () => sync(true));

    async function send(item) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': data.csrf, 'X-Requested-With': 'XMLHttpRequest' };
        if (item.kind === 'attendance') {
            return fetch(URLS.attendance, { method: 'POST', credentials: 'same-origin', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(item.payload) });
        }
        const form = new FormData();
        for (const [key, value] of Object.entries(item.payload)) {
            if (key === 'manpower') {
                value.forEach((m, i) => { form.append(`manpower[${i}][trade]`, m.trade); form.append(`manpower[${i}][count]`, m.count); });
            } else if (value !== null && value !== '') {
                form.append(key, value);
            }
        }
        (item.photos ?? []).forEach((blob, i) => form.append('photos[]', blob, `photo-${i + 1}.jpg`));
        return fetch(URLS.reports, { method: 'POST', credentials: 'same-origin', headers, body: form });
    }

    async function sync(manual = false) {
        if (syncing || !navigator.onLine) {
            if (manual && !navigator.onLine) toast('No internet. Entries stay saved on this phone.');
            return;
        }
        syncing = true;
        try {
            await refreshData(); // also renews the security token
            const items = (await outboxAll()).sort((a, b) => a.createdAt - b.createdAt);
            let sent = 0;
            for (const item of items) {
                const response = await send(item);
                if (response.ok) {
                    await outboxDelete(item.id);
                    sent++;
                } else if (response.status === 422) {
                    const body = await response.json().catch(() => ({}));
                    item.error = Object.values(body.errors ?? {}).flat().join(' ') || body.message || 'Rejected';
                    await outboxPut(item);
                } else if ([401, 403, 419].includes(response.status)) {
                    $('authNote').hidden = false;
                    break;
                } else {
                    break; // server hiccup: try again later
                }
            }
            if (sent) {
                toast(`Sent ${sent} ${sent === 1 ? 'entry' : 'entries'} to the office.`);
                await refreshData();
            } else if (manual) {
                toast(items.length ? 'Some entries need attention. See the notes below.' : 'Everything is up to date.');
            }
        } catch (error) {
            if (manual && error.message !== 'auth') toast('Could not reach the office. Will retry automatically.');
        } finally {
            syncing = false;
            renderOutbox();
        }
    }

    // ── Start ──
    (async () => {
        renderNet();
        data = await kvGet('data') ?? null;
        fillSelects();
        loadAttendance();
        updateCount();
        document.body.insertAdjacentHTML('beforeend', `<datalist id="trades">${Object.values(data?.work_types ?? {}).map((t) => `<option value="${esc(t)}">`).join('')}</datalist>`);
        sync();
        setInterval(sync, 60000);
        navigator.storage?.persist?.();
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(URLS.worker, { scope: URLS.scope }).catch(() => {});
        }
    })();
})();
</script>
</body>
</html>
