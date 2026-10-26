export function initLogin() {
    console.info("OpenAP login module initialized");

    const authTransitionKey = 'openap|auth-transition';
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const params = new URLSearchParams(window.location.search);
    const redirectUrl = $('#redirect-url').val() || params.get('action') || '/';
    $('#redirect-url').val(redirectUrl);
    $('#username').focus();
    $('#username').addClass("focusedInput");

    const form = document.getElementById('admin-login-form');
    const initialFeedback = document.getElementById('openapLoginFeedback');
    if (initialFeedback?.querySelector('.alert-danger')) {
        document.body.classList.add('openap-login-error');
    }
    if (!form || form.dataset.csrfRefreshBound === 'true') {
        return;
    }
    form.dataset.csrfRefreshBound = 'true';
    form.addEventListener('submit', async (event) => {
        if (form.dataset.csrfReady === 'true') {
            return;
        }

        if (!form.checkValidity()) {
            event.preventDefault();
            form.classList.add('was-validated');

            const invalidField = form.querySelector(':invalid');
            const feedback = document.getElementById('openapLoginFeedback');
            if (feedback && invalidField) {
                const fieldName = invalidField.id === 'username' ? 'Username' : 'Password';
                feedback.innerHTML = `<div class="alert alert-danger py-2 mb-3" role="alert" style="font-size:13px;text-align:center">${fieldName} is required.</div>`;
            }
            invalidField?.focus();
            form.reportValidity();
            return;
        }
        event.preventDefault();

        const submitButton = form.querySelector('button[type="submit"]');
        const feedback = document.getElementById('openapLoginFeedback');
        if (submitButton) {
            submitButton.disabled = true;
        }
        const controller = new AbortController();
        const refreshTimeout = window.setTimeout(() => controller.abort(), 8000);
        try {
            const separator = window.location.search ? '&' : '?';
            const response = await fetch(
                `${window.location.pathname}${window.location.search}${separator}csrf_refresh=${Date.now()}`,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                    headers: {'X-OpenAP-CSRF-Refresh': '1'}
                }
            );
            if (!response.ok) {
                throw new Error(`CSRF refresh failed with HTTP ${response.status}`);
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const freshToken = page.querySelector('input[name="csrf_token"]')?.value || '';
            const tokenField = form.querySelector('input[name="csrf_token"]');
            if (!freshToken || !tokenField) {
                throw new Error('CSRF refresh did not return a token');
            }
            tokenField.value = freshToken;
            const authResponse = await fetch(form.action || 'login', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'follow',
                signal: controller.signal,
                body: new FormData(form)
            });
            const finalUrl = new URL(authResponse.url || window.location.href, window.location.href);
            if (finalUrl.pathname === '/login') {
                const authPage = new DOMParser().parseFromString(await authResponse.text(), 'text/html');
                const serverFeedback = authPage.querySelector('#openapLoginFeedback');
                if (feedback) {
                    feedback.innerHTML = serverFeedback?.innerHTML || '<div class="alert alert-danger py-2 mb-3" role="alert" style="font-size:13px;text-align:center">Username or password is incorrect.</div>';
                }
                form.dataset.csrfReady = 'false';
                if (submitButton) submitButton.disabled = false;
                document.body.classList.remove('openap-login-error');
                void document.body.offsetWidth;
                document.body.classList.add('openap-login-error');
                form.querySelector('#password').value = '';
                form.querySelector('#password')?.focus();
                return;
            }
            form.dataset.csrfReady = 'true';
            if (!reducedMotion) {
                try {
                    window.sessionStorage.setItem(authTransitionKey, 'login');
                } catch (storageError) {
                    // Authentication must not depend on sessionStorage.
                }
                document.body.classList.add('openap-auth-login-exit');
                await new Promise((resolve) => window.setTimeout(resolve, 420));
            }
            window.location.assign(finalUrl.href);
        } catch (error) {
            console.error('Unable to refresh the login session', error);
            form.dataset.csrfReady = 'false';
            if (submitButton) {
                submitButton.disabled = false;
            }
            if (feedback) {
                feedback.innerHTML = '<div class="alert alert-danger py-2 mb-3" role="alert" style="font-size:13px;text-align:center">The login session could not be refreshed. Please try again.</div>';
            }
            form.querySelector('#username')?.focus();
        } finally {
            window.clearTimeout(refreshTimeout);
        }
    });
}
