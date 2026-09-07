const DOWNLOAD_PATH = /^\/api\/downloads\/[a-z0-9]{48}\.[a-f0-9]{64}$/;
const JOB_PATH = '/api/download-jobs';
const JOB_TOKEN = /^[a-z0-9]{48}\.[a-f0-9]{64}$/;
const JOB_STATUS_PATH = /^\/api\/download-jobs\/[a-f0-9-]{36}$/;

export function normalizeDownloadDelivery(output) {
    if (!output || typeof output !== 'object' || output.available !== true) {
        return null;
    }

    if (
        output.delivery === 'proxy'
        && typeof output.download_url === 'string'
        && DOWNLOAD_PATH.test(output.download_url)
    ) {
        return { type: 'proxy', url: output.download_url };
    }

    if (
        output.delivery === 'job'
        && output.job_url === JOB_PATH
        && typeof output.job_token === 'string'
        && JOB_TOKEN.test(output.job_token)
    ) {
        return {
            type: 'job',
            url: JOB_PATH,
            token: output.job_token,
        };
    }

    return null;
}

export function normalizeJobStatus(payload) {
    const data = payload?.data;
    if (
        !data
        || typeof data !== 'object'
        || !['queued', 'processing', 'ready', 'failed'].includes(data.status)
        || typeof data.stage !== 'string'
        || !Number.isInteger(data.progress)
        || data.progress < 0
        || data.progress > 100
    ) {
        return null;
    }

    return {
        status: data.status,
        stage: data.stage.slice(0, 80),
        progress: data.progress,
        downloadUrl: typeof data.download_url === 'string'
            && DOWNLOAD_PATH.test(data.download_url)
            ? data.download_url
            : null,
        error: typeof data.error?.message === 'string'
            ? data.error.message.slice(0, 240)
            : null,
    };
}

export function normalizeJobStart(payload) {
    const url = payload?.data?.status_url;

    return typeof url === 'string' && JOB_STATUS_PATH.test(url) ? url : null;
}

export async function responseJson(response) {
    const contentType = response?.headers?.get('content-type') || '';
    if (!contentType.toLowerCase().includes('application/json')) {
        return null;
    }

    try {
        return await response.json();
    } catch {
        return null;
    }
}
