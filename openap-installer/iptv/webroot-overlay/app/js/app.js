import {
    setCSRFTokenHeader,
    getCookie,
    setCookie,
    disableValidation,
    setDarkMode
} from "./helpers.js";

import { initHostapd } from "./ui/hostapd.js";
import { initSystem } from "./ui/system.js";
import { initLogin } from "./ui/login.js?v=20261013-auth-transitions-1";
import { initClientWatchdog } from "./client_watchdog.js?v=20260917-client-watchdog-resize-4";

// ajax handlers
import { initHostapd_ajax } from "./ajax/hostapd.js?v=20260720-openap-optimal-width-2";
import { initSession_ajax } from "./ajax/session.js?v=20260905-session-recovery-2";
import { initSystem_ajax} from "./ajax/system.js";

document.addEventListener('DOMContentLoaded', () => {
    const authTransitionKey = 'openap|auth-transition';
    const authTransition = (() => {
        try {
            const value = window.sessionStorage.getItem(authTransitionKey);
            window.sessionStorage.removeItem(authTransitionKey);
            return value;
        } catch (error) {
            return '';
        }
    })();
    const startAuthEntry = (className, duration, waitForFonts) => {
        const start = () => {
            window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                document.body.classList.add(className);
                document.documentElement.classList.remove(
                    className === 'openap-auth-login-entry'
                        ? 'openap-auth-login-preload'
                        : 'openap-auth-logout-preload'
                );
                window.setTimeout(() => document.body.classList.remove(className), duration);
            }));
        };
        if (waitForFonts && document.fonts?.ready) {
            Promise.race([
                document.fonts.ready,
                new Promise((resolve) => window.setTimeout(resolve, 180))
            ]).then(start);
        } else {
            start();
        }
    };
    if (authTransition === 'login' && !document.body.classList.contains('openap-login-page')) {
        startAuthEntry('openap-auth-login-entry', 760, true);
    } else if (authTransition === 'logout' && document.body.classList.contains('openap-login-page')) {
        startAuthEntry('openap-auth-logout-entry', 760, false);
    }
    initClientWatchdog();
    initSession_ajax();
    console.info("OpenAP app.js initialized");
});

