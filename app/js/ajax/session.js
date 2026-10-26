import { getCSRFToken } from "../helpers.js";

export function showSessionExpiredModal() {
    window.openapSession.expire();
    if (document.getElementById('openap-session-expired')) {
        return;
    }

    const returnUrl = window.location.pathname + window.location.search + window.location.hash;
    const params = new URLSearchParams({
        action: returnUrl,
        reason: 'session_expired'
    });
    const loginUrl = `/login?${params.toString()}`;
    const overlay = document.createElement('div');
    overlay.id = 'openap-session-expired';
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'openap-session-expired-title');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:1.5rem;background:rgba(0,0,0,.72)';
    overlay.innerHTML =
        '<div style="width:min(28rem,100%);padding:2rem;border-radius:.75rem;background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#212529);box-shadow:0 1rem 3rem rgba(0,0,0,.35);text-align:center">' +
        '<h2 id="openap-session-expired-title" style="margin:0 0 .75rem;font-size:1.5rem">Session expired</h2>' +
        '<p style="margin:0 0 1.5rem">Your OpenAP session has expired. Log in again to continue.</p>' +
        '<a href="' + loginUrl.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '" class="btn btn-primary">Log in again</a>' +
        '</div>';
    if (typeof window.openapDownloadDiagnostics === 'function') {
        const download = document.createElement('button');
        download.type = 'button';
        download.className = 'btn btn-link d-block mx-auto mt-3';
        download.textContent = 'Download diagnostics';
        download.addEventListener('click', window.openapDownloadDiagnostics);
        overlay.firstElementChild.appendChild(download);
    }
    document.body.appendChild(overlay);
    overlay.querySelector('a').focus();
}

let initialized = false;
export function initSession_ajax() {
    if (initialized || window.location.pathname === '/login') return;
    initialized = true;
    let pending = false;
    let activity = false;
    let controller = null;
    const recordActivity = event => { if (event.isTrusted) activity = true; };
    const activityEvents = ['pointerdown', 'keydown', 'touchstart', 'wheel'];
    activityEvents.forEach(type => window.addEventListener(type, recordActivity, { passive: true }));
    window.addEventListener('openap:session-expired', () => {
        controller?.abort();
        activityEvents.forEach(type => window.removeEventListener(type, recordActivity));
    }, { once: true });

    async function checkSession() {
        if (pending || window.openapSession.expired) return;
        pending = true;
        controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 8000);
        const interacted = activity;
        activity = false;
        const playing = Array.from(document.querySelectorAll('[data-openap-aptv-player] video'))
            .some(video => !video.paused && !video.ended && video.readyState >= 2);
        try {
            const response = await fetch('/ajax/session/do_check_session.php', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                signal: controller.signal,
                body: new URLSearchParams({csrf_token: getCSRFToken(), keepalive: interacted || playing ? '1' : '0'})
            });
            const data = await response.json();
            if (data.status === 'session_expired' || (response.status === 403 && data.status === 'csrf_invalid')) {
                showSessionExpiredModal();
            } else if (!response.ok) {
                activity = activity || interacted;
            }
        } catch (error) {
            // A network failure alone does not mean the login has expired.
            activity = activity || interacted;
        } finally {
            window.clearTimeout(timeout);
            controller = null;
            pending = false;
        }
    }
    window.openapSession.setInterval(checkSession, 5000);
}
