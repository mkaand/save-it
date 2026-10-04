const PROVIDERS = new Set(['youtube', 'youtube_shorts', 'x', 'instagram', 'linkedin', 'pinterest', 'tiktok', 'facebook', 'unknown']);

function safeString(value, pattern, maxLength) {
    return typeof value === 'string' && value.length <= maxLength && pattern.test(value) ? value : null;
}

export function analysisFailureReportContext({ submittedUrl, error = {} }) {
    return {
        submittedUrl: safeString(submittedUrl, /[\s\S]*/, 2048),
        provider: PROVIDERS.has(error?.provider) ? error.provider : null,
        errorCode: safeString(error?.code, /^[a-z0-9_]{1,64}$/, 64),
        requestId: safeString(error?.request_id, /^[A-Za-z0-9_-]{1,128}$/, 128),
    };
}

export function initIssueReport() {
    const dialog = document.querySelector('[data-issue-report-dialog]');
    const form = document.querySelector('[data-issue-report-form]');
    const normalOpeners = document.querySelectorAll('[data-issue-report-open]');
    const failedOpener = document.querySelector('[data-issue-report-failed-open]');

    if (!dialog || !form || !failedOpener) {
        return;
    }

    const email = form.querySelector('[name="email"]');
    const message = form.querySelector('[name="message"]');
    const context = form.querySelector('[data-issue-report-context]');
    const submittedUrl = form.querySelector('[name="submitted_url"]');
    const submittedUrlDisplay = form.querySelector('[data-issue-report-url]');
    const provider = form.querySelector('[name="provider"]');
    const errorCode = form.querySelector('[name="error_code"]');
    const requestId = form.querySelector('[name="request_id"]');
    const status = form.querySelector('[data-issue-report-status]');
    const submit = form.querySelector('[data-issue-report-submit]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let failedContext = null;
    let sending = false;

    function setStatus(text = '', kind = '') {
        status.textContent = text;
        status.dataset.state = kind;
    }

    function setContext(value = null) {
        submittedUrl.value = value?.submittedUrl || '';
        provider.value = value?.provider || '';
        errorCode.value = value?.errorCode || '';
        requestId.value = value?.requestId || '';
        context.hidden = !value?.submittedUrl;
        submittedUrlDisplay.textContent = value?.submittedUrl || '';
    }

    function open(value = null) {
        form.reset();
        setStatus();
        setContext(value);
        dialog.showModal();
        email.focus();
    }

    normalOpeners.forEach((opener) => opener.addEventListener('click', () => open()));
    failedOpener.addEventListener('click', () => open(failedContext));
    document.querySelector('[data-issue-report-close]')?.addEventListener('click', () => dialog.close());

    document.addEventListener('save-it:analysis-failed', (event) => {
        failedContext = analysisFailureReportContext(event.detail || {});
        failedOpener.hidden = false;
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (sending) {
            return;
        }

        sending = true;
        submit.disabled = true;
        setStatus('Sending your report…');
        const payload = Object.fromEntries(new FormData(form));

        try {
            const response = await fetch('/report-issue', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf || '',
                },
                body: JSON.stringify(payload),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const validationMessage = data?.errors?.email?.[0] || data?.errors?.message?.[0];
                throw new Error(validationMessage || data?.message || 'We could not send your report right now. Please try again later.');
            }
            setStatus(data?.message || 'Thanks. Your report has been sent.', 'success');
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'We could not send your report right now. Please try again later.', 'error');
            submit.disabled = false;
            sending = false;
        }
    });

    dialog.addEventListener('close', () => {
        sending = false;
        submit.disabled = false;
        setStatus();
    });
}