window.openapNotify = (options = {}) => {
        const level = options.level === 'error' ? 'error' : 'success';
        const duration = Number.isFinite(Number(options.duration)) ? Math.max(0, Number(options.duration)) : (level === 'success' ? 5000 : 0);
        let details = Array.isArray(options.details) ? options.details.filter(Boolean) : [];
        const taskProgress = options.taskProgress === true;
        const sequenceMode = options.sequenceMode === true;
        let sequenceItems = Array.isArray(options.sequence) ? options.sequence.filter(Boolean) : details.slice();
        let sequenceIndex = Number.isInteger(Number(options.sequenceIndex))
            ? Math.max(0, Number(options.sequenceIndex))
            : Math.max(0, sequenceItems.length - 1);
        document.getElementById('openapUniversalNotification')?.remove();
        const notification = document.createElement('aside');
        notification.id = 'openapUniversalNotification';
        notification.className = `openap-universal-notification is-${level}${taskProgress ? ' is-task-progress' : ''}${sequenceMode ? ' is-sequence' : ''}`;
        notification.setAttribute('role', level === 'error' ? 'alert' : 'status');
        notification.setAttribute('aria-live', level === 'error' ? 'assertive' : 'polite');
        notification.innerHTML =
            '<div class="openap-universal-notification-icon"><i class="fas"></i></div>' +
            '<div class="openap-universal-notification-main">' +
              '<div class="openap-universal-notification-heading"><strong></strong><button type="button" class="openap-universal-notification-close" aria-label="Close notification"><i class="fas fa-times"></i></button></div>' +
              '<p></p>' +
              '<div class="openap-universal-notification-actions"></div>' +
              '<div class="openap-universal-notification-details" hidden><ul></ul></div>' +
            '</div>' +
            (duration ? '<div class="openap-universal-notification-progress" aria-hidden="true"></div>' : '') +
            (taskProgress ? '<div class="openap-universal-notification-task-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>' : '');
        notification.querySelector('.openap-universal-notification-icon i').className =
            `fas ${options.icon || (level === 'success' ? 'fa-check' : 'fa-exclamation')}`;
        const progress = notification.querySelector('.openap-universal-notification-progress');
        if (progress) progress.style.animationDuration = `${duration}ms`;
        notification.querySelector('strong').textContent = options.title || (level === 'success' ? 'Operation successful' : 'Operation failed');
        const messageNode = notification.querySelector('p');
        let sequenceTrack = null;
        if (sequenceMode) {
            messageNode.classList.add('is-sequence-message');
            sequenceTrack = document.createElement('span');
            sequenceTrack.className = 'openap-universal-notification-sequence-track';
            sequenceTrack.setAttribute('aria-live', 'polite');
            messageNode.replaceChildren(sequenceTrack);
        } else {
            messageNode.textContent = options.message || '';
        }
        const actions = notification.querySelector('.openap-universal-notification-actions');
        const detailPanel = notification.querySelector('.openap-universal-notification-details');
        const taskProgressBar = notification.querySelector('.openap-universal-notification-task-progress');
        const taskProgressFill = taskProgressBar?.querySelector('span');
        if (taskProgress && taskProgressBar && actions) {
            notification.querySelector('.openap-universal-notification-main')?.insertBefore(taskProgressBar, actions);
        }
        if ((taskProgress || sequenceMode) && detailPanel) notification.appendChild(detailPanel);
        let detailsAutoScroll = true;
        detailPanel?.addEventListener('scroll', () => {
            detailsAutoScroll = detailPanel.scrollHeight - detailPanel.scrollTop - detailPanel.clientHeight < 12;
        });
        let showMore = null;
        const renderSequence = function (nextItems, nextSequenceIndex) {
            if (!sequenceTrack) return;
            sequenceItems = Array.isArray(nextItems) ? nextItems.filter(Boolean) : [];
            sequenceIndex = Number.isInteger(Number(nextSequenceIndex))
                ? Math.max(0, Math.min(Math.max(0, sequenceItems.length - 1), Number(nextSequenceIndex)))
                : Math.max(0, sequenceItems.length - 1);
            sequenceTrack.replaceChildren(...sequenceItems.map((item) => {
                const slide = document.createElement('span');
                slide.className = 'openap-universal-notification-sequence-slide';
                slide.textContent = item;
                return slide;
            }));
            sequenceTrack.style.transform = `translate3d(-${sequenceIndex * 100}%, 0, 0)`;
            messageNode.setAttribute('aria-label', sequenceItems[sequenceIndex] || options.message || '');
        };
        const renderDetails = function (nextDetails, nextSequenceIndex) {
            details = Array.isArray(nextDetails) ? nextDetails.filter(Boolean) : [];
            const list = detailPanel?.querySelector('ul');
            if (list) {
                list.replaceChildren(...details.map((detail) => {
                    const item = document.createElement('li');
                    item.textContent = detail;
                    return item;
                }));
            }
            if (detailsAutoScroll && detailPanel) detailPanel.scrollTop = detailPanel.scrollHeight;
            if (showMore) showMore.hidden = sequenceMode ? false : (!details.length && !taskProgress);
        };
        const createDetailsToggle = function (label) {
            showMore = document.createElement('button');
            showMore.type = 'button';
            showMore.className = 'openap-universal-notification-action';
            showMore.innerHTML = `<i class="fas fa-chevron-down"></i><span>${label}</span>`;
            showMore.addEventListener('click', () => {
                const previousHeight = notification.getBoundingClientRect().height;
                const expanded = detailPanel.hidden;
                detailPanel.hidden = !expanded;
                notification.classList.toggle('is-expanded', expanded);
                if (expanded) {
                    detailsAutoScroll = true;
                    detailPanel.scrollTop = detailPanel.scrollHeight;
                }
                showMore.querySelector('span').textContent = expanded ? 'Less' : label;
                showMore.querySelector('i').className = `fas ${expanded ? 'fa-chevron-up' : 'fa-chevron-down'}`;
                const nextHeight = notification.getBoundingClientRect().height;
                notification.animate([
                    { height: `${previousHeight}px` },
                    { height: `${nextHeight}px` }
                ], { duration: 300, easing: 'cubic-bezier(.22,1,.36,1)' });
            });
            actions.appendChild(showMore);
            return showMore;
        };
        if (sequenceMode) {
            renderSequence(sequenceItems, sequenceIndex);
            renderDetails(sequenceItems);
            createDetailsToggle('More');
        } else if (details.length || taskProgress) {
            renderDetails(details);
            createDetailsToggle('Show more');
            if (options.expanded) {
                detailPanel.hidden = false;
                notification.classList.add('is-expanded');
                showMore.querySelector('span').textContent = 'Show less';
                showMore.querySelector('i').className = 'fas fa-chevron-up';
                detailsAutoScroll = true;
                detailPanel.scrollTop = detailPanel.scrollHeight;
            }
        }
        const update = (next = {}) => {
            if (next.title !== undefined) notification.querySelector('strong').textContent = next.title || '';
            if (next.message !== undefined && !sequenceMode) messageNode.textContent = next.message || '';
            if (next.icon !== undefined) notification.querySelector('.openap-universal-notification-icon i').className = `fas ${next.icon}`;
            if (next.level !== undefined) {
                const nextLevel = next.level === 'error' ? 'error' : 'success';
                notification.classList.toggle('is-error', nextLevel === 'error');
                notification.classList.toggle('is-success', nextLevel === 'success');
                notification.setAttribute('role', nextLevel === 'error' ? 'alert' : 'status');
                notification.setAttribute('aria-live', nextLevel === 'error' ? 'assertive' : 'polite');
                if (nextLevel === 'error') addCopyAction();
            }
            if (sequenceMode && Array.isArray(next.sequence)) {
                renderSequence(next.sequence, next.sequenceIndex);
                renderDetails(Array.isArray(next.details) ? next.details : next.sequence);
            } else if (Array.isArray(next.details)) {
                renderDetails(next.details);
            }
            if (taskProgressBar && Number.isFinite(Number(next.progress))) {
                const value = Math.max(0, Math.min(100, Number(next.progress)));
                taskProgressBar.setAttribute('aria-valuenow', String(value));
                if (taskProgressFill) taskProgressFill.style.width = `${value}%`;
            }
            return api;
        };
        let copyAction = null;
        const addCopyAction = () => {
            if (copyAction) return;
            const copy = document.createElement('button');
            copy.type = 'button';
            copy.className = 'openap-universal-notification-action';
            copy.innerHTML = '<i class="far fa-copy"></i><span>Copy text</span>';
            copy.addEventListener('click', () => {
                const copyText = [notification.querySelector('strong').textContent, sequenceMode ? sequenceItems.join('\n') : messageNode.textContent, ...details].filter(Boolean).join('\n');
                const copyPromise = navigator.clipboard && window.isSecureContext
                    ? navigator.clipboard.writeText(copyText)
                    : new Promise((resolve, reject) => {
                        const handler = (event) => {
                            event.clipboardData.setData('text/plain', copyText);
                            event.preventDefault();
                        };
                        document.addEventListener('copy', handler, { once: true });
                        document.execCommand('copy') ? resolve() : reject(new Error('Copy failed'));
                });
                copyPromise.then(() => { copy.querySelector('span').textContent = 'Copied'; }).catch(() => { copy.querySelector('span').textContent = 'Copy failed'; });
            });
            copyAction = copy;
            actions.appendChild(copy);
        };
        if (level === 'error') addCopyAction();
        if (Array.isArray(options.actions)) {
            options.actions.filter((action) => action && action.label).forEach((action) => {
                const actionButton = document.createElement('button');
                actionButton.type = 'button';
                actionButton.className = 'openap-universal-notification-action';
                const actionIcon = document.createElement('i');
                actionIcon.className = `fas ${action.icon || 'fa-check'}`;
                const actionLabel = document.createElement('span');
                actionLabel.textContent = action.label;
                actionButton.append(actionIcon, actionLabel);
                actionButton.addEventListener('click', () => {
                    try { action.onClick?.(); } finally { close(); }
                });
                actions.appendChild(actionButton);
            });
        }
        let remaining = duration;
        let startedAt = 0;
        let timer = null;
        let closed = false;
        const close = () => {
            if (closed) return;
            closed = true;
            window.clearTimeout(timer);
            notification.classList.add('leaving');
            notification.classList.remove('show');
            window.setTimeout(() => {
                notification.remove();
                if (typeof options.onClose === 'function') options.onClose();
            }, 420);
        };
        const pause = () => {
            if (!timer) return;
            window.clearTimeout(timer);
            timer = null;
            remaining = Math.max(0, remaining - (Date.now() - startedAt));
            notification.classList.add('is-paused');
        };
        const resume = () => {
            if (!duration || timer || notification.classList.contains('is-expanded') || notification.contains(document.activeElement)) return;
            notification.classList.remove('is-paused');
            startedAt = Date.now();
            timer = window.setTimeout(close, remaining);
        };
        notification.querySelector('.openap-universal-notification-close').addEventListener('click', close);
        notification.addEventListener('mouseenter', pause);
        notification.addEventListener('mouseleave', resume);
        notification.addEventListener('focusin', pause);
        notification.addEventListener('focusout', () => window.setTimeout(resume, 0));
        notification.querySelector('.openap-universal-notification-actions').addEventListener('click', () => {
            if (notification.classList.contains('is-expanded')) pause(); else resume();
        });
        document.body.appendChild(notification);
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => notification.classList.add('show'));
        });
        resume();
        const api = { close, update, element: notification };
        update({progress: options.progress});
        return api;
    };

