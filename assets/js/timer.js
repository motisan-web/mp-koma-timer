/**
 * コマタイマー — timer.js
 * Manages all timer UI logic client-side.
 */
'use strict';

const CFG = window.KOMA_CONFIG;

// --- Utilities ---

function fmt(sec) {
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

async function apiCall(action, params = {}) {
    try {
        const res = await fetch('/api/timer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...params }),
        });
        return await res.json();
    } catch (e) {
        console.error('apiCall error', e);
        return { ok: false, error: e.message };
    }
}

// --- State ---
// Today's komas: slot -> koma object
const komaState = {};
// Tick intervals: slot -> intervalId
const tickIntervals = {};
// 80/100min notification tracking: slot -> { '80': bool, '100': bool }
const notified = {};

// Prev-incomplete komas: key `date:slot` -> { date, koma }
const prevState = {};
const prevTicks = {};

// --- Status helpers ---

const STATUS_LABEL = {
    idle:         '未開始',
    running:      '実行中',
    paused:       '一時停止',
    overtime:     '超過中',
    overtime_max: '超過完了',
    completed:    '完了',
    closed:       '中止',
    auto_closed:  '自動中止',
};

const STATUS_CLASS = {
    running:      'is-running',
    paused:       'is-paused',
    overtime:     'is-overtime',
    overtime_max: 'is-overtime-max',
    completed:    'is-completed',
    closed:       'is-completed',
    auto_closed:  'is-completed',
};

function isDone(status) {
    return ['completed', 'overtime_max', 'closed', 'auto_closed'].includes(status);
}

// --- Live elapsed ---

function liveElapsed(k) {
    if (!k || !k.segments) return 0;
    let total = 0;
    const now = Date.now();
    for (const seg of k.segments) {
        const start = new Date(seg.start).getTime();
        const end   = seg.end ? new Date(seg.end).getTime() : now;
        total += Math.max(0, Math.floor((end - start) / 1000));
    }
    return total;
}

// ================================================================
// TODAY'S KOMAS
// ================================================================

function initFromState(session) {
    if (!session || !session.koma) return;
    for (const k of session.koma) {
        komaState[k.id] = k;
        notified[k.id]  = { '80': false, '100': false };
    }
    for (let slot = 1; slot <= CFG.komaCount; slot++) {
        renderKoma(slot);
        const k = komaState[slot];
        if (k && (k.status === 'running' || k.status === 'overtime')) {
            startTick(slot);
        }
    }
}

function renderKoma(slot) {
    const k      = komaState[slot];
    const status = k ? k.status : 'idle';

    const card        = document.getElementById(`koma-card-${slot}`);
    const badge       = document.getElementById(`koma-status-${slot}`);
    const fill        = document.getElementById(`koma-fill-${slot}`);
    const pct         = document.getElementById(`koma-pct-${slot}`);
    const overtimeLbl = document.getElementById(`koma-overtime-${slot}`);
    const elapsedEl   = document.getElementById(`koma-elapsed-${slot}`);
    const remainingEl = document.getElementById(`koma-remaining-${slot}`);
    const btnStart    = document.getElementById(`btn-start-${slot}`);
    const btnPause    = document.getElementById(`btn-pause-${slot}`);
    const btnComplete = document.getElementById(`btn-complete-${slot}`);
    const nameInput   = document.getElementById(`koma-name-${slot}`);
    const projInput   = document.getElementById(`koma-project-${slot}`);
    const breakLabel  = document.getElementById(`koma-break-label-${slot}`);
    const breakChk    = document.getElementById(`koma-break-${slot}`);

    if (k) {
        if (document.activeElement !== nameInput) nameInput.value = k.name || '';
        if (document.activeElement !== projInput)  projInput.value = k.project_id || '';
        if (breakChk)    breakChk.checked = !!k.break_after;
        if (breakLabel)  breakLabel.classList.toggle('is-checked', !!k.break_after);
    }

    const elapsed   = k ? liveElapsed(k) : 0;
    const remaining = Math.max(0, CFG.komaDurationSec - elapsed);
    const overSec   = Math.max(0, elapsed - CFG.komaDurationSec);
    const progress  = Math.min(100, Math.round(elapsed / CFG.komaDurationSec * 100));

    elapsedEl.textContent   = fmt(elapsed);
    remainingEl.textContent = remaining > 0 ? fmt(remaining) : `-${fmt(overSec)}`;
    remainingEl.classList.toggle('is-negative', remaining === 0 && elapsed > 0);
    fill.style.width  = progress + '%';
    pct.textContent   = progress + '%';

    if (overSec > 0) {
        overtimeLbl.style.display = '';
        overtimeLbl.textContent   = `+${Math.floor(overSec / 60)}分超過`;
    } else {
        overtimeLbl.style.display = 'none';
    }

    badge.textContent = STATUS_LABEL[status] || status;
    badge.className   = `koma-card__status-badge ${status}`;
    card.className    = 'koma-card ' + (STATUS_CLASS[status] || '');

    const isRunning = status === 'running' || status === 'overtime';
    const done      = isDone(status);

    btnStart.style.display  = isRunning ? 'none' : '';
    btnPause.style.display  = isRunning ? '' : 'none';
    btnStart.disabled       = done;
    btnComplete.disabled    = done;
}

