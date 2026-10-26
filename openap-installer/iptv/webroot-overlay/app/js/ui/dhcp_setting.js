(function () {
    'use strict';
    var form = document.getElementById('dhcpSettingForm');
    if (!form) return;
    var preset = document.getElementById('dhcpDnsPreset');
    var policy = document.getElementById('dhcpDnsPolicy');
    var advertised = document.getElementById('dhcpAdvertisedDns');
    var upstream = document.getElementById('dhcpUpstreamDns');
    var network = document.getElementById('dhcpNetworkAddress');
    var subnet = document.getElementById('dhcpSubnet');
    var gateway = document.getElementById('dhcpGateway');
    var rangeStart = document.getElementById('dhcpRangeStart');
    var rangeEnd = document.getElementById('dhcpRangeEnd');
    var encryptedMode = form.dataset.encryptedDns === '1';
    var leaseTime = form.elements.dhcp_lease_time;
    var savedSettings = null;
    var applyModalElement = document.getElementById('apDualBandApplyModal');
    var applyModalContent = document.getElementById('dhcpApplyModalContent');
    var applyModal = applyModalElement && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(applyModalElement) : null;

    function animateDhcpWidget(settings) {
        var values = Array.from(document.querySelectorAll(
            '.openap-dhcp-setting-widget [data-openap-dhcp-range], ' +
            '.openap-dhcp-setting-widget [data-openap-dhcp-lease]'
        ));
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var updateValue = function (element) {
            if (element.hasAttribute('data-openap-dhcp-range')) {
                element.textContent = settings.get('dhcp_start') + ' - ' + settings.get('dhcp_end');
            }
            if (element.hasAttribute('data-openap-dhcp-lease')) {
                element.textContent = settings.get('dhcp_lease_time');
            }
        };

        values.forEach(function (element) {
            if (reduceMotion || typeof element.animate !== 'function') {
                updateValue(element);
                return;
            }
            var hide = element.animate([
                { transform: 'translateY(0)', opacity: 1 },
                { transform: 'translateY(8px)', opacity: 0 }
            ], {
                duration: 260,
                easing: 'cubic-bezier(.55,0,1,.45)',
                fill: 'forwards'
            });
            hide.finished.then(function () {
                updateValue(element);
                hide.cancel();
                element.animate([
                    { transform: 'translateY(-8px)', opacity: 0 },
                    { transform: 'translateY(0)', opacity: 1 }
                ], {
                    duration: 380,
                    easing: 'cubic-bezier(.22,1,.36,1)'
                });
            }).catch(function () {
                updateValue(element);
            });
        });
    }

    function setApplyStep(element, state) {
        if (!element) return;
        element.className = state || '';
        var icon = element.querySelector('i');
        if (!icon) return;
        icon.className = state === 'active' ? 'fas fa-circle-notch fa-spin'
            : (state === 'done' ? 'fas fa-check-circle' : (state === 'error' ? 'fas fa-circle-exclamation' : 'far fa-circle'));
    }

    function beginApplyModal(kind) {
        if (!applyModal) return;
        var dns = kind === 'dns';
        document.getElementById('dhcpApplyProgressTitle').textContent = 'Applying changes';
        document.getElementById('dhcpApplyProgressCaption').textContent = dns
            ? 'Encrypted DNS services may restart briefly.' : 'DHCP and DNS services may restart briefly.';
        document.getElementById('dhcpApplyProgressIcon').className = 'fas ' + (dns ? 'fa-shield-alt' : 'fa-network-wired');
        document.querySelector('#dhcpApplyStepPrepare span').textContent = dns ? 'Validating encrypted DNS settings' : 'Validating DHCP and DNS settings';
        document.querySelector('#dhcpApplyStepApply span').textContent = dns ? 'Applying provider and restarting proxy' : 'Applying DHCP and DNS configuration';
        document.querySelector('#dhcpApplyStepVerify span').textContent = dns ? 'Verifying dnscrypt-proxy and dnsmasq' : 'Verifying dnsmasq and active network';
        setApplyStep(document.getElementById('dhcpApplyStepPrepare'), 'active');
        setApplyStep(document.getElementById('dhcpApplyStepApply'), '');
        setApplyStep(document.getElementById('dhcpApplyStepVerify'), '');
        if (applyModalContent) applyModalContent.classList.remove('is-apply-error');
        document.body.classList.add('openap-universal-apply-active');
        applyModal.show();
    }

    function closeApplyModal(callback) {
        if (!applyModalElement || !applyModal) {
            if (callback) callback();
            return;
        }
        applyModalElement.addEventListener('hidden.bs.modal', function () {
            document.body.classList.remove('openap-universal-apply-active');
            if (callback) callback();
        }, { once: true });
        applyModal.hide();
    }

    function transitionDnsSections(nextSections) {
        var current = document.querySelector('.openap-dns-column-sections');
        if (!current || !nextSections) return Promise.resolve();
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof current.animate !== 'function') {
            current.innerHTML = nextSections.innerHTML;
            return Promise.resolve();
        }

        var animatedParts = function (root) {
            var standard = root.querySelector('.openap-standard-dns-section');
            var standardContent = standard ? Array.from(standard.children) : [];
            return standardContent.concat(Array.from(root.querySelectorAll('.openap-encrypted-dns-runtime-value')));
        };
        var runVertical = function (elements, opening) {
            return Promise.all(elements.map(function (element) {
                var animation = element.animate(opening ? [
                    { opacity: 0, transform: 'translateY(-12px)', clipPath: 'inset(0 0 100% 0)' },
                    { opacity: 1, transform: 'translateY(0)', clipPath: 'inset(0 0 0 0)' }
                ] : [
                    { opacity: 1, transform: 'translateY(0)', clipPath: 'inset(0 0 0 0)' },
                    { opacity: 0, transform: 'translateY(12px)', clipPath: 'inset(100% 0 0 0)' }
                ], {
                    duration: opening ? 460 : 300,
                    easing: opening ? 'cubic-bezier(.22,1,.36,1)' : 'cubic-bezier(.55,0,1,.45)',
                    fill: 'forwards'
                });
                return animation.finished.catch(function () {}).then(function () {
                    animation.cancel();
                });
            }));
        };

        return runVertical(animatedParts(current), false).then(function () {
            var currentStandard = current.querySelector('.openap-standard-dns-section');
            var nextStandard = nextSections.querySelector('.openap-standard-dns-section');
            if (currentStandard && nextStandard) currentStandard.replaceWith(nextStandard.cloneNode(true));
            var currentFields = current.querySelector('.openap-encrypted-dns-fields');
            var nextFields = nextSections.querySelector('.openap-encrypted-dns-fields');
            if (currentFields && nextFields) currentFields.innerHTML = nextFields.innerHTML;
            var currentNote = current.querySelector('.openap-encrypted-dns-note');
            var nextNote = nextSections.querySelector('.openap-encrypted-dns-note');
            if (currentNote && nextNote) currentNote.replaceWith(nextNote.cloneNode(true));
            preset = document.getElementById('dhcpDnsPreset');
            policy = document.getElementById('dhcpDnsPolicy');
            advertised = document.getElementById('dhcpAdvertisedDns');
            upstream = document.getElementById('dhcpUpstreamDns');
            var refreshedToggle = document.getElementById('encryptedDnsEnabled');
            encryptedMode = !!(refreshedToggle && refreshedToggle.checked);
            form.dataset.encryptedDns = encryptedMode ? '1' : '0';
            if (preset) preset.addEventListener('change', function () { syncDnsPolicy(true); });
            if (policy) policy.addEventListener('change', function () { syncDnsPolicy(true); });
            return runVertical(animatedParts(current), true);
        });
    }

    function refreshDnsSections() {
        var separator = window.location.search ? '&' : '?';
        return fetch(window.location.pathname + window.location.search + separator + 'dns_refresh=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var freshAdvertised = parsed.getElementById('dhcpAdvertisedDns');
            var freshUpstream = parsed.getElementById('dhcpUpstreamDns');
            var freshPolicy = parsed.getElementById('dhcpDnsPolicy');
            if (!freshAdvertised || !freshUpstream || !freshPolicy) {
                throw new Error('Fresh DNS settings were not returned.');
            }
            advertised.value = freshAdvertised.value;
            upstream.value = freshUpstream.value;
            policy.value = freshPolicy.value;
            syncDnsPolicy(false);

            var currentProtectedClientDns = document.querySelector('.openap-dns-path .openap-dns-path-node strong');
            var freshProtectedClientDns = parsed.querySelector('.openap-dns-path .openap-dns-path-node strong');
            if (currentProtectedClientDns && freshProtectedClientDns) {
                currentProtectedClientDns.textContent = freshProtectedClientDns.textContent;
            }
        }).catch(function () {
            // Keep the form coherent even if the optional visual refresh fails;
            // the periodic widget refresh will still obtain authoritative data.
            if (policy && policy.value === 'local' && advertised) {
                advertised.value = gateway.value.trim();
            }
            var protectedClientDns = document.querySelector('.openap-dns-path-node strong');
            if (protectedClientDns && encryptedMode) {
                protectedClientDns.textContent = gateway.value.trim();
            }
        });
    }

    if (applyModalElement) {
        applyModalElement.addEventListener('hidden.bs.modal', function () {
            document.body.classList.remove('openap-universal-apply-active');
        });
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

    function showToast(level, text, title, details) {
        if ((level === 'success' || level === 'danger') && typeof window.openapNotify === 'function') {
            var notificationDetails = Array.isArray(details) && details.length ? details : null;
            if (level === 'danger' && !notificationDetails) {
                notificationDetails = [
                    'Failed operation: ' + (title || 'DHCP and DNS settings'),
                    'Cause reported by OpenAP: ' + text,
                    'Diagnostic source: DHCP/DNS service logs'
                ];
            }
            window.openapNotify({
                level: level === 'danger' ? 'error' : 'success',
                title: level === 'success' ? 'Operation successful' : (title || 'Operation failed'),
                message: level === 'success' ? 'Settings successfully applied.' : text,
                duration: level === 'success' ? 5000 : 0,
                details: notificationDetails || ['No setting values changed']
            });
            return;
        }
        var old = document.getElementById('apConfigurationToast');
        if (old) old.remove();
        var toast = document.createElement('div');
        toast.id = 'apConfigurationToast';
        toast.className = 'openap-ap-config-toast ' + level;
        toast.setAttribute('role', level === 'danger' ? 'alert' : 'status');
        toast.innerHTML = '<i class="fas ' + (level === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i><div class="openap-toast-message"><strong></strong><span></span></div>'
            + (level === 'danger' ? '<button type="button" class="openap-toast-close" aria-label="Close error"><i class="fas fa-times"></i></button><button type="button" class="openap-toast-copy"><i class="far fa-copy"></i><span>Copy error</span></button>' : '');
        toast.querySelector('strong').textContent = title
            || (level === 'success' ? 'Operation successful' : 'Operation failed');
        toast.querySelector('span').textContent = text;
        toast.dataset.copyText = toast.querySelector('strong').textContent + ': ' + text;
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('show'); });
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
        setTimeout(function () {
            toast.classList.add('leaving');
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 180);
        }, 5000);
    }

    function invalidFieldMessage(field) {
        var group = field.closest('.hfield-group');
        var label = group ? group.querySelector('.hfield-label') : null;
        var fieldName = label ? label.textContent.trim() : (field.name || 'Field');
        if (field.validity.valueMissing) return fieldName + ' is required.';
        if (field.validity.patternMismatch) {
            if (field.name === 'dhcp_lease_time') {
                return fieldName + ' must use a value such as 30m, 12h or 7d.';
            }
            return 'Check the format entered for ' + fieldName + '.';
        }
        if (field.validity.tooShort) {
            return fieldName + ' must contain at least ' + field.minLength + ' characters.';
        }
        if (field.validity.tooLong) {
            return fieldName + ' must contain no more than ' + field.maxLength + ' characters.';
        }
        return 'Check the value entered for ' + fieldName + '.';
    }

    function captureSettings() {
        return {
            subnet: subnet.value.trim(),
            gateway: gateway.value.trim(),
            rangeStart: rangeStart.value.trim(),
            rangeEnd: rangeEnd.value.trim(),
            leaseTime: leaseTime.value.trim()
        };
    }

    function savedSettingsMessage(before, after) {
        var changes = [];
        var addChange = function (label, oldValue, newValue) {
            if (String(oldValue) !== String(newValue)) {
                changes.push(label + ': ' + oldValue + ' \u2192 ' + newValue);
            }
        };
        addChange('Hotspot subnet', before.subnet, after.subnet);
        addChange('Gateway', before.gateway, after.gateway);
        addChange('Range start', before.rangeStart, after.rangeStart);
        addChange('Range end', before.rangeEnd, after.rangeEnd);
        addChange('Lease time', before.leaseTime, after.leaseTime);
        return {
            title: changes.length === 0 ? 'Configuration saved'
                : (changes.length === 1 ? 'Setting saved' : changes.length + ' settings saved'),
            text: changes.length ? changes.join(' \u2022 ') : 'Configuration saved without changes.',
            changes: changes.length ? changes : ['No setting values changed']
        };
    }

    function selectedPresetAddresses() {
        if (!preset) return '';
        var option = preset.options[preset.selectedIndex];
        return option && option.dataset.addresses ? option.dataset.addresses : '';
    }
    function syncDnsPolicy(applyPreset) {
        if (encryptedMode) {
            advertised.value = gateway.value.trim();
            upstream.value = '127.0.2.1';
            return;
        }
        var local = policy.value === 'local';
        var addresses = selectedPresetAddresses();
        var custom = preset.value === 'custom';
        advertised.readOnly = local || !custom;
        upstream.readOnly = !custom;
        if (addresses && applyPreset) upstream.value = addresses;
        if (local) {
            advertised.value = form.elements.dhcp_gateway.value.trim();
        } else if (addresses) {
            advertised.value = addresses;
        } else if (applyPreset && advertised.value.trim() === form.elements.dhcp_gateway.value.trim()) {
            advertised.value = upstream.value.trim();
        }
    }
    function syncNetwork() {
        var parts = network.value.trim().split('.');
        var startHost = rangeStart.value.trim().split('.').pop() || '50';
        var endHost = rangeEnd.value.trim().split('.').pop() || '200';
        subnet.value = network.value.trim() + '/24';
        if (parts.length === 4 && parts.every(function (part) { return /^\d{1,3}$/.test(part) && Number(part) <= 255; })) {
            var prefix = parts.slice(0, 3).join('.');
            parts[3] = '1';
            gateway.value = parts.join('.');
            rangeStart.value = prefix + '.' + startHost;
            rangeEnd.value = prefix + '.' + endHost;
            if (policy.value === 'local') advertised.value = gateway.value;
        } else {
            gateway.value = '';
        }
    }
    if (preset) preset.addEventListener('change', function () { syncDnsPolicy(true); });
    policy.addEventListener('change', function () { syncDnsPolicy(true); });
    network.addEventListener('input', syncNetwork);
    syncNetwork();
    syncDnsPolicy(false);
    savedSettings = captureSettings();

    function waitForApply(deadline) {
        return fetch('/ajax/networking/get_dhcp_apply_status.php?t=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
            .then(function (result) {
                if (result.status === 'success') return;
                if (result.status === 'failed') throw new Error('DHCP configuration failed and the previous settings were restored.');
                if (Date.now() >= deadline) throw new Error('Timed out while applying DHCP settings.');
                return new Promise(function (resolve) { setTimeout(resolve, 800); }).then(function () { return waitForApply(deadline); });
            })
            .catch(function (error) {
                if (Date.now() >= deadline || /failed|Timed out/.test(error.message)) throw error;
                return new Promise(function (resolve) { setTimeout(resolve, 800); }).then(function () { return waitForApply(deadline); });
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            var invalidField = Array.prototype.find.call(form.elements, function (field) {
                return field.matches && field.matches(':invalid');
            });
            showToast(
                'danger',
                invalidField ? invalidFieldMessage(invalidField) : 'Complete the required fields before saving.',
                'Check required fields'
            );
            if (invalidField) invalidField.focus({ preventScroll: false });
            return;
        }
        var button = event.submitter;
        var original = button.innerHTML;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> <span>Saving...</span>';
        var data = new FormData(form);
        data.set('SaveDhcpSettings', '1');
        var submittedSettings = captureSettings();
        var saveSummary = savedSettingsMessage(savedSettings || submittedSettings, submittedSettings);
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 12000);
        beginApplyModal('dhcp');
        fetch('/dhcp_setting', { method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: data, signal: controller.signal })
            .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.text(); })
            .then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var error = parsed.querySelector('.alert-danger');
                if (error) throw new Error(error.textContent.trim());
                if (!parsed.querySelector('.alert-success')) throw new Error('OpenAP did not confirm the save operation.');
                setApplyStep(document.getElementById('dhcpApplyStepPrepare'), 'done');
                setApplyStep(document.getElementById('dhcpApplyStepApply'), 'active');
                return waitForApply(Date.now() + 45000);
            }).then(function () {
                setApplyStep(document.getElementById('dhcpApplyStepApply'), 'done');
                setApplyStep(document.getElementById('dhcpApplyStepVerify'), 'active');
                return new Promise(function (resolve) { setTimeout(resolve, 450); });
            }).then(function () {
                setApplyStep(document.getElementById('dhcpApplyStepVerify'), 'done');
                savedSettings = submittedSettings;
                closeApplyModal(function () {
                    if (typeof window.openapRefreshDhcpSettingWidget === 'function') {
                        window.openapRefreshDhcpSettingWidget();
                    } else {
                        animateDhcpWidget(data);
                    }
                    refreshDnsSections();
                    showToast('success', saveSummary.text, saveSummary.title, saveSummary.changes);
                });
            })
            .catch(function (error) {
                var activeStep = document.querySelector('#dhcpApplyModalContent .openap-mode-switch-steps .active');
                setApplyStep(activeStep, 'error');
                if (applyModalContent) applyModalContent.classList.add('is-apply-error');
                document.getElementById('dhcpApplyProgressCaption').textContent = error.message;
                closeApplyModal(function () {
                    showToast('danger', error.message, 'DHCP and DNS settings could not be applied');
                });
            })
            .finally(function () { clearTimeout(timeout); button.disabled = false; button.removeAttribute('aria-busy'); button.innerHTML = original; });
    });

    var dnsSettingsForm = document.getElementById('dnsSettingsForm');
    var dnsSettingsButton = document.getElementById('applyDnsSettings');
    if (dnsSettingsForm && dnsSettingsButton) {
        dnsSettingsForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!dnsSettingsForm.checkValidity()) {
                dnsSettingsForm.classList.add('was-validated');
                showToast('danger', 'Check the DNS settings before saving.', 'Check required fields');
                return;
            }
            var original = dnsSettingsButton.innerHTML;
            dnsSettingsButton.setAttribute('aria-busy', 'true');
            dnsSettingsButton.disabled = true;
            dnsSettingsButton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> <span>Saving...</span>';
            var data = new FormData(dnsSettingsForm);
            data.set('SaveDnsSettings', '1');
            var previousEnabled = dnsSettingsForm.dataset.savedEnabled === '1';
            var previousProvider = dnsSettingsForm.dataset.savedProvider || 'cloudflare';
            var encryptedDnsToggle = document.getElementById('encryptedDnsEnabled');
            var nextEnabled = encryptedDnsToggle ? encryptedDnsToggle.checked : false;
            var nextProvider = String(data.get('dns_provider') || 'cloudflare');
            var pendingDnsSections = null;
            var pendingDnsSnapshotHtml = '';
            var dnsChanges = [];
            var providerLabel = function (value) { return value === 'quad9' ? 'Quad9 Security' : 'Cloudflare'; };
            if (previousEnabled !== nextEnabled) dnsChanges.push('Encrypted DNS: ' + (previousEnabled ? 'Enabled' : 'Disabled') + ' \u2192 ' + (nextEnabled ? 'Enabled' : 'Disabled'));
            if (previousProvider !== nextProvider) dnsChanges.push('DNS provider: ' + providerLabel(previousProvider) + ' \u2192 ' + providerLabel(nextProvider));
            if (nextEnabled) dnsChanges.push('Active DNS provider: ' + providerLabel(nextProvider));
            if (!dnsChanges.length) dnsChanges.push('No setting values changed');
            var controller = new AbortController();
            var timeout = setTimeout(function () { controller.abort(); }, 90000);
            beginApplyModal('dns');
            fetch('/dhcp_setting', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: data,
                signal: controller.signal
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            }).then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var result = parsed.getElementById('encryptedDnsOperationResult');
                if (!result) throw new Error('OpenAP did not return the Encrypted DNS operation result.');
                var success = result.dataset.level === 'success';
                var message = result.dataset.message || 'Encrypted DNS operation completed.';
                if (!success) throw new Error(message);
                pendingDnsSections = parsed.querySelector('.openap-dns-column-sections');
                pendingDnsSnapshotHtml = html;
                setApplyStep(document.getElementById('dhcpApplyStepPrepare'), 'done');
                setApplyStep(document.getElementById('dhcpApplyStepApply'), 'active');
                return new Promise(function (resolve) { setTimeout(resolve, 350); });
            }).then(function () {
                setApplyStep(document.getElementById('dhcpApplyStepApply'), 'done');
                setApplyStep(document.getElementById('dhcpApplyStepVerify'), 'active');
                return new Promise(function (resolve) { setTimeout(resolve, 450); });
            }).then(function () {
                setApplyStep(document.getElementById('dhcpApplyStepVerify'), 'done');
                dnsSettingsForm.dataset.savedEnabled = nextEnabled ? '1' : '0';
                dnsSettingsForm.dataset.savedProvider = nextProvider;
                closeApplyModal(function () {
                    window.requestAnimationFrame(function () {
                        window.dispatchEvent(new CustomEvent('openap:encrypted-dns-confirmed', {
                            detail: { snapshotHtml: pendingDnsSnapshotHtml }
                        }));
                        transitionDnsSections(pendingDnsSections).then(function () {
                            showToast('success', 'DNS operation completed.', 'DNS settings saved', dnsChanges);
                        });
                    });
                });
            }).catch(function (error) {
                var message = error.name === 'AbortError' ? 'Timed out while applying Encrypted DNS settings.' : (error.message || 'Unable to save Encrypted DNS settings.');
                var activeStep = document.querySelector('#dhcpApplyModalContent .openap-mode-switch-steps .active');
                setApplyStep(activeStep, 'error');
                if (applyModalContent) applyModalContent.classList.add('is-apply-error');
                document.getElementById('dhcpApplyProgressCaption').textContent = message;
                closeApplyModal(function () {
                    showToast('danger', message, 'Encrypted DNS could not be applied');
                });
            }).finally(function () {
                clearTimeout(timeout);
                dnsSettingsButton.disabled = false;
                dnsSettingsButton.removeAttribute('aria-busy');
                dnsSettingsButton.innerHTML = original;
            });
        });
    }

    var encryptedDnsResult = document.getElementById('encryptedDnsOperationResult');
    if (encryptedDnsResult) {
        showToast(
            encryptedDnsResult.dataset.level === 'success' ? 'success' : 'danger',
            encryptedDnsResult.dataset.message || 'Encrypted DNS operation completed.'
        );
    }
}());
