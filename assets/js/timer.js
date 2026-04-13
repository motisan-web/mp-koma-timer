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

function fmtMin(sec) {
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    if (h > 0) return `${h}時間${m}分`;
    return `${m}分`;
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
// Map of slot -> { status, segments, total_seconds, overtime_seconds, ... }
const komaState = {};

// Timers
const tickIntervals = {};

// Hook notification tracking (so we fire 80min/100min only once per session)
const notified = {}; // slot -> { '80': bool, '100': bool }

// --- Init ---

function initFromState(session) {
    if (!session || !session.koma) return;
    for (const k of session.koma) {
        komaState[k.id] = k;
        notified[k.id] = notified[k.id] || { '80': false, '100': false };
    }
    for (let slot = 1; slot <= CFG.komaCount; slot++) {
        renderKoma(slot);
        if (komaState[slot] && komaState[slot].status === 'running') {
            startTick(slot);
        }
    }
}

// --- Render ---

function renderKoma(slot) {
    const k = komaState[slot];
    const status = k ? k.status : 'idle';

    const card          = document.getElementById(`koma-card-${slot}`);
    const badge         = document.getElementById(`koma-status-${slot}`);
    const fill          = document.getElementById(`koma-fill-${slot}`);
    const pct           = document.getElementById(`koma-pct-${slot}`);
    const overtimeLbl   = document.getElementById(`koma-overtime-${slot}`);
    const elapsedEl     = document.getElementById(`koma-elapsed-${slot}`);
    const remainingEl   = document.getElementById(`koma-remaining-${slot}`);
    const btnStart      = document.getElementById(`btn-start-${slot}`);
    const btnPause      = document.getElementById(`btn-pause-${slot}`);
    const btnComplete   = document.getElementById(`btn-complete-${slot}`);
    const nameInput     = document.getElementById(`koma-name-${slot}`);
    const projInput     = document.getElementById(`koma-project-${slot}`);
    const breakLabel    = document.getElementById(`koma-break-label-${slot}`);
    const breakChk      = document.getElementById(`koma-break-${slot}`);

    // Sync input values from state (if not focused)
    if (k) {
        if (document.activeElement !== nameInput) nameInput.value = k.name || '';
        if (document.activeElement !== projInput)  projInput.value = k.project_id || '';
        if (breakChk) breakChk.checked = !!k.break_after;
        if (breakLabel) {
            breakLabel.classList.toggle('is-checked', !!k.break_after);
        }
    }

    // Elapsed seconds
    const elapsed = k ? liveElapsed(k) : 0;
    const remaining = Math.max(0, CFG.komaDurationSec - elapsed);
    const overSec  = Math.max(0, elapsed - CFG.komaDurationSec);
    const progress  = Math.min(100, Math.round(elapsed / CFG.komaDurationSec * 100));

    elapsedEl.textContent   = fmt(elapsed);
    remainingEl.textContent = remaining > 0 ? fmt(remaining) : `-${fmt(overSec)}`;
    remainingEl.classList.toggle('is-negative', remaining === 0 && elapsed > 0);

    fill.style.width   = progress + '%';
    pct.textContent    = progress + '%';

    if (overSec > 0) {
        overtimeLbl.style.display = '';
        overtimeLbl.textContent   = `+${Math.floor(overSec/60)}分超過`;
    } else {
        overtimeLbl.style.display = 'none';
    }

    // Status badge
    const labelMap = {
        idle: '未開始', running: '実行中', paused: '一時停止',
        completed: '完了', overtime: '超過中', overtime_max: '超過完了',
    };
    badge.textContent = labelMap[status] || status;
    badge.className   = `koma-card__status-badge ${status}`;

    // Card class
    const classMap = {
        running: 'is-running', paused: 'is-paused',
        completed: 'is-completed', overtime: 'is-overtime', overtime_max: 'is-overtime-max',
    };
    card.className = 'koma-card ' + (classMap[status] || '');

    // Button visibility
    const isRunning   = status === 'running' || status === 'overtime';
    const isCompleted = status === 'completed' || status === 'overtime_max';
    const isIdle      = status === 'idle';
    const isPaused    = status === 'paused';

    btnStart.style.display    = isRunning ? 'none' : '';
    btnPause.style.display    = isRunning ? '' : 'none';
    btnStart.disabled         = isCompleted;
    btnComplete.disabled      = isCompleted;
}

/** Calculate live elapsed seconds for a koma (includes open segment) */
function liveElapsed(k) {
    if (!k || !k.segments) return 0;
    let total = 0;
    const now = Date.now();
    for (const seg of k.segments) {
        const start = new Date(seg.start).getTime();
        const end   = seg.end ? new Date(seg.end).getTime() : now;
        const diff  = Math.max(0, Math.floor((end - start) / 1000));
        total += diff;
    }
    return total;
}

// --- Tick ---

function startTick(slot) {
    if (tickIntervals[slot]) return;
    tickIntervals[slot] = setInterval(() => tick(slot), 1000);
}

function stopTick(slot) {
    if (tickIntervals[slot]) {
        clearInterval(tickIntervals[slot]);
        delete tickIntervals[slot];
    }
}

function tick(slot) {
    const k = komaState[slot];
    if (!k) return;

    const elapsed = liveElapsed(k);
    notified[slot] = notified[slot] || {};

    // 80min notification
    if (elapsed >= CFG.komaDurationSec && !notified[slot]['80']) {
        notified[slot]['80'] = true;
        apiCall('notify_80min', { slot });
        // Update status to overtime
        k.status = 'overtime';
    }

    // 100min auto-complete
    if (elapsed >= CFG.maxDurationSec && !notified[slot]['100']) {
        notified[slot]['100'] = true;
        apiCall('notify_100min', { slot }).then(res => {
            if (res.ok && res.koma) {
                komaState[slot] = res.koma;
            } else {
                k.status = 'completed';
            }
            stopTick(slot);
            renderKoma(slot);
        });
        return;
    }

    renderKoma(slot);
}

// --- Actions ---

async function doStart(slot) {
    const res = await apiCall('start', { slot });
    if (!res.ok) { console.error('start failed', res.error); return; }
    komaState[slot] = res.koma;
    notified[slot]  = notified[slot] || {};
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
        // refresh datalist
        refreshProjectHistory(res.koma.project_id);
    }
}

