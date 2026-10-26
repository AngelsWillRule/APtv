import { getCSRFToken } from './helpers.js';

const prefix = 'openap-client-watchdog-v3:';
const viewId = globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
const key = prefix + viewId;
const maxEvents = 1440;
const freezeReloadKey = 'openap-client-watchdog-freeze-reload';
const freezeReloadCooldown = 60000;
let events = [];
let sequence = 0;
let sending = false;
let initialized = false;
let expected = Date.now() + 15000;
let freezeDetectionArmed = false;
let controller = null;
let delivery = 'pending';
let resizeTimer = 0;
let resizeSettleTimer = 0;
let resizeEventCount = 0;

function viewportDetail(source, count = 1) {
    const visual = window.visualViewport;
    const visualSize = visual
        ? `${Math.round(visual.width)}x${Math.round(visual.height)}@${Math.round(visual.scale * 100) / 100}`
        : 'none';
    const fullscreen = Boolean(document.fullscreenElement || document.webkitFullscreenElement);
    return `${source};events=${count};inner=${window.innerWidth}x${window.innerHeight};visual=${visualSize};outer=${window.outerWidth}x${window.outerHeight};screen=${window.screen?.width || 0}x${window.screen?.height || 0};dpr=${window.devicePixelRatio || 1};fullscreen=${fullscreen};orientation=${screen.orientation?.type || 'unknown'}`;
}