function startTick(slot) {
    if (tickIntervals[slot]) return;
    tickIntervals[slot] = setInterval(() => tick(slot), 1000);
}

function stopTick(slot) {
    clearInterval(tickIntervals[slot]);
    delete tickIntervals[slot];
}

function tick(slot) {
    const k = komaState[slot];
    if (!k) return;

    const elapsed = liveElapsed(k);
    notified[slot] = notified[slot] || {};

    if (elapsed >= CFG.komaDurationSec && !notified[slot]['80']) {
        notified[slot]['80'] = true;
        apiCall('notify_80min', { slot });
        k.status = 'overtime';
    }

    if (elapsed >= CFG.maxDurationSec && !notified[slot]['100']) {
        notified[slot]['100'] = true;
        apiCall('notify_100min', { slot }).then(res => {
            komaState[slot] = res.ok && res.koma ? res.koma : { ...k, status: 'completed' };
            stopTick(slot);
            renderKoma(slot);
        });
        return;
    }

    renderKoma(slot);
}

async function doStart(slot) {
    const res = await apiCall('start', { slot });
    if (!res.ok) { console.error('start failed', res.error); return; }
    komaState[slot] = res.koma;
    notified[slot]  = notified[slot] || { '80': false, '100': false };
    startTick(slot);
    renderKoma(slot);
}

async function doPause(slot) {
    const res = await apiCall('pause', { slot });
    if (!res.ok) { console.error('pause failed', res.error); return; }
    komaState[slot] = res.koma;
    stopTick(slot);
    renderKoma(slot);
}

async function doComplete(slot) {
    const res = await apiCall('complete', { slot });
    if (!res.ok) { console.error('complete failed', res.error); return; }
    komaState[slot] = res.koma;
    stopTick(slot);
    renderKoma(slot);
}

async function doUpdateMeta(slot, name, projectId) {
    const params = { slot };
    if (name      !== undefined) params.name       = name;
    if (projectId !== undefined) params.project_id = projectId;
    const res = await apiCall('update_meta', params);
    if (res.ok && res.koma) {
        komaState[slot] = res.koma;
        refreshProjectHistory(res.koma.project_id);
    }
}

async function doSetBreak(slot, value) {
    const res = await apiCall('set_break', { slot, value });
    if (res.ok && res.koma) { komaState[slot] = res.koma; renderKoma(slot); }
}

// ================================================================
// PREV INCOMPLETE KOMAS
// ================================================================

function prevKey(date, slot) { return `${date}:${slot}`; }

function initPrevIncomplete(items) {
    if (!items || items.length === 0) return;

    const area = document.getElementById('prev-incomplete-area');
    area.innerHTML = `
        <div class="prev-incomplete-area">
            <div class="prev-incomplete-area__heading">前日の未完了コマ</div>
            <div class="prev-incomplete-grid" id="prev-incomplete-grid"></div>
        </div>
    `;
    const grid = document.getElementById('prev-incomplete-grid');

    for (const item of items) {
        const { date, koma: k } = item;
        const key  = prevKey(date, k.id);
        prevState[key] = { date, koma: k };

        const card = buildPrevCard(date, k);
        grid.appendChild(card);

        if (k.status === 'running' || k.status === 'overtime') {
            startPrevTick(key);
        }
    }
}