async function doSetBreak(slot, value) {
    const res = await apiCall('set_break', { slot, value });
    if (res.ok && res.koma) {
        komaState[slot] = res.koma;
        renderKoma(slot);
    }
}

// --- Project history datalist ---

function refreshProjectHistory(newProject) {
    if (!newProject) return;
    const dl = document.getElementById('project-history-list');
    if (!dl) return;
    // Check if already exists
    for (const opt of dl.options) {
        if (opt.value === newProject) return;
    }
    const opt = document.createElement('option');
    opt.value = newProject;
    dl.prepend(opt);
}

// --- Event binding ---

function bindEvents() {
    // Start buttons
    document.querySelectorAll('.btn-start').forEach(btn => {
        btn.addEventListener('click', () => doStart(+btn.dataset.slot));
    });
    // Pause buttons
    document.querySelectorAll('.btn-pause').forEach(btn => {
        btn.addEventListener('click', () => doPause(+btn.dataset.slot));
    });
    // Complete buttons
    document.querySelectorAll('.btn-complete').forEach(btn => {
        btn.addEventListener('click', () => doComplete(+btn.dataset.slot));
    });

    // Name inputs — save on blur or Enter
    document.querySelectorAll('.koma-card__name-input').forEach(input => {
        let saveTimer = null;
        const save = () => doUpdateMeta(+input.dataset.slot, input.value, undefined);
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); input.blur(); } });
        input.addEventListener('input', () => {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(save, 800);
        });
    });

    // Project inputs — save on blur or Enter
    document.querySelectorAll('.koma-card__project-input').forEach(input => {
        let saveTimer = null;
        const save = () => doUpdateMeta(+input.dataset.slot, undefined, input.value);
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); input.blur(); } });
        input.addEventListener('input', () => {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(save, 800);
        });
    });

    // Break checkboxes
    document.querySelectorAll('input[id^="koma-break-"]').forEach(chk => {
        chk.addEventListener('change', () => doSetBreak(+chk.dataset.slot, chk.checked));
    });
}

// --- Boot ---

document.addEventListener('DOMContentLoaded', () => {
    initFromState(CFG.initialState);
    bindEvents();
});
