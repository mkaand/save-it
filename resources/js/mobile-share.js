const EXTENSIONS = new Map([
    ['video/mp4', 'mp4'],
    ['video/webm', 'webm'],
    ['audio/mp4', 'm4a'],
    ['audio/m4a', 'm4a'],
    ['audio/mpeg', 'mp3'],
    ['audio/webm', 'webm'],
    ['image/jpeg', 'jpg'],
    ['image/png', 'png'],
    ['image/webp', 'webp'],
]);

export class ShareMediaError extends Error {
    constructor(code, message, status = null) {
        super(message);
        this.name = 'ShareMediaError';
        this.code = code;
        this.status = status;
    }
}

function delivery(output) {
    return output?.delivery === 'proxy' && typeof output.download_url === 'string'
        ? output.download_url
        : null;
}

function sameOriginUrl(value) {
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin && url.pathname.startsWith('/api/downloads/')
            ? url
            : null;
    } catch {
        return null;
    }
}

function tokenFromDelivery(url) {
    const match = /^\/api\/downloads\/([a-z0-9]{48}\.[a-f0-9]{64})$/.exec(url.pathname);

    return match ? match[1] : null;
}

function sameOriginPreparationUrl(value) {
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin && /^\/api\/share-preparations\/[a-z0-9]{48}$/.test(url.pathname)
            ? url
            : null;
    } catch {
        return null;
    }
}

function sameOriginPreparationStatusUrl(value) {
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin && /^\/api\/share-preparation-jobs\/[a-z0-9]{48}$/.test(url.pathname)
            ? url
            : null;
    } catch {
        return null;
    }
}

export function canOfferMobileShare(output) {
    return Boolean(
        window.matchMedia?.('(pointer: coarse)').matches
        && navigator.share
        && output?.available
        && output?.share?.eligible !== false
        && output?.delivery === 'proxy'
        && sameOriginUrl(delivery(output)),
    );
}

function shareMaximum(output) {
    const maximum = output?.share?.max_bytes;

    return Number.isSafeInteger(maximum) && maximum > 0 ? maximum : null;
}

function preparationError(response, data) {
    const code = data?.error?.code;
    const message = data?.error?.message;

    if (code === 'media_too_large' && typeof message === 'string' && message.length > 0 && message.length <= 240) {
        return new ShareMediaError(code, message, response.status);
    }

    return new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.', response.status);
}

function mediaType(value) {
    const mime = String(value || '').split(';', 1)[0].trim().toLowerCase();

    return EXTENSIONS.has(mime) ? mime : null;
}

function headerFilename(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const encoded = /filename\*=UTF-8''([^;]+)/i.exec(value)?.[1];
    if (encoded) {
        try {
            return decodeURIComponent(encoded);
        } catch {
            return null;
        }
    }

    return /filename="?([^";]+)"?/i.exec(value)?.[1] || null;
}

export function sharedFilename(output, mime, disposition = null) {
    const extension = EXTENSIONS.get(mediaType(mime)) || 'bin';
    const candidate = headerFilename(disposition) || output?.filename || output?.label || 'save-it-media';
    const stem = String(candidate)
        .replace(/\.\.([\\/]|$)/g, '')
        .replace(/[\\/\u0000-\u001F\u007F]+/g, '-')
        .replace(/\.[A-Za-z0-9]{1,8}$/u, '')
        .replace(/[^A-Za-z0-9._ -]+/g, '-')
        .replace(/[. -]+$/u, '')
        .trim()
        .slice(0, 100) || 'save-it-media';

    return `${stem}.${extension}`;
}

export async function responseBlob(response, size, onState = () => {}) {
    const reader = response.body?.getReader?.();

    if (!reader) {
        onState({ phase: 'loading', percent: null });
        return response.blob();
    }

    const chunks = [];
    let received = 0;
    let lastPercent = -1;
    onState({ phase: 'loading', percent: 0 });

    while (true) {
        const { done, value } = await reader.read();
        if (done) {
            break;
        }

        chunks.push(value);
        received += value.byteLength;
        const percent = Math.min(100, Math.floor((received / size) * 100));
        if (percent !== lastPercent) {
            lastPercent = percent;
            onState({ phase: 'loading', percent });
        }
    }

    return new Blob(chunks, { type: response.headers.get('content-type') || '' });
}

export function loadPreparedBlob(url, size, onState = () => {}, {
    requestFactory = () => new XMLHttpRequest(),
    timeoutMs = 0,
} = {}) {
    return new Promise((resolve, reject) => {
        let request;
        let lastPercent = -1;
        const fail = () => reject(new ShareMediaError(
            'share_unavailable',
            'The prepared file could not be loaded for sharing.',
        ));
        try {
            request = requestFactory();
            request.open('GET', url.toString(), true);
            request.responseType = 'blob';
            request.timeout = timeoutMs;
            request.onprogress = (event) => {
                if (!Number.isFinite(event.loaded) || event.loaded < 0) {
                    return;
                }
                const percent = Math.min(100, Math.max(0, Math.floor((event.loaded / size) * 100)));
                if (percent !== lastPercent) {
                    lastPercent = percent;
                    onState({ phase: 'loading', percent });
                }
            };
            request.onerror = fail;
            request.onabort = fail;
            request.ontimeout = fail;
            request.onload = () => {
                const blob = request.response;
                const mime = mediaType(request.getResponseHeader?.('content-type')) || mediaType(blob?.type);
                if (request.status < 200 || request.status >= 300 || !(blob instanceof Blob) || blob.size !== size || mime !== 'video/mp4') {
                    fail();

                    return;
                }
                onState({ phase: 'preparing', stage: 'Finalizing', percent: null });
                resolve({
                    blob,
                    mime,
                    disposition: request.getResponseHeader?.('content-disposition') || null,
                });
            };
            onState({ phase: 'loading', percent: 0 });
            request.send();
        } catch {
            fail();
        }
    });
}

