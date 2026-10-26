/* Shared by classic page scripts and ES modules. Never changes browser timers. */
(function () {
    'use strict';
    const timers = new Set();
    window.openapSession = {
        expired: false,
        setInterval(callback, delay) {
            if (this.expired) return 0;
            const timer = window.setInterval(() => {
                if (!this.expired) return callback();
            }, delay);
            timers.add(timer);
            return timer;
        },
        expire() {
            if (this.expired) return;
            this.expired = true;
            timers.forEach(timer => window.clearInterval(timer));
            timers.clear();
            window.dispatchEvent(new CustomEvent('openap:session-expired'));
        }
    };
})();