function buildPrevCard(date, k) {
    const key    = prevKey(date, k.id);
    const card   = document.createElement('div');
    card.className = 'koma-card koma-card--prev is-paused';
    card.id      = `prev-card-${key}`;

    // Start time label
    const startTime = k.segments && k.segments[0]
        ? new Date(k.segments[0].start).toLocaleString('ja-JP', { month:'numeric', day:'numeric', hour:'2-digit', minute:'2-digit' })
        : date;

    card.innerHTML = `
        <div class="koma-card__date-label">${date} 開始 ${startTime}</div>
        <div class="koma-card__header">
            <span class="koma-card__slot">コマ ${k.id}</span>
            <span class="koma-card__status-badge ${k.status}" id="prev-badge-${key}">${STATUS_LABEL[k.status] || k.status}</span>
        </div>
        <input type="text" class="koma-card__name-input" id="prev-name-${key}"
               value="${escHtml(k.name || '')}" placeholder="作業内容"
               data-key="${key}">
        <input type="text" class="koma-card__project-input" id="prev-project-${key}"
               value="${escHtml(k.project_id || '')}" placeholder="#project/"
               list="project-history-list" data-key="${key}">
        <div class="koma-card__progress-wrap">
            <div class="koma-card__progress-bar">
                <div class="koma-card__progress-fill" id="prev-fill-${key}" style="width:0%"></div>
            </div>
            <div class="koma-card__progress-labels">
                <span class="koma-card__progress-pct" id="prev-pct-${key}">0%</span>
                <span class="koma-card__overtime-label" id="prev-overtime-${key}" style="display:none"></span>
            </div>
        </div>
        <div class="koma-card__time">
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">経過</div>
                <div class="koma-card__time-elapsed" id="prev-elapsed-${key}">0:00:00</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">残り</div>
                <div class="koma-card__time-remaining" id="prev-remaining-${key}">-</div>
            </div>
        </div>
        <div class="koma-card__actions">
            <button class="btn btn-start"    id="prev-btn-start-${key}"    data-key="${key}">再開</button>
            <button class="btn btn-pause"    id="prev-btn-pause-${key}"    data-key="${key}" style="display:none">停止</button>
            <button class="btn btn-complete" id="prev-btn-complete-${key}" data-key="${key}">完了</button>
            <button class="btn btn-secondary" id="prev-btn-close-${key}"  data-key="${key}" style="padding:6px 10px;">中止</button>
        </div>
    `;

    // Bind events
    card.querySelector(`#prev-btn-start-${key}`)
        .addEventListener('click', () => doPrevStart(key));
    card.querySelector(`#prev-btn-pause-${key}`)
        .addEventListener('click', () => doPrevPause(key));
    card.querySelector(`#prev-btn-complete-${key}`)
        .addEventListener('click', () => doPrevComplete(key));
    card.querySelector(`#prev-btn-close-${key}`)
        .addEventListener('click', () => doPrevClose(key));

    // Meta save
    const nameInp = card.querySelector(`#prev-name-${key}`);
    const projInp = card.querySelector(`#prev-project-${key}`);
    bindMetaSave(nameInp, key, 'name');
    bindMetaSave(projInp, key, 'project');

    renderPrevKoma(key);
    return card;
}

function renderPrevKoma(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const status = k.status;

    const badge       = document.getElementById(`prev-badge-${key}`);
    const fill        = document.getElementById(`prev-fill-${key}`);
    const pct         = document.getElementById(`prev-pct-${key}`);
    const overtimeLbl = document.getElementById(`prev-overtime-${key}`);
    const elapsedEl   = document.getElementById(`prev-elapsed-${key}`);
    const remainingEl = document.getElementById(`prev-remaining-${key}`);
    const btnStart    = document.getElementById(`prev-btn-start-${key}`);
    const btnPause    = document.getElementById(`prev-btn-pause-${key}`);
    const btnComplete = document.getElementById(`prev-btn-complete-${key}`);
    const btnClose    = document.getElementById(`prev-btn-close-${key}`);
    const card        = document.getElementById(`prev-card-${key}`);

    if (!badge) return;

    const elapsed   = liveElapsed(k);
    const remaining = Math.max(0, CFG.komaDurationSec - elapsed);
    const overSec   = Math.max(0, elapsed - CFG.komaDurationSec);
    const progress  = Math.min(100, Math.round(elapsed / CFG.komaDurationSec * 100));

    elapsedEl.textContent   = fmt(elapsed);
    remainingEl.textContent = remaining > 0 ? fmt(remaining) : `-${fmt(overSec)}`;
    remainingEl.classList.toggle('is-negative', remaining === 0);
    fill.style.width = progress + '%';
    pct.textContent  = progress + '%';

    if (overSec > 0) {
        overtimeLbl.style.display = '';
        overtimeLbl.textContent   = `+${Math.floor(overSec / 60)}分超過`;
    } else {
        overtimeLbl.style.display = 'none';
    }

    badge.textContent = STATUS_LABEL[status] || status;
    badge.className   = `koma-card__status-badge ${status}`;
    card.className    = 'koma-card koma-card--prev ' + (STATUS_CLASS[status] || '');

    const isRunning = status === 'running' || status === 'overtime';
    const done      = isDone(status);

    btnStart.style.display  = isRunning ? 'none' : '';
    btnPause.style.display  = isRunning ? '' : 'none';
    btnStart.disabled       = done;
    btnComplete.disabled    = done;
    btnClose.disabled       = done;
}