function storedViews() {
    const views = [];
    try {
        for (let i = 0; i < localStorage.length; i++) {
            const name = localStorage.key(i);
            if (!name?.startsWith(prefix)) continue;
            try {
                const record = JSON.parse(localStorage.getItem(name));
                if (Array.isArray(record.events)) views.push({name, ...record});
            } catch (error) { /* Ignore damaged records. */ }
        }
    } catch (error) { /* Diagnostics also work without browser storage. */ }
    return views;
}
function save() {
    try {
        // Separate records prevent frames from overwriting each other's queue.
        const old = storedViews().filter(view => view.name !== key).sort((a, b) => b.updated - a.updated);
        old.forEach((view, index) => {
            if (index >= 19 || Date.now() - view.updated > 86400000) localStorage.removeItem(view.name);
        });
        const record = JSON.stringify({updated: Date.now(), view_id: viewId, delivery, events});
        for (;;) {
            try { localStorage.setItem(key, record); break; }
            catch (error) {
                // Reclaim only our own oldest diagnostic records on quota errors.
                if (error.name !== 'QuotaExceededError' || !old.length) break;
                localStorage.removeItem(old.pop().name);
            }
        }
    } catch (error) { /* Keep the bounded in-memory ring when storage is full. */ }
}
function add(type, detail = '', lag = 0) {
    const memory = performance.memory;
    events.push({seq: ++sequence, view_id: viewId, time: new Date().toISOString(), type,
        path: location.pathname, visibility: document.visibilityState,
        frame: window.self !== window.top, width: window.innerWidth, height: window.innerHeight,
        lag_ms: Math.max(0, Math.round(lag)), heap_mb: memory ? Math.round(memory.usedJSHeapSize / 1048576) : 0,
        online: navigator.onLine, detail: String(detail).slice(0, 300)});
    if (events.length > maxEvents) events.splice(0, events.length - maxEvents);
}
function describeNode(node) {
    if (!node || node.nodeType !== 1) return 'none';
    const element = /** @type {HTMLElement} */ (node);
    const classes = typeof element.className === 'string'
        ? `.${element.className.trim().replace(/\s+/g, '.').slice(0, 90)}` : '';
    const style = getComputedStyle(element);
    return `${element.tagName.toLowerCase()}${element.id ? `#${element.id}` : ''}${classes}`
        + `[pe=${style.pointerEvents},pos=${style.position},z=${style.zIndex},op=${style.opacity}]`;
}
function collectUiProbe() {
    const width = window.innerWidth;
    const height = window.innerHeight;
    const points = [
        ['center', width / 2, height / 2],
        ['rail', 25, Math.min(height - 1, 90)],
        ['header', width / 2, 26]
    ];
    const hitTargets = points.map(([name, x, y]) => {
        try { return `${name}=${describeNode(document.elementFromPoint(x, y))}`; }
        catch (error) { return `${name}=error`; }
    }).join(';');
    const sidebar = document.querySelector('.sb-sidenav-menu .nav');
    const main = document.querySelector('#layoutSidenav_content main > .container-fluid');
    const activeAnimations = Array.from(document.getAnimations())
        .filter(animation => animation.playState === 'running')
        .map(animation => animation.animationName || animation.id || 'anonymous')
        .filter((name, index, names) => names.indexOf(name) === index)
        .slice(0, 8);
    const overlays = Array.from(document.querySelectorAll(
        '.modal.show, .modal-backdrop.show, [role="dialog"][aria-hidden="false"], [data-openap-overlay].is-open'
    )).map(describeNode).slice(0, 5);
    return [
        hitTargets,
        `body=${document.body.className || 'none'}`,
        `nav=${sidebar?.className || 'none'}`,
        `main=${main?.className || 'none'}`,
        `anim=${activeAnimations.join('|') || 'none'}`,
        `overlays=${overlays.join('|') || 'none'}`
    ].join(' ').slice(0, 300);
}
function describeEventTarget(target) {
    if (!target || target.nodeType !== 1) return 'none';
    const element = /** @type {HTMLElement} */ (target);
    const label = (element.getAttribute('aria-label') || element.textContent || '')
        .replace(/\s+/g, ' ').trim().slice(0, 80);
    return `${describeNode(element)}${label ? ` label=${label}` : ''}`;
}
async function send() {
    if (sending || !events.length || location.pathname === '/login' || window.openapSession.expired) return;
    sending = true;
    const batch = events.slice(0, 200);
    controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
        const response = await fetch('/api/client-watchdog', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            body: new URLSearchParams({csrf_token: getCSRFToken(), events: JSON.stringify(batch)})
        });
        const result = await response.json();
        if (response.ok && result.ok === true && result.accepted === batch.length) {
            const lastSequence = batch[batch.length - 1].seq;
            events = events.filter(event => event.seq > lastSequence);
            delivery = 'delivered';
        } else {
            delivery = `http-${response.status}`;
        }
    } catch (error) {
        delivery = error.name === 'AbortError' ? 'aborted' : 'failed';
    } finally {
        window.clearTimeout(timeout);
        controller = null;
        sending = false;
        save();
    }
}
export function initClientWatchdog() {
    if (initialized) return;
    initialized = true;
    add('boot', navigator.userAgent);
    try {
        const recoveredAt = Number(sessionStorage.getItem(freezeReloadKey) || 0);
        if (recoveredAt > 0 && Date.now() - recoveredAt < freezeReloadCooldown) {
            add('recovered_after_freeze', `reload_at_${new Date(recoveredAt).toISOString()}`);
        }
        sessionStorage.removeItem(freezeReloadKey);
    } catch (error) { /* Diagnostics continue without session storage. */ }
    save();
    window.addEventListener('error', event => add('error', `${event.error?.name || 'Error'} line ${event.lineno || 0}`));
    window.addEventListener('unhandledrejection', () => add('rejection'));
    document.addEventListener('visibilitychange', () => { add('visibility'); save(); });
    window.addEventListener('online', () => add('online'));
    window.addEventListener('offline', () => add('offline'));
    const recordResize = (source) => {
        resizeEventCount += 1;
        document.documentElement.classList.add('openap-viewport-resizing');
        window.clearTimeout(resizeTimer);
        window.clearTimeout(resizeSettleTimer);
        resizeTimer = window.setTimeout(() => {
            const count = resizeEventCount;
            resizeEventCount = 0;
            add('resize', viewportDetail(source, count));
            save();
            send();
        }, 180);
        resizeSettleTimer = window.setTimeout(() => {
            document.documentElement.classList.remove('openap-viewport-resizing');
            resizeSettleTimer = 0;
        }, 260);
    };
    window.addEventListener('resize', () => recordResize('window'));
    window.visualViewport?.addEventListener('resize', () => recordResize('visualViewport'));
    window.addEventListener('pagehide', () => { add('pagehide'); save(); });
    window.addEventListener('pageshow', event => add('pageshow', event.persisted ? 'bfcache' : 'normal'));
    // Keep a small trace of navigation-related input. This is intentionally
    // delegated and filtered so normal player controls cannot flood the ring.
    document.addEventListener('click', event => {
        const target = event.target?.closest?.(
            '.sb-sidenav-menu a.nav-link, .openap-workspace-switch a, .openap-iptv-side-actions a'
        );
        if (target) add('navigation_click', describeEventTarget(target));
    }, true);
    if (globalThis.PerformanceObserver) {
        try {
            const observer = new PerformanceObserver(list => {
                list.getEntries().forEach(entry => {
                    if (entry.duration >= 50) add('longtask', `${Math.round(entry.duration)}ms`);
                });
                save();
            });
            observer.observe({type: 'longtask', buffered: true});
        } catch (error) { /* Long-task timing is optional. */ }
    }
    window.addEventListener('openap:session-expired', () => {
        add('session_expired');
        delivery = 'session_expired';
        controller?.abort();
        save();
    });
    // These diagnostic timers intentionally survive session expiry. Disk writes
    // are once per minute, not on each heartbeat or every JavaScript error.
    window.setInterval(() => {
        const now = Date.now();
        const lag = now - expected;
        add('heartbeat', '', lag);
        if (document.visibilityState === 'visible') add('ui_probe', collectUiProbe(), lag);
        expected = now + 15000;
        if (!freezeDetectionArmed || document.visibilityState !== 'visible'
            || window.openapSession.expired || lag < 10000) return;

        // A completely stalled main thread cannot reload itself while stalled.
        // This branch runs as soon as the event loop becomes responsive again.
        let lastReload = 0;
        try { lastReload = Number(sessionStorage.getItem(freezeReloadKey) || 0); } catch (error) {}
        if (Date.now() - lastReload < freezeReloadCooldown) return;
        try { sessionStorage.setItem(freezeReloadKey, String(Date.now())); } catch (error) {}
        add('freeze_detected', `event_loop_lag_${Math.round(lag)}ms`);
        save();
        window.setTimeout(() => window.location.reload(), 50);
    }, 15000);
    window.setTimeout(() => { freezeDetectionArmed = true; }, 10000);
    window.setInterval(() => { save(); send(); }, 60000);
    window.setTimeout(send, 3000);
    window.openapDownloadDiagnostics = () => {
        save();
        const blob = new Blob([JSON.stringify({exported: new Date().toISOString(), views: storedViews()}, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'openap-browser-diagnostics.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
}
