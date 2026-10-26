(function () {
    'use strict';

    var endpoint = '/ajax/networking/hotspot_service.php';
    var polling = false;
    var telemetryRetryTimer = null;

    function notifyAction(action) {
        if (typeof window.openapNotify !== 'function') return;
        var notices = {
            start: { title: 'Hotspot started', message: 'The WiFi access point is active.', icon: 'fa-play' },
            stop: { title: 'Hotspot stopped', message: 'The WiFi access point has been stopped.', icon: 'fa-stop' },
            restart: { title: 'Hotspot restarted', message: 'The WiFi access point is active again.', icon: 'fa-sync-alt' }
        };
        var notice = notices[action];
        if (!notice) return;
        window.openapNotify({
            level: 'success',
            title: notice.title,
            message: notice.message,
            icon: notice.icon,
            duration: 5000
        });
    }

    function notifyFailure(action, message) {
        if (typeof window.openapNotify !== 'function') return;
        window.openapNotify({
            level: 'error',
            title: 'Hotspot action failed',
            message: message || ('Unable to ' + action + ' the WiFi access point.'),
            details: ['Requested action: ' + action]
        });
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf_token"]');
        return meta ? meta.content : '';
    }

    function controls() {
        return Array.from(document.querySelectorAll('.openap-hotspot-action[data-hotspot-action]'));
    }

    function transitionStateBadge(field, html, stateKey) {
        var slot = field.querySelector('.openap-hotspot-state-slot');
        if (!slot) {
            slot = document.createElement('span');
            slot.className = 'openap-hotspot-state-slot';
            var currentBadge = field.querySelector('.openap-hotspot-status:not(.is-hidden)');
            if (currentBadge) {
                slot.appendChild(currentBadge);
            }
            field.insertBefore(slot, field.firstChild);
        }
        if (slot.dataset.hotspotStateKey === stateKey) {
            return;
        }

        var holder = document.createElement('div');
        holder.innerHTML = html;
        var nextBadge = holder.firstElementChild;
        var current = slot.querySelector('.openap-hotspot-status');
        slot.dataset.hotspotStateKey = stateKey;
        if (!nextBadge) {
            return;
        }
        if (!current || window.matchMedia('(prefers-reduced-motion: reduce)').matches ||
            typeof current.animate !== 'function') {
            slot.replaceChildren(nextBadge);
            return;
        }

        slot.appendChild(nextBadge);
        var outgoing = current.animate([
            { transform: 'translateY(0)', opacity: 1 },
            { transform: 'translateY(135%)', opacity: 0 }
        ], {
            duration: 360,
            easing: 'cubic-bezier(.55,0,1,.45)',
            fill: 'forwards'
        });
        nextBadge.animate([
            { transform: 'translateY(-135%)', opacity: 0 },
            { transform: 'translateY(8%)', opacity: 1, offset: .82 },
            { transform: 'translateY(0)', opacity: 1 }
        ], {
            duration: 460,
            easing: 'cubic-bezier(.22,1,.36,1)'
        });
        outgoing.finished.then(function () {
            current.remove();
            outgoing.cancel();
        }).catch(function () {
            current.remove();
        });
    }

    function updateTopology(active) {
        document.querySelectorAll(
            '[data-openap-topology-node="hotspot"], [data-openap-topology-node="clients"]'
        ).forEach(function (node) {
            var wasActive = node.classList.contains('active');
            if (wasActive !== active && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                node.classList.remove('openap-led-to-active', 'openap-led-to-inactive');
                void node.offsetWidth;
                node.classList.add(active ? 'openap-led-to-active' : 'openap-led-to-inactive');
                window.setTimeout(function () {
                    node.classList.remove('openap-led-to-active', 'openap-led-to-inactive');
                }, 560);
            }
            node.classList.toggle('active', active);
        });
        document.querySelectorAll(
            '[data-openap-topology-line="uplink-openap"], [data-openap-topology-line="openap-clients"]'
        ).forEach(function (line) {
            line.classList.toggle('active', active);
        });
    }

    function updateState(state, pendingAction) {
        var active = state === 'active';
        var settling = state === 'activating' || state === 'deactivating' || Boolean(pendingAction);
        updateTopology(active && !settling);
        controls().forEach(function (button) {
            var action = button.dataset.hotspotAction;
            button.disabled = settling || (active ? action === 'start' : action !== 'start');
            button.classList.toggle('is-pending', pendingAction === action);
        });
        document.querySelectorAll('[data-openap-hotspot-field="state"]').forEach(function (field) {
            var badgeHtml;
            var stateKey;
            if (settling) {
                stateKey = pendingAction === 'stop' ? 'stopping' : (pendingAction === 'restart' ? 'restarting' : 'starting');
                badgeHtml = '<span class="openap-hotspot-status is-pending is-' + stateKey + '"><i class="fas fa-circle-notch fa-spin"></i> ' +
                    (stateKey === 'stopping' ? 'Stopping' : (stateKey === 'restarting' ? 'Restarting' : 'Starting')) + '</span>';
            } else {
                stateKey = active ? 'running' : 'stopped';
                badgeHtml = active
                    ? '<span class="openap-hotspot-status is-running"><i class="fas fa-circle"></i> Running</span>'
                    : '<span class="openap-hotspot-status is-stopped"><i class="fas fa-circle"></i> Stopped</span>';
            }
            transitionStateBadge(field, badgeHtml, stateKey);
        });
    }

    function readState() {
        return fetch(endpoint, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || ('HTTP ' + response.status));
                }
                return payload.state;
            });
        });
    }

    function replaceHotspotGrid(grid, html, stopped, reducedMotion, afterReplace) {
        var oldHeight = grid.getBoundingClientRect().height;
        grid.innerHTML = html;
        grid.classList.toggle('is-service-stopped', stopped);

        if (typeof afterReplace === 'function') {
            afterReplace();
        }
        if (reducedMotion || typeof grid.animate !== 'function') {
            return;
        }

        var newHeight = grid.getBoundingClientRect().height;
        if (Math.abs(oldHeight - newHeight) < 1) {
            return;
        }
        grid.style.overflow = 'hidden';
        var resize = grid.animate([
            { height: oldHeight + 'px' },
            { height: newHeight + 'px' }
        ], {
            duration: 480,
            easing: 'cubic-bezier(.22,1,.36,1)'
        });
        resize.finished.then(function () {
            grid.style.removeProperty('overflow');
        }).catch(function () {
            grid.style.removeProperty('overflow');
        });
    }

    function hideHotspotBands() {
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var stoppedMessage = '<div class="openap-hotspot-stopped-message" role="status">' +
            '<span class="openap-hotspot-stopped-icon" aria-hidden="true"><i class="fas fa-ban"></i></span>' +
            '<div><strong>Hotspot stopped</strong><span class="openap-hotspot-stopped-info"><i class="fas fa-info-circle" aria-hidden="true"></i>WiFi channels are unavailable while the hotspot service is stopped.</span></div>' +
            '</div>';
        document.querySelectorAll('.openap-hotspot-band-grid').forEach(function (grid) {
            var rows = Array.from(grid.querySelectorAll('[data-openap-hotspot-band]'));
            if (reducedMotion || !rows.length || typeof rows[0].animate !== 'function') {
                replaceHotspotGrid(grid, stoppedMessage, true, reducedMotion);
                return;
            }
            rows.forEach(function (row, rowIndex) {
                window.setTimeout(function () {
                    row.animate([
                        { transform: 'translateY(0)', opacity: 1 },
                        { transform: 'translateY(115%)', opacity: 0 }
                    ], {
                        duration: 300,
                        easing: 'cubic-bezier(.55,0,1,.45)',
                        fill: 'forwards'
                    });
                }, rowIndex * 70);
            });
            window.setTimeout(function () {
                rows.forEach(function (row) {
                    row.getAnimations().forEach(function (animation) { animation.cancel(); });
                });
                replaceHotspotGrid(grid, stoppedMessage, true, reducedMotion);
            }, 320 + Math.max(0, rows.length - 1) * 70);
        });
    }

    function gridTelemetryReady(grid) {
        var rows = Array.from(grid.querySelectorAll('[data-openap-hotspot-band]'));
        return rows.length > 0 && rows.every(function (row) {
            var band = row.dataset.openapHotspotBand;
            var channel = row.querySelector('[data-openap-hotspot-field="' + band + '-channel"]');
            var width = row.querySelector('[data-openap-hotspot-field="' + band + '-width"]');
            return channel && /^[0-9]+$/.test(channel.textContent.trim()) &&
                width && parseInt(width.textContent, 10) > 0;
        });
    }

    function refreshHotspotBands(fromStopped, attempt) {
        attempt = Number(attempt) || 0;
        var currentSummaries = Array.from(document.querySelectorAll('.openap-hotspot-summary'));
        if (!currentSummaries.length) {
            return;
        }
        var separator = window.location.search ? '&' : '?';
        fetch(window.location.pathname + window.location.search + separator + 'hotspot_refresh=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var freshSummaries = Array.from(parsed.querySelectorAll('.openap-hotspot-summary'));
            var telemetryReady = freshSummaries.length > 0 && freshSummaries.every(function (summary) {
                var grid = summary.querySelector('.openap-hotspot-band-grid');
                return grid && gridTelemetryReady(grid);
            });
            if (!telemetryReady) {
                if (attempt < 20) {
                    window.clearTimeout(telemetryRetryTimer);
                    telemetryRetryTimer = window.setTimeout(function () {
                        refreshHotspotBands(fromStopped, attempt + 1);
                    }, 750);
                }
                return;
            }
            var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            currentSummaries.forEach(function (summary, summaryIndex) {
                var freshSummary = freshSummaries[summaryIndex];
                if (!freshSummary) {
                    return;
                }
                var currentGrid = summary.querySelector('.openap-hotspot-band-grid');
                var freshGrid = freshSummary.querySelector('.openap-hotspot-band-grid');
                if (!currentGrid || !freshGrid) {
                    return;
                }
                if (fromStopped || currentGrid.classList.contains('is-service-stopped')) {
                    replaceHotspotGrid(currentGrid, freshGrid.innerHTML, false, reducedMotion, function () {
                        Array.from(currentGrid.querySelectorAll('[data-openap-hotspot-band]')).forEach(function (row, rowIndex) {
                            if (reducedMotion || typeof row.animate !== 'function') {
                                return;
                            }
                            window.setTimeout(function () {
                                row.animate([
                                    { transform: 'translateY(115%)', opacity: 0 },
                                    { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                                    { transform: 'translateY(0)', opacity: 1 }
                                ], {
                                    duration: 440,
                                    easing: 'cubic-bezier(.22,1,.36,1)'
                                });
                            }, rowIndex * 70);
                        });
                    });
                    return;
                }
                var freshRows = new Map(Array.from(freshSummary.querySelectorAll('[data-openap-hotspot-band]')).map(function (row) {
                    return [row.dataset.openapHotspotBand, row];
                }));
                Array.from(summary.querySelectorAll('[data-openap-hotspot-band]')).forEach(function (row, rowIndex) {
                    var freshRow = freshRows.get(row.dataset.openapHotspotBand);
                    if (!freshRow) {
                        return;
                    }
                    var replacement = freshRow.cloneNode(true);
                    if (reducedMotion || typeof row.animate !== 'function') {
                        row.replaceWith(replacement);
                        return;
                    }
                    window.setTimeout(function () {
                        var hide = row.animate([
                            { transform: 'translateY(0)', opacity: 1 },
                            { transform: 'translateY(115%)', opacity: 0 }
                        ], {
                            duration: 300,
                            easing: 'cubic-bezier(.55,0,1,.45)',
                            fill: 'forwards'
                        });
                        hide.finished.then(function () {
                            row.replaceWith(replacement);
                            hide.cancel();
                            replacement.animate([
                                { transform: 'translateY(115%)', opacity: 0 },
                                { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                                { transform: 'translateY(0)', opacity: 1 }
                            ], {
                                duration: 440,
                                easing: 'cubic-bezier(.22,1,.36,1)'
                            });
                        }).catch(function () {
                            row.replaceWith(replacement);
                        });
                    }, rowIndex * 70);
                });
            });
        }).catch(function () {
            // The service action already succeeded; retain the last confirmed values.
        });
    }

    function poll(action, startedAt) {
        if (!polling) {
            return;
        }
        readState().then(function (state) {
            var elapsed = Date.now() - startedAt;
            var complete = elapsed >= 750 && ((action === 'stop' && state === 'inactive') ||
                (action !== 'stop' && state === 'active'));
            if (complete) {
                polling = false;
                updateState(state, '');
                if (action === 'stop') {
                    hideHotspotBands();
                } else {
                    refreshHotspotBands(action === 'start', 0);
                }
                notifyAction(action);
                return;
            }
            if (elapsed >= 30000 || state === 'failed') {
                polling = false;
                updateState(state, '');
                notifyFailure(action, state === 'failed' ? 'The hotspot service entered a failed state.' : 'The hotspot did not reach the requested state in time.');
                return;
            }
            updateState(state, action);
            window.setTimeout(function () { poll(action, startedAt); }, 500);
        }).catch(function () {
            if (Date.now() - startedAt >= 30000) {
                polling = false;
                controls().forEach(function (button) { button.disabled = false; });
                return;
            }
            window.setTimeout(function () { poll(action, startedAt); }, 1000);
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.openap-hotspot-actions form');
        if (!form) {
            return;
        }
        event.preventDefault();
        if (polling) {
            return;
        }
        var submitter = event.submitter || form.querySelector('[data-hotspot-action]');
        var action = submitter ? submitter.dataset.hotspotAction : '';
        if (!['start', 'stop', 'restart'].includes(action)) {
            return;
        }

        polling = true;
        var startedAt = Date.now();
        updateState(action === 'stop' ? 'deactivating' : 'activating', action);
        var data = new URLSearchParams();
        data.set('action', action);
        data.set('csrf_token', csrfToken());
        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: data.toString()
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || ('HTTP ' + response.status));
                }
                poll(action, startedAt);
            });
        }).catch(function (error) {
            polling = false;
            readState().then(function (state) { updateState(state, ''); });
            notifyFailure(action, error && error.message ? error.message : 'The hotspot command could not be completed.');
        });
    });
}());