function startPrevTick(key) {
    if (prevTicks[key]) return;
    prevTicks[key] = setInterval(() => {
        renderPrevKoma(key);
    }, 1000);
}

function stopPrevTick(key) {
    clearInterval(prevTicks[key]);
    delete prevTicks[key];
}

function removePrevCard(key) {
    stopPrevTick(key);
    const card = document.getElementById(`prev-card-${key}`);
    if (card) card.remove();
    delete prevState[key];

    // Hide entire area if no cards left
    const grid = document.getElementById('prev-incomplete-grid');
    if (grid && grid.children.length === 0) {
        const area = document.getElementById('prev-incomplete-area');
        if (area) area.innerHTML = '';
    }
}

async function doPrevStart(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const res = await apiCall('start', { slot: k.id, date });
    if (!res.ok) { console.error('prev start failed', res.error); return; }
    entry.koma = res.koma;
    startPrevTick(key);
    renderPrevKoma(key);
}

async function doPrevPause(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const res = await apiCall('pause', { slot: k.id, date });
    if (!res.ok) { console.error('prev pause failed', res.error); return; }
    entry.koma = res.koma;
    stopPrevTick(key);
    renderPrevKoma(key);
}

async function doPrevComplete(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const res = await apiCall('complete', { slot: k.id, date });
    if (!res.ok) { console.error('prev complete failed', res.error); return; }
    entry.koma = res.koma;
    stopPrevTick(key);
    renderPrevKoma(key);
    setTimeout(() => removePrevCard(key), 1500);
}

async function doPrevClose(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const res = await apiCall('close', { slot: k.id, date });
    if (!res.ok) { console.error('prev close failed', res.error); return; }
    entry.koma = res.koma;
    stopPrevTick(key);
    renderPrevKoma(key);
    setTimeout(() => removePrevCard(key), 1500);
}

function bindMetaSave(input, key, field) {
    if (!input) return;
    let t = null;
    const save = async () => {
        const entry = prevState[key];
        if (!entry) return;
        const params = { slot: entry.koma.id, date: entry.date };
        if (field === 'name')    params.name       = input.value;
        if (field === 'project') params.project_id = input.value;
        const res = await apiCall('update_meta', params);
        if (res.ok && res.koma) {
            entry.koma = res.koma;
            refreshProjectHistory(res.koma.project_id);
        }
    };
    input.addEventListener('blur', save);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); input.blur(); } });
    input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 800); });
}

// ================================================================
// PROJECT HISTORY DATALIST
// ================================================================

function refreshProjectHistory(newProject) {
    if (!newProject) return;
    const dl = document.getElementById('project-history-list');
    if (!dl) return;
    for (const opt of dl.options) {
        if (opt.value === newProject) return;
    }
    const opt = document.createElement('option');
    opt.value = newProject;
    dl.prepend(opt);
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ================================================================
// EVENT BINDING (TODAY'S KOMAS)
// ================================================================

function bindEvents() {
    document.querySelectorAll('.btn-start').forEach(btn => {
        btn.addEventListener('click', () => doStart(+btn.dataset.slot));
    });
    document.querySelectorAll('.btn-pause').forEach(btn => {
        btn.addEventListener('click', () => doPause(+btn.dataset.slot));
    });
    document.querySelectorAll('.btn-complete').forEach(btn => {
        btn.addEventListener('click', () => doComplete(+btn.dataset.slot));
    });

    document.querySelectorAll('.koma-card__name-input').forEach(input => {
        let t = null;
        const save = () => doUpdateMeta(+input.dataset.slot, input.value, undefined);
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); input.blur(); } });
        input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 800); });
    });

    document.querySelectorAll('.koma-card__project-input').forEach(input => {
        let t = null;
        const save = () => doUpdateMeta(+input.dataset.slot, undefined, input.value);
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); input.blur(); } });
        input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 800); });
    });

    document.querySelectorAll('input[id^="koma-break-"]').forEach(chk => {
        chk.addEventListener('change', () => doSetBreak(+chk.dataset.slot, chk.checked));
    });
}

// ================================================================
// BOOT
// ================================================================

document.addEventListener('DOMContentLoaded', () => {
    initFromState(CFG.initialState);
    initPrevIncomplete(CFG.prevIncomplete);
    bindEvents();
});
