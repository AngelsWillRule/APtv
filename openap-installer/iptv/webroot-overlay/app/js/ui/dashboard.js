(function () {
    'use strict';

    var modalMap = {
        'hotspot': { id: 'hotspotModal' },
        'wifi-qr': { id: 'wifiQrModal' },
        'service-logs': { id: 'serviceLogsModal' },
        'ap-ethernet': { id: 'apEthernetModal', url: '/ap_ethernet_embed.php', content: 'apEthernetModalContent', fragment: true },
        'uplink': { id: 'uplinkModal', url: '/uplink_embed.php', content: 'uplinkModalContent', fragment: true }
    };

    function uplinkScanProgressMarkup() {
        return '<div class="openap-uplink-scan-state" role="status" aria-live="polite">' +
            '<div class="openap-uplink-scan-visual" aria-hidden="true">' +
              '<span class="openap-uplink-scan-ring"></span>' +
              '<span class="openap-uplink-scan-icon"><i class="fas fa-wifi"></i></span>' +
            '</div>' +
            '<div class="openap-uplink-scan-label">Scanning for available networks</div>' +
          '</div>';
    }

    function animateModalMutation(content, modalSelector, mutation) {
        var modal = content ? content.closest(modalSelector) : null;
        if (!modal || typeof mutation !== 'function') {
            if (typeof mutation === 'function') mutation();
            return;
        }

        if (modal.openapResizeTimer) {
            window.clearTimeout(modal.openapResizeTimer);
            modal.classList.remove('is-resizing');
            modal.style.width = '';
            modal.style.height = '';
        }

        var start = modal.getBoundingClientRect();
        mutation();
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        modal.style.width = '';
        modal.style.height = '';
        var end = modal.getBoundingClientRect();
        if (Math.abs(start.height - end.height) < 1 && Math.abs(start.width - end.width) < 1) return;

        modal.style.width = start.width + 'px';
        modal.style.height = start.height + 'px';
        modal.getBoundingClientRect();
        modal.classList.add('is-resizing');
        window.requestAnimationFrame(function () {
            modal.style.width = end.width + 'px';
            modal.style.height = end.height + 'px';
        });
        modal.openapResizeTimer = window.setTimeout(function () {
            modal.classList.remove('is-resizing');
            modal.style.width = '';
            modal.style.height = '';
            delete modal.openapResizeTimer;
        }, 380);
    }

    function animateUplinkModalMutation(content, mutation) {
        animateModalMutation(content, '.openap-repeater-modal', mutation);
    }

    function animateApEthernetModalMutation(content, mutation) {
        animateModalMutation(content, '.openap-ap-ethernet-modal', mutation);
    }

    function replaceUplinkModalContent(content, html, afterReplace) {
        animateUplinkModalMutation(content, function () {
            content.innerHTML = html;
            if (typeof afterReplace === 'function') afterReplace();
        });
    }

    window.openapScanUplinkNetworks = function (trigger) {
        var content = document.getElementById('uplinkModalContent');
        if (!content || !trigger || trigger.disabled) return;

        trigger.disabled = true;
        trigger.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span><span>Scanning...</span>';
        content.setAttribute('aria-busy', 'true');
        replaceUplinkModalContent(content, uplinkScanProgressMarkup());

        fetch('/uplink_embed.php?scan=1', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            replaceUplinkModalContent(content, html, function () { bindDynamicContent(content); });
        }).catch(function (error) {
            replaceUplinkModalContent(content, '<div class="alert alert-danger m-3">Unable to scan networks: ' + escapeHtml(error.message) + '</div>');
            trigger.hidden = false;
        }).finally(function () {
            trigger.disabled = false;
            trigger.innerHTML = '<i class="fas fa-exchange-alt" aria-hidden="true"></i><span>Change uplink</span>';
            content.removeAttribute('aria-busy');
        });
    };

    function copyOperationText(value) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(value);
        var handler = function (event) {
            event.clipboardData.setData('text/plain', value);
            event.preventDefault();
        };
        document.addEventListener('copy', handler, { once: true });
        var copied = document.execCommand('copy');
        if (!copied) document.removeEventListener('copy', handler);
        return copied ? Promise.resolve() : Promise.reject(new Error('copy failed'));
    }

    function showOperationToast(level, text, title, details, onClose) {
        if ((level === 'success' || level === 'danger') && typeof window.openapNotify === 'function') {
            return window.openapNotify({
                level: level === 'danger' ? 'error' : 'success',
                title: title || (level === 'success' ? 'Operation successful' : 'Operation failed'),
                message: level === 'success' ? 'Settings successfully applied.' : text,
                duration: level === 'success' ? 5000 : 0,
                details: Array.isArray(details) && details.length ? details : (level === 'danger' ? [
                    'Failed operation: ' + (title || 'Dashboard configuration'),
                    'Cause reported by OpenAP: ' + text,
                    'Diagnostic source: System and networking service logs'
                ] : ['No setting values changed']),
                onClose: onClose
            });
            return;
        }
        var old = document.getElementById('apConfigurationToast');
        if (old) old.remove();
        var toast = document.createElement('div');
        toast.id = 'apConfigurationToast';
        toast.className = 'openap-ap-config-toast ' + level;
        toast.setAttribute('role', level === 'success' ? 'status' : 'alert');
        toast.innerHTML = '<i class="fas ' + (level === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle')
            + '"></i><div class="openap-toast-message"><strong></strong><span></span></div>'
            + (level === 'danger' ? '<button type="button" class="openap-toast-close" aria-label="Close error"><i class="fas fa-times"></i></button><button type="button" class="openap-toast-copy"><i class="far fa-copy"></i><span>Copy error</span></button>' : '');
        toast.querySelector('strong').textContent = title
            || (level === 'success' ? 'Operation successful' : 'Operation failed');
        toast.querySelector('span').textContent = text;
        toast.dataset.copyText = toast.querySelector('strong').textContent + ': ' + text;
        document.body.appendChild(toast);
        window.requestAnimationFrame(function () { toast.classList.add('show'); });
        if (level === 'danger') {
            toast.querySelector('.openap-toast-close').addEventListener('click', function () { toast.remove(); });
            toast.querySelector('.openap-toast-copy').addEventListener('click', function () {
                copyOperationText(toast.dataset.copyText).then(function () {
                    toast.querySelector('.openap-toast-copy span').textContent = 'Copied';
                }).catch(function () {
                    toast.querySelector('.openap-toast-copy span').textContent = 'Copy failed';
                });
            });
            return;
        }
        window.setTimeout(function () {
            toast.classList.add('leaving');
            toast.classList.remove('show');
            window.setTimeout(function () { toast.remove(); }, 180);
        }, 5000);
    }

    function invalidModalFieldMessage(field) {
        var label = field.id ? document.querySelector('label[for="' + field.id + '"]') : null;
        var fieldName = label ? label.textContent.trim() : (field.name || 'Field');
        if (field.validity.valueMissing) return fieldName + ' is required.';
        if (field.validity.patternMismatch) return 'Check the format entered for ' + fieldName + '.';
        return 'Check the value entered for ' + fieldName + '.';
    }

    function animateModeSelector(targetMode) {
        var selector = document.querySelector('.openap-mode-selector');
        if (!selector) return;
        var toRepeater = targetMode === 'repeater_wifi';
        var toBridge = targetMode === 'ap_ethernet_bridge';
        var indicator = selector.querySelector('.openap-mode-selector-indicator');
        var options = Array.from(selector.querySelectorAll('.openap-mode-option'));
        var ethernetState = options[0] ? options[0].querySelector('.openap-mode-option-state') : null;
        var repeaterState = options[1] ? options[1].querySelector('.openap-mode-option-state') : null;
        var repeaterCard = options[1] || null;
        var nextStates = [
            toRepeater ? 'Switch →' : (toBridge ? '● Bridge' : '● Routed / NAT'),
            toRepeater ? '● Active' : (toBridge ? 'Routed first' : 'Switch →')
        ];
        if (indicator) indicator.style.transition = 'none';
        var startTransform = indicator ? window.getComputedStyle(indicator).transform : 'none';
        selector.dataset.currentMode = targetMode;
        if (repeaterCard) {
            repeaterCard.dataset.bridgeBlocked = toBridge ? '1' : '0';
            repeaterCard.classList.toggle('is-unavailable', toBridge);
            repeaterCard.dataset.suppressAvailabilityAnimationOnce = '1';
        }
        selector.classList.toggle('is-repeater', toRepeater);
        selector.classList.toggle('is-ap-ethernet', !toRepeater);
        selector.classList.add('is-switching');
        options.forEach(function (option, index) {
            var active = toRepeater ? index === 1 : index === 0;
            option.classList.toggle('active', active);
            if (active) {
                option.setAttribute('aria-current', 'true');
            } else {
                option.removeAttribute('aria-current');
            }
        });
        var stateNodes = [ethernetState, repeaterState].filter(Boolean);
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || !stateNodes.length || typeof stateNodes[0].animate !== 'function') {
            if (ethernetState) ethernetState.textContent = nextStates[0];
            if (repeaterState) repeaterState.textContent = nextStates[1];
        } else {
            stateNodes.forEach(function (state, index) {
                state.getAnimations().forEach(function (animation) { animation.cancel(); });
                var outgoing = state.animate([
                    { transform: 'translateY(0)', opacity: 1 },
                    { transform: 'translateY(115%)', opacity: 0 }
                ], {
                    duration: 280,
                    delay: index * 45,
                    easing: 'cubic-bezier(.55,0,1,.45)',
                    fill: 'forwards'
                });
                outgoing.finished.then(function () {
                    state.textContent = state === ethernetState ? nextStates[0] : nextStates[1];
                    state.getAnimations().forEach(function (animation) { animation.cancel(); });
                    state.animate([
                        { transform: 'translateY(115%)', opacity: 0 },
                        { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                        { transform: 'translateY(0)', opacity: 1 }
                    ], {
                        duration: 420,
                        easing: 'cubic-bezier(.22,1,.36,1)'
                    });
                }).catch(function () {});
            });
        }
        if (indicator) void indicator.offsetWidth;
        if (indicator && typeof indicator.animate === 'function'
            && !reduceMotion) {
            var endTransform = window.getComputedStyle(indicator).transform;
            indicator.getAnimations().forEach(function (animation) { animation.cancel(); });
            var movement = indicator.animate([
                { transform: startTransform },
                { transform: endTransform }
            ], {
                duration: 900,
                easing: 'cubic-bezier(.22,1,.36,1)'
            });
            movement.finished.finally(function () { indicator.style.transition = ''; });
        } else if (indicator) {
            indicator.style.transition = '';
        }
    }

    function animateTopologyModeIcon(targetMode) {
        var icon = document.querySelector('[data-openap-topology-mode-icon]');
        if (!icon || icon.dataset.animating === '1') return;

        var viewport = icon.querySelector('.openap-topology-mode-icon-viewport');
        var currentGlyph = viewport ? viewport.querySelector('.openap-topology-mode-glyph') : null;
        var statusDot = icon.querySelector('.topo-status-indicator');
        var toRepeater = targetMode === 'repeater_wifi';
        var nextClass = toRepeater ? 'fa-wifi' : 'fa-network-wired';
        if (!viewport || !currentGlyph || currentGlyph.classList.contains(nextClass)) return;

        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof currentGlyph.animate !== 'function') {
            currentGlyph.className = 'fas ' + nextClass + ' openap-topology-mode-glyph';
            icon.dataset.currentMode = targetMode;
            return;
        }

        icon.dataset.animating = '1';
        var nextGlyph = document.createElement('i');
        nextGlyph.className = 'fas ' + nextClass + ' openap-topology-mode-glyph openap-topology-mode-glyph-next';
        viewport.appendChild(nextGlyph);

        if (statusDot && typeof statusDot.animate === 'function') {
            statusDot.getAnimations().forEach(function (animation) { animation.cancel(); });
            statusDot.animate([
                { transform: 'scale(1)', opacity: 1 },
                { transform: 'scale(0)', opacity: 0 }
            ], {
                duration: 260,
                easing: 'cubic-bezier(.4,0,1,1)',
                fill: 'forwards'
            });
        }

        window.setTimeout(function () {
            var outgoing = currentGlyph.animate([
                { transform: 'translateX(0)', opacity: 1 },
                { transform: 'translateX(145%)', opacity: 0 }
            ], {
                duration: 680,
                easing: 'cubic-bezier(.65,0,.35,1)',
                fill: 'forwards'
            });
            nextGlyph.animate([
                { transform: 'translateX(-145%)', opacity: 0 },
                { transform: 'translateX(0)', opacity: 1 }
            ], {
                duration: 680,
                easing: 'cubic-bezier(.65,0,.35,1)',
                fill: 'forwards'
            });

            outgoing.finished.then(function () {
                currentGlyph.remove();
                nextGlyph.classList.remove('openap-topology-mode-glyph-next');
                nextGlyph.getAnimations().forEach(function (animation) { animation.cancel(); });
                icon.dataset.currentMode = targetMode;
                delete icon.dataset.animating;
                if (statusDot && typeof statusDot.animate === 'function') {
                    statusDot.getAnimations().forEach(function (animation) { animation.cancel(); });
                    statusDot.animate([
                        { transform: 'scale(0)', opacity: 0 },
                        { transform: 'scale(1.18)', opacity: 1, offset: .72 },
                        { transform: 'scale(1)', opacity: 1 }
                    ], {
                        duration: 360,
                        easing: 'cubic-bezier(.22,1,.36,1)'
                    });
                }
            }).catch(function () {
                delete icon.dataset.animating;
            });
        }, 260);
    }

    function animateTopologyModeText(targetMode) {
        var node = document.querySelector('[data-openap-topology-node="uplink"]');
        if (!node || node.dataset.textAnimating === '1') return;

        var label = node.querySelector('[data-openap-topology-mode-label]');
        var sub = node.querySelector('[data-openap-topology-mode-sub]');
        var lines = [label, sub].filter(Boolean);
        if (!lines.length) return;

        var toRepeater = targetMode === 'repeater_wifi';
        var nextText = {
            label: toRepeater ? 'Uplink AP' : 'Ethernet uplink',
            sub: toRepeater ? 'WiFi uplink' : 'Ethernet'
        };
        var freshTextRequest = fetch(toRepeater ? '/uplink_embed.php?status=1&format=json' : '/', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return toRepeater ? response.json() : response.text();
        }).then(function (payload) {
            if (toRepeater) {
                if (payload && payload.ssid) nextText.sub = String(payload.ssid).trim();
                return;
            }
            var html = payload;
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var freshNode = parsed.querySelector('[data-openap-topology-node="uplink"]');
            var freshLabel = freshNode ? freshNode.querySelector('[data-openap-topology-mode-label]') : null;
            var freshSub = freshNode ? freshNode.querySelector('[data-openap-topology-mode-sub]') : null;
            if (freshLabel && freshLabel.textContent.trim()) nextText.label = freshLabel.textContent.trim();
            if (freshSub && freshSub.textContent.trim()) nextText.sub = freshSub.textContent.trim();
        }).catch(function () {
            // The mode-switch transport can briefly disappear. Keep the
            // generic fallback only when no confirmed SSID is available.
        });
        var freshText = Promise.race([
            freshTextRequest,
            new Promise(function (resolve) { window.setTimeout(resolve, 900); })
        ]);

        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof lines[0].animate !== 'function') {
            freshText.finally(function () {
                if (label) label.textContent = nextText.label;
                if (sub) sub.textContent = nextText.sub;
            });
            return;
        }

        node.dataset.textAnimating = '1';
        lines.forEach(function (line, index) {
            window.setTimeout(function () {
                line.animate([
                    { transform: 'translateY(0)', opacity: 1 },
                    { transform: 'translateY(115%)', opacity: 0 }
                ], {
                    duration: 330,
                    easing: 'cubic-bezier(.55,0,1,.45)',
                    fill: 'forwards'
                });
            }, index * 45);
        });

        Promise.all([
            freshText,
            new Promise(function (resolve) { window.setTimeout(resolve, 430); })
        ]).finally(function () {
            if (label) label.textContent = nextText.label;
            if (sub) sub.textContent = nextText.sub;
            lines.forEach(function (line) {
                line.getAnimations().forEach(function (animation) { animation.cancel(); });
                line.animate([
                    { transform: 'translateY(115%)', opacity: 0 },
                    { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                    { transform: 'translateY(0)', opacity: 1 }
                ], {
                    duration: 440,
                    easing: 'cubic-bezier(.22,1,.36,1)'
                });
            });
            window.setTimeout(function () { delete node.dataset.textAnimating; }, 460);
        });
    }

    function animateUplinkGateway(nextGateway) {
        var nextText = String(nextGateway || '-').trim() || '-';
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        document.querySelectorAll('[data-openap-uplink-gateway]').forEach(function (node) {
            if (node.dataset.textAnimating === '1' || node.textContent.trim() === nextText) return;
            if (reduceMotion || typeof node.animate !== 'function') {
                node.textContent = nextText;
                return;
            }

            node.dataset.textAnimating = '1';
            node.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(115%)', opacity: 0 }
            ], {
                duration: 330,
                easing: 'cubic-bezier(.55,0,1,.45)',
                fill: 'forwards'
            });

            window.setTimeout(function () {
                node.textContent = nextText;
                node.getAnimations().forEach(function (animation) { animation.cancel(); });
                node.animate([
                    { transform: 'translateY(115%)', opacity: 0 },
                    { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                    { transform: 'translateY(0)', opacity: 1 }
                ], {
                    duration: 440,
                    easing: 'cubic-bezier(.22,1,.36,1)'
                });
                window.setTimeout(function () { delete node.dataset.textAnimating; }, 460);
            }, 430);
        });
    }

    function animateTrafficUplinkIdentity(freshDocument) {
        var selectors = [
            '[data-openap-traffic-uplink-name]',
            '[data-openap-traffic-uplink-device]',
            '[data-openap-traffic-uplink-address]',
            '[data-openap-traffic-ap-address]'
        ];
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        selectors.forEach(function (selector) {
            var node = document.querySelector(selector);
            var freshNode = freshDocument.querySelector(selector);
            if (!node || !freshNode) return;

            var nextText = freshNode.textContent;
            if (node.dataset.textAnimating === '1' || node.textContent === nextText) return;
            if (reduceMotion || typeof node.animate !== 'function') {
                node.textContent = nextText;
                return;
            }

            node.dataset.textAnimating = '1';
            var outgoing = node.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(115%)', opacity: 0 }
            ], {
                duration: 320,
                easing: 'cubic-bezier(.55,0,1,.45)',
                fill: 'forwards'
            });

            outgoing.finished.then(function () {
                node.textContent = nextText;
                outgoing.cancel();
                node.animate([
                    { transform: 'translateY(-115%)', opacity: 0 },
                    { transform: 'translateY(8%)', opacity: 1, offset: .82 },
                    { transform: 'translateY(0)', opacity: 1 }
                ], {
                    duration: 440,
                    easing: 'cubic-bezier(.22,1,.36,1)'
                });
                window.setTimeout(function () { delete node.dataset.textAnimating; }, 460);
            }).catch(function () {
                node.textContent = nextText;
                delete node.dataset.textAnimating;
            });
        });
    }

    function applyNetworkUplinkWidgetSnapshot(parsed, expectedMode) {
        var modeNode = parsed.querySelector('.openap-mode-selector[data-current-mode]');
        var freshCard = parsed.querySelector('[data-openap-widget-id="uplink"] > .stat-card');
        var currentCards = Array.from(document.querySelectorAll('[data-openap-widget-id="uplink"] > .stat-card'));
        if (!modeNode || modeNode.dataset.currentMode !== expectedMode || !freshCard || currentCards.length === 0) {
            return false;
        }

        animateTrafficUplinkIdentity(parsed);
        currentCards.forEach(function (currentCard) {
                    var currentValues = Array.from(currentCard.querySelectorAll('.openap-widget-value'));
                    var freshValues = Array.from(freshCard.querySelectorAll('.openap-widget-value'));
                    var previousValues = currentValues.map(function (node) {
                        return {
                            text: node.textContent,
                            title: node.getAttribute('title')
                        };
                    });

                    currentCard.className = freshCard.className;
                    currentCard.innerHTML = freshCard.innerHTML;

                    var nextValues = Array.from(currentCard.querySelectorAll('.openap-widget-value'));
                    nextValues.forEach(function (node, index) {
                        var previous = previousValues[index];
                        var freshValue = freshValues[index];
                        if (!previous || !freshValue) return;

                        var nextText = freshValue.textContent;
                        var nextTitle = freshValue.getAttribute('title');
                        if (previous.text === nextText && previous.title === nextTitle) return;

                        node.textContent = previous.text;
                        if (previous.title === null) node.removeAttribute('title');
                        else node.setAttribute('title', previous.title);

                        var replaceValue = function () {
                            node.textContent = nextText;
                            if (nextTitle === null) node.removeAttribute('title');
                            else node.setAttribute('title', nextTitle);
                        };
                        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches ||
                            typeof node.animate !== 'function') {
                            replaceValue();
                            return;
                        }

                        var outgoing = node.animate([
                            { transform: 'translateY(0)', opacity: 1 },
                            { transform: 'translateY(115%)', opacity: 0 }
                        ], {
                            duration: 320,
                            easing: 'cubic-bezier(.55,0,1,.45)',
                            fill: 'forwards'
                        });
                        outgoing.finished.then(function () {
                            replaceValue();
                            outgoing.cancel();
                            node.animate([
                                { transform: 'translateY(-115%)', opacity: 0 },
                                { transform: 'translateY(8%)', opacity: 1, offset: .82 },
                                { transform: 'translateY(0)', opacity: 1 }
                            ], {
                                duration: 440,
                                easing: 'cubic-bezier(.22,1,.36,1)'
                            });
                        }).catch(function () {
                            replaceValue();
                        });
                    });
        });
        return true;
    }

    function refreshNetworkUplinkWidget(expectedMode, attemptsLeft, verifiedDocument) {
        if (verifiedDocument && applyNetworkUplinkWidgetSnapshot(verifiedDocument, expectedMode)) return;
        if (attemptsLeft <= 0) return;
        window.setTimeout(function () {
            fetch('/?widget_refresh=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            }).then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                if (!applyNetworkUplinkWidgetSnapshot(parsed, expectedMode)) {
                    refreshNetworkUplinkWidget(expectedMode, attemptsLeft - 1);
                }
            }).catch(function () {
                refreshNetworkUplinkWidget(expectedMode, attemptsLeft - 1);
            });
        }, 750);
    }

    function animateUplinkAddress(nextAddress) {
        var node = document.querySelector('[data-openap-uplink-address]');
        if (!node || node.dataset.textAnimating === '1') return;

        var nextText = String(nextAddress || '-').trim() || '-';
        if (node.textContent.trim() === nextText) return;

        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof node.animate !== 'function') {
            node.textContent = nextText;
            return;
        }

        node.dataset.textAnimating = '1';
        node.animate([
            { transform: 'translateY(0)', opacity: 1 },
            { transform: 'translateY(115%)', opacity: 0 }
        ], {
            duration: 330,
            easing: 'cubic-bezier(.55,0,1,.45)',
            fill: 'forwards'
        });

        window.setTimeout(function () {
            node.textContent = nextText;
            node.getAnimations().forEach(function (animation) { animation.cancel(); });
            node.animate([
                { transform: 'translateY(115%)', opacity: 0 },
                { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                { transform: 'translateY(0)', opacity: 1 }
            ], {
                duration: 440,
                easing: 'cubic-bezier(.22,1,.36,1)'
            });
            window.setTimeout(function () { delete node.dataset.textAnimating; }, 460);
        }, 430);
    }

    function isValidIpv4Address(value) {
        var parts = String(value || '').trim().split('.');
        if (parts.length !== 4) return false;
        return parts.every(function (part) {
            if (!/^\d{1,3}$/.test(part)) return false;
            var octet = Number(part);
            return octet >= 0 && octet <= 255;
        });
    }

    function animateTopologyHotspotAddress(nextAddress) {
        var node = document.querySelector('[data-openap-topology-hotspot-address]');
        if (!node || node.dataset.textAnimating === '1') return;

        var nextText = String(nextAddress || '').trim();
        // During a routed/bridge transition the freshly rendered dashboard can
        // briefly expose "-" before the management address is assigned. Never
        // replace a valid visible address with that transient placeholder.
        if (!isValidIpv4Address(nextText)) return;
        if (node.textContent.trim() === nextText) return;

        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof node.animate !== 'function') {
            node.textContent = nextText;
            return;
        }

        node.dataset.textAnimating = '1';
        node.animate([
            { transform: 'translateY(0)', opacity: 1 },
            { transform: 'translateY(115%)', opacity: 0 }
        ], {
            duration: 330,
            easing: 'cubic-bezier(.55,0,1,.45)',
            fill: 'forwards'
        });

        window.setTimeout(function () {
            node.textContent = nextText;
            node.getAnimations().forEach(function (animation) { animation.cancel(); });
            node.animate([
                { transform: 'translateY(115%)', opacity: 0 },
                { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                { transform: 'translateY(0)', opacity: 1 }
            ], {
                duration: 440,
                easing: 'cubic-bezier(.22,1,.36,1)'
            });
            window.setTimeout(function () { delete node.dataset.textAnimating; }, 460);
        }, 430);
    }

    function animateTopologyClientNetwork(nextNetwork) {
        var clientsNode = document.querySelector('[data-openap-topology-node="clients"]');
        var node = clientsNode ? clientsNode.querySelector('[data-openap-topology-sub]') : null;
        if (!node || node.dataset.textAnimating === '1') return;

        var nextText = String(nextNetwork || '-').trim() || '-';
        node.dataset.apReadyText = nextText;
        if (node.textContent.trim() === nextText) return;
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof node.animate !== 'function') {
            node.textContent = nextText;
            return;
        }
        node.dataset.textAnimating = '1';
        var outgoing = node.animate([
            { transform: 'translateY(0)', opacity: 1 },
            { transform: 'translateY(115%)', opacity: 0 }
        ], {
            duration: 330,
            easing: 'cubic-bezier(.55,0,1,.45)',
            fill: 'forwards'
        });
        outgoing.finished.then(function () {
            node.textContent = nextText;
            outgoing.cancel();
            return node.animate([
                { transform: 'translateY(-115%)', opacity: 0 },
                { transform: 'translateY(8%)', opacity: 1, offset: .82 },
                { transform: 'translateY(0)', opacity: 1 }
            ], {
                duration: 440,
                easing: 'cubic-bezier(.22,1,.36,1)'
            }).finished.catch(function () {});
        }).catch(function () {
            node.textContent = nextText;
        }).finally(function () {
            delete node.dataset.textAnimating;
        });
    }

    function showModeSwitchAnimation(targetMode) {
        var old = document.getElementById('openapModeSwitchOverlay');
        if (old) old.remove();
        document.body.classList.add('openap-universal-apply-active');
        var toRepeater = targetMode === 'repeater_wifi';
        var overlay = document.createElement('div');
        overlay.id = 'openapModeSwitchOverlay';
        overlay.className = 'openap-mode-switch-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML =
            '<div class="openap-mode-switch-card openap-universal-mode-apply">' +
              '<div class="openap-mode-switch-eyebrow"><i class="fas fa-shuffle"></i> OpenAP</div>' +
              '<div class="openap-mode-switch-title">Applying changes</div>' +
              '<div class="openap-mode-switch-caption">Network services may take a few seconds to settle.</div>' +
              '<div class="openap-universal-mode-visual" aria-hidden="true"><span><i class="fas ' + (toRepeater ? 'fa-wifi' : 'fa-network-wired') + '"></i></span></div>' +
              '<div class="openap-mode-switch-steps">' +
                '<div class="active"><i class="fas fa-circle-notch fa-spin"></i><span>' + (toRepeater ? 'Validating Wi-Fi uplink settings' : 'Validating Ethernet configuration') + '</span></div>' +
                '<div><i class="far fa-circle"></i><span>' + (toRepeater ? 'Applying repeater network services' : 'Applying routes and firewall') + '</span></div>' +
                '<div><i class="far fa-circle"></i><span>' + (toRepeater ? 'Verifying uplink connectivity' : 'Verifying Ethernet Mode services') + '</span></div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        var steps = Array.from(overlay.querySelectorAll('.openap-mode-switch-steps > div'));
        var timers = [];
        var successDetails = [];

        function activateStep(index) {
            steps.forEach(function (step, stepIndex) {
                step.classList.toggle('active', stepIndex === index);
                step.classList.toggle('done', stepIndex < index);
                var icon = step.querySelector('i');
                if (!icon) return;
                icon.className = stepIndex < index
                    ? 'fas fa-check-circle'
                    : (stepIndex === index ? 'fas fa-circle-notch fa-spin' : 'far fa-circle');
            });
        }
        function revealOverlay() {
            if (overlay.dataset.revealed === '1' || !overlay.isConnected) return;
            overlay.dataset.revealed = '1';
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    overlay.classList.add('show');
                });
            });
        }

        var openModeModal = document.querySelector('#apEthernetModal.show, #uplinkModal.show');
        if (openModeModal) {
            openModeModal.dataset.suppressRefreshOnce = '1';
            openModeModal.addEventListener('hidden.bs.modal', revealOverlay, { once: true });
            bootstrap.Modal.getOrCreateInstance(openModeModal).hide();
            window.setTimeout(revealOverlay, 500);
        } else {
            revealOverlay();
        }

        function close(delay, afterClose) {
            timers.forEach(window.clearTimeout);
            window.setTimeout(function () {
                overlay.classList.remove('show');
                window.setTimeout(function () {
                    overlay.remove();
                    document.body.classList.remove('openap-universal-apply-active');
                    if (typeof afterClose === 'function') afterClose();
                }, 220);
            }, delay || 0);
        }

        return {
            complete: function (afterClose, snapshotHtml) {
                if (toRepeater) {
                    try {
                        // Keep the recently confirmed uplink state available to
                        // the live topology checks while the success notice is
                        // visible, without replacing the animated dashboard.
                        window.sessionStorage.setItem(
                            'openapTopologyRecentUplinkSuccessUntil',
                            String(Date.now() + 15000)
                        );
                    } catch (error) {}
                }
                ['apEthernetModal', 'uplinkModal'].forEach(function (modalId) {
                    var modalElement = document.getElementById(modalId);
                    if (modalElement && modalElement.classList.contains('show')) {
                        modalElement.dataset.suppressRefreshOnce = '1';
                        bootstrap.Modal.getOrCreateInstance(modalElement).hide();
                    }
                });
                activateStep(3);
                steps.forEach(function (step) {
                    step.classList.remove('active');
                    step.classList.add('done');
                    var icon = step.querySelector('i');
                    if (icon) icon.className = 'fas fa-check-circle';
                });
                var targetNode = overlay.querySelector('.openap-mode-node.target');
                if (targetNode) targetNode.classList.add('complete');
                overlay.querySelector('.openap-mode-switch-title').textContent = toRepeater ? 'Repeater Mode active' : 'Ethernet Mode active';
                overlay.querySelector('.openap-mode-switch-caption').textContent = 'Connectivity verified successfully.';
                close(700, function () {
                    window.setTimeout(function () {
                        window.requestAnimationFrame(function () {
                            delete window.openapLocalModeSwitchGuard;
                            window.dispatchEvent(new CustomEvent('openap:mode-switch-confirmed', {
                                detail: { targetMode: targetMode, snapshotHtml: snapshotHtml || '' }
                            }));
                            animateModeSelector(targetMode);
                            animateTopologyModeIcon(targetMode);
                            animateTopologyModeText(targetMode);
                            if (typeof afterClose === 'function') {
                                afterClose();
                            } else {
                                window.setTimeout(function () {
                                    showOperationToast('success', 'Settings successfully applied.', 'Operation successful', successDetails);
                                }, 900);
                            }
                        });
                    }, 450);
                });
            },
            setSuccessDetails: function (details) {
                successDetails = Array.isArray(details) ? details : [];
            },
            apply: function () {
                activateStep(1);
            },
            verify: function () {
                activateStep(2);
            },
            fail: function () {
                delete window.openapLocalModeSwitchGuard;
                overlay.classList.add('failed');
                var activeStep = overlay.querySelector('.openap-mode-switch-steps .active');
                if (activeStep) {
                    activeStep.classList.add('error');
                    var activeIcon = activeStep.querySelector('i');
                    if (activeIcon) activeIcon.className = 'fas fa-circle-exclamation';
                }
                overlay.querySelector('.openap-mode-switch-title').textContent = 'Mode switch not completed';
                overlay.querySelector('.openap-mode-switch-caption').textContent = 'OpenAP kept the last confirmed network state.';
                close(1800);
            }
        };
    }

    function initHotspotTabs() {
        var modal = document.getElementById('hotspotModal') || document.getElementById('apConfigurationPanel');
        if (!modal || modal.dataset.openapTabsInitialized === '1') {
            return;
        }
        var tabs = Array.from(modal.querySelectorAll('[data-hotspot-tab]'));
        var panes = Array.from(modal.querySelectorAll('.hotspot-pane'));

        if (!tabs.length) {
            modal.dataset.openapTabsInitialized = '1';
            return;
        }

        function activateTab(tab) {
            var targetId = 'hsp-' + tab.dataset.hotspotTab;
            tabs.forEach(function (item) {
                var active = item === tab;
                item.classList.toggle('active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
                item.tabIndex = active ? 0 : -1;
            });
            panes.forEach(function (pane) {
                var active = pane.id === targetId;
                pane.classList.toggle('active', active);
                pane.setAttribute('aria-hidden', active ? 'false' : 'true');
            });
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () {
                activateTab(tab);
            });
            tab.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                event.preventDefault();
                var offset = event.key === 'ArrowRight' ? 1 : -1;
                var next = tabs[(index + offset + tabs.length) % tabs.length];
                activateTab(next);
                next.focus();
            });
        });
        activateTab(tabs.find(function (tab) { return tab.classList.contains('active'); }) || tabs[0]);
        modal.dataset.openapTabsInitialized = '1';
    }

    function initHotspotPasswordToggle() {
        var modal = document.getElementById('hotspotModal') || document.getElementById('apConfigurationPanel');
        var input = modal ? modal.querySelector('#pskDisplay') : null;
        var button = modal ? modal.querySelector('[data-hotspot-toggle-psk]') : null;
        if (!input || !button || button.dataset.openapToggleInitialized === '1') {
            return;
        }
        button.addEventListener('click', function () {
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', !reveal);
                icon.classList.toggle('fa-eye-slash', reveal);
            }
        });
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('aria-label', 'Show password');
        button.dataset.openapToggleInitialized = '1';
    }

    function initUplinkModalRefresh() {
        var modal = document.getElementById('uplinkModal');
        if (!modal || modal.dataset.openapRefreshInitialized === '1') return;
        modal.addEventListener('hidden.bs.modal', function () {
            if (modal.dataset.suppressRefreshOnce === '1') {
                delete modal.dataset.suppressRefreshOnce;
                delete modal.dataset.refreshOnClose;
                return;
            }
            if (modal.dataset.refreshOnClose === '1') {
                window.location.reload();
            }
        });
        modal.dataset.openapRefreshInitialized = '1';
    }

    function initHotspotModalRefresh() {
        var modal = document.getElementById('hotspotModal');
        if (!modal || modal.dataset.openapRefreshInitialized === '1') return;
        modal.addEventListener('hidden.bs.modal', function () {
            if (modal.dataset.refreshOnClose === '1') {
                window.location.reload();
            }
        });
        modal.dataset.openapRefreshInitialized = '1';
    }

    function initHotspotRadioControls() {
        var band = document.getElementById('hsBand');
        var channel = document.getElementById('hsChannel');
        var mode = document.getElementById('hsMode');
        var width = document.getElementById('hsWidth');
        var info = document.getElementById('chipInfo');
        if (!band || !channel || !mode || !width || band.dataset.openapInitialized === '1') {
            return;
        }

        var channelOptions = Array.from(channel.querySelectorAll('option[data-band]')).map(function (option) {
            return {
                band: option.dataset.band,
                value: option.value,
                label: option.textContent.trim()
            };
        });
        var initialChannel = channel.value;
        var initialWidth = parseInt(width.dataset.currentWidth || '20', 10);

        function compatibleWidths(selectedChannel, is5GHz, availableChannels) {
            var available = new Set(availableChannels.map(function (item) {
                return parseInt(item.value, 10);
            }));
            var containsBlock = function (block) {
                return block.includes(selectedChannel) && block.every(function (item) {
                    return available.has(item);
                });
            };
            var widths = [20];

            if (!is5GHz) {
                var secondary = selectedChannel <= 7 ? selectedChannel + 4 : selectedChannel - 4;
                if (available.has(secondary)) widths.push(40);
                return widths;
            }

            var blocks40 = [
                [36, 40], [44, 48], [52, 56], [60, 64],
                [100, 104], [108, 112], [116, 120], [124, 128],
                [132, 136], [140, 144], [149, 153], [157, 161], [165, 169]
            ];
            var blocks80 = [
                [36, 40, 44, 48], [52, 56, 60, 64],
                [100, 104, 108, 112], [116, 120, 124, 128],
                [132, 136, 140, 144], [149, 153, 157, 161]
            ];
            if (blocks40.some(containsBlock)) widths.push(40);
            if (blocks80.some(containsBlock)) widths.push(80);
            return widths;
        }

        function synchronize(resetDefaults, optimizeWidth) {
            var selectedBand = band.value === '5' ? '5' : '24';
            var is5GHz = selectedBand === '5';
            var previousChannel = resetDefaults ? '' : (channel.value || initialChannel);
            var availableChannels = channelOptions.filter(function (option) {
                return option.band === selectedBand;
            });

            channel.replaceChildren();
            availableChannels.forEach(function (item) {
                var option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                channel.appendChild(option);
            });
            if (availableChannels.some(function (item) { return item.value === previousChannel; })) {
                channel.value = previousChannel;
            } else if (availableChannels.length) {
                channel.value = availableChannels[0].value;
            }
            channel.disabled = availableChannels.length === 0;

            mode.value = is5GHz ? 'ac' : 'n';
            Array.from(mode.options).forEach(function (option) {
                option.hidden = option.dataset.band !== selectedBand;
                option.disabled = option.dataset.band !== selectedBand;
            });

            var selectedChannel = parseInt(channel.value || '0', 10);
            var allowedWidths = compatibleWidths(selectedChannel, is5GHz, availableChannels);
            var optimalWidth = Math.max.apply(null, allowedWidths);
            var requestedWidth = (resetDefaults || optimizeWidth) ? optimalWidth : initialWidth;
            if (!allowedWidths.includes(requestedWidth)) {
                requestedWidth = optimalWidth;
            }
            width.replaceChildren();
            allowedWidths.forEach(function (mhz) {
                var option = document.createElement('option');
                option.value = String(mhz);
                option.textContent = mhz + ' MHz';
                width.appendChild(option);
            });
            width.value = String(requestedWidth);
            width.dataset.currentWidth = String(requestedWidth);
            var suggestion = document.getElementById('widthSuggestion');
            if (suggestion) {
                suggestion.textContent = 'auto';
                suggestion.title = 'Recommended width: ' + optimalWidth + ' MHz';
            }
            if (info) {
                info.textContent = (is5GHz ? '802.11a/ac' : '802.11n') + ' · ' +
                    availableChannels.length + ' channels';
            }
        }

        band.addEventListener('change', function () {
            synchronize(true, true);
        });
        channel.addEventListener('change', function () {
            synchronize(false, true);
        });
        width.addEventListener('change', function () {
            width.dataset.currentWidth = width.value;
        });
        band.dataset.openapInitialized = '1';
        synchronize(false, false);
    }

    function initHotspotFormSubmission() {
        var form = document.getElementById('hotspotForm');
        var status = document.getElementById('hostapdStatus');
        if (!form || !status || form.dataset.openapSubmitInitialized === '1') {
            return;
        }

        function showState(level, text, spinning) {
            status.replaceChildren();
            var alert = document.createElement('div');
            alert.className = 'alert alert-' + level + ' py-2 mx-3 mt-3 mb-0';
            alert.setAttribute('role', 'alert');
            if (spinning) {
                var spinner = document.createElement('i');
                spinner.className = 'fas fa-spinner fa-spin me-2';
                spinner.setAttribute('aria-hidden', 'true');
                alert.appendChild(spinner);
            }
            alert.appendChild(document.createTextNode(text));
            status.appendChild(alert);
            status.style.display = 'block';
        }

        function showSuccess(applied) {
            var modal = document.getElementById('hotspotModal') || document.getElementById('apConfigurationPanel');
            var standalone = Boolean(form.closest('.openap-ap-config-panel'));
            var tabs = form.querySelector('.hotspot-tabs');
            var panes = form.querySelector('.hotspot-panes');
            var footer = form.querySelector('.card-footer');
            if (tabs) tabs.classList.add('d-none');
            if (panes) panes.classList.add('d-none');
            if (footer) footer.classList.add('d-none');

            status.replaceChildren();
            status.style.display = 'block';

            var panel = document.createElement('div');
            panel.className = 'p-4 text-center';

            var icon = document.createElement('div');
            icon.className = 'text-success mb-2';
            icon.style.fontSize = '32px';
            icon.innerHTML = '<i class="fas fa-check-circle" aria-hidden="true"></i>';
            panel.appendChild(icon);

            var title = document.createElement('h5');
            title.className = 'mb-2';
            title.textContent = 'WiFi hotspot settings saved';
            panel.appendChild(title);

            var description = document.createElement('p');
            description.className = 'text-muted mb-3';
            description.textContent = 'OpenAP applied the new radio configuration and restarted the hotspot. Connected WiFi clients may need to reconnect.';
            panel.appendChild(description);

            var summary = document.createElement('div');
            summary.className = 'border rounded text-start mx-auto mb-3';
            summary.style.maxWidth = '420px';
            var rows = [
                ['SSID', applied.ssid],
                ['Band', applied.band],
                ['Wireless mode', applied.mode],
                ['Channel', applied.channel],
                ['Channel width', applied.width + ' MHz'],
                ['Country', applied.country]
            ];
            rows.forEach(function (row) {
                var line = document.createElement('div');
                line.className = 'd-flex justify-content-between gap-3 px-3 py-2 border-bottom';
                var label = document.createElement('span');
                label.className = 'text-muted';
                label.textContent = row[0];
                var value = document.createElement('strong');
                value.className = 'text-end text-break';
                value.textContent = row[1] || '-';
                line.append(label, value);
                summary.appendChild(line);
            });
            summary.lastElementChild?.classList.remove('border-bottom');
            panel.appendChild(summary);

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'btn-ss primary';
            if (standalone) {
                close.addEventListener('click', function () { window.location.reload(); });
                close.innerHTML = '<i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back to AP Configuration';
            } else {
                close.setAttribute('data-bs-dismiss', 'modal');
                close.innerHTML = '<i class="fas fa-times me-1" aria-hidden="true"></i> Close';
            }
            panel.appendChild(close);
            status.appendChild(panel);

            if (modal) modal.dataset.refreshOnClose = '1';
        }

        function waitForSavedRadioSettings(applied, deadline) {
            return new Promise(function (resolve) {
                window.setTimeout(resolve, 1200);
            }).then(function () {
                return fetch('/ap_configuration?verify=' + Date.now(), {
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            }).then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var savedChannel = parsed.querySelector('#apcChannel');
                var savedWidth = parsed.querySelector('#apcWidth');
                var channelMatches = savedChannel
                    && String(savedChannel.value) === String(applied.channel || '');
                var widthMatches = savedWidth
                    && String(savedWidth.value || savedWidth.dataset.currentWidth || '')
                        === String(applied.width || '');
                if (channelMatches && widthMatches) return;
                throw new Error('Radio settings are not ready yet.');
            }).catch(function (error) {
                if (Date.now() >= deadline) throw error;
                return waitForSavedRadioSettings(applied, deadline);
            });
        }

        form.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            if (!submitter || submitter.name !== 'SaveHostAPDSettings') {
                if (submitter && ['StartHotspot', 'RestartHotspot', 'StopHotspot'].includes(submitter.name)
                    && form.closest('.openap-ap-config-panel')) {
                    event.preventDefault();
                    var serviceData = new FormData(form);
                    serviceData.set(submitter.name, submitter.value || '1');
                    submitter.disabled = true;
                    fetch(form.action || '/hostapd_conf', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: serviceData
                    }).then(function (response) {
                        if (!response.ok) throw new Error('HTTP ' + response.status);
                        window.location.reload();
                    }).catch(function (error) {
                        submitter.disabled = false;
                        showState('danger', 'Unable to control hotspot: ' + error.message, false);
                    });
                }
                return;
            }
            event.preventDefault();

            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return;
            }

            var data = new FormData(form);
            data.set('SaveHostAPDSettings', submitter.value || '1');
            var applied = {
                ssid: String(data.get('ssid') || '').trim(),
                band: document.getElementById('hsBand')?.selectedOptions[0]?.textContent.trim() || '',
                mode: document.getElementById('hsMode')?.selectedOptions[0]?.textContent.trim() || '',
                channel: String(data.get('channel') || ''),
                width: String(data.get('openap_channel_width') || ''),
                country: String(data.get('country_code') || '')
            };
            var originalHtml = submitter.innerHTML;
            submitter.disabled = true;
            submitter.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            showState('info', 'Applying hotspot settings...', true);

            fetch(form.action || '/hostapd_conf', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: data
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            }).then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var messages = Array.from(parsed.querySelectorAll('.card-body .alert')).map(function (alert) {
                    var level = alert.classList.contains('alert-danger') ? 'danger'
                        : alert.classList.contains('alert-warning') ? 'warning'
                        : alert.classList.contains('alert-info') ? 'info' : 'success';
                    var close = alert.querySelector('.btn-close');
                    if (close) close.remove();
                    return { level: level, text: alert.textContent.trim() };
                }).filter(function (message) {
                    return message.text !== '';
                });
                var failed = messages.some(function (message) { return message.level === 'danger'; });
                var succeeded = messages.some(function (message) { return message.level === 'success'; });
                if (failed) {
                    var errorText = messages.filter(function (message) {
                        return message.level === 'danger';
                    }).map(function (message) {
                        return message.text;
                    }).join(' ');
                    showState('danger', errorText || 'Unable to save hotspot settings.', false);
                    return;
                }
                if (!succeeded) {
                    throw new Error('OpenAP did not confirm that the settings were saved.');
                }
                showState('info', 'WiFi radio restarting. Waiting to verify the saved channel...', true);
                return waitForSavedRadioSettings(applied, Date.now() + 45000)
                    .then(function () { showSuccess(applied); });
            }).catch(function (error) {
                var interrupted = error instanceof TypeError
                    || /Failed to fetch|NetworkError|Load failed/i.test(error.message);
                if (interrupted) {
                    showState('info', 'WiFi radio restarting. Waiting to verify the saved channel...', true);
                    return waitForSavedRadioSettings(applied, Date.now() + 45000)
                        .then(function () { showSuccess(applied); })
                        .catch(function (verifyError) {
                            showState('danger', 'Unable to verify hotspot settings after reconnecting: '
                                + verifyError.message, false);
                        });
                }
                showState('danger', 'Unable to save hotspot settings: ' + error.message, false);
            }).finally(function () {
                submitter.disabled = false;
                submitter.innerHTML = originalHtml;
            });
        });

        form.dataset.openapSubmitInitialized = '1';
    }

    function showModal(config) {
        var modalElement = document.getElementById(config.id);
        if (!modalElement && config.id === 'hotspotModal') {
            var panel = document.getElementById('apConfigurationPanel');
            if (panel) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var firstField = panel.querySelector('input:not([type="hidden"]), select, button');
                if (firstField) window.setTimeout(function () { firstField.focus(); }, 350);
            }
            return;
        }
        if (!modalElement || typeof bootstrap === 'undefined') {
            return;
        }

        var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();

        if (!config.url || !config.content) {
            return;
        }

        var content = document.getElementById(config.content);
        if (!content) {
            return;
        }

        content.setAttribute('aria-busy', 'true');
        if (config.id === 'uplinkModal') {
            replaceUplinkModalContent(content, uplinkScanProgressMarkup());
        }
        fetch(config.url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            if (config.fragment) {
                if (config.id === 'uplinkModal') {
                    replaceUplinkModalContent(content, html, function () { bindDynamicContent(content); });
                    return;
                }
                content.innerHTML = html;
            } else {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var fragment = parsed.querySelector('.card');
                if (!fragment) {
                    throw new Error('Wizard content not found');
                }
                content.innerHTML = fragment.outerHTML;
            }
            bindDynamicContent(content);
        }).catch(function (error) {
            var errorMarkup = '<div class="alert alert-danger m-3">Unable to load configuration: ' +
                escapeHtml(error.message) + '</div>';
            if (config.id === 'uplinkModal') {
                replaceUplinkModalContent(content, errorMarkup);
            } else {
                content.innerHTML = errorMarkup;
            }
        }).finally(function () {
            content.removeAttribute('aria-busy');
        });
    }

    function pollSettlingUplink(content, attemptsLeft) {
        if (!content || !content.querySelector('.js-uplink-settling')) return;
        if (attemptsLeft <= 0) {
            if (content.openapModeAnimation) content.openapModeAnimation.fail();
            showOperationToast('danger', 'The WiFi uplink did not become ready.');
            return;
        }
        if (content.dataset.openapUplinkPoll === '1') return;
        content.dataset.openapUplinkPoll = '1';
        window.setTimeout(function () {
            fetch('/uplink_embed.php?status=1&format=json', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                if (response.redirected && /\/login(?:\?|$)/.test(response.url)) {
                    throw new Error('Authentication expired');
                }
                return response.json();
            }).then(function (status) {
                var expectedSsid = content.dataset.openapExpectedUplinkSsid || '';
                var correctUplink = !expectedSsid || status.ssid === expectedSsid;
                if (status.mode === 'repeater_wifi' && status.ready && correctUplink) {
                    if (content.openapModeAnimation) {
                        content.openapModeAnimation.verify();
                        content.openapModeAnimation.complete();
                        delete content.openapModeAnimation;
                    }
                    var modal = document.getElementById('uplinkModal');
                    if (modal) delete modal.dataset.refreshOnClose;
                    refreshNetworkUplinkWidget('repeater_wifi', 20);
                    return;
                }
                if (status.apply_state === 'failed') {
                    if (content.openapModeAnimation) content.openapModeAnimation.fail();
                    showOperationToast('danger', status.reason || 'Repeater mode activation failed.');
                    return;
                }
                delete content.dataset.openapUplinkPoll;
                pollSettlingUplink(content, attemptsLeft - 1);
            }).catch(function () {
                delete content.dataset.openapUplinkPoll;
                pollSettlingUplink(content, attemptsLeft - 1);
            });
        }, 1000);
    }

    function refreshDashboardAfterApSwitch(expectClient, attemptsLeft, modeAnimation, expectedMode, expectedGateway, successDetails, lastApplyState, notificationState) {
        notificationState = notificationState || { successShown: false };
        function notifyTrackedSuccess() {
            if (notificationState.successShown) return;
            notificationState.successShown = true;
            showOperationToast('success', 'Settings successfully applied.', 'Operation successful', successDetails);
        }
        if (attemptsLeft <= 0) {
            // A routed/bridge transition can temporarily move the browser to
            // another DHCP network.  Loss of the verification request is not
            // evidence that the privileged transaction failed.  Only the
            // tracked helper is allowed to report failure.
            if (lastApplyState && lastApplyState.state === 'failed'
                && lastApplyState.target === expectedMode) {
                if (modeAnimation) modeAnimation.fail();
                showOperationToast(
                    'danger',
                    lastApplyState.message || 'The requested Ethernet Mode failed and the previous network settings were restored.'
                );
                return;
            }
            if (lastApplyState && lastApplyState.state === 'success'
                && lastApplyState.target === expectedMode) {
                notifyTrackedSuccess();
            }
            refreshDashboardAfterApSwitch(expectClient, 15, modeAnimation, expectedMode, expectedGateway, successDetails, lastApplyState, notificationState);
            return;
        }
        window.setTimeout(function () {
            var statusRequest = fetch('/ajax/networking/get_ethernet_mode_apply_status.php?t=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            }).catch(function () { return lastApplyState || { state: 'unknown', target: '' }; });
            var dashboardRequest = fetch('/', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            }).catch(function () { return ''; });
            Promise.all([statusRequest, dashboardRequest]).then(function (results) {
                var applyState = results[0];
                var html = results[1];
                if (applyState.state === 'failed' && applyState.target === expectedMode) {
                    if (modeAnimation) modeAnimation.fail();
                    showOperationToast(
                        'danger',
                        applyState.message || 'The requested Ethernet Mode failed and the previous network settings were restored.'
                    );
                    return;
                }
                var transactionSucceeded = applyState.state === 'success'
                    && applyState.target === expectedMode;
                if (transactionSucceeded) notifyTrackedSuccess();
                var parsed = html ? new DOMParser().parseFromString(html, 'text/html') : null;
                var channelNode = parsed ? parsed.querySelector('[data-openap-ap-channel]') : null;
                var channel = channelNode ? (channelNode.dataset.openapApChannel || '') : '';
                var channelReady = channel !== '' && channel !== '-';
                var modeNode = parsed ? parsed.querySelector('.openap-mode-selector[data-current-mode]') : null;
                var modeReady = modeNode && modeNode.dataset.currentMode === expectedMode;
                var gatewayNode = parsed ? parsed.querySelector('[data-openap-uplink-gateway]') : null;
                var verifiedGateway = gatewayNode ? gatewayNode.textContent.trim() : '';
                var gatewayReady = expectedMode !== 'ap_ethernet'
                    || expectedGateway === ''
                    || verifiedGateway === expectedGateway;
                var hotspotAddressNode = parsed ? parsed.querySelector('[data-openap-topology-hotspot-address]') : null;
                var verifiedHotspotAddress = hotspotAddressNode ? hotspotAddressNode.textContent.trim() : '';
                var hotspotAddressReady = expectedMode !== 'ap_ethernet_bridge'
                    || isValidIpv4Address(verifiedHotspotAddress);
                var clientNetworkNode = parsed
                    ? parsed.querySelector('[data-openap-topology-node="clients"] [data-openap-topology-sub]')
                    : null;
                var verifiedClientNetwork = clientNetworkNode ? clientNetworkNode.textContent.trim() : '';
                // The tracked helper is the authority for the committed mode.
                // Dashboard fields can converge in different HTTP snapshots
                // while the management route changes, so they must never keep
                // the selector or overlay in the previous mode after success.
                if (transactionSucceeded) {
                    var verifiedDocument = modeReady && channelReady && gatewayReady
                        && hotspotAddressReady ? parsed : null;
                    if (verifiedDocument && verifiedGateway !== '') {
                        animateUplinkGateway(verifiedGateway);
                    }
                    var reconcileWidgets = function () {
                        refreshNetworkUplinkWidget(expectedMode, 20, verifiedDocument);
                        if (verifiedDocument) {
                            animateTopologyHotspotAddress(verifiedHotspotAddress);
                            animateTopologyClientNetwork(verifiedClientNetwork);
                        }
                    };
                    if (modeAnimation) {
                        modeAnimation.complete(reconcileWidgets, verifiedDocument ? html : '');
                    } else {
                        delete window.openapLocalModeSwitchGuard;
                        animateModeSelector(expectedMode);
                        reconcileWidgets();
                    }
                    return;
                }
                refreshDashboardAfterApSwitch(expectClient, attemptsLeft - 1, modeAnimation, expectedMode, expectedGateway, successDetails, applyState, notificationState);
            }).catch(function () {
                refreshDashboardAfterApSwitch(expectClient, attemptsLeft - 1, modeAnimation, expectedMode, expectedGateway, successDetails, lastApplyState, notificationState);
            });
        }, 2000);
    }

    function bindDynamicContent(root) {
        var changeUplink = document.getElementById('openapChangeUplink');
        if (changeUplink) {
            changeUplink.disabled = false;
            changeUplink.innerHTML = '<i class="fas fa-exchange-alt" aria-hidden="true"></i><span>Change uplink</span>';
            changeUplink.hidden = !root.querySelector('.js-repeater-success');
        }

        root.querySelectorAll('.js-ap-ethernet-form').forEach(function (modeForm) {
            var radios = modeForm.querySelectorAll('input[name="network_mode"]');
            var bridgeNote = modeForm.querySelector('[data-bridge-note]');
            var gatewayInput = modeForm.querySelector('[name="ethernet_gateway"]');
            function syncNetworkMode(animate) {
                var selected = modeForm.querySelector('input[name="network_mode"]:checked');
                var bridge = selected && selected.value === 'bridge';
                if (bridgeNote) {
                    if (animate) {
                        animateApEthernetModalMutation(bridgeNote, function () {
                            bridgeNote.hidden = !bridge;
                        });
                    } else {
                        bridgeNote.hidden = !bridge;
                    }
                }
                if (gatewayInput) gatewayInput.required = true;
            }
            radios.forEach(function (radio) {
                radio.addEventListener('change', function () { syncNetworkMode(true); });
            });
            syncNetworkMode(false);
        });

        root.querySelectorAll('.js-ap-ethernet-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!form.checkValidity()) {
                    form.classList.add('was-validated');
                    var invalidField = form.querySelector(':invalid');
                    showOperationToast(
                        'danger',
                        invalidField ? invalidModalFieldMessage(invalidField) : 'Complete the required fields before saving.',
                        'Check required fields'
                    );
                    if (invalidField) invalidField.focus({ preventScroll: false });
                    return;
                }
                var content = document.getElementById('apEthernetModalContent');
                var selectedMode = form.querySelector('input[name="network_mode"]:checked');
                var expectedMode = selectedMode && selectedMode.value === 'bridge' ? 'ap_ethernet_bridge' : 'ap_ethernet';
                var submittedGatewayInput = form.querySelector('[name="ethernet_gateway"]');
                var requestedGateway = submittedGatewayInput ? submittedGatewayInput.value.trim() : '';
                var currentModeNode = document.querySelector('.openap-mode-selector[data-current-mode]');
                var previousMode = currentModeNode ? currentModeNode.dataset.currentMode : 'repeater_wifi';
                if (!content) return;
                window.openapLocalModeSwitchGuard = {
                    targetMode: expectedMode,
                    expiresAt: Date.now() + 90000
                };
                var modeAnimation = showModeSwitchAnimation(expectedMode);
                var expectClient = document.querySelector('.openap-topology-clients .client-table tbody tr') !== null;
                var submit = form.querySelector('.js-ap-ethernet-submit');
                if (submit) {
                    var spinner = submit.querySelector('.js-ap-ethernet-spinner');
                    var icon = submit.querySelector('.js-ap-ethernet-icon');
                    var label = submit.querySelector('.js-ap-ethernet-label');
                    if (spinner) spinner.classList.remove('d-none');
                    if (icon) icon.classList.add('d-none');
                    if (label) label.textContent = submit.dataset.loadingText || 'Saving...';
                    submit.disabled = true;
                }
                fetch('/ap_ethernet_embed.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(new FormData(form)).toString()
                }).then(function (response) {
                    return response.text();
                }).then(function (html) {
                    var parsed = new DOMParser().parseFromString(html, 'text/html');
                    if (parsed.querySelector('.alert-success') && !parsed.querySelector('.alert-danger')) {
                        if (modeAnimation) modeAnimation.verify();
                        var saved = parsed.querySelector('.js-ap-ethernet-success');
                        var savedMode = saved ? saved.dataset.networkMode : (expectedMode === 'ap_ethernet_bridge' ? 'bridge' : 'routed');
                        var savedGateway = saved ? (saved.dataset.gateway || '') : '';
                        var successDetails = [
                            'Operating mode: ' + (previousMode === 'repeater_wifi' ? 'Repeater Mode' : (previousMode === 'ap_ethernet_bridge' ? 'Ethernet Bridge' : 'Ethernet Mode'))
                                + ' \u2192 ' + (savedMode === 'bridge' ? 'Ethernet Bridge' : 'Ethernet Mode'),
                            'Network type: ' + (savedMode === 'bridge' ? 'Bridge' : 'Routed / NAT'),
                            'Management gateway: ' + (savedGateway || 'Automatically assigned')
                        ];
                        var modalElement = document.getElementById('apEthernetModal');
                        if (modalElement && modalElement.classList.contains('show')) {
                            modalElement.dataset.openapModeApplied = '1';
                            bootstrap.Modal.getOrCreateInstance(modalElement).hide();
                        }
                        refreshDashboardAfterApSwitch(expectClient, 14, modeAnimation, expectedMode, savedGateway || requestedGateway, successDetails);
                        return;
                    }
                    modeAnimation.fail();
                    var serverError = parsed.querySelector('.alert-danger');
                    if (serverError) {
                        showOperationToast('danger', serverError.textContent.trim());
                    }
                    content.innerHTML = html;
                    bindDynamicContent(content);
                }).catch(function (error) {
                    var interrupted = error instanceof TypeError
                        || /Failed to fetch|NetworkError|Load failed/i.test(error.message || '');
                    if (interrupted) {
                        content.innerHTML =
                            '<div class="text-center py-5" role="status">' +
                              '<div class="spinner-border text-primary mb-3" aria-hidden="true"></div>' +
                              '<div class="openap-ap-ethernet-title">Verifying Ethernet Mode</div>' +
                              '<p style="font-size:12px;color:#64748b;margin:8px 20px 0">' +
                                'The network changed before the browser received the response. ' +
                                'OpenAP is checking the applied mode…' +
                              '</p>' +
                            '</div>';
                        refreshDashboardAfterApSwitch(
                            expectClient,
                            14,
                            modeAnimation,
                            expectedMode,
                            requestedGateway,
                            [
                                'Operating mode: ' + (previousMode === 'repeater_wifi' ? 'Repeater Mode' : 'Ethernet Mode') + ' \u2192 Ethernet Mode',
                                'Network type: ' + (expectedMode === 'ap_ethernet_bridge' ? 'Bridge' : 'Routed / NAT'),
                                'Configuration verified after reconnecting'
                            ]
                        );
                        return;
                    }
                    modeAnimation.fail();
                    content.innerHTML = '<div class="alert alert-danger m-3">Unable to apply configuration: ' + escapeHtml(error.message) + '</div>';
                    showOperationToast('danger', 'Unable to enable Ethernet Mode.');
                });
            });
        });

        root.querySelectorAll('.js-uplink-select').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = root.querySelector('.js-uplink-ssid');
                var form = input ? input.closest('.js-uplink-form') : null;
                var security = form ? form.querySelector('.js-uplink-security') : null;
                var password = form ? form.querySelector('[name="passphrase"]') : null;
                var passwordField = form ? form.querySelector('.js-uplink-password-field') : null;
                var credentials = root.querySelector('.openap-repeater-credentials');
                var isOpen = button.dataset.security === 'open';
                if (input) {
                    input.value = button.dataset.ssid || '';
                }
                if (security) security.value = isOpen ? 'open' : 'wpa';
                if (password) {
                    password.required = !isOpen;
                    password.disabled = isOpen;
                    password.value = isOpen ? '' : (button.dataset.savedPassword || '');
                }
                if (passwordField) passwordField.hidden = isOpen;
                if (credentials) {
                    animateUplinkModalMutation(credentials, function () { credentials.hidden = false; });
                }
                if (isOpen && input) input.focus();
                if (!isOpen && password) password.focus();
            });
        });

        root.querySelectorAll('.js-uplink-manual').forEach(function (button) {
            button.addEventListener('click', function () {
                var credentials = root.querySelector('.openap-repeater-credentials');
                var form = credentials ? credentials.querySelector('.js-uplink-form') : null;
                var input = form ? form.querySelector('.js-uplink-ssid') : null;
                var security = form ? form.querySelector('.js-uplink-security') : null;
                var password = form ? form.querySelector('[name="passphrase"]') : null;
                var passwordField = form ? form.querySelector('.js-uplink-password-field') : null;
                if (credentials) {
                    animateUplinkModalMutation(credentials, function () { credentials.hidden = false; });
                }
                if (input) input.value = '';
                if (security) security.value = 'wpa';
                if (password) { password.disabled = false; password.required = true; password.value = ''; }
                if (passwordField) passwordField.hidden = false;
                if (input) input.focus();
            });
        });

        root.querySelectorAll('.js-uplink-ssid').forEach(function (input) {
            input.addEventListener('input', function (event) {
                if (!event.isTrusted) return;
                var form = input.closest('.js-uplink-form');
                var security = form ? form.querySelector('.js-uplink-security') : null;
                var password = form ? form.querySelector('[name="passphrase"]') : null;
                var passwordField = form ? form.querySelector('.js-uplink-password-field') : null;
                if (security) security.value = 'wpa';
                if (password) {
                    password.disabled = false;
                    password.required = true;
                }
                if (passwordField) passwordField.hidden = false;
            });
        });

        var uplinkModal = document.getElementById('uplinkModal');
        var rescanRoot = uplinkModal && uplinkModal.contains(root) ? uplinkModal : root;
        rescanRoot.querySelectorAll('.js-uplink-rescan').forEach(function (link) {
            if (link.dataset.openapRescanInitialized === '1') return;
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var content = document.getElementById('uplinkModalContent');
                if (!content) return;
                link.classList.add('disabled');
                link.setAttribute('aria-disabled', 'true');
                link.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Scanning...';
                content.setAttribute('aria-busy', 'true');
                replaceUplinkModalContent(content, uplinkScanProgressMarkup());
                fetch('/uplink_embed.php?scan=1', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.text();
                }).then(function (html) {
                    replaceUplinkModalContent(content, html, function () { bindDynamicContent(content); });
                }).catch(function (error) {
                    replaceUplinkModalContent(content, '<div class="alert alert-danger m-3">Unable to scan networks: ' + escapeHtml(error.message) + '</div>');
                }).finally(function () {
                    content.removeAttribute('aria-busy');
                });
            });
            link.dataset.openapRescanInitialized = '1';
        });

        root.querySelectorAll('.js-uplink-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var content = document.getElementById('uplinkModalContent');
                if (!content || !form.checkValidity()) return;
                window.openapUplinkSwitchGuard = {
                    baselineCheckedAt: Number(window.openapLatestUplinkWatchdogCheck) || null,
                    expiresAt: Date.now() + 210000
                };
                var modeAnimation = showModeSwitchAnimation('repeater_wifi');
                var currentModeNode = document.querySelector('.openap-mode-selector[data-current-mode]');
                var previousMode = currentModeNode ? currentModeNode.dataset.currentMode : 'ap_ethernet';
                var uplinkSsid = form.querySelector('[name="ssid"]');
                var uplinkSecurity = form.querySelector('.js-uplink-security');
                content.dataset.openapExpectedUplinkSsid = uplinkSsid && uplinkSsid.value ? uplinkSsid.value : '';
                modeAnimation.setSuccessDetails([
                    'Operating mode: ' + (previousMode === 'repeater_wifi' ? 'Repeater Mode' : (previousMode === 'ap_ethernet_bridge' ? 'Ethernet Bridge' : 'Ethernet Mode')) + ' \u2192 Repeater Mode',
                    'Wi-Fi uplink: ' + (uplinkSsid && uplinkSsid.value ? uplinkSsid.value : 'Selected network'),
                    'Uplink security: ' + (uplinkSecurity && uplinkSecurity.value === 'open' ? 'Open' : 'Protected')
                ]);
                var submit = form.querySelector('.js-openap-switch-submit');
                if (submit) {
                    var spinner = submit.querySelector('.js-openap-switch-spinner');
                    var icon = submit.querySelector('.js-openap-switch-icon');
                    var label = submit.querySelector('.js-openap-switch-label');
                    if (spinner) spinner.classList.remove('d-none');
                    if (icon) icon.classList.add('d-none');
                    if (label) label.textContent = submit.dataset.loadingText || 'Connecting...';
                    submit.disabled = true;
                }
                fetch('/uplink_embed.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form)
                }).then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    modeAnimation.apply();
                    return response.text();
                }).then(function (html) {
                    replaceUplinkModalContent(content, html, function () { bindDynamicContent(content); });
                    var success = content.querySelector('.js-repeater-success');
                    var failure = content.querySelector('.alert-danger');
                    var modal = document.getElementById('uplinkModal');
                    if (success && modal) {
                        modeAnimation.verify();
                        modeAnimation.complete();
                        delete modal.dataset.refreshOnClose;
                        refreshNetworkUplinkWidget('repeater_wifi', 20);
                    } else if (failure) {
                        delete window.openapUplinkSwitchGuard;
                        modeAnimation.fail();
                        showOperationToast('danger', failure.textContent.trim() || 'Unable to configure the WiFi uplink.');
                    } else if (content.querySelector('.js-uplink-settling')) {
                        content.openapModeAnimation = modeAnimation;
                        delete content.dataset.openapUplinkPoll;
                        pollSettlingUplink(content, 60);
                    } else {
                        delete window.openapUplinkSwitchGuard;
                        modeAnimation.fail();
                    }
                }).catch(function (error) {
                    var interrupted = error instanceof TypeError
                        || /Failed to fetch|NetworkError|Load failed/i.test(error.message || '');
                    if (interrupted) {
                        replaceUplinkModalContent(content,
                            '<div class="js-uplink-settling text-center py-5" role="status">' +
                              '<div class="spinner-border text-primary mb-3" aria-hidden="true"></div>' +
                              '<div class="openap-repeater-title">Reconnecting to OpenAP</div>' +
                              '<p style="font-size:12px;color:#64748b;margin:8px 20px 0">' +
                                'The hotspot connection changed before the browser received the response. ' +
                                'OpenAP is verifying Repeater Mode…' +
                              '</p>' +
                            '</div>');
                        content.openapModeAnimation = modeAnimation;
                        delete content.dataset.openapUplinkPoll;
                        pollSettlingUplink(content, 60);
                        return;
                    }
                    delete window.openapUplinkSwitchGuard;
                    modeAnimation.fail();
                    replaceUplinkModalContent(content, '<div class="alert alert-danger m-3">Unable to configure uplink: ' + escapeHtml(error.message) + '</div>');
                    showOperationToast('danger', 'Unable to configure the WiFi uplink.');
                });
            });
        });

        root.querySelectorAll('.js-uplink-forget-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var content = document.getElementById('uplinkModalContent');
                if (!content) return;
                var formData = new FormData(form);
                var ssid = String(formData.get('ssid') || '');
                var previousHtml = content.innerHTML;
                var confirmation =
                    '<div class="openap-uplink-forget-confirm" role="alertdialog" aria-labelledby="openapForgetTitle">' +
                      '<span class="openap-uplink-forget-icon" aria-hidden="true"><i class="fas fa-trash"></i></span>' +
                      '<h3 id="openapForgetTitle">Remove saved network?</h3>' +
                      '<strong class="openap-uplink-forget-ssid">' + escapeHtml(ssid) + '</strong>' +
                      '<div class="openap-uplink-forget-actions">' +
                        '<button type="button" class="btn-ss js-uplink-forget-cancel">Cancel</button>' +
                        '<button type="button" class="btn-ss danger js-uplink-forget-confirm"><i class="fas fa-trash me-1"></i>Remove</button>' +
                      '</div>' +
                    '</div>';

                replaceUplinkModalContent(content, confirmation, function () {
                    var cancel = content.querySelector('.js-uplink-forget-cancel');
                    var confirm = content.querySelector('.js-uplink-forget-confirm');
                    if (cancel) {
                        cancel.focus();
                        cancel.addEventListener('click', function () {
                            replaceUplinkModalContent(content, previousHtml, function () { bindDynamicContent(content); });
                        });
                    }
                    if (!confirm) return;
                    confirm.addEventListener('click', function () {
                        confirm.disabled = true;
                        confirm.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Removing';
                        fetch('/uplink_embed.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData })
                            .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.text(); })
                            .then(function (html) { replaceUplinkModalContent(content, html, function () { bindDynamicContent(content); }); })
                            .catch(function (error) { replaceUplinkModalContent(content, '<div class="alert alert-danger m-3">Unable to forget network: ' + escapeHtml(error.message) + '</div>'); });
                    });
                });
            });
        });

        root.querySelectorAll('.js-repeater-copy').forEach(function (button) {
            if (button.dataset.openapCopyInitialized === '1') return;
            button.addEventListener('click', function () {
                var source = root.querySelector('.js-repeater-copy-source');
                if (!source) return;
                var value = source.textContent.trim();
                var complete = function () {
                    var label = button.querySelector('span');
                    if (label) label.textContent = 'Copied';
                    button.classList.remove('btn-outline-primary');
                    button.classList.add('btn-success');
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(value).then(complete);
                    return;
                }
                var textarea = document.createElement('textarea');
                textarea.value = value;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                if (document.execCommand('copy')) complete();
                textarea.remove();
            });
            button.dataset.openapCopyInitialized = '1';
        });

        root.querySelectorAll('.js-openap-switch-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var submit = form.querySelector('.js-openap-switch-submit');
                if (!submit) {
                    return;
                }
                var spinner = submit.querySelector('.js-openap-switch-spinner');
                var icon = submit.querySelector('.js-openap-switch-icon');
                var label = submit.querySelector('.js-openap-switch-label');
                if (spinner) {
                    spinner.classList.remove('d-none');
                }
                if (icon) {
                    icon.classList.add('d-none');
                }
                if (label) {
                    label.textContent = submit.dataset.loadingText || label.textContent;
                }
                submit.disabled = true;
            });
        });

        var uplinkContent = document.getElementById('uplinkModalContent');
        if (uplinkContent && root === uplinkContent) {
            pollSettlingUplink(uplinkContent, 60);
        }
    }

    function escapeHtml(value) {
        var element = document.createElement('div');
        element.textContent = value;
        return element.innerHTML;
    }

    function formatTrafficValue(bytes, perSecond) {
        var value = Math.max(0, Number(bytes) || 0);
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        var precision = unit === 0 || value >= 100 ? 0 : 1;
        return value.toFixed(precision) + ' ' + units[unit] + (perSecond ? '/s' : '');
    }

    function initLiveTraffic() {
        var cards = Array.from(document.querySelectorAll('.openap-live-traffic[data-interface]'));
        if (!cards.length) return;

        cards.forEach(function (card) {
            var state = {
                rx: Number(card.dataset.rxBytes) || 0,
                tx: Number(card.dataset.txBytes) || 0,
                timestamp: Date.now()
            };

            function update() {
                if (window.openapSession.expired) return;
                var multiple = card.dataset.interfaces || '';
                var query = multiple
                    ? 'interfaces=' + encodeURIComponent(multiple)
                    : 'interface=' + encodeURIComponent(card.dataset.interface);
                var url = '/ajax/networking/get_interface_traffic.php?' + query + '&t=' + Date.now();
                fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (response) {
                        if (!response.ok) throw new Error('HTTP ' + response.status);
                        return response.json();
                    })
                    .then(function (data) {
                        var now = Number(data.timestamp_ms) || Date.now();
                        var elapsed = Math.max(.25, (now - state.timestamp) / 1000);
                        var rx = Math.max(0, Number(data.rx_bytes) || 0);
                        var tx = Math.max(0, Number(data.tx_bytes) || 0);
                        var rxRate = rx >= state.rx ? (rx - state.rx) / elapsed : 0;
                        var txRate = tx >= state.tx ? (tx - state.tx) / elapsed : 0;
                        state.rx = rx;
                        state.tx = tx;
                        state.timestamp = now;

                        var downloadRate = card.dataset.downloadCounter === 'tx' ? txRate : rxRate;
                        var uploadRate = card.dataset.downloadCounter === 'tx' ? rxRate : txRate;
                        var activeTotal = downloadRate + uploadRate;
                        var downloadPercent = activeTotal > 0 ? Math.round(downloadRate / activeTotal * 100) : 0;
                        var uploadPercent = activeTotal > 0 ? 100 - downloadPercent : 0;
                        var total = card.querySelector('.openap-traffic-total');
                        var downloadNode = card.querySelector('.openap-rate-download');
                        var uploadNode = card.querySelector('.openap-rate-upload');
                        var downloadBar = card.querySelector('.openap-share-download');
                        var uploadBar = card.querySelector('.openap-share-upload');
                        var downloadPercentNode = card.querySelector('.openap-percent-download');
                        var uploadPercentNode = card.querySelector('.openap-percent-upload');
                        if (total) total.textContent = formatTrafficValue(rx + tx, false);
                        if (downloadNode) downloadNode.textContent = formatTrafficValue(downloadRate, true);
                        if (uploadNode) uploadNode.textContent = formatTrafficValue(uploadRate, true);
                        if (downloadBar) downloadBar.style.width = downloadPercent + '%';
                        if (uploadBar) uploadBar.style.width = uploadPercent + '%';
                        if (downloadPercentNode) downloadPercentNode.textContent = downloadPercent + '%';
                        if (uploadPercentNode) uploadPercentNode.textContent = uploadPercent + '%';
                        var role = card.dataset.trafficRole || '';
                        var rxTotal = document.querySelector('[data-openap-traffic-total="' + role + '-rx"]');
                        var txTotal = document.querySelector('[data-openap-traffic-total="' + role + '-tx"]');
                        if (rxTotal) rxTotal.textContent = formatTrafficValue(rx, false);
                        if (txTotal) txTotal.textContent = formatTrafficValue(tx, false);
                    })
                    .catch(function () {
                        card.classList.add('openap-traffic-paused');
                    });
            }

            window.setTimeout(update, 1000);
            window.openapSession.setInterval(update, 3000);
        });
    }

    function initDashboardClientMonitor() {
        var counters = Array.from(document.querySelectorAll('[data-openap-client-count]'));
        var bandCounters = Array.from(document.querySelectorAll('[data-openap-client-band]'));
        if (!counters.length && !bandCounters.length) return;

        function update() {
            if (window.openapSession.expired) return;
            fetch('/ajax/networking/get_dashboard_clients.php?t=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            }).then(function (data) {
                var total = Math.max(0, Number(data.total) || 0);
                counters.forEach(function (counter) { counter.textContent = total; });
                bandCounters.forEach(function (counter) {
                    var band = counter.dataset.openapClientBand;
                    counter.textContent = Math.max(0, Number(data.bands && data.bands[band]) || 0);
                });
            }).catch(function () {});
        }

        window.setTimeout(update, 1200);
        window.openapSession.setInterval(update, 4000);
    }

    function rollTopologyLed(node, active) {
        if (!node || node.classList.contains('active') === active ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        node.classList.remove('openap-led-to-active', 'openap-led-to-inactive');
        void node.offsetWidth;
        node.classList.add(active ? 'openap-led-to-active' : 'openap-led-to-inactive');
        window.setTimeout(function () {
            node.classList.remove('openap-led-to-active', 'openap-led-to-inactive');
        }, 560);
    }

    function animateTopologyValueChanges(changes, applyState, onComplete) {
        var changed = changes.filter(function (change) {
            return change.node && change.node.textContent.trim() !== change.text;
        });
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!changed.length || reducedMotion || typeof changed[0].node.animate !== 'function') {
            applyState();
            if (typeof onComplete === 'function') onComplete();
            return;
        }
        changed.forEach(function (change) {
            change.node.getAnimations().forEach(function (animation) { animation.cancel(); });
            change.node.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(115%)', opacity: 0 }
            ], {
                duration: 320,
                easing: 'cubic-bezier(.55,0,1,.45)',
                fill: 'forwards'
            });
        });
        window.setTimeout(function () {
            applyState();
            changed.forEach(function (change) {
                change.node.getAnimations().forEach(function (animation) { animation.cancel(); });
                change.node.animate([
                    { transform: 'translateY(-115%)', opacity: 0 },
                    { transform: 'translateY(8%)', opacity: 1, offset: .82 },
                    { transform: 'translateY(0)', opacity: 1 }
                ], {
                    duration: 440,
                    easing: 'cubic-bezier(.22,1,.36,1)'
                });
            });
            window.setTimeout(function () {
                if (typeof onComplete === 'function') onComplete();
            }, 460);
        }, 330);
    }

    function initUplinkRecoveryMonitor() {
        var selector = document.querySelector('.openap-mode-selector');
        var modalElement = document.getElementById('uplinkRecoveryModal');
        if (!selector || selector.dataset.currentMode !== 'repeater_wifi'
            || !modalElement || typeof bootstrap === 'undefined') {
            return;
        }

        var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        var degradedSamples = 0;
        var outageVisible = false;
        var dismissed = false;
        var fallbackHandoff = false;
        var latestDegraded = false;
        var remoteModeChangeHandled = false;
        var recoveryTimer = null;
        var reason = document.getElementById('uplinkRecoveryReason');
        var attempt = document.getElementById('uplinkRecoveryAttempt');
        var restartStep = document.getElementById('uplinkRecoveryStepRestart');
        var verifyStep = document.getElementById('uplinkRecoveryStepVerify');
        var fallback = document.getElementById('uplinkRecoveryFallback');
        var ethernetReason = document.getElementById('uplinkRecoveryEthernetReason');
        var ethernetButton = document.getElementById('uplinkRecoveryEthernetButton');
        var apEthernetModal = document.getElementById('apEthernetModal');
        var topologyHealth = document.querySelector('[data-openap-topology-health]');
        var topologyLive = document.querySelector('[data-openap-topology-live]');
        var topologyInternet = document.querySelector('[data-openap-topology-node="internet"]');
        var topologyUplink = document.querySelector('[data-openap-topology-node="uplink"]');
        var topologyLines = Array.from(document.querySelectorAll(
            '[data-openap-topology-line="internet-uplink"], [data-openap-topology-line="uplink-openap"]'
        ));
        var topologySnapshot = {
            healthClass: topologyHealth ? topologyHealth.className : '',
            healthHtml: topologyHealth ? topologyHealth.innerHTML : '',
            liveClass: topologyLive ? topologyLive.className : '',
            liveHtml: topologyLive ? topologyLive.innerHTML : ''
        };
        var topologyRecoveryAnimating = false;
        var topologyRecoveryWaitingForOverlay = false;
        var topologyRecoveryPending = null;
        var topologyRecoveryOverlayClearedAt = 0;
        var topologyRecentUplinkSuccess = false;
        try {
            topologyRecentUplinkSuccess = Number(window.sessionStorage.getItem(
                'openapTopologyRecentUplinkSuccessUntil'
            ) || 0) > Date.now();
            if (!topologyRecentUplinkSuccess) {
                window.sessionStorage.removeItem('openapTopologyRecentUplinkSuccessUntil');
            }
        } catch (error) {}

        function waitForModeOverlayBeforeRecovery() {
            var overlay = document.getElementById('openapModeSwitchOverlay');
            if (overlay && overlay.isConnected) {
                topologyRecoveryOverlayClearedAt = 0;
                window.setTimeout(waitForModeOverlayBeforeRecovery, 100);
                return;
            }
            if (!topologyRecoveryOverlayClearedAt) {
                topologyRecoveryOverlayClearedAt = Date.now();
            }
            // The confirmed mode icon and labels begin shortly after the
            // overlay closes. Let that transition finish before the recovery
            // state uses the same topology elements.
            if (Date.now() - topologyRecoveryOverlayClearedAt < 1500) {
                window.setTimeout(waitForModeOverlayBeforeRecovery, 100);
                return;
            }
            var pending = topologyRecoveryPending;
            topologyRecoveryPending = null;
            topologyRecoveryWaitingForOverlay = false;
            topologyRecoveryAnimating = false;
            if (pending) updateTopology(pending.degraded, pending.data);
        }

        function animateTopologyRecoveryState(degraded, data) {
            var modeOverlay = document.getElementById('openapModeSwitchOverlay');
            if (modeOverlay && modeOverlay.isConnected) {
                topologyRecoveryPending = { degraded: degraded, data: data };
                if (!topologyRecoveryWaitingForOverlay) {
                    topologyRecoveryWaitingForOverlay = true;
                    topologyRecoveryAnimating = true;
                    waitForModeOverlayBeforeRecovery();
                }
                return;
            }
            var nodes = [topologyInternet, topologyUplink].filter(Boolean);
            var internetSub = topologyInternet ? topologyInternet.querySelector('[data-openap-topology-sub]') : null;
            var uplinkSub = topologyUplink ? topologyUplink.querySelector('[data-openap-topology-sub]') : null;
            var nextInternetSub = degraded ? 'Offline' : ((internetSub && internetSub.dataset.readyText) || 'Upstream');
            var nextUplinkSub = degraded
                ? ((data && data.reason) || 'Uplink unavailable')
                : ((uplinkSub && uplinkSub.dataset.readyText) || 'Uplink ready');
            topologyRecoveryAnimating = true;
            nodes.forEach(function (node) { rollTopologyLed(node, !degraded); });
            animateTopologyValueChanges([
                { node: internetSub, text: nextInternetSub },
                { node: uplinkSub, text: nextUplinkSub }
            ], function () {
                updateTopology(degraded, data, true);
            }, function () {
                topologyRecoveryAnimating = false;
            });
        }

        function updateTopology(degraded, data, skipAnimation) {
            if (topologyRecoveryWaitingForOverlay && !skipAnimation) {
                topologyRecoveryPending = { degraded: degraded, data: data };
                return;
            }
            if (topologyRecoveryAnimating && !skipAnimation) {
                return;
            }
            // Compare the watchdog result with the topology actually painted
            // in the browser. A sequence of uplink changes and recovery events
            // can outlive an earlier JavaScript state variable; the DOM remains
            // the authoritative visual state that must be transitioned.
            var renderedDegraded = topologyUplink
                ? !topologyUplink.classList.contains('active')
                : false;
            if (degraded !== renderedDegraded && !skipAnimation) {
                animateTopologyRecoveryState(degraded, data);
                return;
            }
            [topologyInternet, topologyUplink].forEach(function (node) {
                if (!node) return;
                node.classList.toggle('active', !degraded);
                node.classList.toggle('openap-uplink-interrupted', degraded);
                var indicator = node.querySelector('.topo-status-indicator');
                if (indicator) indicator.setAttribute('aria-label', degraded ? 'Interrupted' : 'Active');
                var label = node.querySelector('.topo-label');
                if (label) label.style.color = degraded ? '#dc2626' : '#059669';
            });
            topologyLines.forEach(function (line) {
                line.classList.toggle('active', !degraded);
                line.classList.toggle('openap-uplink-interrupted', degraded);
            });
            if (topologyInternet) {
                var internetSub = topologyInternet.querySelector('[data-openap-topology-sub]');
                if (internetSub) {
                    internetSub.textContent = degraded
                        ? 'Offline'
                        : (internetSub.dataset.readyText || 'Upstream');
                }
            }
            if (topologyUplink) {
                var uplinkSub = topologyUplink.querySelector('[data-openap-topology-sub]');
                if (uplinkSub) {
                    uplinkSub.textContent = degraded
                        ? ((data && data.reason) || 'Uplink unavailable')
                        : (uplinkSub.dataset.readyText || 'Uplink ready');
                }
            }
            if (topologyHealth) {
                if (degraded) {
                    topologyHealth.classList.remove('healthy');
                    topologyHealth.classList.add('degraded');
                    topologyHealth.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Status: Degraded';
                } else {
                    topologyHealth.className = topologySnapshot.healthClass;
                    topologyHealth.innerHTML = topologySnapshot.healthHtml;
                }
            }
            if (topologyLive) {
                if (degraded) {
                    topologyLive.classList.remove('live', 'offline');
                    topologyLive.classList.add('degraded');
                    topologyLive.innerHTML = '<i class="fas fa-circle"></i> <span>Interrupted</span>';
                } else {
                    topologyLive.className = topologySnapshot.liveClass;
                    topologyLive.innerHTML = topologySnapshot.liveHtml;
                }
            }
        }

        window.addEventListener('openap:mode-switch-confirmed', function (event) {
            var targetMode = event.detail && event.detail.targetMode;
            if (targetMode !== 'ap_ethernet' && targetMode !== 'ap_ethernet_bridge') return;

            // Recovery may have painted the topology as interrupted before the
            // administrator selected the Ethernet fallback. Reconcile those
            // classes and badges immediately; the mode icon and labels perform
            // their own transition right after this event.
            latestDegraded = false;
            degradedSamples = 0;
            outageVisible = false;
            dismissed = false;
            updateTopology(false, null, true);
        });

        function setStep(node, state) {
            if (!node) return;
            node.classList.toggle('active', state === 'active');
            node.classList.toggle('done', state === 'done');
            var icon = node.querySelector('i');
            if (icon) {
                icon.className = state === 'done'
                    ? 'fas fa-check-circle'
                    : (state === 'active' ? 'fas fa-circle-notch fa-spin' : 'far fa-circle');
            }
        }

        function render(data) {
            var failures = Math.max(0, Number(data.failures) || 0);
            if (reason) reason.textContent = data.reason || 'WiFi uplink is unavailable';
            if (attempt) {
                attempt.textContent = data.status === 'impaired'
                    ? 'The WiFi link is active; OpenAP is verifying upstream Internet access'
                    : (failures < 3
                    ? 'Recovery check ' + failures + ' of 3 before restarting the uplink service'
                    : 'OpenAP restarted the uplink and continues trying the saved network');
            }
            if (data.status === 'impaired') {
                setStep(restartStep, 'done');
                setStep(verifyStep, 'active');
            } else {
                setStep(restartStep, failures >= 3 ? 'done' : 'active');
                setStep(verifyStep, failures >= 3 ? 'active' : 'pending');
            }

            var ethernet = data.ethernet || {};
            if (ethernetReason) {
                ethernetReason.textContent = ethernet.reason || 'Checking the Ethernet connection…';
            }
            if (fallback) fallback.classList.toggle('is-ready', Boolean(ethernet.ready));
            if (ethernetButton) ethernetButton.hidden = !ethernet.ready;
        }

        function showRecovered() {
            if (recoveryTimer) window.clearTimeout(recoveryTimer);
            if (reason) reason.textContent = 'Internet connection restored';
            if (attempt) attempt.textContent = 'The saved WiFi uplink is online again.';
            setStep(restartStep, 'done');
            setStep(verifyStep, 'done');
            modalElement.querySelectorAll('.openap-recovery-node').forEach(function (node) {
                node.classList.remove('is-failed');
                node.classList.add('is-online');
            });
            modalElement.querySelectorAll('.openap-recovery-link').forEach(function (link) {
                link.classList.remove('is-failed');
                link.classList.add('is-online');
                var failureIcon = link.querySelector('i');
                if (failureIcon) failureIcon.remove();
            });
            recoveryTimer = window.setTimeout(function () {
                modal.hide();
                outageVisible = false;
                dismissed = false;
                window.location.reload();
            }, 1600);
        }

        function showRecoveryModalAfterTopologyAnimation() {
            if (!latestDegraded || !outageVisible || dismissed) return;
            if (topologyRecoveryAnimating || topologyRecoveryWaitingForOverlay) {
                window.setTimeout(showRecoveryModalAfterTopologyAnimation, 100);
                return;
            }
            modal.show();
        }

        modalElement.addEventListener('hidden.bs.modal', function () {
            if (fallbackHandoff) return;
            if (outageVisible) dismissed = true;
        });

        if (ethernetButton) {
            ethernetButton.addEventListener('click', function () {
                fallbackHandoff = true;
                dismissed = false;
                modal.hide();
                window.setTimeout(function () {
                    if (apEthernetModal) delete apEthernetModal.dataset.openapModeApplied;
                    showModal(modalMap['ap-ethernet']);
                }, 250);
            });
        }

        if (apEthernetModal) {
            apEthernetModal.addEventListener('hidden.bs.modal', function () {
                if (!fallbackHandoff) return;
                fallbackHandoff = false;
                if (apEthernetModal.dataset.openapModeApplied === '1') {
                    delete apEthernetModal.dataset.openapModeApplied;
                    return;
                }
                if (latestDegraded) {
                    dismissed = false;
                    outageVisible = true;
                    window.setTimeout(function () { modal.show(); }, 250);
                }
            });
        }

        function poll() {
            if (window.openapSession.expired) return;
            fetch('/ajax/networking/get_uplink_recovery_status.php?t=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            }).then(function (data) {
                var checkedAt = Number(data.checked_at) || 0;
                if (data.transitioning) {
                    degradedSamples = 0;
                    return;
                }
                var switchGuard = window.openapUplinkSwitchGuard;
                if (switchGuard) {
                    if (Date.now() >= switchGuard.expiresAt) {
                        delete window.openapUplinkSwitchGuard;
                    } else {
                        if (switchGuard.baselineCheckedAt === null) {
                            switchGuard.baselineCheckedAt = checkedAt;
                            degradedSamples = 0;
                            return;
                        }
                        if (checkedAt <= switchGuard.baselineCheckedAt) {
                            degradedSamples = 0;
                            return;
                        }
                        delete window.openapUplinkSwitchGuard;
                        degradedSamples = 0;
                    }
                }
                if (checkedAt > 0) window.openapLatestUplinkWatchdogCheck = checkedAt;
                if (!data.active) {
                    if (topologyRecentUplinkSuccess) {
                        topologyRecentUplinkSuccess = false;
                        try { window.sessionStorage.removeItem('openapTopologyRecentUplinkSuccessUntil'); } catch (error) {}
                    }
                    if (!remoteModeChangeHandled && data.mode && data.mode !== 'repeater_wifi') {
                        var localModeSwitch = window.openapLocalModeSwitchGuard;
                        if (localModeSwitch && localModeSwitch.expiresAt > Date.now()
                            && localModeSwitch.targetMode === data.mode) {
                            return;
                        }
                        remoteModeChangeHandled = true;
                        latestDegraded = false;
                        outageVisible = false;
                        fallbackHandoff = false;
                        dismissed = false;
                        if (reason) reason.textContent = data.mode === 'ap_ethernet_bridge'
                            ? 'Ethernet Bridge is now active'
                            : 'Ethernet Mode is now active';
                        if (attempt) {
                            attempt.textContent = 'The operating mode was changed from another OpenAP session.';
                        }
                        setStep(restartStep, 'done');
                        setStep(verifyStep, 'done');
                        updateTopology(false, data);
                        window.setTimeout(function () {
                            if (modalElement.classList.contains('show')) modal.hide();
                            window.location.reload();
                        }, modalElement.classList.contains('show') ? 1200 : 100);
                    }
                    return;
                }
                var degraded = data.status === 'degraded';
                var impaired = data.status === 'impaired';
                if (topologyRecentUplinkSuccess && (degraded || impaired || data.status === 'ready')) {
                    topologyRecentUplinkSuccess = false;
                    try { window.sessionStorage.removeItem('openapTopologyRecentUplinkSuccessUntil'); } catch (error) {}
                }
                latestDegraded = degraded;
                updateTopology(degraded, data);
                if (impaired) {
                    degradedSamples = 0;
                    if (outageVisible) {
                        outageVisible = false;
                        modal.hide();
                    }
                    return;
                }
                if (degraded) {
                    degradedSamples++;
                    render(data);
                    if (degradedSamples >= 2 && !outageVisible && !dismissed) {
                        outageVisible = true;
                        showRecoveryModalAfterTopologyAnimation();
                    }
                    return;
                }
                degradedSamples = 0;
                dismissed = false;
                if (data.status === 'ready' && outageVisible) {
                    showRecovered();
                }
            }).catch(function () {
                // Losing the management request is not enough evidence to
                // declare an uplink outage; wait for a confirmed watchdog state.
            });
        }

        if (topologyRecentUplinkSuccess && topologyUplink
            && !topologyUplink.classList.contains('active')) {
            // Preserve the last state the administrator actually saw before
            // the post-success reload. The first confirmed degraded watchdog
            // sample will now have a visible green state to transition from.
            updateTopology(false, null, true);
        }

        window.setTimeout(poll, 1200);
        window.openapSession.setInterval(poll, 4000);
    }

    function initRepeaterAvailabilityMonitor() {
        var card = document.getElementById('openapRepeaterModeCard');
        var selector = document.querySelector('.openap-mode-selector');
        if (!card || !selector) return;
        var ethernetCard = selector.querySelector('[data-openap-ethernet-mode]');
        var ethernetIcon = ethernetCard ? ethernetCard.querySelector('.openap-mode-option-icon i') : null;
        var ethernetState = ethernetCard ? ethernetCard.querySelector('.openap-mode-option-state') : null;
        var icon = card.querySelector('.openap-repeater-mode-icon i');
        var title = card.querySelector('.openap-repeater-mode-title');
        var state = card.querySelector('.openap-repeater-mode-state');
        var polling = false;
        var repeaterAvailabilityState = null;
        var hotspotNode = document.querySelector('[data-openap-topology-node="hotspot"]');
        var clientsNode = document.querySelector('[data-openap-topology-node="clients"]');
        var apLines = Array.from(document.querySelectorAll(
            '[data-openap-topology-line="uplink-openap"], [data-openap-topology-line="openap-clients"]'
        ));
        var topologyHealth = document.querySelector('[data-openap-topology-health]');
        var topologyLive = document.querySelector('[data-openap-topology-live]');
        var apReadyState = hotspotNode ? hotspotNode.classList.contains('active') : null;
        var apTopologyAnimating = false;
        var apTopologySnapshot = {
            healthClass: topologyHealth ? topologyHealth.className : '',
            healthHtml: topologyHealth ? topologyHealth.innerHTML : '',
            liveClass: topologyLive ? topologyLive.className : '',
            liveHtml: topologyLive ? topologyLive.innerHTML : ''
        };

        function updateEthernetAvailability(available) {
            if (!ethernetCard) return;
            var selectable = Boolean(available);
            var currentMode = selector.dataset.currentMode || '';
            var active = currentMode === 'ap_ethernet' || currentMode === 'ap_ethernet_bridge';
            ethernetCard.classList.toggle('is-unavailable', !selectable);
            if (selectable) {
                ethernetCard.dataset.openapModal = 'ap-ethernet';
                ethernetCard.removeAttribute('aria-disabled');
                ethernetCard.removeAttribute('tabindex');
                ethernetCard.removeAttribute('title');
                ethernetCard.removeAttribute('onclick');
                if (ethernetIcon) ethernetIcon.className = 'fas fa-network-wired';
                if (ethernetState) ethernetState.textContent = currentMode === 'ap_ethernet_bridge'
                    ? '● Bridge'
                    : (currentMode === 'ap_ethernet' ? '● Routed / NAT' : 'Switch →');
            } else {
                ethernetCard.removeAttribute('data-openap-modal');
                ethernetCard.setAttribute('aria-disabled', 'true');
                ethernetCard.setAttribute('tabindex', '-1');
                ethernetCard.setAttribute('title', 'Ethernet cable disconnected. Connect the cable to use Ethernet Mode.');
                ethernetCard.setAttribute('onclick', 'return false;');
                if (ethernetIcon) ethernetIcon.className = 'fas fa-ban';
                if (ethernetState) ethernetState.textContent = 'Cable disconnected';
            }
            if (active) ethernetCard.setAttribute('aria-current', 'true');
            else ethernetCard.removeAttribute('aria-current');
        }

        [hotspotNode, clientsNode].forEach(function (node) {
            if (!node) return;
            var label = node.querySelector('[data-openap-topology-label], .topo-label');
            var sub = node.querySelector('[data-openap-topology-sub], .topo-sub');
            if (label) label.dataset.apReadyText = label.textContent;
            if (sub) sub.dataset.apReadyText = sub.textContent;
        });

        function applyApTopologyState(ready) {
            [hotspotNode, clientsNode].forEach(function (node) {
                if (!node) return;
                node.classList.toggle('active', ready);
                node.classList.toggle('openap-uplink-interrupted', !ready);
                var indicator = node.querySelector('.topo-status-indicator');
                if (indicator) indicator.setAttribute('aria-label', ready ? 'Active' : 'Interrupted');
            });
            apLines.forEach(function (line) {
                line.classList.toggle('active', ready);
                line.classList.toggle('openap-uplink-interrupted', !ready);
            });
            if (hotspotNode) {
                var hotspotSub = hotspotNode.querySelector('[data-openap-topology-sub]');
                if (hotspotSub) hotspotSub.textContent = ready ? hotspotSub.dataset.apReadyText : 'Hotspot stopped';
            }
            if (clientsNode) {
                var clientsSub = clientsNode.querySelector('[data-openap-topology-sub]');
                if (clientsSub) clientsSub.textContent = ready ? clientsSub.dataset.apReadyText : 'Hotspot offline';
            }
            if (!ready) {
                if (topologyHealth) {
                    topologyHealth.className = 'openap-topology-health degraded';
                    topologyHealth.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Status: Degraded';
                }
                if (topologyLive) {
                    topologyLive.className = 'badge rounded-pill badge-openap openap-topology-live-badge degraded';
                    topologyLive.innerHTML = '<i class="fas fa-circle"></i> <span>Hotspot offline</span>';
                }
            } else {
                var uplinkNode = document.querySelector('[data-openap-topology-node="uplink"]');
                if (!uplinkNode || uplinkNode.classList.contains('active')) {
                    if (topologyHealth) {
                        topologyHealth.className = apTopologySnapshot.healthClass;
                        topologyHealth.innerHTML = apTopologySnapshot.healthHtml;
                    }
                    if (topologyLive) {
                        topologyLive.className = apTopologySnapshot.liveClass;
                        topologyLive.innerHTML = apTopologySnapshot.liveHtml;
                    }
                }
            }
        }

        function updateApTopology(ready, animate) {
            if (!hotspotNode || apTopologyAnimating || ready === apReadyState) return;
            apReadyState = ready;
            var nodes = [hotspotNode, clientsNode].filter(Boolean);
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!animate || reduceMotion || typeof hotspotNode.animate !== 'function') {
                applyApTopologyState(ready);
                return;
            }
            apTopologyAnimating = true;
            var hotspotSub = hotspotNode.querySelector('[data-openap-topology-sub]');
            var clientsSub = clientsNode ? clientsNode.querySelector('[data-openap-topology-sub]') : null;
            var changes = [
                {
                    node: hotspotSub,
                    text: ready ? ((hotspotSub && hotspotSub.dataset.apReadyText) || '-') : 'Hotspot stopped'
                },
                {
                    node: clientsSub,
                    text: ready ? ((clientsSub && clientsSub.dataset.apReadyText) || '-') : 'Hotspot offline'
                }
            ];
            nodes.forEach(function (node) { rollTopologyLed(node, ready); });
            animateTopologyValueChanges(changes, function () {
                applyApTopologyState(ready);
            }, function () {
                apTopologyAnimating = false;
            });
        }

        function replaceAvailabilityContent(selectable, active, bridgeBlocked, reason, animate) {
            var nextIcon = 'fas ' + (selectable ? 'fa-wifi' : (bridgeBlocked ? 'fa-lock' : 'fa-ban'));
            var nextState = active ? '● Active' : (bridgeBlocked ? 'Routed first' : (selectable ? 'Switch →' : reason));
            var elements = [icon, title, state].filter(Boolean);
            if (!animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                if (icon) icon.className = nextIcon;
                if (state) state.textContent = nextState;
                return;
            }
            elements.forEach(function (element, index) {
                window.setTimeout(function () {
                    element.animate([
                        { transform: 'translateY(0)', opacity: 1 },
                        { transform: 'translateY(115%)', opacity: 0 }
                    ], { duration: 280, easing: 'cubic-bezier(.55,0,1,.45)', fill: 'forwards' });
                }, index * 45);
            });
            window.setTimeout(function () {
                if (icon) icon.className = nextIcon;
                if (state) state.textContent = nextState;
                elements.slice().reverse().forEach(function (element, index) {
                    window.setTimeout(function () {
                        element.getAnimations().forEach(function (animation) { animation.cancel(); });
                        element.animate([
                            { transform: 'translateY(115%)', opacity: 0 },
                            { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                            { transform: 'translateY(0)', opacity: 1 }
                        ], { duration: 420, easing: 'cubic-bezier(.22,1,.36,1)' });
                    }, index * 55);
                });
            }, 430);
        }

        function update() {
            if (window.openapSession.expired) return;
            if (polling || document.hidden || document.getElementById('openapModeSwitchOverlay')) return;
            polling = true;
            fetch('/ajax/networking/get_interface_roles.php?t=' + Date.now(), {
                credentials: 'same-origin', cache: 'no-store'
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            }).then(function (result) {
                var bridgeBlocked = card.dataset.bridgeBlocked === '1';
                var available = Boolean(result.repeater_available);
                updateEthernetAvailability(result.ethernet_mode_available !== false);
                var unavailableReason = result.repeater_unavailable_reason || 'Repeater mode is unavailable.';
                var apReady = Boolean(result.ap_present && result.ap_service_active);
                var selectable = available && !bridgeBlocked;
                var active = available && selector.dataset.currentMode === 'repeater_wifi';

                updateApTopology(apReady, apReadyState !== null);

                var nextAvailabilityState = bridgeBlocked
                    ? 'bridge_blocked'
                    : (available ? 'available' : (result.repeater_unavailable_code || unavailableReason));
                var availabilityChanged = repeaterAvailabilityState !== null
                    && repeaterAvailabilityState !== nextAvailabilityState;
                if (card.dataset.suppressAvailabilityAnimationOnce === '1') {
                    availabilityChanged = false;
                    delete card.dataset.suppressAvailabilityAnimationOnce;
                }
                repeaterAvailabilityState = nextAvailabilityState;

                card.classList.toggle('is-unavailable', !selectable);
                card.classList.toggle('active', active);
                if (selectable) {
                    card.dataset.openapModal = 'uplink';
                    card.removeAttribute('aria-disabled');
                    card.removeAttribute('tabindex');
                    card.title = 'Configure repeater mode';
                } else {
                    card.removeAttribute('data-openap-modal');
                    card.setAttribute('aria-disabled', 'true');
                    card.tabIndex = -1;
                    card.title = bridgeBlocked
                        ? 'Switch Ethernet Mode to Routed / NAT first'
                        : unavailableReason;
                }
                if (active) card.setAttribute('aria-current', 'true');
                else card.removeAttribute('aria-current');
                replaceAvailabilityContent(selectable, active, bridgeBlocked, unavailableReason, availabilityChanged);
            }).catch(function () {
                // Preserve the last confirmed state after a transient request failure.
            }).finally(function () {
                polling = false;
            });
        }

        update();
        window.openapSession.setInterval(update, 4000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) update();
        });
    }

    function initDashboardWidgetArea() {
        var area = document.getElementById('openapDashboardWidgetArea');
        if (!area) return;

        var grid = area.querySelector('[data-openap-widget-grid]');
        var editButton = area.querySelector('[data-openap-widget-edit]');
        var resetButton = area.querySelector('[data-openap-widget-reset]');
        var status = area.querySelector('[data-openap-widget-status]');
        var desktop = window.matchMedia('(min-width: 992px)');
        var items = Array.from(grid.querySelectorAll('[data-openap-widget-id]'));
        var defaultOrder = items.map(function (item) { return item.dataset.openapWidgetId; });
        var configuredOrder = window.openapDashboardConfig && Array.isArray(window.openapDashboardConfig.widgetOrder)
            ? window.openapDashboardConfig.widgetOrder : defaultOrder;
        var accountOrder = configuredOrder.filter(function (id) { return defaultOrder.indexOf(id) !== -1; });
        var dragging = null;
        var dragArmed = false;
        var wideSwapTarget = '';
        var smallSwapTarget = '';
        var saveTimer = 0;

        function moveWithAnimation(callback) {
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var positions = new Map();
            if (!reduceMotion) {
                items.forEach(function (widget) {
                    if (widget !== dragging) positions.set(widget, widget.getBoundingClientRect());
                });
            }
            callback();
            if (reduceMotion) return;
            items.forEach(function (widget) {
                var previous = positions.get(widget);
                if (!previous) return;
                var current = widget.getBoundingClientRect();
                var offsetX = previous.left - current.left;
                var offsetY = previous.top - current.top;
                if (!offsetX && !offsetY) return;
                widget.getAnimations().forEach(function (animation) {
                    if (animation.id === 'openap-widget-reflow') animation.cancel();
                });
                var animation = widget.animate([
                    { transform: 'translate(' + offsetX + 'px, ' + offsetY + 'px)' },
                    { transform: 'translate(0, 0)' }
                ], { duration: 180, easing: 'cubic-bezier(.2, .8, .3, 1)' });
                animation.id = 'openap-widget-reflow';
            });
        }

        function setDragGhost(event, item) {
            if (!event.dataTransfer || typeof event.dataTransfer.setDragImage !== 'function') return;
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

        function announce(message) {
            if (!status) return;
            status.textContent = '';
            window.requestAnimationFrame(function () { status.textContent = message; });
        }

        function currentOrder() {
            return Array.from(grid.querySelectorAll('[data-openap-widget-id]')).map(function (item) {
                return item.dataset.openapWidgetId;
            });
        }

        function applyOrder(order) {
            var complete = order.concat(defaultOrder.filter(function (id) { return order.indexOf(id) === -1; }));
            complete.forEach(function (id) {
                var item = grid.querySelector('[data-openap-widget-id="' + id + '"]');
                if (item) {
                    item.style.order = '';
                    grid.appendChild(item);
                }
            });
        }

        function saveOrder() {
            accountOrder = currentOrder();
            window.clearTimeout(saveTimer);
            announce('Saving widget order for this account...');
            saveTimer = window.setTimeout(function () {
                var csrf = document.querySelector('meta[name="csrf_token"]');
                var data = new URLSearchParams();
                data.set('order', JSON.stringify(accountOrder));
                if (csrf) data.set('csrf_token', csrf.content);
                fetch('/ajax/dashboard/widget_layout.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: data.toString()
                }).then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok || !payload.success) throw new Error(payload.message || ('HTTP ' + response.status));
                        return payload;
                    });
                }).then(function (payload) {
                    accountOrder = payload.order;
                    announce('Widget order saved for this account.');
                }).catch(function (error) {
                    announce('Unable to save widget order: ' + error.message);
                });
            }, 180);
        }

        function setEditing(enabled) {
            enabled = Boolean(enabled && desktop.matches);
            area.classList.toggle('is-editing', enabled);
            editButton.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            editButton.querySelector('span').textContent = enabled ? 'Done' : 'Customize';
            editButton.querySelector('i').className = enabled ? 'fas fa-check' : 'fas fa-sliders';
            resetButton.hidden = !enabled;
            items.forEach(function (item) { item.draggable = enabled; });
            if (enabled) announce('Edit mode enabled. Drag a handle or use its arrow keys to arrange widgets.');
            else if (status) status.textContent = '';
        }

        function isHalfWidth(item) {
            return item && item.classList.contains('col-6');
        }

        function sameVisualRow(first, second) {
            if (!isHalfWidth(first) || !isHalfWidth(second)) return false;
            return Math.abs(first.getBoundingClientRect().top - second.getBoundingClientRect().top) < 4;
        }

        function halfWidthRow(target) {
            var ordered = Array.from(grid.querySelectorAll('[data-openap-widget-id]')).filter(function (widget) {
                return widget !== dragging;
            });
            var index = ordered.indexOf(target);
            var previous = index > 0 ? ordered[index - 1] : null;
            var next = index >= 0 && index < ordered.length - 1 ? ordered[index + 1] : null;
            if (sameVisualRow(previous, target)) return [previous, target];
            if (sameVisualRow(target, next)) return [target, next];
            return [target];
        }

        items.forEach(function (item) {
            var title = item.querySelector('.openap-widget-title');
            var label = title ? title.textContent.trim() : item.dataset.openapWidgetId;
            var handle = document.createElement('button');
            handle.type = 'button';
            handle.className = 'openap-widget-drag-handle';
            handle.setAttribute('aria-label', 'Move ' + label + ' widget');
            handle.title = 'Drag to reorder; use arrow keys for keyboard control';
            handle.innerHTML = '<i class="fas fa-grip-vertical" aria-hidden="true"></i>';
            item.appendChild(handle);

            handle.addEventListener('pointerdown', function () { dragArmed = true; });
            handle.addEventListener('pointerup', function () { dragArmed = false; });
            handle.addEventListener('keydown', function (event) {
                if (!area.classList.contains('is-editing') || !desktop.matches) return;
                var sibling = null;
                if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') sibling = item.previousElementSibling;
                if (event.key === 'ArrowDown' || event.key === 'ArrowRight') sibling = item.nextElementSibling;
                if (!sibling) return;
                event.preventDefault();
                if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') grid.insertBefore(item, sibling);
                else grid.insertBefore(sibling, item);
                saveOrder();
                handle.focus();
            });

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
                if (dragging) saveOrder();
                dragging = null;
                wideSwapTarget = '';
                smallSwapTarget = '';
                items.forEach(function (widget) { widget.classList.remove('is-dragging', 'is-drag-target'); });
            });
            item.addEventListener('dragover', function (event) {
                if (!dragging || dragging === item) return;
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                items.forEach(function (widget) { widget.classList.remove('is-drag-target'); });

                if (isHalfWidth(dragging) && isHalfWidth(item) && sameVisualRow(dragging, item)) {
                    item.classList.add('is-drag-target');
                    var smallTargetKey = item.dataset.openapWidgetId;
                    if (smallSwapTarget === smallTargetKey) return;
                    var rowWidgets = Array.from(grid.querySelectorAll('[data-openap-widget-id]'));
                    var smallDraggingIndex = rowWidgets.indexOf(dragging);
                    var smallTargetIndex = rowWidgets.indexOf(item);
                    smallSwapTarget = smallTargetKey;
                    moveWithAnimation(function () {
                        if (smallDraggingIndex < smallTargetIndex) grid.insertBefore(dragging, item.nextElementSibling);
                        else grid.insertBefore(dragging, item);
                    });
                    return;
                }

                if (isHalfWidth(dragging) && isHalfWidth(item)) {
                    wideSwapTarget = '';
                    var verticalTargetKey = 'vertical:' + item.dataset.openapWidgetId;
                    if (smallSwapTarget === verticalTargetKey) return;
                    var verticalWidgets = Array.from(grid.querySelectorAll('[data-openap-widget-id]'));
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
                    if (wideSwapTarget === targetKey) return;
                    var orderedWidgets = Array.from(grid.querySelectorAll('[data-openap-widget-id]'));
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
        resetButton.addEventListener('click', function () {
            applyOrder(defaultOrder);
            saveOrder();
        });
        var handleViewportChange = function () {
            setEditing(false);
            applyOrder(desktop.matches ? accountOrder : defaultOrder);
        };
        if (typeof desktop.addEventListener === 'function') desktop.addEventListener('change', handleViewportChange);
        else desktop.addListener(handleViewportChange);

        applyOrder(desktop.matches ? accountOrder : defaultOrder);
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-openap-modal]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        var config = modalMap[trigger.dataset.openapModal];
        if (config) {
            showModal(config);
        }
    });

    initHotspotRadioControls();
    initHotspotFormSubmission();
    initHotspotTabs();
    initHotspotPasswordToggle();
    initHotspotModalRefresh();
    initUplinkModalRefresh();
    initLiveTraffic();
    // Connected-client totals, AP bands and SSID are refreshed by the shared
    // widget monitor in app/js/ui/widget_layout.js on every widget page.
    initRepeaterAvailabilityMonitor();
    initUplinkRecoveryMonitor();
    // initDashboardWidgetArea() replaced by app/js/ui/widget_layout.js
}());
