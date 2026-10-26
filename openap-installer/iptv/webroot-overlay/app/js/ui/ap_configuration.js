(function () {
    'use strict';

    var form = document.getElementById('apConfigurationForm');
    if (!form) return;

    var band = document.getElementById('apcBand');
    var channel = document.getElementById('apcChannel');
    var mode = document.getElementById('apcMode');
    var modeDisplay = document.getElementById('apcModeDisplay');
    var width = document.getElementById('apcWidth');
    var info = document.getElementById('apcChipInfo');
    var suggestion = document.getElementById('apcWidthSuggestion');
    var psk = document.getElementById('apcPsk');
    var togglePsk = document.getElementById('apcTogglePsk');
    var security = document.getElementById('apcSecurity');
    var encryption = document.getElementById('apcEncryption');
    var pskRow = document.getElementById('apcPskRow');
    var securityNote = document.getElementById('apcSecurityNote');
    var openSecurityModalElement = document.getElementById('apOpenSecurityConfirmModal');
    var openSecurityConfirm = document.getElementById('apOpenSecurityConfirm');
    var pendingOpenSecuritySubmitter = null;
    var openSecurityConfirmed = false;
    var ssidInput = form.querySelector('[name="ssid"]');
    var txPowerInput = form.querySelector('[name="txpower"]');
    var settingToggles = Array.from(form.querySelectorAll('#apcIsolation, #apcHiddenSsid'));
    var savedSettings = null;
    var options = Array.from(channel.querySelectorAll('option[data-band]')).map(function (option) {
        return {
            band: option.dataset.band,
            value: option.value,
            label: option.textContent.trim(),
            maxDbm: parseInt(option.dataset.maxDbm || '30', 10)
        };
    });
    var initialWidth = parseInt(width.dataset.currentWidth || '20', 10);

    function validWidths(selected, is5, available) {
        var set = new Set(available.map(function (item) { return parseInt(item.value, 10); }));
        var widths = [20];
        if (!is5) {
            if ((selected <= 9 && set.has(selected + 4)) || (selected >= 5 && set.has(selected - 4))) {
                widths.push(40);
            }
            return widths;
        }
        var blocks40 = [[36,40],[44,48],[52,56],[60,64],[100,104],[108,112],[116,120],[124,128],[132,136],[140,144],[149,153],[157,161]];
        var blocks80 = [[36,40,44,48],[52,56,60,64],[100,104,108,112],[116,120,124,128],[132,136,140,144],[149,153,157,161]];
        var contains = function (block) { return block[0] === selected && block.every(function (item) { return set.has(item); }); };
        if (blocks40.some(contains)) widths.push(40);
        if (blocks80.some(contains)) widths.push(80);
        return widths;
    }

    function syncRadio(optimize) {
        var selectedBand = band.value === '5' ? '5' : '24';
        var is5 = selectedBand === '5';
        var previous = channel.value;
        var available = options.filter(function (item) { return item.band === selectedBand; });
        channel.replaceChildren();
        available.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            option.dataset.maxDbm = String(item.maxDbm);
            channel.appendChild(option);
        });
        if (available.some(function (item) { return item.value === previous; })) channel.value = previous;
        mode.value = is5 ? 'ac' : 'n';
        if (modeDisplay) {
            modeDisplay.value = is5 ? '802.11a / 802.11ac (5 GHz)' : '802.11n (2.4 GHz)';
        }
        var allowed = validWidths(parseInt(channel.value || '0', 10), is5, available);
        width.replaceChildren();
        var selectedWidth = optimize ? 20 : parseInt(width.dataset.currentWidth || String(initialWidth), 10);
        if (!allowed.includes(selectedWidth)) selectedWidth = 20;
        allowed.forEach(function (value) {
            var option = document.createElement('option');
            option.value = String(value);
            option.textContent = value + ' MHz';
            width.appendChild(option);
        });
        width.value = String(selectedWidth);
        width.disabled = false;
        width.dataset.currentWidth = String(selectedWidth);
        var selectedOption = channel.options[channel.selectedIndex];
        var maxDbm = selectedOption ? parseInt(selectedOption.dataset.maxDbm || '30', 10) : 30;
        txPowerInput.max = String(maxDbm);
        if (parseInt(txPowerInput.value || '0', 10) > maxDbm) {
            txPowerInput.value = String(maxDbm);
        }
        if (suggestion) suggestion.textContent = '';
        info.textContent = (is5 ? '802.11a/ac' : '802.11n') + ' · ' + available.length + ' channels';
        return allowed;
    }

    function copyToastText(value) {
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

    function showToast(level, title, text, duration, details) {
        if ((level === 'success' || level === 'danger') && typeof window.openapNotify === 'function') {
            var diagnosticDetails = details;
            if (level === 'danger' && (!Array.isArray(diagnosticDetails) || !diagnosticDetails.length)) {
                diagnosticDetails = [
                    'Failed operation: ' + title,
                    'Cause reported by OpenAP: ' + text,
                    'Diagnostic source: System logs and hostapd/dnsmasq service logs'
                ];
            }
            window.openapNotify({
                level: level === 'danger' ? 'error' : 'success',
                title: title,
                message: text,
                duration: level === 'success' ? 5000 : 0,
                details: Array.isArray(diagnosticDetails) && diagnosticDetails.length ? diagnosticDetails : [text]
            });
            return;
        }
        var previous = document.getElementById('apConfigurationToast');
        if (previous) previous.remove();
        var toast = document.createElement('div');
        toast.id = 'apConfigurationToast';
        toast.className = 'openap-ap-config-toast ' + level;
        toast.setAttribute('role', level === 'danger' ? 'alert' : 'status');
        var icon = level === 'success' ? 'fa-check-circle'
            : (level === 'info' ? 'fa-info-circle' : 'fa-exclamation-circle');
        toast.innerHTML = '<i class="fas ' + icon + '" aria-hidden="true"></i>'
            + '<div class="openap-toast-message"><strong></strong><span></span></div>'
            + (level === 'danger' ? '<button type="button" class="openap-toast-close" aria-label="Close error"><i class="fas fa-times"></i></button><button type="button" class="openap-toast-copy"><i class="far fa-copy"></i><span>Copy error</span></button>' : '');
        toast.querySelector('strong').textContent = title;
        toast.querySelector('span').textContent = text;
        toast.dataset.copyText = title + ': ' + text;
        document.body.appendChild(toast);
        window.requestAnimationFrame(function () { toast.classList.add('show'); });
        if (level === 'danger') {
            toast.querySelector('.openap-toast-close').addEventListener('click', function () { toast.remove(); });
            toast.querySelector('.openap-toast-copy').addEventListener('click', function () {
                copyToastText(toast.dataset.copyText).then(function () {
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
        }, duration);
    }

    function invalidFieldMessage(field) {
        var group = field.closest('.hfield-group');
        var label = group ? group.querySelector('.hfield-label') : null;
        var fieldName = label ? label.textContent.trim() : (field.name || 'Field');
        if (field.validity.valueMissing) return fieldName + ' is required.';
        if (field.validity.tooShort) {
            return fieldName + ' must contain at least ' + field.minLength + ' characters.';
        }
        if (field.validity.tooLong) {
            return fieldName + ' must contain no more than ' + field.maxLength + ' characters.';
        }
        if (field.validity.rangeUnderflow || field.validity.rangeOverflow) {
            return fieldName + ' must be between ' + field.min + ' and ' + field.max + '.';
        }
        return 'Check the value entered for ' + fieldName + '.';
    }

    var configurationResizeAnimation = null;

    function captureConfigurationPanelHeight() {
        var panel = document.getElementById('apConfigurationPanel');
        var page = document.getElementById('apConfigurationPage');
        if (!panel || !page || !page.classList.contains('is-page-ready')) return null;
        return panel.getBoundingClientRect().height;
    }

    function animateConfigurationPanelResize(previousHeight) {
        var panel = document.getElementById('apConfigurationPanel');
        if (!panel || previousHeight === null || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (configurationResizeAnimation) {
            configurationResizeAnimation.cancel();
            panel.style.height = '';
            panel.style.overflow = '';
        }
        var nextHeight = panel.getBoundingClientRect().height;
        if (Math.abs(nextHeight - previousHeight) < 1) return;
        panel.style.height = previousHeight + 'px';
        panel.style.overflow = 'hidden';
        var animation = panel.animate([
            { height: previousHeight + 'px' },
            { height: nextHeight + 'px' }
        ], {
            duration: 460,
            easing: 'cubic-bezier(.22,1,.36,1)'
        });
        configurationResizeAnimation = animation;
        animation.finished.catch(function () {}).finally(function () {
            if (configurationResizeAnimation !== animation) return;
            panel.style.height = '';
            panel.style.overflow = '';
            configurationResizeAnimation = null;
        });
    }

    function responseMessages(html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        return Array.from(parsed.querySelectorAll('.alert')).map(function (alert) {
            return {
                danger: alert.classList.contains('alert-danger'),
                success: alert.classList.contains('alert-success'),
                text: alert.textContent.trim()
            };
        }).filter(function (message) { return message.text !== ''; });
    }

    function captureSettings() {
        return {
            ssid: ssidInput.value.trim(),
            band: band.value,
            channel: channel.value,
            width: width.value,
            txPower: txPowerInput.value,
            security: security.value,
            psk: psk.value,
            isolation: document.getElementById('apcIsolation').checked,
            hiddenSsid: document.getElementById('apcHiddenSsid').checked
        };
    }

    function savedSettingsMessage(before, after) {
        var changes = [];
        var addChange = function (label, oldValue, newValue, suffix) {
            if (String(oldValue) !== String(newValue)) {
                changes.push(label + ': ' + oldValue + ' \u2192 ' + newValue + (suffix || ''));
            }
        };
        addChange('Network name', before.ssid, after.ssid);
        addChange('Band', before.band === '5' ? '5 GHz' : '2.4 GHz',
            after.band === '5' ? '5 GHz' : '2.4 GHz');
        addChange('Channel', before.channel, after.channel);
        addChange('Width', before.width, after.width, ' MHz');
        addChange('TX power', before.txPower, after.txPower, ' dBm');
        addChange('Security', before.security === 'none' ? 'None' : 'WPA2-PSK',
            after.security === 'none' ? 'None' : 'WPA2-PSK');
        if (after.security !== 'none' && before.psk !== after.psk) changes.push('WiFi password updated');
        if (before.isolation !== after.isolation) {
            changes.push('AP Isolation ' + (after.isolation ? 'enabled' : 'disabled'));
        }
        if (before.hiddenSsid !== after.hiddenSsid) {
            changes.push('Hidden SSID ' + (after.hiddenSsid ? 'enabled' : 'disabled'));
        }
        return {
            title: changes.length === 0 ? 'Configuration saved'
                : (changes.length === 1 ? 'Setting saved' : (changes.length + ' settings saved')),
            text: changes.length ? changes.join(' \u2022 ') : 'Configuration saved without changes.',
            changes: changes.length ? changes : ['No setting values changed']
        };
    }

    var hotspotSummaryAnimation = null;

    function replaceHotspotSummarySmoothly(current, fresh) {
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion || typeof current.animate !== 'function') {
            current.innerHTML = fresh.innerHTML;
            return Promise.resolve();
        }
        if (hotspotSummaryAnimation) {
            hotspotSummaryAnimation.cancel();
            hotspotSummaryAnimation = null;
        }

        var currentBands = Array.from(current.querySelectorAll('[data-openap-hotspot-band]'))
            .map(function (node) { return node.dataset.openapHotspotBand; }).sort().join('|');
        var freshBands = Array.from(fresh.querySelectorAll('[data-openap-hotspot-band]'))
            .map(function (node) { return node.dataset.openapHotspotBand; }).sort().join('|');
        var currentFields = Array.from(current.querySelectorAll('[data-openap-hotspot-field]'));
        var freshFields = Array.from(fresh.querySelectorAll('[data-openap-hotspot-field]'));
        var freshByKey = new Map(freshFields.map(function (node) {
            return [node.dataset.openapHotspotField, node];
        }));
        var sameStructure = currentBands === freshBands
            && currentFields.length === freshFields.length
            && currentFields.every(function (node) {
                return freshByKey.has(node.dataset.openapHotspotField);
            });

        var reveal = function (node) {
            return node.animate([
                { transform: 'translateY(115%)', opacity: 0 },
                { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                { transform: 'translateY(0)', opacity: 1 }
            ], { duration: 440, easing: 'cubic-bezier(.22,1,.36,1)' }).finished.catch(function () {});
        };

        if (!sameStructure) {
            var hideSummary = current.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(14px)', opacity: 0 }
            ], { duration: 330, easing: 'cubic-bezier(.55,0,1,.45)', fill: 'forwards' });
            hotspotSummaryAnimation = hideSummary;
            return hideSummary.finished.then(function () {
                current.innerHTML = fresh.innerHTML;
                hideSummary.cancel();
                hotspotSummaryAnimation = null;
                return reveal(current);
            }).catch(function () {
                current.innerHTML = fresh.innerHTML;
                hotspotSummaryAnimation = null;
            });
        }

        var changes = currentFields.map(function (node) {
            var freshNode = freshByKey.get(node.dataset.openapHotspotField);
            if (node.outerHTML === freshNode.outerHTML) return null;
            if (node.dataset.openapHotspotField === 'state'
                && node.innerHTML === freshNode.innerHTML
                && node.className === freshNode.className) {
                // Hidden SSID is recorded on the state container for summary
                // bookkeeping, but it does not change the visible Running
                // badge or service controls.  Synchronize that metadata
                // without animating the unrelated controls in the corner.
                node.dataset.hotspotHidden = freshNode.dataset.hotspotHidden || '0';
                return null;
            }
            var hideField = node.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(115%)', opacity: 0 }
            ], { duration: 330, easing: 'cubic-bezier(.55,0,1,.45)', fill: 'forwards' });
            return hideField.finished.then(function () {
                var replacement = freshNode.cloneNode(true);
                node.replaceWith(replacement);
                hideField.cancel();
                return reveal(replacement);
            }).catch(function () {
                node.replaceWith(freshNode.cloneNode(true));
            });
        }).filter(Boolean);
        return Promise.all(changes).then(function () {});
    }

    function refreshHotspotSummary() {
        return fetch('/ap_configuration?summary=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var fresh = parsed.querySelector('.openap-ap-configuration-page .hs-section');
            var current = document.querySelector('.openap-ap-configuration-page .hs-section');
            if (!fresh || !current) throw new Error('Hotspot summary not found');
            var summaryUpdate = replaceHotspotSummarySmoothly(current, fresh);

            var freshControls = parsed.querySelector('#apConfigurationForm .btn-group-ss');
            var currentControls = document.querySelector('#apConfigurationForm .btn-group-ss');
            if (freshControls && currentControls) {
                currentControls.innerHTML = freshControls.innerHTML;
            }

            var freshServices = parsed.querySelector('.openap-service-status-card');
            var currentServices = document.querySelector('.openap-service-status-card');
            if (freshServices && currentServices) {
                currentServices.innerHTML = freshServices.innerHTML;
            }
            return summaryUpdate;
        });
    }

    function waitForSavedRadioSettings(data, deadline, requireExactMatch) {
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
                && String(savedChannel.value) === String(data.get('channel') || '');
            var widthMatches = savedWidth
                && String(savedWidth.value || savedWidth.dataset.currentWidth || '')
                    === String(data.get('openap_channel_width') || '');
            // A successful POST already confirms that hostapd accepted and
            // saved the configuration. Drivers may normalize the channel or
            // width while the radio restarts, so in that case the form being
            // readable again is enough to confirm that the UI is ready.
            // Keep the exact comparison when the POST connection was
            // interrupted, because then it is our only proof that the save
            // reached the server.
            if (savedChannel && savedWidth
                && (!requireExactMatch || (channelMatches && widthMatches))) return;
            throw new Error('Radio settings are not ready yet.');
        }).catch(function (error) {
            if (Date.now() >= deadline) throw error;
            return waitForSavedRadioSettings(data, deadline, requireExactMatch);
        });
    }

    band.addEventListener('change', function () { syncRadio(true); });
    channel.addEventListener('change', function () { syncRadio(true); });
    width.addEventListener('change', function () {
        width.dataset.currentWidth = width.value;
        if (suggestion) suggestion.textContent = '';
    });
    settingToggles.forEach(function (control) {
        control.dataset.savedValue = control.checked ? '1' : '0';
    });
    togglePsk.addEventListener('click', function () {
        var reveal = psk.type === 'password';
        psk.type = reveal ? 'text' : 'password';
        togglePsk.querySelector('i').className = 'fas ' + (reveal ? 'fa-eye-slash' : 'fa-eye');
    });

    function syncSecurity() {
        var previousHeight = captureConfigurationPanelHeight();
        var isOpen = security.value === 'none';
        psk.disabled = isOpen;
        psk.required = !isOpen;
        pskRow.hidden = isOpen;
        encryption.value = isOpen ? 'None' : 'CCMP';
        var copy = securityNote.querySelector('span');
        copy.textContent = isOpen
            ? 'Open networks have no password or traffic encryption. Anyone within range can connect.'
            : 'WPA2-PSK with AES/CCMP is used for client compatibility.';
        animateConfigurationPanelResize(previousHeight);
    }
    security.addEventListener('change', syncSecurity);

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var submitter = event.submitter;
        if (!submitter) return;
        if (submitter.name === 'SaveHostAPDSettings'
            && security.value === 'none'
            && !openSecurityConfirmed) {
            pendingOpenSecuritySubmitter = submitter;
            bootstrap.Modal.getOrCreateInstance(openSecurityModalElement).show();
            return;
        }
        openSecurityConfirmed = false;
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            var invalidField = form.querySelector(':invalid');
            showToast('danger', 'Check required fields',
                invalidField ? invalidFieldMessage(invalidField) : 'Complete the required fields before saving.',
                5000);
            if (invalidField) invalidField.focus({ preventScroll: false });
            return;
        }
        var data = new FormData(form);
        data.set(submitter.name, submitter.value || '1');
        var submittedSettings = captureSettings();
        var saveSummary = savedSettingsMessage(savedSettings || submittedSettings, submittedSettings);
        settingToggles.forEach(function (control) { control.disabled = true; });
        var originalButtonHtml = submitter.innerHTML;
        var actionLabels = {
            SaveHostAPDSettings: 'Saving…',
            RestartHotspot: 'Restarting…',
            StopHotspot: 'Stopping…',
            StartHotspot: 'Starting…'
        };
        submitter.disabled = true;
        submitter.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> '
            + (actionLabels[submitter.name] || 'Working…');
        submitter.setAttribute('aria-busy', 'true');
        fetch(form.action || '/hostapd_conf', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: data
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            var messages = responseMessages(html);
            var failure = messages.find(function (message) { return message.danger; });
            if (failure) throw new Error(failure.text);
            if (submitter.name === 'SaveHostAPDSettings'
                && !messages.some(function (message) { return message.success; })) {
                throw new Error('OpenAP did not confirm that the settings were saved.');
            }
            var ssid = String(data.get('ssid') || 'WiFi hotspot').trim();
            if (submitter.name === 'SaveHostAPDSettings') {
                width.dataset.currentWidth = String(data.get('openap_channel_width') || '20');
                settingToggles.forEach(function (control) {
                    control.dataset.savedValue = control.checked ? '1' : '0';
                });
            }
            var successMessages = {
                SaveHostAPDSettings: saveSummary.text,
                RestartHotspot: ssid + ' successfully restarted.',
                StopHotspot: ssid + ' successfully stopped.',
                StartHotspot: ssid + ' successfully started.'
            };
            if (submitter.name === 'SaveHostAPDSettings') {
                showToast('info', 'Reconnecting',
                    'The WiFi radio is restarting. Waiting to verify the saved channel…', 45000);
                return waitForSavedRadioSettings(data, Date.now() + 45000, false).then(function () {
                    savedSettings = submittedSettings;
                    showToast('success', 'Operation successful',
                        'Settings successfully applied.', 5000, saveSummary.changes);
                    return refreshHotspotSummary().catch(function () {});
                }).finally(function () {
                    submitter.disabled = false;
                    settingToggles.forEach(function (control) { control.disabled = false; });
                    submitter.innerHTML = originalButtonHtml;
                    submitter.removeAttribute('aria-busy');
                });
            }
            showToast('success', 'Operation successful',
                successMessages[submitter.name] || 'Operation completed successfully.', 5000,
                [successMessages[submitter.name] || 'Hotspot service state updated']);
            window.setTimeout(function () {
                refreshHotspotSummary().catch(function () {
                    // The configuration is already saved. Leave the current
                    // summary in place rather than causing a full-page jump.
                }).finally(function () {
                    submitter.disabled = false;
                    settingToggles.forEach(function (control) { control.disabled = false; });
                    submitter.innerHTML = originalButtonHtml;
                    submitter.removeAttribute('aria-busy');
                });
            }, 700);
        }).catch(function (error) {
            var interrupted = submitter.name === 'SaveHostAPDSettings'
                && (error instanceof TypeError || /Failed to fetch|NetworkError|Load failed/i.test(error.message));
            if (interrupted) {
                showToast('info', 'Reconnecting',
                    'The WiFi radio is restarting. Waiting to verify the saved channel…', 45000);
                return waitForSavedRadioSettings(data, Date.now() + 45000, true).then(function () {
                    width.dataset.currentWidth = String(data.get('openap_channel_width') || '20');
                    settingToggles.forEach(function (control) {
                        control.dataset.savedValue = control.checked ? '1' : '0';
                    });
                    savedSettings = submittedSettings;
                    showToast('success', 'Operation successful',
                        'Settings successfully applied.', 5000, saveSummary.changes);
                    return refreshHotspotSummary().catch(function () {});
                }).catch(function (verifyError) {
                    settingToggles.forEach(function (control) {
                        control.checked = control.dataset.savedValue === '1';
                    });
                    showToast('danger', 'Operation failed',
                        'Unable to verify hotspot settings after reconnecting: ' + verifyError.message,
                        4000);
                }).finally(function () {
                    submitter.disabled = false;
                    settingToggles.forEach(function (control) { control.disabled = false; });
                    submitter.innerHTML = originalButtonHtml;
                    submitter.removeAttribute('aria-busy');
                });
            }
            submitter.disabled = false;
            settingToggles.forEach(function (control) {
                control.disabled = false;
                control.checked = control.dataset.savedValue === '1';
            });
            submitter.innerHTML = originalButtonHtml;
            submitter.removeAttribute('aria-busy');
            showToast('danger', 'Operation failed', error.message, 4000);
        });
    });

    var roleSwap = document.getElementById('apRoleSwap');
    var roleModalElement = document.getElementById('apInterfaceRoleModal');
    var roleModalConfirm = document.getElementById('apRoleModalConfirm');
    var roleConfirmView = document.getElementById('apInterfaceRoleConfirmView');
    var roleProgressView = document.getElementById('apInterfaceRoleProgressView');
    var roleSuccessView = document.getElementById('apInterfaceRoleSuccessView');
    var roleModalContent = document.getElementById('apInterfaceRoleModalContent');
    var rolePanel = document.getElementById('apInterfaceRolePanel');
    var roleAvailability = rolePanel && !rolePanel.hidden;
    var rolePollBusy = false;
    var rolePanelAnimation = null;
    var multiRolePanel = document.getElementById('apMultiRadioRoles');
    var configurationLayout = document.getElementById('apConfigurationLayout');
    var configurationPage = document.getElementById('apConfigurationPage');
    var pageViewport = configurationPage ? configurationPage.parentElement : null;
    var unassignedRadios = document.getElementById('apUnassignedRadios');
    var unassignedDropzone = document.getElementById('apUnassignedDropzone');
    var applyRoleDraft = document.getElementById('apApplyRoleDraft');
    var roleDraftBadge = document.getElementById('apRoleDraftBadge');
    var roleDraftStatus = document.getElementById('apRoleDraftStatus');
    var roleInventory = [];
    var roleDraft = { ap_24ghz: null, ap_5ghz: null, uplink: null };
    var roleDraftInitialized = false;
    var roleDraftDirty = false;
    var activeRoleSnapshot = { ap_24ghz: null, ap_5ghz: null, uplink: null };
    var appliedDualSnapshot = null;
    var pendingDualChanges = [];
    var roleSlots = ['ap_5ghz', 'ap_24ghz', 'uplink'];
    var singleBandBasic = document.getElementById('apc-basic');
    var dualBandBasic = document.getElementById('apc-dual-band');
    var noApBasic = document.getElementById('apc-no-ap');
    var saveHotspotSettings = document.getElementById('apcSaveSettings');
    var dual5Radio = document.getElementById('apcDual5Radio');
    var dual24Radio = document.getElementById('apcDual24Radio');
    var dualSsid = document.getElementById('apcDualSsid');
    var dual5Channel = document.getElementById('apcDual5Channel');
    var dual5Width = document.getElementById('apcDual5Width');
    var dual5TxPower = document.getElementById('apcDual5TxPower');
    var dual24Channel = document.getElementById('apcDual24Channel');
    var dual24Width = document.getElementById('apcDual24Width');
    var dual24TxPower = document.getElementById('apcDual24TxPower');
    var bandSettings5 = document.getElementById('apcBandSettings5');
    var bandSettings24 = document.getElementById('apcBandSettings24');
    var bandSettingsGrid = document.getElementById('apcBandSettingsGrid');
    var hotspotDraftInitialized = false;
    var roleBackendSignature = null;

    function captureDualSnapshot() {
        return {
            ssid: dualSsid ? dualSsid.value : '',
            channel5: dual5Channel ? dual5Channel.value : '',
            width5: dual5Width ? dual5Width.value : '',
            tx5: dual5TxPower ? dual5TxPower.value : '',
            channel24: dual24Channel ? dual24Channel.value : '',
            width24: dual24Width ? dual24Width.value : '',
            tx24: dual24TxPower ? dual24TxPower.value : '',
            security: security ? security.value : '2',
            psk: psk ? psk.value : '',
            isolation: document.getElementById('apcIsolation').checked,
            hiddenSsid: document.getElementById('apcHiddenSsid').checked
        };
    }

    function dualConfigurationChanges() {
        var changes = [];
        var before = appliedDualSnapshot || captureDualSnapshot();
        var after = captureDualSnapshot();
        var add = function (label, oldValue, newValue, suffix) {
            if (String(oldValue) !== String(newValue)) changes.push(label + ': ' + oldValue + ' \u2192 ' + newValue + (suffix || ''));
        };
        ['ap_5ghz', 'ap_24ghz', 'uplink'].forEach(function (slot) {
            if ((activeRoleSnapshot[slot] || '') !== (roleDraft[slot] || '')) {
                changes.push(slot.replace('ap_', 'AP ').replace('ghz', ' GHz').replace('uplink', 'Wi-Fi uplink')
                    + ': ' + (activeRoleSnapshot[slot] ? assignedRadioName(activeRoleSnapshot[slot]) : 'Not assigned')
                    + ' \u2192 ' + (roleDraft[slot] ? assignedRadioName(roleDraft[slot]) : 'Not assigned'));
            }
        });
        add('Network name', before.ssid, after.ssid);
        if (roleDraft.ap_5ghz) {
            add('5 GHz channel', before.channel5, after.channel5);
            add('5 GHz width', before.width5, after.width5, ' MHz');
            add('5 GHz TX power', before.tx5, after.tx5, ' dBm');
        }
        if (roleDraft.ap_24ghz) {
            add('2.4 GHz channel', before.channel24, after.channel24);
            add('2.4 GHz width', before.width24, after.width24, ' MHz');
            add('2.4 GHz TX power', before.tx24, after.tx24, ' dBm');
        }
        add('Security', before.security === 'none' ? 'None' : 'WPA2-PSK',
            after.security === 'none' ? 'None' : 'WPA2-PSK');
        if (after.security !== 'none' && before.psk !== after.psk) {
            changes.push('WiFi password updated');
        }
        add('AP Isolation', before.isolation ? 'Enabled' : 'Disabled', after.isolation ? 'Enabled' : 'Disabled');
        add('Hidden SSID', before.hiddenSsid ? 'Enabled' : 'Disabled', after.hiddenSsid ? 'Enabled' : 'Disabled');
        return changes.length ? changes : ['No setting values changed'];
    }

    function revealConfigurationLayout() {
        if (!configurationPage || !configurationPage.classList.contains('is-page-loading')) return;
        window.requestAnimationFrame(function () {
            if (pageViewport) pageViewport.classList.add('openap-deferred-page-ready');
            configurationPage.classList.remove('is-page-loading');
            configurationPage.classList.add('is-page-ready');
            configurationPage.style.removeProperty('visibility');
            configurationPage.style.removeProperty('opacity');
            if (!configurationPage.getAttribute('style')) configurationPage.removeAttribute('style');
            configurationPage.removeAttribute('aria-busy');
        });
    }

    function roleIdentity(radio) {
        return radio.identity_mac || radio.permanent_mac || radio.mac;
    }

    function radioSupportsSlot(radio, slot) {
        if (slot === 'uplink') return Boolean(radio.supports_managed);
        if (!radio.supports_ap) return false;
        return slot === 'ap_24ghz' ? Boolean(radio.supports_24ghz) : Boolean(radio.supports_5ghz);
    }

    function setRoleDraftStatus(message, level) {
        if (!roleDraftStatus) return;
        roleDraftStatus.textContent = message || '';
        roleDraftStatus.classList.toggle('is-error', level === 'error');
        roleDraftStatus.classList.toggle('is-success', level === 'success');
    }

    function assignDraftRole(identity, targetSlot) {
        roleSlots.forEach(function (slot) {
            if (roleDraft[slot] === identity) roleDraft[slot] = null;
        });
        if (targetSlot) {
            roleDraft[targetSlot] = identity;
        }
        roleDraftDirty = true;
        renderRoleDraft();
        setRoleDraftStatus('Assignments changed. Apply the configuration when ready.');
    }

    function createRoleSelect(radio, identity, selectedSlot) {
        var select = document.createElement('select');
        select.className = 'openap-radio-assign';
        select.setAttribute('aria-label', 'Assign ' + radio.name + ' to a wireless role');
        [['', 'Not assigned'], ['ap_5ghz', 'AP 5 GHz'], ['ap_24ghz', 'AP 2.4 GHz'], ['uplink', 'Wi-Fi uplink']]
            .forEach(function (entry) {
                var option = document.createElement('option');
                option.value = entry[0];
                option.textContent = entry[1];
                option.selected = entry[0] === (selectedSlot || '');
                option.disabled = Boolean(entry[0] && !radioSupportsSlot(radio, entry[0]));
                select.appendChild(option);
            });
        select.addEventListener('change', function () {
            assignDraftRole(identity, select.value || null);
        });
        return select;
    }

    function createRadioCard(radio, selectedSlot) {
        var identity = roleIdentity(radio);
        var card = document.createElement('div');
        card.className = 'openap-radio-card';
        card.draggable = true;
        card.dataset.radioIdentity = identity;
        var dragHandle = document.createElement('span');
        dragHandle.className = 'openap-radio-drag-handle';
        dragHandle.innerHTML = '<i class="fas fa-grip-vertical" aria-hidden="true"></i><span>Drag</span>';
        var name = document.createElement('strong');
        name.textContent = radio.name;
        var details = document.createElement('small');
        details.textContent = radio.details || [radio.bus, radio.driver].filter(Boolean).join(' · ');
        card.appendChild(dragHandle);
        card.appendChild(name);
        card.appendChild(details);
        var roleSelect = createRoleSelect(radio, identity, selectedSlot);
        card.appendChild(roleSelect);
        roleSelect.addEventListener('pointerdown', function () { card.draggable = false; });
        roleSelect.addEventListener('blur', function () { card.draggable = true; });
        roleSelect.addEventListener('change', function () { card.draggable = true; });
        card.addEventListener('dragstart', function (event) {
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', identity);
        });
        card.addEventListener('dragend', function () {
            card.classList.remove('is-dragging');
            document.querySelectorAll('.is-drag-over').forEach(function (element) {
                element.classList.remove('is-drag-over');
            });
        });
        return card;
    }

    function createMissingRadioCard(identity) {
        var card = document.createElement('div');
        card.className = 'openap-radio-card';
        var name = document.createElement('strong');
        name.textContent = 'Assigned radio disconnected';
        var details = document.createElement('small');
        details.textContent = 'The slot is preserved until the same hardware returns or is unassigned.';
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary btn-sm';
        button.textContent = 'Unassign';
        button.addEventListener('click', function () { assignDraftRole(identity, null); });
        card.appendChild(name);
        card.appendChild(details);
        card.appendChild(button);
        return card;
    }

    function syncHotspotConfigurationMode() {
        var ap5Assigned = Boolean(roleDraft.ap_5ghz);
        var ap24Assigned = Boolean(roleDraft.ap_24ghz);
        var apCount = Number(ap5Assigned) + Number(ap24Assigned);
        if (singleBandBasic) singleBandBasic.hidden = true;
        if (dualBandBasic) dualBandBasic.hidden = apCount === 0;
        if (noApBasic) noApBasic.hidden = apCount !== 0;
        if (bandSettings5) bandSettings5.hidden = !ap5Assigned;
        if (bandSettings24) bandSettings24.hidden = !ap24Assigned;
        if (bandSettingsGrid) bandSettingsGrid.classList.toggle('is-single-band', apCount === 1);
        if (dual5Radio) {
            var radio5 = roleInventory.find(function (radio) { return roleIdentity(radio) === roleDraft.ap_5ghz; });
            dual5Radio.textContent = radio5 ? radio5.name : 'Assigned radio disconnected';
        }
        if (dual24Radio) {
            var radio24 = roleInventory.find(function (radio) { return roleIdentity(radio) === roleDraft.ap_24ghz; });
            dual24Radio.textContent = radio24 ? radio24.name : 'Assigned radio disconnected';
        }
        syncDualBandControl(roleDraft.ap_24ghz, '2.4', dual24Channel, dual24Width, dual24TxPower, false);
        syncDualBandControl(roleDraft.ap_5ghz, '5', dual5Channel, dual5Width, dual5TxPower, false);
    }

    function syncDualBandControl(identity, selectedBand, channelControl, widthControl, txControl, optimize) {
        if (!identity || !channelControl || !widthControl || !txControl) return;
        var assignmentChanged = channelControl.dataset.radioIdentity !== identity;
        var radio = roleInventory.find(function (item) { return roleIdentity(item) === identity; });
        var channels = radio && Array.isArray(radio.channels)
            ? radio.channels.filter(function (item) {
                return String(item.band) === selectedBand && item.selectable !== false;
            }) : [];
        if (!channels.length) return;
        var previousChannel = channelControl.value;
        channelControl.replaceChildren();
        channels.forEach(function (item) {
            var option = document.createElement('option');
            option.value = String(item.channel);
            option.textContent = String(item.channel);
            option.dataset.maxDbm = String(item.max_dbm);
            option.dataset.radar = item.radar ? '1' : '0';
            channelControl.appendChild(option);
        });
        if (channels.some(function (item) { return String(item.channel) === previousChannel; })) {
            channelControl.value = previousChannel;
        }
        var available = channels.map(function (item) { return { value: String(item.channel) }; });
        var allowed = validWidths(parseInt(channelControl.value || '0', 10), selectedBand === '5', available);
        var previousWidth = parseInt(widthControl.value || '20', 10);
        widthControl.replaceChildren();
        allowed.forEach(function (value) {
            var option = document.createElement('option');
            option.value = String(value);
            option.textContent = value + ' MHz';
            widthControl.appendChild(option);
        });
        widthControl.value = String(!optimize && allowed.includes(previousWidth) ? previousWidth : 20);
        var selected = channels.find(function (item) { return String(item.channel) === channelControl.value; });
        var maxDbm = selected ? parseInt(selected.max_dbm, 10) : 30;
        txControl.max = String(maxDbm);
        txControl.readOnly = true;
        txControl.setAttribute('aria-readonly', 'true');
        txControl.title = 'Maximum ' + maxDbm + ' dBm on channel ' + channelControl.value;
        if (optimize || assignmentChanged || parseInt(txControl.value || '0', 10) !== maxDbm) {
            txControl.value = String(maxDbm);
        }
        channelControl.dataset.radioIdentity = identity;
    }

    function renderRoleDraft() {
        if (!multiRolePanel || !unassignedRadios) return;
        var previousHeight = captureConfigurationPanelHeight();
        var assigned = new Set(roleSlots.map(function (slot) { return roleDraft[slot]; }).filter(Boolean));
        unassignedRadios.replaceChildren();
        roleInventory.filter(function (radio) { return !assigned.has(roleIdentity(radio)); }).forEach(function (radio) {
            unassignedRadios.appendChild(createRadioCard(radio, null));
        });
        if (!unassignedRadios.children.length) {
            var empty = document.createElement('span');
            empty.className = 'openap-radio-empty';
            empty.textContent = roleInventory.length ? 'All detected radios are assigned.' : 'No wireless interfaces detected.';
            unassignedRadios.appendChild(empty);
        }

        roleSlots.forEach(function (slot) {
            var content = multiRolePanel.querySelector('[data-role-content="' + slot + '"]');
            if (!content) return;
            content.replaceChildren();
            var identity = roleDraft[slot];
            var radio = roleInventory.find(function (item) { return roleIdentity(item) === identity; });
            if (radio) {
                content.appendChild(createRadioCard(radio, slot));
            } else if (identity) {
                content.appendChild(createMissingRadioCard(identity));
            } else {
                var placeholder = document.createElement('span');
                placeholder.className = 'openap-radio-empty';
                placeholder.textContent = 'Drop or assign a compatible radio';
                content.appendChild(placeholder);
            }
        });
        roleDraftBadge.hidden = !roleDraftDirty;
        if (applyRoleDraft) {
            applyRoleDraft.disabled = !roleDraft.ap_24ghz && !roleDraft.ap_5ghz;
        }
        syncHotspotConfigurationMode();
        animateConfigurationPanelResize(previousHeight);
    }

    function updateMultiRadioRoles(result) {
        if (!multiRolePanel) return;
        var backendSignature = JSON.stringify({
            radios: result.radios || [],
            draft_roles: result.draft_roles || {},
            hotspot_draft: result.hotspot_draft || {}
        });
        if (roleDraftInitialized && backendSignature === roleBackendSignature) {
            revealConfigurationLayout();
            return;
        }
        roleBackendSignature = backendSignature;
        roleInventory = Array.isArray(result.radios) ? result.radios : [];
        activeRoleSnapshot = Object.assign({ ap_24ghz: null, ap_5ghz: null, uplink: null }, result.active_roles || {});
        if (!roleDraftInitialized || !roleDraftDirty) {
            roleDraft = Object.assign(
                { ap_24ghz: null, ap_5ghz: null, uplink: null },
                result.draft_roles || {}
            );
            roleDraftInitialized = true;
        }
        if (!hotspotDraftInitialized && result.hotspot_draft && result.hotspot_draft.configured) {
            var hotspot = result.hotspot_draft;
            dualSsid.value = hotspot.ssid;
            dual24Channel.value = String(hotspot.ap_24ghz_channel);
            dual24Width.value = String(hotspot.ap_24ghz_width);
            dual24TxPower.value = String(hotspot.ap_24ghz_txpower);
            dual5Channel.value = String(hotspot.ap_5ghz_channel);
            dual5Width.value = String(hotspot.ap_5ghz_width);
            dual5TxPower.value = String(hotspot.ap_5ghz_txpower);
            hotspotDraftInitialized = true;
        }
        renderRoleDraft();
        if (!appliedDualSnapshot) appliedDualSnapshot = captureDualSnapshot();
        revealConfigurationLayout();
        if (!roleDraftDirty && result.draft_validation && !result.draft_validation.valid) {
            setRoleDraftStatus(result.draft_validation.errors.map(function (error) { return error.message; }).join(' '), 'error');
        }
    }

    if (multiRolePanel) {
        [[dual24Channel, '2.4', dual24Width, dual24TxPower],
            [dual5Channel, '5', dual5Width, dual5TxPower]]
            .forEach(function (entry) {
                if (!entry[0]) return;
                entry[0].addEventListener('change', function () {
                    var identity = entry[1] === '5' ? roleDraft.ap_5ghz : roleDraft.ap_24ghz;
                    syncDualBandControl(identity, entry[1], entry[0], entry[2], entry[3], true);
                });
            });
        [dualSsid, dual24Channel, dual24Width, dual24TxPower, dual5Channel, dual5Width, dual5TxPower]
            .filter(Boolean).forEach(function (control) {
                control.addEventListener('change', function () {
                    roleDraftDirty = true;
                    renderRoleDraft();
                    setRoleDraftStatus('Hotspot settings changed. Apply the configuration when ready.');
                });
            });
        multiRolePanel.querySelectorAll('[data-role-slot]').forEach(function (slotElement) {
            slotElement.addEventListener('dragover', function (event) {
                event.preventDefault();
                slotElement.classList.add('is-drag-over');
            });
            slotElement.addEventListener('dragleave', function () {
                slotElement.classList.remove('is-drag-over');
            });
            slotElement.addEventListener('drop', function (event) {
                event.preventDefault();
                slotElement.classList.remove('is-drag-over');
                var identity = event.dataTransfer.getData('text/plain');
                var radio = roleInventory.find(function (item) { return roleIdentity(item) === identity; });
                var slot = slotElement.dataset.roleSlot;
                if (!radio || !radioSupportsSlot(radio, slot)) {
                    setRoleDraftStatus('That radio is not compatible with this slot.', 'error');
                    return;
                }
                assignDraftRole(identity, slot);
            });
        });
        unassignedDropzone.addEventListener('dragover', function (event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            unassignedDropzone.classList.add('is-drag-over');
        });
        unassignedDropzone.addEventListener('dragleave', function (event) {
            if (!unassignedDropzone.contains(event.relatedTarget)) {
                unassignedDropzone.classList.remove('is-drag-over');
            }
        });
        unassignedDropzone.addEventListener('drop', function (event) {
            event.preventDefault();
            unassignedDropzone.classList.remove('is-drag-over');
            var identity = event.dataTransfer.getData('text/plain');
            if (identity) assignDraftRole(identity, null);
        });
    }

    function saveRoleConfiguration() {
            var data = new FormData();
            data.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
            roleSlots.forEach(function (slot) { data.set(slot, roleDraft[slot] || ''); });
            data.set('hotspot_ssid', dualSsid.value);
            data.set('ap_24ghz_channel', dual24Channel.value);
            data.set('ap_24ghz_width', dual24Width.value);
            data.set('ap_24ghz_txpower', dual24TxPower.value);
            data.set('ap_5ghz_channel', dual5Channel.value);
            data.set('ap_5ghz_width', dual5Width.value);
            data.set('ap_5ghz_txpower', dual5TxPower.value);
            data.set('security', security.value);
            data.set('wpa_passphrase', psk.disabled ? '' : psk.value);
            data.set('ap_isolate', document.getElementById('apcIsolation').checked ? '1' : '0');
            data.set('ignore_broadcast_ssid', document.getElementById('apcHiddenSsid').checked ? '1' : '0');
            setRoleDraftStatus('Validating and saving configuration...');
            return fetch('/ajax/networking/save_wifi_role_draft.php', {
                method: 'POST', credentials: 'same-origin', body: data
            }).then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok || !body.success) {
                        var errors = body.validation && body.validation.errors
                            ? body.validation.errors.map(function (error) { return error.message; }).join(' ')
                            : body.message;
                        if (body.hotspot_validation && body.hotspot_validation.errors.length) {
                            errors = body.hotspot_validation.errors.map(function (error) { return error.message; }).join(' ');
                        }
                        throw new Error(errors || ('HTTP ' + response.status));
                    }
                    return body;
                });
            }).then(function (body) {
                roleDraft = body.draft_roles;
                hotspotDraftInitialized = Boolean(body.hotspot_draft && body.hotspot_draft.configured);
                roleDraftDirty = false;
                renderRoleDraft();
                return body;
            });
    }

    var dualApplyModalElement = document.getElementById('apDualBandApplyModal');
    var dualApplyModalContent = document.getElementById('apDualBandApplyModalContent');
    var dualApplyConfirm = document.getElementById('apDualBandApplyConfirm');
    var dualApplyConfirmView = document.getElementById('apDualBandConfirmView');
    var dualApplyProgressView = document.getElementById('apDualBandProgressView');
    var dualApplySuccessView = document.getElementById('apDualBandSuccessView');
    var dualApplyDismiss = document.getElementById('apDualBandApplyDismiss');

    if (dualApplyModalElement) {
        dualApplyModalElement.addEventListener('hidden.bs.modal', function () {
            document.body.classList.remove('openap-universal-apply-active');
            if (dualApplyModalContent) dualApplyModalContent.classList.remove('is-applying', 'is-apply-success', 'is-apply-error');
        });
    }

    function setDualApplyConfirmLoading(loading) {
        dualApplyConfirm.disabled = loading;
        dualApplyConfirm.setAttribute('aria-busy', loading ? 'true' : 'false');
        dualApplyConfirm.innerHTML = loading
            ? '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> <span>Applying...</span>'
            : '<i class="fas fa-rotate" aria-hidden="true"></i> <span>Apply and restart</span>';
    }

    function setDualApplyPrivacyState(element, enabled) {
        element.textContent = enabled ? 'Enabled' : 'Disabled';
        element.classList.toggle('is-enabled', enabled);
    }

    function assignedRadioName(identity) {
        var radio = roleInventory.find(function (item) { return roleIdentity(item) === identity; });
        return radio ? radio.name : 'Unavailable radio';
    }

    function setPageSaveButtonLoading(loading) {
        if (!applyRoleDraft) return;
        var spinner = applyRoleDraft.querySelector('[data-openap-save-spinner]');
        var icon = applyRoleDraft.querySelector('[data-openap-save-icon]');
        var label = applyRoleDraft.querySelector('[data-openap-save-label]');
        if (spinner) spinner.classList.toggle('d-none', !loading);
        if (icon) icon.classList.toggle('d-none', loading);
        if (label) {
            if (!label.dataset.defaultText) label.dataset.defaultText = label.textContent;
            label.textContent = loading
                ? (applyRoleDraft.dataset.loadingText || 'Saving...')
                : label.dataset.defaultText;
        }
        applyRoleDraft.disabled = loading || (!roleDraft.ap_24ghz && !roleDraft.ap_5ghz);
    }

    function setApplyStep(element, state) {
        element.className = state;
        element.querySelector('i').className = state === 'done'
            ? 'fas fa-check-circle'
            : (state === 'active' ? 'fas fa-circle-notch fa-spin' : 'far fa-circle');
    }

    function pollDualBandApply(deadline) {
        return fetch('/ajax/networking/get_interface_roles.php?apply=' + Date.now(), {
            credentials: 'same-origin', cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('OpenAP is restarting');
            return response.json();
        }).then(function (result) {
            if (result.apply_state === 'failed') {
                throw new Error((result.apply_error || 'The new configuration failed.')
                    + ' OpenAP restored the previous hotspot.');
            }
            if (result.apply_state === 'rollback_failed') {
                throw new Error((result.apply_error || 'The new configuration and its rollback failed.')
                    + ' OpenAP could not verify recovery; manual intervention is required.');
            }
            if (result.apply_state === 'scheduled' || result.apply_state === 'applying') {
                setApplyStep(document.getElementById('apDualStepPrepare'), 'done');
                setApplyStep(document.getElementById('apDualStepApply'), 'active');
            }
            var active = result.active_roles || {};
            var rolesMatch = active.ap_24ghz === roleDraft.ap_24ghz
                && active.ap_5ghz === roleDraft.ap_5ghz
                && active.uplink === roleDraft.uplink;
            if (result.apply_state === 'success' && rolesMatch) return result;
            if (Date.now() >= deadline) throw new Error('Timed out while verifying the hotspot.');
            return new Promise(function (resolve) { window.setTimeout(resolve, 1200); })
                .then(function () { return pollDualBandApply(deadline); });
        }).catch(function (error) {
            if (error.message.indexOf('restored the previous hotspot') !== -1
                || error.message.indexOf('manual intervention is required') !== -1
                || Date.now() >= deadline) throw error;
            return new Promise(function (resolve) { window.setTimeout(resolve, 1200); })
                .then(function () { return pollDualBandApply(deadline); });
        });
    }

    if (applyRoleDraft && dualApplyModalElement && dualApplyConfirm) {
        applyRoleDraft.addEventListener('click', function () {
            pendingDualChanges = dualConfigurationChanges();
            document.getElementById('apDualApplySsid').textContent = dualSsid.value;
            document.getElementById('apDualApply24').textContent = roleDraft.ap_24ghz
                ? assignedRadioName(roleDraft.ap_24ghz) + ' · ch ' + dual24Channel.value + ' · ' + dual24Width.value + ' MHz'
                : 'Disabled';
            document.getElementById('apDualApply5').textContent = roleDraft.ap_5ghz
                ? assignedRadioName(roleDraft.ap_5ghz) + ' · ch ' + dual5Channel.value + ' · ' + dual5Width.value + ' MHz'
                : 'Disabled';
            document.getElementById('apDualApplyUplink').textContent = roleDraft.uplink
                ? assignedRadioName(roleDraft.uplink) : 'Ethernet only';
            setDualApplyPrivacyState(document.getElementById('apDualApplyIsolation'), document.getElementById('apcIsolation').checked);
            setDualApplyPrivacyState(document.getElementById('apDualApplyHiddenSsid'), document.getElementById('apcHiddenSsid').checked);
            dualApplyConfirmView.hidden = false;
            dualApplyProgressView.hidden = true;
            dualApplySuccessView.hidden = true;
            setDualApplyConfirmLoading(false);
            if (dualApplyModalContent) dualApplyModalContent.classList.remove('is-applying', 'is-apply-success', 'is-apply-error');
            if (dualApplyDismiss) dualApplyDismiss.hidden = true;
            document.getElementById('apDualBandProgressTitle').textContent = 'Applying changes';
            document.getElementById('apDualBandProgressCaption').textContent = 'Wi-Fi clients may disconnect briefly.';
            ['apDualStepPrepare', 'apDualStepApply', 'apDualStepVerify'].forEach(function (id, index) {
                setApplyStep(document.getElementById(id), index === 0 ? 'active' : '');
            });
            bootstrap.Modal.getOrCreateInstance(dualApplyModalElement).show();
        });

        dualApplyConfirm.addEventListener('click', function () {
            setDualApplyConfirmLoading(true);
            setPageSaveButtonLoading(true);
            var data = new FormData();
            data.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
            saveRoleConfiguration().then(function () {
                setRoleDraftStatus('Configuration saved. Scheduling Wi-Fi restart...');
                return fetch('/ajax/networking/apply_wifi_role_draft.php', {
                method: 'POST', credentials: 'same-origin', body: data
                });
            }).then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok || !body.success) {
                        var applyError = new Error(body.message || ('HTTP ' + response.status));
                        if (body.diagnostic) {
                            applyError.diagnosticDetails = [
                                'Failed step: ' + (body.diagnostic.failed_step || 'AP configuration apply'),
                                'Cause: ' + (body.diagnostic.cause || 'Specific cause unavailable'),
                                'Component: ' + (body.diagnostic.component || 'Not reported'),
                                'Recovery: ' + (body.diagnostic.recovery || 'Recovery state not reported')
                            ];
                        }
                        throw applyError;
                    }
                    return body;
                });
            }).then(function () {
                dualApplyConfirmView.hidden = true;
                dualApplyProgressView.hidden = false;
                document.body.classList.add('openap-universal-apply-active');
                if (dualApplyModalContent) dualApplyModalContent.classList.add('is-applying');
                setApplyStep(document.getElementById('apDualStepPrepare'), 'done');
                setApplyStep(document.getElementById('apDualStepApply'), 'active');
                return pollDualBandApply(Date.now() + 90000);
            }).then(function () {
                setApplyStep(document.getElementById('apDualStepApply'), 'done');
                setApplyStep(document.getElementById('apDualStepVerify'), 'active');
                document.getElementById('apDualBandProgressCaption').textContent = 'Verifying the active Wi-Fi services.';
                return new Promise(function (resolve) { window.setTimeout(resolve, 450); });
            }).then(function () {
                setApplyStep(document.getElementById('apDualStepVerify'), 'done');
                window.setTimeout(function () {
                    dualApplyModalElement.addEventListener('hidden.bs.modal', function () {
                        savedSettings = captureSettings();
                        appliedDualSnapshot = captureDualSnapshot();
                        activeRoleSnapshot = Object.assign({}, roleDraft);
                        settingToggles.forEach(function (control) {
                            control.dataset.savedValue = control.checked ? '1' : '0';
                        });
                        setPageSaveButtonLoading(false);
                        setRoleDraftStatus('Configuration applied successfully.');
                        refreshInterfaceRoles();
                        refreshHotspotSummary().catch(function () {
                            // The backend already verified the applied state;
                            // keep the current summary if this optional refresh fails.
                        });
                        showToast('success', 'Operation successful',
                            'Settings successfully applied.', 5000, pendingDualChanges);
                    }, { once: true });
                    bootstrap.Modal.getOrCreateInstance(dualApplyModalElement).hide();
                }, 650);
            }).catch(function (error) {
                dualApplyProgressView.hidden = false;
                if (dualApplyModalContent) dualApplyModalContent.classList.add('is-applying', 'is-apply-error');
                document.getElementById('apDualBandProgressTitle').textContent = 'Applying changes';
                document.getElementById('apDualBandProgressCaption').textContent = error.message;
                var activeStep = dualApplyProgressView.querySelector('.openap-mode-switch-steps .active');
                if (activeStep) {
                    activeStep.className = 'error';
                    activeStep.querySelector('i').className = 'fas fa-circle-exclamation';
                }
                if (dualApplyDismiss) dualApplyDismiss.hidden = false;
                setDualApplyConfirmLoading(false);
                setPageSaveButtonLoading(false);
                var errorDetails = error.diagnosticDetails || null;
                dualApplyModalElement.addEventListener('hidden.bs.modal', function () {
                    showToast('danger', 'AP configuration could not be applied', error.message, 0,
                        errorDetails);
                }, { once: true });
                bootstrap.Modal.getOrCreateInstance(dualApplyModalElement).hide();
            });
        });
    }

    function animateRolePanel(available) {
        if (!rolePanel || rolePanel.hidden === !available) return;
        if (rolePanelAnimation) rolePanelAnimation.cancel();
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches
            || typeof rolePanel.animate !== 'function') {
            rolePanel.hidden = !available;
            return;
        }

        if (available) rolePanel.hidden = false;
        var fullHeight = rolePanel.getBoundingClientRect().height;
        rolePanel.style.overflow = 'hidden';
        rolePanelAnimation = rolePanel.animate(available ? [
            { height: '0px', opacity: 0, transform: 'translateY(-12px)' },
            { height: fullHeight + 'px', opacity: 1, transform: 'translateY(0)' }
        ] : [
            { height: fullHeight + 'px', opacity: 1, transform: 'translateY(0)' },
            { height: '0px', opacity: 0, transform: 'translateY(-12px)' }
        ], {
            duration: available ? 460 : 340,
            easing: available ? 'cubic-bezier(.22,1,.36,1)' : 'cubic-bezier(.55,0,1,.45)'
        });
        rolePanelAnimation.finished.then(function () {
            if (!available && !roleAvailability) rolePanel.hidden = true;
        }).catch(function () {}).finally(function () {
            rolePanel.style.overflow = '';
            rolePanelAnimation = null;
        });
    }
    function refreshInterfaceRoles() {
        if (window.openapSession.expired) return;
        if (!multiRolePanel || rolePollBusy || document.hidden) return;
        rolePollBusy = true;
        fetch('/ajax/networking/get_interface_roles.php?t=' + Date.now(), {
            credentials: 'same-origin', cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }).then(function (result) {
            updateMultiRadioRoles(result);
        }).catch(function () {
            // Keep the last known UI state on a transient detector or network error.
            if (!roleDraftInitialized) {
                roleDraftInitialized = true;
                renderRoleDraft();
                setRoleDraftStatus('Unable to refresh wireless interfaces. Retrying automatically.', 'error');
                revealConfigurationLayout();
            }
        }).finally(function () {
            rolePollBusy = false;
        });
    }
    function waitForInterfaceRoles(deadline) {
        return fetch('/ajax/networking/get_interface_role_apply_status.php?t=' + Date.now(), {
            credentials: 'same-origin', cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }).then(function (result) {
            if (result.status === 'success') return result;
            if (result.status === 'failed') throw new Error('The role change failed and OpenAP attempted to restore the previous roles.');
            if (Date.now() >= deadline) throw new Error('Timed out while waiting for the Wi-Fi roles to become ready.');
            return new Promise(function (resolve) { window.setTimeout(resolve, 900); })
                .then(function () { return waitForInterfaceRoles(deadline); });
        }).catch(function (error) {
            if (Date.now() >= deadline || /failed|Timed out/.test(error.message)) throw error;
            return new Promise(function (resolve) { window.setTimeout(resolve, 900); })
                .then(function () { return waitForInterfaceRoles(deadline); });
        });
    }
    function setRoleStepDone(element) {
        element.className = 'done';
        element.querySelector('i').className = 'fas fa-check-circle';
    }
    function setRoleStepActive(element) {
        element.className = 'active';
        element.querySelector('i').className = 'fas fa-circle-notch fa-spin';
    }
    if (roleSwap && roleModalElement && roleModalConfirm) {
        roleSwap.addEventListener('click', function () {
            var currentApName = roleSwap.dataset.apName;
            var currentUplinkName = roleSwap.dataset.uplinkName;
            roleConfirmView.hidden = false;
            roleProgressView.hidden = true;
            roleSuccessView.hidden = true;
            roleModalContent.classList.remove('is-success');
            roleModalConfirm.disabled = false;
            document.querySelector('#apInterfaceRoleProgressView .openap-role-transfer-visual').classList.remove('complete', 'is-exchanging', 'is-finishing');
            document.getElementById('apRoleProgressTitle').textContent = 'Switching wireless roles';
            document.getElementById('apRoleProgressCaption').textContent = 'OpenAP is restarting both Wi-Fi interfaces.';
            document.getElementById('apRoleModalNewAp').textContent = currentUplinkName;
            document.getElementById('apRoleModalNewUplink').textContent = currentApName;
            document.getElementById('apRoleProgressOldAp').textContent = currentApName;
            document.getElementById('apRoleProgressNewAp').textContent = currentUplinkName;
            bootstrap.Modal.getOrCreateInstance(roleModalElement).show();
        });
        roleModalConfirm.addEventListener('click', function () {
            roleModalConfirm.disabled = true;
            var original = roleSwap.innerHTML;
            roleSwap.disabled = true;
            roleSwap.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            roleConfirmView.hidden = true;
            roleProgressView.hidden = false;
            var prepareStep = document.getElementById('apRoleStepPrepare');
            var applyStep = document.getElementById('apRoleStepApply');
            var verifyStep = document.getElementById('apRoleStepVerify');
            window.setTimeout(function () { setRoleStepDone(prepareStep); setRoleStepActive(applyStep); }, 700);
            var data = new FormData();
            data.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
            data.set('ap_mac', roleSwap.dataset.uplinkMac);
            data.set('uplink_mac', roleSwap.dataset.apMac);
            fetch('/ajax/networking/apply_interface_roles.php', {
                method: 'POST', credentials: 'same-origin', body: data
            }).then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok || !body.success) throw new Error(body.message || ('HTTP ' + response.status));
                    setRoleStepDone(applyStep);
                    setRoleStepActive(verifyStep);
                    return waitForInterfaceRoles(Date.now() + 70000);
                });
            }).then(function (result) {
                setRoleStepDone(verifyStep);
                var transferVisual = document.querySelector('#apInterfaceRoleProgressView .openap-role-transfer-visual');
                var oldApText = document.getElementById('apRoleProgressOldAp');
                var oldUplinkText = document.getElementById('apRoleProgressNewAp');
                transferVisual.classList.add('is-finishing');
                window.setTimeout(function () {
                    [oldApText, oldUplinkText].forEach(function (line, index) {
                        window.setTimeout(function () {
                            line.animate([
                                { transform: 'translateY(-50%)', opacity: 1 },
                                { transform: 'translateY(65%)', opacity: 0 }
                            ], { duration: 330, easing: 'cubic-bezier(.55,0,1,.45)', fill: 'forwards' });
                        }, index * 45);
                    });
                }, 220);
                window.setTimeout(function () {
                    oldApText.textContent = result.ap;
                    oldUplinkText.textContent = result.uplink;
                    [oldApText, oldUplinkText].forEach(function (line) {
                        line.getAnimations().forEach(function (animation) { animation.cancel(); });
                        line.animate([
                            { transform: 'translateY(65%)', opacity: 0 },
                            { transform: 'translateY(-58%)', opacity: 1, offset: .82 },
                            { transform: 'translateY(-50%)', opacity: 1 }
                        ], { duration: 440, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'forwards' });
                    });
                    transferVisual.classList.add('complete');
                }, 650);
                document.getElementById('apRoleProgressTitle').textContent = 'Wireless roles updated';
                document.getElementById('apRoleProgressCaption').textContent = 'Access point: ' + result.ap + ' · Wi-Fi uplink: ' + result.uplink;
                function animateRoleCards() {
                    var replacements = [
                        [document.getElementById('apRoleCurrentApName'), result.ap],
                        [document.getElementById('apRoleCurrentApDetails'), roleSwap.dataset.uplinkDetails],
                        [document.getElementById('apRoleCurrentUplinkName'), result.uplink],
                        [document.getElementById('apRoleCurrentUplinkDetails'), roleSwap.dataset.apDetails]
                    ];
                    replacements.forEach(function (entry, index) {
                        window.setTimeout(function () {
                            entry[0].animate([
                                { transform: 'translateY(0)', opacity: 1 },
                                { transform: 'translateY(115%)', opacity: 0 }
                            ], { duration: 330, easing: 'cubic-bezier(.55,0,1,.45)', fill: 'forwards' });
                        }, index * 45);
                    });
                    window.setTimeout(function () {
                        replacements.forEach(function (entry) {
                            entry[0].textContent = entry[1];
                            entry[0].getAnimations().forEach(function (animation) { animation.cancel(); });
                            entry[0].animate([
                                { transform: 'translateY(115%)', opacity: 0 },
                                { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                                { transform: 'translateY(0)', opacity: 1 }
                            ], { duration: 440, easing: 'cubic-bezier(.22,1,.36,1)' });
                        });
                    }, 430);
                }
                window.setTimeout(function () {
                    document.getElementById('apRoleSuccessAp').textContent = result.ap;
                    document.getElementById('apRoleSuccessUplink').textContent = result.uplink;
                    roleProgressView.hidden = true;
                    roleSuccessView.hidden = false;
                    roleModalContent.classList.add('is-success');
                }, 1300);
                window.setTimeout(function () {
                    bootstrap.Modal.getOrCreateInstance(roleModalElement).hide();
                }, 3300);
                roleModalElement.addEventListener('hidden.bs.modal', function () {
                    animateRoleCards();
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                }, { once: true });
            }).catch(function (error) {
                document.getElementById('apRoleProgressTitle').textContent = 'Unable to change wireless roles';
                document.getElementById('apRoleProgressCaption').textContent = error.message;
                roleSwap.disabled = false;
                roleSwap.innerHTML = original;
                window.setTimeout(function () {
                    roleProgressView.hidden = true;
                    roleConfirmView.hidden = false;
                    roleModalConfirm.disabled = false;
                }, 3000);
            });
        });
    }
    window.openapSession.setInterval(refreshInterfaceRoles, 4000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refreshInterfaceRoles();
    });

    openSecurityConfirm.addEventListener('click', function () {
        var submitter = pendingOpenSecuritySubmitter;
        pendingOpenSecuritySubmitter = null;
        openSecurityConfirmed = true;
        bootstrap.Modal.getOrCreateInstance(openSecurityModalElement).hide();
        form.requestSubmit(submitter);
    });
    openSecurityModalElement.addEventListener('hidden.bs.modal', function () {
        if (!openSecurityConfirmed) pendingOpenSecuritySubmitter = null;
    });

    syncRadio(false);
    syncSecurity();
    savedSettings = captureSettings();
    refreshInterfaceRoles();
    window.setTimeout(revealConfigurationLayout, 3000);
}());