const pause = (milliseconds) => new Promise((resolve) => globalThis.setTimeout(resolve, milliseconds));

async function preparedMediaFromJob(statusUrl, onState, { pollIntervalMs, sleep }) {
    while (true) {
        const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            throw preparationError(response, payload);
        }
        const state = payload?.data;
        if (!['queued', 'processing', 'ready', 'failed'].includes(state?.status)) {
            throw new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.', response.status);
        }
        if (state.status === 'failed') {
            throw preparationError(response, { error: state.error });
        }
        if (state.status === 'ready') {
            const url = sameOriginPreparationUrl(state.url);
            if (!url || state.mime_type !== 'video/mp4' || typeof state.filename !== 'string') {
                throw new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.', response.status);
            }

            return { url, filename: state.filename, mime_type: state.mime_type };
        }
        const percent = Number.isInteger(state.progress) && state.progress >= 0 && state.progress <= 100
            ? state.progress
            : null;
        onState({ phase: 'preparing', stage: typeof state.stage === 'string' ? state.stage : 'Preparing', percent });
        await sleep(pollIntervalMs);
    }
}

export async function prepareShareMedia(output, onState = () => {}, {
    pollIntervalMs = 1000,
    sleep = pause,
    requestFactory,
} = {}) {
    if (output?.share?.eligible === false) {
        throw new ShareMediaError('media_too_large', 'Download only · Too large for Share / Save');
    }
    const maximum = shareMaximum(output);
    if (!maximum) {
        throw new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.');
    }
    let url = sameOriginUrl(delivery(output));
    if (!url) {
        throw new Error('This file is no longer available. Analyze the URL again.');
    }
    let preparedArtifact = false;
    if (mediaType(output?.mime_type) === 'video/mp4') {
        onState({ phase: 'preparing', stage: 'Queued', percent: null });
        const token = tokenFromDelivery(url);
        if (!token) {
            throw new Error('This file is no longer available. Analyze the URL again.');
        }
        const preparation = await fetch('/api/share-preparations', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ token }),
        });
        const data = await preparation.json().catch(() => null);
        const statusUrl = sameOriginPreparationStatusUrl(data?.data?.status_url);
        if (!preparation.ok || preparation.status !== 202) {
            throw preparationError(preparation, data);
        }
        if (!statusUrl) {
            throw new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.', preparation.status);
        }
        const prepared = await preparedMediaFromJob(statusUrl, onState, { pollIntervalMs, sleep });
        url = prepared.url;
        output = { ...output, filename: prepared.filename, mime_type: prepared.mime_type };
        preparedArtifact = true;
    }
    onState({ phase: 'loading', percent: null });
    const probe = await fetch(url, { headers: { Range: 'bytes=0-0' } });
    const range = probe.headers.get('content-range') || '';
    const match = /^bytes 0-0\/([0-9]+)$/.exec(range);
    const size = match ? Number(match[1]) : Number(probe.headers.get('content-length'));
    await probe.body?.cancel();
    if (!Number.isSafeInteger(size) || size < 1 || size > maximum) {
        throw new ShareMediaError('media_too_large', 'Download only · Too large for Share / Save');
    }
    let blob;
    let mime;
    let disposition = null;
    if (preparedArtifact) {
        const loaded = await loadPreparedBlob(url, size, onState, { requestFactory });
        blob = loaded.blob;
        mime = loaded.mime;
        disposition = loaded.disposition;
    } else {
        const response = await fetch(url);
        if (!response.ok) {
            throw new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.');
        }
        blob = await responseBlob(response, size, onState);
        mime = mediaType(response.headers.get('content-type')) || mediaType(blob.type);
        disposition = response.headers.get('content-disposition');
    }
    if (blob.size !== size || blob.size > maximum || !mime) {
        throw new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.');
    }
    let file;
    try {
        file = new File([blob], sharedFilename(output, mime, disposition), { type: mime });
    } catch {
        throw new ShareMediaError('share_unavailable', 'The prepared file could not be loaded for sharing.');
    }
    return Object.freeze({
        file,
        title: output.label || 'Save It media',
    });
}

export function openShareSheet(prepared) {
    const file = prepared?.file;
    const title = typeof prepared?.title === 'string' && prepared.title !== ''
        ? prepared.title
        : 'Save It media';

    if (!(file instanceof File)) {
        throw new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.');
    }

    if (navigator.canShare && !navigator.canShare({ files: [file] })) {
        throw new ShareMediaError('share_unavailable', 'Sharing is not available for this file on this device.');
    }

    // This deliberately has no await or asynchronous work before navigator.share().
    return navigator.share({ files: [file], title });
}

export function isShareAbortError(error) {
    return error?.name === 'AbortError';
}

export function shareErrorMessage(error) {
    if (isShareAbortError(error)) {
        return null;
    }

    if (error instanceof ShareMediaError) {
        return error.message;
    }

    if (['NotAllowedError', 'SecurityError', 'InvalidStateError', 'TypeError', 'DataError'].includes(error?.name)) {
        return error?.name === 'NotAllowedError'
            ? 'Tap Share / Save again to open the share sheet.'
            : 'The share sheet could not be opened. Please try again.';
    }

    return 'Sharing could not be prepared.';
}
