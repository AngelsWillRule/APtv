/* Shared OpenAP widget area.
 *
 * Ports the dashboard widget sidebar so it can be arranged per user and per
 * page. Reads the server-rendered layout from window.openapWidgets
 * ({ page, order, hidden, widths, moduleNames }).
 *
 * Edit is EXPLICIT: you enter edit mode ("Customize"), arrange freely for as
 * long as you like (drag & drop, hide/show, single/double width), then commit
 * with "Save", or restore page defaults with "Reset".
 * Nothing is persisted or reloaded until you choose Save/Reset.
 */
(function () {
    'use strict';

    var MODULE_IDS = ['clients', 'traffic', 'uplink', 'system-health', 'services', 'dhcp'];
    var DEFAULT_ORDER = ['clients', 'traffic', 'uplink', 'system-health', 'services', 'dhcp'];
    var DEFAULT_WIDTHS = {
        'clients': 'col-6',
        'traffic': 'col-6',
        'uplink': 'col-6',
        'system-health': 'col-12',
        'services': 'col-12',
        'dhcp': 'col-6'
    };

    var area = document.getElementById('openapWidgetArea');
    if (!area) {
        return;
    }

    var config = window.openapWidgets || {};
    var page = String(config.page || 'dashboard');
    var moduleNames = config.moduleNames || {};

    function cloneLayout(layout) {
        return {
            order: (layout.order || DEFAULT_ORDER).slice(),
            hidden: (layout.hidden || []).slice(),
            widths: Object.assign({}, DEFAULT_WIDTHS, (layout.widths && typeof layout.widths === 'object') ? layout.widths : {})
        };
    }

    var initial = cloneLayout(config);
    var state = cloneLayout(config);

    var grid = area.querySelector('[data-openap-widget-grid]');
    var editButton = area.querySelector('[data-openap-widget-edit]');
    var saveButton = area.querySelector('[data-openap-widget-save]');
    var resetButton = area.querySelector('[data-openap-widget-reset]');
    var status = area.querySelector('[data-openap-widget-status]');
    var hiddenTray = area.querySelector('[data-openap-widget-hidden-tray]');
    var desktop = window.matchMedia('(min-width: 992px)');

    if (!grid) {
        return;
    }

    var dragging = null;
    var dragArmed = false;
    var wideSwapTarget = '';
    var smallSwapTarget = '';
    var oneByOneMeasureFrame = 0;

    function label(id) {
        return moduleNames[id] || id;
    }

    /* All rendered widgets that are currently visible (hidden ones are kept in
     * the DOM under .is-hidden but excluded from layout/drag). */
    function allItems() {
        return Array.from(grid.querySelectorAll('[data-openap-widget-id]'));
    }

    function visibleItems() {
        return allItems().filter(function (el) { return !el.classList.contains('is-hidden'); });
    }

    function visibleDomOrder() {
        return visibleItems().map(function (el) { return el.dataset.openapWidgetId; });
    }

    function isHidden(id) {
        return state.hidden.indexOf(id) !== -1;
    }

    function announce(message) {
        if (!status) {
            return;
        }
        status.textContent = '';
        window.requestAnimationFrame(function () { status.textContent = message; });
    }

    function initServiceStatusMonitor() {
        var busy = false;
        var initialSync = true;

        function animateElementChange(element, freshElement) {
            if (!element || !freshElement) {
                return Promise.resolve();
            }
            var sameValue = element.textContent.trim() === freshElement.textContent.trim() &&
                element.className === freshElement.className &&
                (element.getAttribute('title') || '') === (freshElement.getAttribute('title') || '');
            if (sameValue) return Promise.resolve();
            var replace = function () {
                element.className = freshElement.className;
                element.innerHTML = freshElement.innerHTML;
            };
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches ||
                typeof element.animate !== 'function') {
                replace();
                return Promise.resolve();
            }
            var outgoing = element.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(115%)', opacity: 0 }
            ], {
                duration: 280,
                easing: 'cubic-bezier(.55,0,1,.45)',
                fill: 'forwards'
            });
            return outgoing.finished.then(function () {
                replace();
                outgoing.cancel();
                return element.animate([
                    { transform: 'translateY(-115%)', opacity: 0 },
                    { transform: 'translateY(8%)', opacity: 1, offset: .82 },
                    { transform: 'translateY(0)', opacity: 1 }
                ], {
                    duration: 400,
                    easing: 'cubic-bezier(.22,1,.36,1)'
                }).finished.catch(function () {});
            }).catch(function () {
                replace();
            });
        }

        function patchServiceStatus(current, fresh) {
            var tasks = [];
            var currentItems = new Map(Array.from(current.querySelectorAll('[data-openap-service-key]')).map(function (item) {
                return [item.dataset.openapServiceKey, item];
            }));
            var freshItems = new Map(Array.from(fresh.querySelectorAll('[data-openap-service-key]')).map(function (item) {
                return [item.dataset.openapServiceKey, item];
            }));
            var sameServices = currentItems.size === freshItems.size &&
                Array.from(currentItems.keys()).every(function (key) { return freshItems.has(key); });

            current.className = fresh.className;
            var currentLive = current.querySelector('[data-openap-service-live]');
            var freshLive = fresh.querySelector('[data-openap-service-live]');
            if (currentLive && freshLive) {
                currentLive.className = freshLive.className;
                currentLive.innerHTML = freshLive.innerHTML;
            }

            if (!sameServices) {
                tasks.push(animateElementChange(
                    current.querySelector('.openap-service-grid'),
                    fresh.querySelector('.openap-service-grid')
                ));
            } else {
                currentItems.forEach(function (item, key) {
                    tasks.push(animateElementChange(
                        item.querySelector('.openap-service-led'),
                        freshItems.get(key).querySelector('.openap-service-led')
                    ));
                    var currentCopy = item.querySelector('div');
                    var freshCopy = freshItems.get(key).querySelector('div');
                    if (currentCopy && freshCopy && currentCopy.innerHTML !== freshCopy.innerHTML) {
                        currentCopy.innerHTML = freshCopy.innerHTML;
                    }
                });
            }

            var currentFooter = current.querySelector('.stat-bottom');
            var freshFooter = fresh.querySelector('.stat-bottom');
            if (currentFooter && freshFooter) {
                var currentFooterIcon = currentFooter.querySelector('span i');
                var freshFooterIcon = freshFooter.querySelector('span i');
                if (currentFooterIcon && freshFooterIcon) {
                    currentFooterIcon.className = freshFooterIcon.className;
                }
                var currentFooterValue = currentFooter.querySelector('strong');
                var freshFooterValue = freshFooter.querySelector('strong');
                if (currentFooterValue && freshFooterValue) {
                    currentFooterValue.innerHTML = freshFooterValue.innerHTML;
                }
            }
            return Promise.all(tasks);
        }

        function refreshServiceStatus() {
            if (window.openapSession.expired) return;
            if (busy || document.hidden
                || document.body.classList.contains('openap-universal-apply-active')) return;
            var current = area.querySelector('.openap-service-status-card');
            if (!current) return;
            busy = true;
            fetch('/?widget_refresh=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            }).then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var fresh = parsed.querySelector('.openap-service-status-card');
                current = area.querySelector('.openap-service-status-card');
                if (!fresh || !current) return;
                var suppressAnimation = initialSync;
                initialSync = false;
                if (current.outerHTML === fresh.outerHTML) return;
                if (suppressAnimation) {
                    return;
                }

                return patchServiceStatus(current, fresh);
            }).catch(function () {
                // A failed health poll must not alter the last verified state.
            }).finally(function () {
                busy = false;
            });
        }

        window.setTimeout(refreshServiceStatus, 2500);
        window.openapSession.setInterval(refreshServiceStatus, 10000);
        window.addEventListener('openap:mode-switch-confirmed', function (event) {
            var snapshotHtml = event.detail && event.detail.snapshotHtml;
            if (snapshotHtml) {
                var parsed = new DOMParser().parseFromString(snapshotHtml, 'text/html');
                var fresh = parsed.querySelector('.openap-service-status-card');
                var current = area.querySelector('.openap-service-status-card');
                initialSync = false;
                if (fresh && current) patchServiceStatus(current, fresh);
                return;
            }
            refreshServiceStatus();
        });
        window.addEventListener('openap:encrypted-dns-confirmed', function (event) {
            var snapshotHtml = event.detail && event.detail.snapshotHtml;
            if (!snapshotHtml) {
                refreshServiceStatus();
                return;
            }
            var parsed = new DOMParser().parseFromString(snapshotHtml, 'text/html');
            var fresh = parsed.querySelector('.openap-service-status-card');
            var current = area.querySelector('.openap-service-status-card');
            initialSync = false;
            if (fresh && current && current.outerHTML !== fresh.outerHTML) {
                patchServiceStatus(current, fresh);
            }
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) refreshServiceStatus();
        });
    }

    function initDhcpSettingMonitor() {
        var busy = false;
        var initialSync = true;

        function animateDhcpField(element, freshElement) {
            if (!element || !freshElement) {
                return Promise.resolve();
            }
            var sameValue = element.textContent.trim() === freshElement.textContent.trim() &&
                element.className === freshElement.className &&
                (element.getAttribute('title') || '') === (freshElement.getAttribute('title') || '');
            if (sameValue) return Promise.resolve();
            var replace = function () {
                element.className = freshElement.className;
                element.innerHTML = freshElement.innerHTML;
                Array.from(freshElement.attributes).forEach(function (attribute) {
                    if (attribute.name !== 'class') element.setAttribute(attribute.name, attribute.value);
                });
            };
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches ||
                typeof element.animate !== 'function') {
                replace();
                return Promise.resolve();
            }
            var outgoing = element.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(115%)', opacity: 0 }
            ], {
                duration: 320,
                easing: 'cubic-bezier(.55,0,1,.45)',
                fill: 'forwards'
            });
            return outgoing.finished.then(function () {
                replace();
                outgoing.cancel();
                return element.animate([
                    { transform: 'translateY(-115%)', opacity: 0 },
                    { transform: 'translateY(8%)', opacity: 1, offset: .82 },
                    { transform: 'translateY(0)', opacity: 1 }
                ], {
                    duration: 440,
                    easing: 'cubic-bezier(.22,1,.36,1)'
                }).finished.catch(function () {});
            }).catch(function () { replace(); });
        }

        function patchDhcpSetting(current, fresh) {
            var tasks = [];
            var currentMode = current.dataset.openapDhcpMode || 'local';
            var freshMode = fresh.dataset.openapDhcpMode || 'local';
            current.dataset.openapDhcpMode = freshMode;

            var currentCaption = current.querySelector('.openap-widget-caption');
            var freshCaption = fresh.querySelector('.openap-widget-caption');
            if (currentCaption && freshCaption) currentCaption.textContent = freshCaption.textContent;

            var currentIcon = current.querySelector('.openap-widget-icon i');
            var freshIcon = fresh.querySelector('.openap-widget-icon i');
            if (currentIcon && freshIcon) {
                currentIcon.getAnimations().forEach(function (animation) { animation.cancel(); });
                currentIcon.className = freshIcon.className;
                currentIcon.removeAttribute('style');
            }

            if (currentMode !== freshMode) {
                tasks.push(animateDhcpField(
                    current.querySelector('.openap-dhcp-summary'),
                    fresh.querySelector('.openap-dhcp-summary')
                ));
                tasks.push(animateDhcpField(
                    current.querySelector('.stat-bottom span'),
                    fresh.querySelector('.stat-bottom span')
                ));
                tasks.push(animateDhcpField(
                    current.querySelector('.stat-bottom strong'),
                    fresh.querySelector('.stat-bottom strong')
                ));
                return Promise.all(tasks);
            }

            [
                '[data-openap-dhcp-leases]',
                '[data-openap-dhcp-dns]',
                '[data-openap-dhcp-provider]',
                '[data-openap-dhcp-transport]',
                '[data-openap-dhcp-range]'
            ].forEach(function (selector) {
                tasks.push(animateDhcpField(current.querySelector(selector), fresh.querySelector(selector)));
            });
            return Promise.all(tasks);
        }

        function refreshDhcpSetting(forceAnimation) {
            if (window.openapSession.expired) return;
            if (busy || document.hidden
                || document.body.classList.contains('openap-universal-apply-active')) return;
            var current = area.querySelector('.openap-dhcp-setting-widget');
            if (!current) return;
            busy = true;
            var separator = window.location.search ? '&' : '?';
            fetch(window.location.pathname + window.location.search + separator + 'widget_refresh=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            }).then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var fresh = parsed.querySelector('.openap-dhcp-setting-widget');
                current = area.querySelector('.openap-dhcp-setting-widget');
                if (!fresh || !current) return;
                var suppressAnimation = initialSync && forceAnimation !== true;
                initialSync = false;
                if (current.outerHTML === fresh.outerHTML) return;
                if (suppressAnimation) {
                    current.className = fresh.className;
                    current.innerHTML = fresh.innerHTML;
                    Array.from(fresh.attributes).forEach(function (attribute) {
                        if (attribute.name !== 'class') current.setAttribute(attribute.name, attribute.value);
                    });
                    return;
                }
                return patchDhcpSetting(current, fresh);
            }).catch(function () {
                // Keep the last verified DHCP state when a poll fails.
            }).finally(function () {
                busy = false;
            });
        }

        window.openapRefreshDhcpSettingWidget = function () {
            refreshDhcpSetting(true);
        };
        window.addEventListener('openap:mode-switch-confirmed', function (event) {
            var snapshotHtml = event.detail && event.detail.snapshotHtml;
            if (snapshotHtml) {
                var parsed = new DOMParser().parseFromString(snapshotHtml, 'text/html');
                var fresh = parsed.querySelector('.openap-dhcp-setting-widget');
                var current = area.querySelector('.openap-dhcp-setting-widget');
                initialSync = false;
                if (fresh && current) patchDhcpSetting(current, fresh);
                return;
            }
            refreshDhcpSetting(true);
        });
        window.addEventListener('openap:encrypted-dns-confirmed', function (event) {
            var snapshotHtml = event.detail && event.detail.snapshotHtml;
            if (!snapshotHtml) {
                refreshDhcpSetting(true);
                return;
            }
            var parsed = new DOMParser().parseFromString(snapshotHtml, 'text/html');
            var fresh = parsed.querySelector('.openap-dhcp-setting-widget');
            var current = area.querySelector('.openap-dhcp-setting-widget');
            initialSync = false;
            if (fresh && current && current.outerHTML !== fresh.outerHTML) {
                patchDhcpSetting(current, fresh);
            }
        });
        window.setTimeout(refreshDhcpSetting, 1800);
        window.openapSession.setInterval(refreshDhcpSetting, 4000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) refreshDhcpSetting();
        });
    }

    function moveWithAnimation(callback) {
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var positions = new Map();
        var items = visibleItems();
        if (!reduceMotion) {
            items.forEach(function (widget) {
                if (widget !== dragging) {
                    positions.set(widget, widget.getBoundingClientRect());
                }
            });
        }
        callback();
        if (reduceMotion) {
            return;
        }
        items.forEach(function (widget) {
            var previous = positions.get(widget);
            if (!previous) {
                return;
            }
            var current = widget.getBoundingClientRect();
            var offsetX = previous.left - current.left;
            var offsetY = previous.top - current.top;
            if (!offsetX && !offsetY) {
                return;
            }
            widget.getAnimations().forEach(function (animation) {
                if (animation.id === 'openap-widget-reflow') {
                    animation.cancel();
                }
            });
            var animation = widget.animate([
                { transform: 'translate(' + offsetX + 'px, ' + offsetY + 'px)' },
                { transform: 'translate(0, 0)' }
            ], { duration: 180, easing: 'cubic-bezier(.2, .8, .3, 1)' });
            animation.id = 'openap-widget-reflow';
        });
    }

    function setDragGhost(event, item) {
        if (!event.dataTransfer || typeof event.dataTransfer.setDragImage !== 'function') {
            return;
        }
        var rect = item.getBoundingClientRect();
        var ghost = item.cloneNode(true);
        ghost.classList.remove('is-dragging', 'is-drag-target');
        ghost.classList.add('openap-widget-drag-ghost');
        ghost.removeAttribute('id');
        ghost.style.width = rect.width + 'px';
        ghost.style.height = rect.height + 'px';
        ghost.style.top = rect.top + 'px';
        ghost.style.left = rect.left + 'px';
        ghost.style.opacity = '.34';
        ghost.style.filter = 'grayscale(.18) saturate(.58)';
        document.body.appendChild(ghost);
        event.dataTransfer.setDragImage(
            ghost,
            Math.max(0, Math.min(rect.width, event.clientX - rect.left)),
            Math.max(0, Math.min(rect.height, event.clientY - rect.top))
        );
        window.requestAnimationFrame(function () { ghost.remove(); });
    }

    function isHalfWidth(item) {
        return item.classList.contains('col-6');
    }

    function sameVisualRow(first, second) {
        if (!isHalfWidth(first) || !isHalfWidth(second)) {
            return false;
        }
        return Math.abs(first.getBoundingClientRect().top - second.getBoundingClientRect().top) < 4;
    }

    function halfWidthRow(target) {
        var ordered = visibleItems().filter(function (widget) { return widget !== dragging; });
        var index = ordered.indexOf(target);
        var previous = index > 0 ? ordered[index - 1] : null;
        var next = index >= 0 && index < ordered.length - 1 ? ordered[index + 1] : null;
        if (sameVisualRow(previous, target)) {
            return [previous, target];
        }
        if (sameVisualRow(target, next)) {
            return [target, next];
        }
        return [target];
    }

    /* Rebuild the full order (including hidden widgets, which keep their slots)
     * from the current DOM order of the visible widgets. */
    function recomputeFullOrder() {
        var visible = visibleDomOrder();
        var vi = 0;
        var next = [];
        state.order.forEach(function (id) {
            if (isHidden(id)) {
                next.push(id);
            } else {
                next.push(visible[vi] !== undefined ? visible[vi] : id);
                vi += 1;
            }
        });
        while (vi < visible.length) {
            next.push(visible[vi]);
            vi += 1;
        }
        state.order = next;
    }

    function applyOrder(order) {
        var complete = order.slice();
        MODULE_IDS.forEach(function (id) {
            if (complete.indexOf(id) === -1) {
                complete.push(id);
            }
        });
        complete.forEach(function (id) {
            var item = grid.querySelector('[data-openap-widget-id="' + id + '"]');
            if (item) {
                item.style.order = '';
                grid.appendChild(item);
            }
        });
    }

    function applyWidths() {
        allItems().forEach(function (item) {
            var id = item.dataset.openapWidgetId;
            if (id === 'system-health') {
                state.widths[id] = 'col-12';
            } else if (id === 'dhcp') {
                state.widths[id] = 'col-6';
            }
            item.classList.toggle('col-6', state.widths[id] === 'col-6');
            item.classList.toggle('col-12', state.widths[id] === 'col-12');
        });
    }

    function applyHidden() {
        allItems().forEach(function (item) {
            item.classList.toggle('is-hidden', isHidden(item.dataset.openapWidgetId));
        });
    }

    function refreshHiddenTray() {
        if (!hiddenTray) {
            return;
        }
        hiddenTray.innerHTML = '';
        var hiddenIds = state.hidden.filter(function (id) { return MODULE_IDS.indexOf(id) !== -1; });
        if (hiddenIds.length === 0) {
            hiddenTray.hidden = true;
            return;
        }
        hiddenTray.hidden = false;
        var title = document.createElement('span');
        title.className = 'openap-widget-hidden-tray-label';
        title.textContent = 'Hidden:';
        hiddenTray.appendChild(title);
        hiddenIds.forEach(function (id) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'openap-widget-hidden-chip';
            chip.title = 'Show ' + label(id) + ' widget';
            chip.innerHTML = '<span>' + label(id) + '</span><i class="fas fa-eye" aria-hidden="true"></i>';
            chip.addEventListener('click', function () { showWidget(id); });
            hiddenTray.appendChild(chip);
        });
    }

    /* Use Connected clients as the canonical 1x1 size.  offsetHeight is the
     * unzoomed layout height: getBoundingClientRect() would already include
     * the QHD/UHD canvas zoom and would make every assigned widget height be
     * scaled a second time. */
    function syncOneByOneHeight() {
        window.cancelAnimationFrame(oneByOneMeasureFrame);
        area.style.removeProperty('--openap-widget-1x1-height');
        if (!desktop.matches) {
            return;
        }
        oneByOneMeasureFrame = window.requestAnimationFrame(function () {
            var reference = grid.querySelector('[data-openap-widget-id="clients"].col-6:not(.is-hidden) > .stat-card');
            if (!reference) {
                return;
            }
            var referenceHeight = reference.offsetHeight;
            if (referenceHeight > 0) {
                area.style.setProperty('--openap-widget-1x1-height', (referenceHeight + 10) + 'px');
            }
        });
    }

    function initConnectedClientsMonitor() {
        var cards = Array.from(document.querySelectorAll('[data-openap-widget-id="clients"] > .stat-card'));
        if (!cards.length) {
            return;
        }

        function normalizedBands(data) {
            var active = Array.isArray(data.active_bands) ? data.active_bands.map(String) : [];
            return ['5', '2.4'].filter(function (band) { return active.indexOf(band) !== -1; });
        }

        function replaceCardState(card, data, bands) {
            var total = Math.max(0, Number(data.total) || 0);
            var ssid = String(data.ssid || '-').trim() || '-';
            var ssidNode = card.querySelector('[data-openap-ap-ssid]');
            var summary = card.querySelector('[data-openap-client-band-summary]');

            card.querySelectorAll('[data-openap-client-count]').forEach(function (node) {
                node.textContent = total;
            });
            if (ssidNode) {
                ssidNode.textContent = ssid;
                ssidNode.title = ssid;
            }
            if (summary) {
                summary.innerHTML = '';
                summary.classList.toggle('is-hotspot-stopped', bands.length === 0);
                if (!bands.length) {
                    summary.innerHTML = '<div class="openap-client-hotspot-stopped" role="status">' +
                        '<i class="fas fa-info-circle" aria-hidden="true"></i>' +
                        '<div><strong>Hotspot stopped</strong><span>Client bands are unavailable</span></div></div>';
                } else {
                    bands.forEach(function (band) {
                        var item = document.createElement('span');
                        item.className = 'openap-client-band-count';
                        var badge = document.createElement('span');
                        badge.className = 'openap-hotspot-band-badge';
                        badge.textContent = band === '5' ? '5G' : '2.4G';
                        var count = document.createElement('strong');
                        count.dataset.openapClientBand = band;
                        count.textContent = Math.max(0, Number(data.bands && data.bands[band]) || 0);
                        item.appendChild(badge);
                        item.appendChild(count);
                        summary.appendChild(item);
                    });
                }
                summary.hidden = false;
            }
            card.dataset.openapClientApState = bands.join(',') + '|' + ssid;
        }

        function updateCard(card, data, bands) {
            var ssid = String(data.ssid || '-').trim() || '-';
            var currentBands = Array.from(card.querySelectorAll('[data-openap-client-band]'))
                .map(function (node) { return node.dataset.openapClientBand; });
            var currentSsidNode = card.querySelector('[data-openap-ap-ssid]');
            var currentSsid = currentSsidNode ? currentSsidNode.textContent.trim() : '-';
            var changed = currentBands.join(',') !== bands.join(',') || currentSsid !== ssid;

            if (!changed) {
                replaceCardState(card, data, bands);
                return;
            }
            if (card.dataset.openapClientAnimating === '1') {
                return;
            }

            var summary = card.querySelector('[data-openap-client-band-summary]');
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduceMotion || !summary || typeof summary.animate !== 'function') {
                replaceCardState(card, data, bands);
                syncOneByOneHeight();
                return;
            }

            card.dataset.openapClientAnimating = '1';
            var hide = summary.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(115%)', opacity: 0 }
            ], {
                duration: 330,
                easing: 'cubic-bezier(.55,0,1,.45)',
                fill: 'forwards'
            });
            hide.finished.then(function () {
                replaceCardState(card, data, bands);
                hide.cancel();
                summary.animate([
                    { transform: 'translateY(-115%)', opacity: 0 },
                    { transform: 'translateY(8%)', opacity: 1, offset: .82 },
                    { transform: 'translateY(0)', opacity: 1 }
                ], {
                    duration: 440,
                    easing: 'cubic-bezier(.22,1,.36,1)'
                });
                syncOneByOneHeight();
                window.setTimeout(function () { delete card.dataset.openapClientAnimating; }, 460);
            }).catch(function () {
                replaceCardState(card, data, bands);
                delete card.dataset.openapClientAnimating;
            });
        }

        function update() {
            if (window.openapSession.expired) return;
            fetch('/ajax/networking/get_dashboard_clients.php?t=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            }).then(function (data) {
                var bands = normalizedBands(data);
                document.querySelectorAll('[data-openap-client-count]').forEach(function (node) {
                    node.textContent = Math.max(0, Number(data.total) || 0);
                });
                cards.forEach(function (card) { updateCard(card, data, bands); });
            }).catch(function () {});
        }

        window.setTimeout(update, 1200);
        window.openapSession.setInterval(update, 4000);
    }

    function renderFromState() {
        applyOrder(state.order);
        applyWidths();
        applyHidden();
        refreshHiddenTray();
        syncOneByOneHeight();
    }

    function hideWidget(id) {
        if (isHidden(id)) {
            return;
        }
        state.hidden.push(id);
        applyHidden();
        refreshHiddenTray();
    }

    function showWidget(id) {
        var index = state.hidden.indexOf(id);
        if (index === -1) {
            return;
        }
        state.hidden.splice(index, 1);
        applyHidden();
        refreshHiddenTray();
    }

    function toggleWidth(id) {
        state.widths[id] = state.widths[id] === 'col-6' ? 'col-12' : 'col-6';
        applyWidths();
        syncOneByOneHeight();
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf_token"]');
        return meta ? meta.content : '';
    }

    function persist(successMessage, callback) {
        recomputeFullOrder();
        var data = new URLSearchParams();
        data.set('page', page);
        data.set('order', JSON.stringify(state.order));
        data.set('hidden', JSON.stringify(state.hidden));
        data.set('widths', JSON.stringify(state.widths));
        data.set('csrf_token', csrfToken());
        fetch('/ajax/widget_layout.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: data.toString()
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || ('HTTP ' + response.status));
                }
                return payload;
            });
        }).then(function (payload) {
            state = cloneLayout(payload);
            if (callback) {
                callback(true);
            } else if (successMessage) {
                announce(successMessage);
            }
        }).catch(function (error) {
            announce('Unable to save widget layout: ' + error.message);
            if (callback) {
                callback(false);
            }
        });
    }

    function setEditing(enabled) {
        enabled = Boolean(enabled && desktop.matches);
        area.classList.toggle('is-editing', enabled);
        editButton.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        editButton.querySelector('span').textContent = enabled ? 'Done' : 'Customize';
        editButton.querySelector('i').className = enabled ? 'fas fa-check' : 'fas fa-sliders';
        saveButton.hidden = !enabled;
        resetButton.hidden = !enabled;
        visibleItems().forEach(function (item) { item.draggable = enabled; });
        if (enabled) {
            refreshHiddenTray();
            announce('Edit mode enabled. Arrange freely, then Save.');
        } else {
            if (hiddenTray) {
                hiddenTray.hidden = true;
            }
            if (status) {
                status.textContent = '';
            }
        }
    }

    // Build the per-widget edit controls (drag handle + hide + width).
    function buildItemControls(item) {
        var id = item.dataset.openapWidgetId;
        var controls = document.createElement('div');
        controls.className = 'openap-widget-card-actions';
        controls.setAttribute('aria-hidden', 'false');

        var widthBtn = document.createElement('button');
        widthBtn.type = 'button';
        widthBtn.className = 'openap-widget-width-btn';
        widthBtn.title = 'Toggle single / double column width';
        widthBtn.setAttribute('aria-label', 'Toggle width of ' + label(id));
        widthBtn.innerHTML = '<i class="fas fa-expand" aria-hidden="true"></i>';
        if (id !== 'system-health' && id !== 'dhcp') {
            widthBtn.addEventListener('click', function () { toggleWidth(id); });
            controls.appendChild(widthBtn);
        }

        var hideBtn = document.createElement('button');
        hideBtn.type = 'button';
        hideBtn.className = 'openap-widget-hide-btn';
        hideBtn.title = 'Hide this widget';
        hideBtn.setAttribute('aria-label', 'Hide ' + label(id) + ' widget');
        hideBtn.innerHTML = '<i class="fas fa-eye-slash" aria-hidden="true"></i>';
        hideBtn.addEventListener('click', function () { hideWidget(id); });
        controls.appendChild(hideBtn);

        var title = item.querySelector('.openap-widget-title');
        var handleLabel = title ? title.textContent.trim() : label(id);
        var handle = document.createElement('button');
        handle.type = 'button';
        handle.className = 'openap-widget-drag-handle';
        handle.setAttribute('aria-label', 'Move ' + handleLabel + ' widget');
        handle.title = 'Drag to reorder; use arrow keys for keyboard control';
        handle.innerHTML = '<i class="fas fa-grip-vertical" aria-hidden="true"></i>';
        handle.addEventListener('pointerdown', function () { dragArmed = true; });
        handle.addEventListener('pointerup', function () { dragArmed = false; });
        handle.addEventListener('keydown', function (event) {
            if (!area.classList.contains('is-editing') || !desktop.matches) {
                return;
            }
            var sibling = null;
            if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
                sibling = item.previousElementSibling;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
                sibling = item.nextElementSibling;
            }
            if (!sibling || sibling.classList.contains('is-hidden')) {
                return;
            }
            event.preventDefault();
            if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
                grid.insertBefore(item, sibling);
            } else {
                grid.insertBefore(sibling, item);
            }
            recomputeFullOrder();
            handle.focus();
        });
        controls.appendChild(handle);

        item.appendChild(controls);
        item.draggable = false;
    }

    visibleItems().forEach(buildItemControls);

    visibleItems().forEach(function (item) {
        item.addEventListener('dragstart', function (event) {
            if (!dragArmed || !area.classList.contains('is-editing') || !desktop.matches) {
                event.preventDefault();
                return;
            }
            dragging = item;
            wideSwapTarget = '';
            smallSwapTarget = '';
            item.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.dataset.openapWidgetId);
            setDragGhost(event, item);
        });

        item.addEventListener('dragend', function () {
            dragArmed = false;
            if (dragging) {
                recomputeFullOrder();
            }
            dragging = null;
            wideSwapTarget = '';
            smallSwapTarget = '';
            visibleItems().forEach(function (widget) { widget.classList.remove('is-dragging', 'is-drag-target'); });
        });

        item.addEventListener('dragover', function (event) {
            if (!dragging || dragging === item) {
                return;
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            visibleItems().forEach(function (widget) { widget.classList.remove('is-drag-target'); });

            if (isHalfWidth(dragging) && isHalfWidth(item) && sameVisualRow(dragging, item)) {
                item.classList.add('is-drag-target');
                var smallTargetKey = item.dataset.openapWidgetId;
                if (smallSwapTarget === smallTargetKey) {
                    return;
                }
                var smallWidgets = visibleItems();
                var smallDraggingIndex = smallWidgets.indexOf(dragging);
                var smallTargetIndex = smallWidgets.indexOf(item);
                smallSwapTarget = smallTargetKey;
                moveWithAnimation(function () {
                    if (smallDraggingIndex < smallTargetIndex) {
                        grid.insertBefore(dragging, item.nextElementSibling);
                    } else {
                        grid.insertBefore(dragging, item);
                    }
                });
                return;
            }

            if (isHalfWidth(dragging) && isHalfWidth(item)) {
                wideSwapTarget = '';
                var verticalTargetKey = 'vertical:' + item.dataset.openapWidgetId;
                if (smallSwapTarget === verticalTargetKey) {
                    return;
                }
                var verticalWidgets = visibleItems();
                var verticalDraggingIndex = verticalWidgets.indexOf(dragging);
                var verticalTargetIndex = verticalWidgets.indexOf(item);
                smallSwapTarget = verticalTargetKey;
                verticalWidgets[verticalDraggingIndex] = item;
                verticalWidgets[verticalTargetIndex] = dragging;
                moveWithAnimation(function () {
                    verticalWidgets.forEach(function (widget) { grid.appendChild(widget); });
                });
                return;
            }

            if (dragging.classList.contains('col-12') && isHalfWidth(item)) {
                smallSwapTarget = '';
                var targetRow = halfWidthRow(item);
                targetRow.forEach(function (widget) { widget.classList.add('is-drag-target'); });
                var targetKey = targetRow.map(function (widget) { return widget.dataset.openapWidgetId; }).join('|');
                if (wideSwapTarget === targetKey) {
                    return;
                }
                var orderedWidgets = visibleItems();
                var draggingIndex = orderedWidgets.indexOf(dragging);
                var targetIndex = orderedWidgets.indexOf(targetRow[0]);
                wideSwapTarget = targetKey;
                moveWithAnimation(function () {
                    if (draggingIndex < targetIndex) {
                        grid.insertBefore(dragging, targetRow[targetRow.length - 1].nextElementSibling);
                    } else {
                        grid.insertBefore(dragging, targetRow[0]);
                    }
                });
                return;
            }

            wideSwapTarget = '';
            smallSwapTarget = '';
            item.classList.add('is-drag-target');
            var rect = item.getBoundingClientRect();
            var before = event.clientY < rect.top + (rect.height / 2);
            moveWithAnimation(function () {
                grid.insertBefore(dragging, before ? item : item.nextElementSibling);
            });
        });
    });

    editButton.addEventListener('click', function () {
        setEditing(!area.classList.contains('is-editing'));
    });

    saveButton.addEventListener('click', function () {
        persist('Widget layout saved for this account.', function (ok) {
            if (ok) {
                renderFromState();
                setEditing(false);
                announce('Widget layout saved for this account.');
            }
        });
    });

    resetButton.addEventListener('click', function () {
        state = cloneLayout({
            order: DEFAULT_ORDER.slice(),
            hidden: [],
            widths: DEFAULT_WIDTHS
        });
        renderFromState();
        persist('Widget layout reset to defaults for this account.', function (ok) {
            if (ok) {
                renderFromState();
                setEditing(false);
                announce('Widget layout reset to defaults for this account.');
            }
        });
    });

    var handleViewportChange = function () {
        setEditing(false);
    };
    if (typeof desktop.addEventListener === 'function') {
        desktop.addEventListener('change', handleViewportChange);
    } else {
        desktop.addListener(handleViewportChange);
    }
    window.addEventListener('resize', syncOneByOneHeight);

    renderFromState();
    initConnectedClientsMonitor();
    initServiceStatusMonitor();
    initDhcpSettingMonitor();
}());