document.addEventListener('DOMContentLoaded', () => {
    // Monitor Wi-Fi hardware from every authenticated OpenAP page. Page-level
    // controls consume the same endpoint, while this global listener owns the
    // notification so users receive it regardless of their current section.
    const initGlobalWifiHardwareMonitor = () => {
        if (!document.querySelector('.sb-topnav')) return;
        let previousCount = null;
        let polling = false;

        let previousEthernetLink = null;
        let ethernetCandidate = null;
        let ethernetCandidateSamples = 0;

        const showEthernetNotification = (connected) => {
            window.openapNotify({
                level: connected ? 'success' : 'error',
                title: connected ? 'Ethernet connected' : 'Ethernet disconnected',
                message: connected
                    ? 'The wired network connection is active.'
                    : 'The network cable has been disconnected.',
                icon: connected ? 'fa-network-wired' : 'fa-plug',
                duration: 6000
            });
        };

        const observeEthernetLink = (connected) => {
            if (typeof connected !== 'boolean') {
                ethernetCandidate = null;
                ethernetCandidateSamples = 0;
                return;
            }
            if (previousEthernetLink === null) {
                if (ethernetCandidate !== connected) {
                    ethernetCandidate = connected;
                    ethernetCandidateSamples = 1;
                } else {
                    ethernetCandidateSamples += 1;
                }
                if (ethernetCandidateSamples >= 2) {
                    previousEthernetLink = connected;
                    ethernetCandidate = null;
                    ethernetCandidateSamples = 0;
                }
                return;
            }
            if (connected === previousEthernetLink) {
                ethernetCandidate = null;
                ethernetCandidateSamples = 0;
                return;
            }
            if (ethernetCandidate !== connected) {
                ethernetCandidate = connected;
                ethernetCandidateSamples = 1;
                return;
            }
            ethernetCandidateSamples += 1;
            if (ethernetCandidateSamples < 2) return;
            previousEthernetLink = connected;
            ethernetCandidate = null;
            ethernetCandidateSamples = 0;
            showEthernetNotification(connected);
        };

        const showHardwareToast = (connected) => {
            document.getElementById('openapHardwareToast')?.remove();
            const toast = document.createElement('div');
            toast.id = 'openapHardwareToast';
            toast.className = `openap-hardware-toast ${connected ? 'is-connected' : 'is-disconnected'}`;
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.innerHTML = '<div class="openap-hardware-toast-icon"><i class="fas '
                + (connected ? 'fa-wifi' : 'fa-plug') + '"></i></div>'
                + '<div class="openap-hardware-toast-copy"><strong></strong><span></span></div>'
                + '<div class="openap-hardware-toast-progress" aria-hidden="true"></div>';
            toast.querySelector('strong').textContent = connected ? 'Wi-Fi interface detected' : 'Wi-Fi interface disconnected';
            toast.querySelector('span').textContent = connected
                ? 'A new wireless interface is now available.'
                : 'A wireless interface is no longer available.';
            document.body.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('show'));
            window.setTimeout(() => {
                toast.classList.add('leaving');
                toast.classList.remove('show');
                window.setTimeout(() => toast.remove(), 420);
            }, 5000);
        };

        const poll = () => {
            if (window.openapSession.expired || polling || document.hidden) return;
            polling = true;
            fetch(`/ajax/networking/get_interface_roles.php?t=${Date.now()}`, {
                credentials: 'same-origin', cache: 'no-store'
            }).then((response) => {
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            }).then((result) => {
                const count = Number(result.count) || 0;
                if (previousCount !== null && count !== previousCount) showHardwareToast(count > previousCount);
                previousCount = count;
                observeEthernetLink(result.ethernet_connected);
            }).catch(() => {
                // Authentication transitions and temporary detector failures
                // must not create false hardware notifications.
            }).finally(() => { polling = false; });
        };

        poll();
        window.openapSession.setInterval(poll, 1500);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    };

    initGlobalWifiHardwareMonitor();

    // Treat the mobile bottom navigation as primary app tabs. Besides
    // replacing entries, remember the latest tab so mobile back/forward cache
    // restores cannot reveal an older primary tab.
    const mobilePrimaryRoutes = new Set(['/', '/dashboard', '/ap_configuration', '/dhcp_setting', '/logging', '/system_info']);
    const mobilePrimaryRouteKey = 'openap|mobile-primary-route';
    const mobileNavIndexKey = 'openap|mobile-nav-previous-index';
    const compactLandscapeMedia = '(max-width: 1138px) and (max-height: 712px), (max-width: 1180px) and (max-height: 820px), (max-width: 1280px) and (max-height: 800px)';
    const sidebarOnlyLandscapeMedia = '(max-width: 1210px) and (max-height: 834px)';
    const isMobilePrimaryNavigation = () => window.self === window.top && window.matchMedia('(max-width: 767.98px), (max-width: 712px) and (max-height: 1138px), (max-width: 800px) and (max-height: 1280px), (max-width: 820px) and (max-height: 1180px), (max-width: 834px) and (max-height: 1210px), (max-width: 846px) and (max-height: 414px), (max-width: 915px) and (max-height: 412px), ' + compactLandscapeMedia).matches;
    const enforceLatestMobilePrimaryRoute = () => {
        if (!isMobilePrimaryNavigation() || !mobilePrimaryRoutes.has(window.location.pathname)) {
            return;
        }
        try {
            const latestRoute = sessionStorage.getItem(mobilePrimaryRouteKey);
            if (latestRoute && latestRoute !== window.location.pathname && mobilePrimaryRoutes.has(latestRoute)) {
                window.location.replace(latestRoute);
            }
        } catch (error) {
            // History replacement still works when session storage is blocked.
        }
    };

    if (isMobilePrimaryNavigation() && mobilePrimaryRoutes.has(window.location.pathname)) {
        try {
            const navigation = performance.getEntriesByType('navigation')[0];
            const restoredByHistory = navigation && navigation.type === 'back_forward';
            const latestRoute = sessionStorage.getItem(mobilePrimaryRouteKey);
            if (restoredByHistory && latestRoute && latestRoute !== window.location.pathname && mobilePrimaryRoutes.has(latestRoute)) {
                window.location.replace(latestRoute);
                return;
            }
            sessionStorage.setItem(mobilePrimaryRouteKey, window.location.pathname);
        } catch (error) {
            // Keep navigation usable in private or hardened browser contexts.
        }
    }

    window.addEventListener('pageshow', enforceLatestMobilePrimaryRoute);
    window.addEventListener('popstate', enforceLatestMobilePrimaryRoute);

    const mobileBottomNav = document.querySelector('.openap-mobile-bottom-nav[data-active-index]');
    if (mobileBottomNav && isMobilePrimaryNavigation()) {
        const activeIndex = Number(mobileBottomNav.dataset.activeIndex);
        try {
            const previousIndex = Number(sessionStorage.getItem(mobileNavIndexKey));
            if (Number.isInteger(previousIndex) && previousIndex >= 0 && previousIndex <= 4 && previousIndex !== activeIndex) {
                mobileBottomNav.classList.add('is-sliding');
                mobileBottomNav.style.setProperty('--openap-mobile-active', previousIndex);
                void mobileBottomNav.offsetWidth;
                requestAnimationFrame(() => {
                    mobileBottomNav.style.setProperty('--openap-mobile-active', activeIndex);
                });
            }
            sessionStorage.setItem(mobileNavIndexKey, String(activeIndex));
        } catch (error) {
            mobileBottomNav.style.setProperty('--openap-mobile-active', activeIndex);
        }
    }

    let mobilePageNavigationPending = false;
    const navigateMobileApPage = (link, event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin || destination.href === window.location.href || destination.hash || link.hasAttribute('download')) {
            return;
        }
        if (mobilePageNavigationPending) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        mobilePageNavigationPending = true;
        const expandedPanel = link.closest('.openap-mobile-more-panel');
        if (expandedPanel) {
            expandedPanel.hidden = true;
            expandedPanel.closest('.openap-global-footer')?.classList.remove('is-ap-expanded');
        }

        const currentNav = link.closest('.openap-mobile-bottom-nav');
        const destinationIndex = Number(link.dataset.mobileIndex);
        const canAnimateMobileTab = Boolean(
            currentNav &&
            isMobilePrimaryNavigation() &&
            Number.isInteger(destinationIndex) &&
            destinationIndex >= 0 &&
            destinationIndex <= 4
        );
        const currentIndex = Number(currentNav?.dataset.activeIndex ?? destinationIndex);
        const pageDirection = destinationIndex >= currentIndex ? 'forward' : 'backward';
        const mobilePageContent = document.querySelector('#layoutSidenav_content main > .container-fluid');
        const canAnimatePage = canAnimateMobileTab &&
            !window.matchMedia('(prefers-reduced-motion: reduce)').matches &&
            Boolean(mobilePageContent);

        try {
            if (mobilePrimaryRoutes.has(destination.pathname)) {
                sessionStorage.setItem(mobilePrimaryRouteKey, destination.pathname);
            }
            if (currentNav) {
                sessionStorage.setItem(
                    mobileNavIndexKey,
                    canAnimateMobileTab ? String(destinationIndex) : (currentNav.dataset.activeIndex || '0')
                );
            }
            if (canAnimatePage) {
                sessionStorage.setItem('openap|sidebar-page-transition', pageDirection);
            } else {
                sessionStorage.removeItem('openap|sidebar-page-transition');
            }
        } catch (error) {
            // Continue with replacement navigation when storage is blocked.
        }

        const replacePage = () => window.location.replace(destination.href);
        if (canAnimateMobileTab) {
            currentNav.classList.add('is-sliding');
            currentNav.style.setProperty('--openap-mobile-active', String(destinationIndex));
            if (canAnimatePage) {
                mobilePageContent.classList.add('openap-page-exit', 'openap-page-exit-' + pageDirection);
            }
            window.setTimeout(replacePage, canAnimatePage ? 340 : 0);
        } else {
            replacePage();
        }
    };

    document.querySelectorAll('.openap-mobile-bottom-link[href], .openap-mobile-more-panel a[href]').forEach((link) => {
        link.addEventListener('click', (event) => navigateMobileApPage(link, event));
    });

    const mobileMoreToggle = document.querySelector('.openap-mobile-more-toggle');
    const mobileMorePanel = document.querySelector('.openap-mobile-more-panel');
    if (mobileMoreToggle && mobileMorePanel) {
        const mobileFooter = mobileMoreToggle.closest('.openap-global-footer');
        const closeMobileMore = () => {
            mobileMorePanel.hidden = true;
            mobileFooter?.classList.remove('is-ap-expanded');
            mobileMoreToggle.setAttribute('aria-expanded', 'false');
            mobileMoreToggle.querySelector('i')?.classList.replace('fa-chevron-down', 'fa-chevron-up');
        };
        mobileMoreToggle.addEventListener('click', () => {
            const opening = mobileMorePanel.hidden;
            mobileMorePanel.hidden = !opening;
            mobileFooter?.classList.toggle('is-ap-expanded', opening);
            mobileMoreToggle.setAttribute('aria-expanded', String(opening));
            const icon = mobileMoreToggle.querySelector('i');
            if (icon) icon.className = opening ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
        });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMobileMore(); });
        document.addEventListener('click', (event) => {
            if (!mobileMorePanel.hidden && !mobileMorePanel.contains(event.target) && !mobileMoreToggle.contains(event.target)) closeMobileMore();
        });
    }

    // APtv renders its page script before the global footer. Bridge footer
    // actions to the already initialized controls and mirror their state.
    const iptvFooterNav = document.querySelector('.openap-footer-iptv-nav');
    if (iptvFooterNav) {
        const footerActionMap = new Map([
            ['[data-openap-iptv-live-toggle]', '[data-openap-iptv-live-toggle]'],
            ['[data-openap-epg-toggle]', '[data-openap-epg-toggle]'],
            ['[data-openap-iptv-movies-toggle]', '[data-openap-iptv-movies-toggle]'],
            ['[data-openap-iptv-series-toggle]', '[data-openap-iptv-series-toggle]'],
            ['[data-openap-iptv-settings-toggle]', '[data-openap-iptv-settings-toggle]']
        ]);
        const bindings = [];
        footerActionMap.forEach((sourceSelector, footerSelector) => {
            const footerButton = iptvFooterNav.querySelector(footerSelector);
            const sourceButton = Array.from(document.querySelectorAll(sourceSelector)).find((button) => !iptvFooterNav.contains(button));
            if (!footerButton || !sourceButton) return;
            const sync = () => {
                const expanded = sourceButton.getAttribute('aria-expanded') === 'true';
                footerButton.classList.toggle('is-active', expanded);
                footerButton.setAttribute('aria-expanded', String(expanded));
            };
            footerButton.addEventListener('click', () => sourceButton.click());
            new MutationObserver(sync).observe(sourceButton, {attributes: true, attributeFilter: ['class', 'aria-expanded']});
            sync();
            bindings.push([footerButton, sourceButton]);
        });
    }

    // Continue the desktop sidebar transition after the server-rendered page
    // load. Mobile primary navigation intentionally changes page immediately.
    const pageTransitionKey = 'openap|sidebar-page-transition';
    const pageContent = document.querySelector('#layoutSidenav_content main > .container-fluid');
    try {
        const pageEntryDirection = sessionStorage.getItem(pageTransitionKey);
        sessionStorage.removeItem(pageTransitionKey);
        const validPageEntryDirections = ['forward', 'backward'];
        if (pageContent && validPageEntryDirections.includes(pageEntryDirection)) {
            pageContent.classList.add('openap-page-enter');
            pageContent.classList.add(`openap-page-enter-${pageEntryDirection}`);
            document.documentElement.classList.remove(`openap-page-preload-${pageEntryDirection}`);
            window.setTimeout(() => {
                pageContent.classList.remove('openap-page-enter');
                pageContent.classList.remove(`openap-page-enter-${pageEntryDirection}`);
            }, 620);
        } else if (validPageEntryDirections.includes(pageEntryDirection)) {
            document.documentElement.classList.remove(`openap-page-preload-${pageEntryDirection}`);
        }
    } catch (error) {
        // Storage can be unavailable in hardened/private browser contexts.
    }

    const sidebarNav = document.querySelector('.sb-sidenav-menu .nav');
    const sidebarIndicator = sidebarNav?.querySelector('.openap-sidebar-indicator');
    const sidebarItems = sidebarNav ? Array.from(sidebarNav.querySelectorAll(':scope > .sb-nav-link-icon[data-openap-sidebar-index]')) : [];
    const sidebarIndexKey = 'openap|sidebar-active-index';
    const reducedNavigationMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    // Coordinate the vertical transition between the OpenAP and APtv areas
    // across their server-rendered page load.
    const areaTransitionKey = 'openap|area-page-transition';
    const areaRail = document.querySelector('.openap-workspace-switch');
    const areaLinks = areaRail ? Array.from(areaRail.querySelectorAll('.openap-workspace-link[href]')) : [];
    let areaNavigationPending = false;
    try {
        const entryDirection = sessionStorage.getItem(areaTransitionKey);
        sessionStorage.removeItem(areaTransitionKey);
        if (entryDirection && !reducedNavigationMotion.matches) {
            document.documentElement.classList.add(`openap-area-enter-${entryDirection}`);
            window.setTimeout(() => {
                document.documentElement.classList.remove(`openap-area-enter-${entryDirection}`);
            }, 380);
        } else if (entryDirection) {
            document.documentElement.classList.remove(`openap-area-enter-${entryDirection}`);
        }
    } catch (error) {
        // The area change still works when session storage is unavailable.
    }

    areaLinks.forEach((link, targetIndex) => {
        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const destination = new URL(link.href, window.location.href);
            if (destination.origin !== window.location.origin || destination.hash || link.hasAttribute('download')) return;
            const activeIndex = areaLinks.findIndex((candidate) => candidate.classList.contains('active'));
            if (activeIndex < 0 || activeIndex === targetIndex) return;
            if (areaNavigationPending) {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            areaNavigationPending = true;
            const direction = targetIndex > activeIndex ? 'down' : 'up';
            if (reducedNavigationMotion.matches) {
                window.location.assign(destination.href);
                return;
            }

            document.body.classList.add(`openap-area-exit-${direction}`);
            areaRail?.classList.add(`is-area-exiting-${direction}`);
            try {
                sessionStorage.setItem(areaTransitionKey, direction);
            } catch (error) {
                // Navigation must not depend on session storage.
            }
            window.setTimeout(() => window.location.assign(destination.href), 380);
        });
    });

    let sidebarSlideTimer = null;
    const moveSidebarIndicator = (item, animate = true) => {
        if (!sidebarNav || !sidebarIndicator || !item) return;
        window.clearTimeout(sidebarSlideTimer);
        const shouldAnimate = animate && !reducedNavigationMotion.matches;
        sidebarNav.classList.toggle('is-sliding', shouldAnimate);
        sidebarIndicator.style.setProperty('--openap-sidebar-top', `${item.offsetTop}px`);
        sidebarIndicator.style.setProperty('--openap-sidebar-height', `${item.offsetHeight}px`);
        sidebarIndicator.classList.add('is-ready');
        sidebarItems.forEach((candidate) => candidate.classList.toggle('active', candidate === item));
        if (shouldAnimate) {
            sidebarSlideTimer = window.setTimeout(() => {
                sidebarNav.classList.remove('is-sliding');
                sidebarSlideTimer = null;
            }, 360);
        }
    };

    // The dashboard is served both as `/` and `/dashboard`, while the
    // server-rendered AP sidebar uses the latter as its first link.  When the
    // area switcher lands on `/`, pathname matching alone therefore leaves the
    // indicator uninitialized until the user clicks an item.  Keep the first
    // AP item selected as the dashboard default, just like the IPTV rail.
    const currentSidebarItem = sidebarItems.find((item) => {
        const link = item.querySelector('a.nav-link[href]');
        return link && new URL(link.href, window.location.href).pathname === window.location.pathname;
    }) || sidebarItems[0] || null;
    if (currentSidebarItem) {
        let previousSidebarItem = null;
        try {
            const previousIndex = Number(sessionStorage.getItem(sidebarIndexKey));
            previousSidebarItem = Number.isInteger(previousIndex) ? sidebarItems[previousIndex] : null;
        } catch (error) {
            // Start directly at the active item when storage is unavailable.
        }
        if (previousSidebarItem && previousSidebarItem !== currentSidebarItem && !reducedNavigationMotion.matches) {
            moveSidebarIndicator(previousSidebarItem, false);
            requestAnimationFrame(() => requestAnimationFrame(() => moveSidebarIndicator(currentSidebarItem, true)));
        } else {
            moveSidebarIndicator(currentSidebarItem, false);
        }
        try {
            sessionStorage.setItem(sidebarIndexKey, currentSidebarItem.dataset.openapSidebarIndex || '0');
        } catch (error) {
            // The visual state does not depend on storage.
        }
    }

    let sidebarNavigationPending = false;
    document.querySelectorAll('.sb-sidenav-menu a.nav-link[href]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const destination = new URL(link.href, window.location.href);
            if (destination.origin !== window.location.origin || destination.hash || link.hasAttribute('download')) {
                return;
            }
            if (destination.href === window.location.href) return;
            if (sidebarNavigationPending) {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            sidebarNavigationPending = true;
            const selectedItem = link.closest('.sb-nav-link-icon[data-openap-sidebar-index]');
            moveSidebarIndicator(selectedItem, true);
            const currentIndex = Number(currentSidebarItem?.dataset.openapSidebarIndex ?? 0);
            const targetIndex = Number(selectedItem?.dataset.openapSidebarIndex ?? currentIndex);
            const pageDirection = targetIndex >= currentIndex ? 'forward' : 'backward';
            const mobileSidebar = window.matchMedia('(max-width: 991.98px), ' + compactLandscapeMedia + ', ' + sidebarOnlyLandscapeMedia).matches;

            // Start the current page's horizontal exit before navigating.  The
            // next document uses the matching enter animation, so the page is
            // handed off continuously instead of being replaced abruptly.
            if (!mobileSidebar && pageContent && !reducedNavigationMotion.matches) {
                pageContent.classList.add('openap-page-exit', `openap-page-exit-${pageDirection}`);
            }

            try {
                if (mobileSidebar) sessionStorage.removeItem(pageTransitionKey);
                else sessionStorage.setItem(pageTransitionKey, pageDirection);
                sessionStorage.setItem(sidebarIndexKey, selectedItem?.dataset.openapSidebarIndex || '0');
            } catch (error) {
                // Navigation must continue even when storage is unavailable.
            }

            const animationDelay = mobileSidebar || reducedNavigationMotion.matches ? 0 : 340;
            const closeDelay = 0;
            window.setTimeout(() => {
                if (mobileSidebar) {
                    document.body.classList.remove('sb-sidenav-toggled');
                    document.documentElement.classList.remove('openap-mobile-sidebar-open');
                    try {
                        localStorage.setItem('openap|mobile-sidebar-open', 'false');
                    } catch (error) {
                        // Closing the drawer must not depend on storage.
                    }
                }
                window.setTimeout(() => {
                    if (destination.href !== window.location.href) {
                        window.location.assign(destination.href);
                    }
                }, closeDelay);
            }, animationDelay);
        });
    });

    let hostapdPageInitialized = false;
    const initializeHostapdPage = () => {
        if (hostapdPageInitialized) return;
        initHostapd();
        initHostapd_ajax();
        hostapdPageInitialized = true;
    };

    // Initialize the appropriate module based on the current path
    const path = window.location.pathname;
    console.log(`Current path: ${path}`);
    switch (path) {
        case '/dashboard':
        case '/':
            // initDashboard();
            break;
        case '/ap_configuration':
        case '/dhcp_setting':
        case '/logging':
        case '/clients':
        case '/aptv':
            // These pages load their own page module from the footer. Keep
            // them explicit here so a valid page is not reported as missing
            // an initialization function.
            break;
        case '/hostapd_conf':
        case '/hostapd_conf/':
            initializeHostapdPage();
            break;
        case '/system_info':
            initSystem();
            initSystem_ajax();
            break;
        case '/login':
            initLogin();
            break;
        default:
            console.warn(`No initialization function defined for path: ${path}`);
    }

    // Rewritten or prefixed routes may not expose /hostapd_conf verbatim.
    // The form itself is the reliable signal that hotspot controls are present.
    if (document.querySelector('#cbxopenapband, #cbxhwmode')) {
        initializeHostapdPage();
    }

    // --------- Global initialization ---------
    $(document).ajaxSend(setCSRFTokenHeader);
    globalThis.getCookie = getCookie;
    globalThis.setCookie = setCookie;
    globalThis.disableValidation = disableValidation;

    // Enable Bootstrap tooltips
    $('[data-bs-toggle="tooltip"]').tooltip()

    // Allows closing of sidebar when content overlay is clicked
    $(document).on('click', '.sb-sidenav-toggled #layoutSidenav_content', function() {
        // Only apply on mobile style nav
        if (window.matchMedia('(max-width: 991.98px), ' + compactLandscapeMedia + ', ' + sidebarOnlyLandscapeMedia).matches) {
            $('#sidebarToggle').trigger('click');
        }
    });

    // Sets focus on a specified tab
    jQuery(function() {
        // Store hash in URL
        $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
            var hash = $(e.target).attr('href');
            history.pushState(null, null, hash);
        });

        // Activate tab based on URL hash
        var hash = window.location.hash;
        if (hash) {
            $('.nav-link[href="' + hash + '"]').tab('show');
        }
    });

    // Event listener for Bootstrap's form validation
    window.addEventListener('load', function() {
        // Fetch all the forms we want to apply custom Bootstrap validation styles to
        var forms = document.getElementsByClassName('needs-validation');
        // Loop over them and prevent submission
        var validation = Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
            if (form.checkValidity() === false) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
            }, false);
        });
    }, false);

    // Input masks
    $('.ip_address').mask('0ZZ.0ZZ.0ZZ.0ZZ', {
        translation: {
            'Z': {
                pattern: /[0-9]/, optional: true
            }
        },
        placeholder: "___.___.___.___"
    });
    $('.mac_address').mask('FF:FF:FF:FF:FF:FF', {
        translation: {
            'F': {
                pattern: /[0-9a-fA-F]/, optional: false
            }
        },
        placeholder: "__:__:__:__:__:__"
    });
    $('.cidr').mask('099.099.099.099/099', {
        translation: {
            '0': { pattern: /[0-9]/ }
        },
        placeholder: "___.___.___.___/___"
    });

    // Show hide password functionality
    $(document).on("click", ".js-toggle-password", function(e) {
        var button = $(e.currentTarget);
        var field  = $(button.data("bsTarget"));

        if (field.is(":input")) {
            e.preventDefault();

            if (!button.data("__toggle-with-initial")) {
                $("i", button).removeClass("fas fa-eye").addClass(button.attr("data-toggle-with")); 
            }

            if (field.attr("type") === "password") {
                field.attr("type", "text");
            } else {
                $("i", button).removeClass("fas fa-eye-slash").addClass("fas fa-eye");
                field.attr("type", "password");
            }
        }
    });

    // Static Array method
    Array.range = (start, end) => Array.from({length: (end - start)}, (v, k) => k + start);


    // Dark is the single supported colour mode.  Normalize legacy browser
    // preferences on load so neither an old cookie nor OS appearance can
    // switch the UI back to light.
    setDarkMode();
    setCookie('use_system_color_scheme', 'false', 365);
    $('.system-mode-toggle').prop('checked', false).removeClass('active');

    // Handle stacking of multiple Bootstrap modals
    $(document).on('show.bs.modal', '.modal', function () {
        // Calculate increasing z-index based on how many modals are currently visible
        // 1050 is Bootstrap's base modal z-index
        const zIndex = 1050 + 10 * $('.modal:visible').length;

        $(this).css('z-index', zIndex);

        // Give the backdrop a slightly lower z-index and mark it as stacked
        // Small delay ensures Bootstrap has created the backdrop
        setTimeout(() => {
            $('.modal-backdrop').not('.modal-stack')
            .css('z-index', zIndex - 1)
            .addClass('modal-stack');
        }, 10);
    });

    // To auto-close Bootstrap alerts; time is in milliseconds
    const alertTimeout = parseInt(getCookie('alert_timeout'), 10);
    window.setTimeout(
        function() {
            $(".alert").not(".openap-persistent-alert").fadeTo(500, 0).slideUp(500, function(){
                $(this).remove();
            });
        },
        !isNaN(alertTimeout) && alertTimeout > 0 ? alertTimeout : 5000
    );
});
