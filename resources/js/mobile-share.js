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
const OPFS_TEMP_ROOT = 'save-it-share-temp';
const OPFS_SESSION_MAX_AGE_MS = 24 * 60 * 60 * 1000;
const OPFS_STALE_SCAN_LIMIT = 20;

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
        let lastPercent = 0;
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
                if (percent > lastPercent) {
                    lastPercent = percent;
                    onState({ phase: 'loading', percent });
                }
            };
            request.onerror = fail;
            request.onabort = fail;
            request.ontimeout = fail;
            request.onload = () => {
                const blob = request.response;
                const contentType = request.getResponseHeader?.('content-type');
                const mime = mediaType(contentType || blob?.type);
                if (request.status !== 200 || !(blob instanceof Blob) || blob.size !== size || mime !== 'video/mp4' || (blob.type && mediaType(blob.type) !== 'video/mp4')) {
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

function preparedMediaLoadError() {
    return new ShareMediaError('share_unavailable', 'The prepared file could not be loaded for sharing.');
}

function opfsSessionName(now, randomUuid) {
    return `session-${now()}-${randomUuid()}`;
}

async function removeOpfsSession(root, name) {
    try {
        await root.removeEntry(name, { recursive: true });
    } catch {
        // OPFS cleanup is best-effort and must never surface browser internals.
    }
}

async function removeStaleOpfsSessions(root, now) {
    if (!root.entries) {
        return;
    }
    let scanned = 0;
    for await (const [name, handle] of root.entries()) {
        if (scanned >= OPFS_STALE_SCAN_LIMIT) {
            return;
        }
        scanned += 1;
        const match = /^session-([0-9]{13})-[0-9a-f-]{36}$/i.exec(name);
        if (handle.kind === 'directory' && match && Number(match[1]) < now() - OPFS_SESSION_MAX_AGE_MS) {
            await removeOpfsSession(root, name);
        }
    }
}

function opfsSupported(storageDirectoryFactory, randomUuid) {
    return typeof storageDirectoryFactory === 'function' && typeof randomUuid === 'function';
}

export function transferPercent(received, size) {
    if (!Number.isSafeInteger(received) || !Number.isSafeInteger(size) || received < 0 || size < 1) {
        return null;
    }

    return Math.min(100, Math.max(0, Math.floor((received / size) * 100)));
}

export async function streamVideoToOpfs(url, size, output, onState = () => {}, {
    mime = mediaType(output?.mime_type) || 'video/mp4',
    maximum = shareMaximum(output) || 104_857_600,
    storageDirectoryFactory = () => navigator.storage?.getDirectory?.(),
    randomUuid = () => crypto.randomUUID(),
    now = () => Date.now(),
    fetcher = fetch,
} = {}) {
    if (!opfsSupported(storageDirectoryFactory, randomUuid) || !mime?.startsWith('video/') || !maximum) {
        return null;
    }
    let tempRoot;
    let sessionName;
    let reader;
    let writable;
    let cleaned = false;
    const cleanup = async () => {
        if (cleaned || !tempRoot || !sessionName) {
            return;
        }
        cleaned = true;
        await removeOpfsSession(tempRoot, sessionName);
    };
    try {
        const root = await storageDirectoryFactory();
        if (!root?.getDirectoryHandle) {
            return null;
        }
        tempRoot = await root.getDirectoryHandle(OPFS_TEMP_ROOT, { create: true });
        if (!tempRoot?.getDirectoryHandle || !tempRoot?.removeEntry) {
            return null;
        }
        await removeStaleOpfsSessions(tempRoot, now);
        sessionName = opfsSessionName(now, randomUuid);
        const session = await tempRoot.getDirectoryHandle(sessionName, { create: true });
        const filename = sharedFilename(output, mime);
        const fileHandle = await session.getFileHandle(filename, { create: true });
        writable = await fileHandle.createWritable();
        const response = await fetcher(url);
        const responseMime = mediaType(response.headers?.get?.('content-type'));
        if (!response.ok || response.status !== 200 || responseMime !== mime || !response.body?.getReader) {
            throw preparedMediaLoadError();
        }
        reader = response.body.getReader();
        let received = 0;
        let lastPercent = -1;
        onState({ phase: 'loading', percent: Number.isSafeInteger(size) ? 0 : null });
        while (true) {
            const { done, value } = await reader.read();
            if (done) {
                break;
            }
            if (!(value instanceof Uint8Array) || value.byteLength < 1 || received + value.byteLength > maximum || (Number.isSafeInteger(size) && received + value.byteLength > size)) {
                throw preparedMediaLoadError();
            }
            await writable.write(value);
            received += value.byteLength;
            const percent = transferPercent(received, size);
            if (percent !== null && percent > lastPercent) {
                lastPercent = percent;
                onState({ phase: 'loading', percent });
            }
        }
        if (received < 1 || received > maximum || (Number.isSafeInteger(size) && received !== size)) {
            throw preparedMediaLoadError();
        }
        await writable.close();
        writable = null;
        onState({ phase: 'preparing', stage: 'Finalizing', percent: null });
        const stored = await fileHandle.getFile();
        if (!(stored instanceof File) || stored.size !== received || stored.size < 1 || stored.size > maximum) {
            throw preparedMediaLoadError();
        }
        // OPFS commonly derives type from the video filename. If it does not,
        // File references the OPFS-backed Blob without an ArrayBuffer/byte copy.
        const file = mediaType(stored.type) === mime
            ? stored
            : new File([stored], filename, { type: mime, lastModified: stored.lastModified });
        if (file.size !== received || mediaType(file.type) !== mime) {
            throw preparedMediaLoadError();
        }
        return { file, cleanup };
    } catch {
        try {
            await reader?.cancel?.();
        } catch {
            // Ignore reader cancellation failures while cleaning this session.
        }
        try {
            await writable?.abort?.();
        } catch {
            // Ignore writable abort failures while cleaning this session.
        }
        await cleanup();
        throw preparedMediaLoadError();
    }
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
    storageDirectoryFactory,
    randomUuid,
    now,
    fetcher = fetch,
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
    let expectedMime = mediaType(output?.mime_type);
    const video = expectedMime?.startsWith('video/');
    if (expectedMime === 'video/mp4') {
        onState({ phase: 'preparing', stage: 'Queued', percent: null });
        const token = tokenFromDelivery(url);
        if (!token) {
            throw new Error('This file is no longer available. Analyze the URL again.');
        }
        const preparation = await fetcher('/api/share-preparations', {
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
        expectedMime = prepared.mime_type;
        preparedArtifact = true;
    }
    onState({ phase: 'loading', percent: null });
    const probe = await fetcher(url, { headers: { Range: 'bytes=0-0' } });
    const range = probe.headers.get('content-range') || '';
    const match = /^bytes 0-0\/([0-9]+)$/.exec(range);
    const size = match ? Number(match[1]) : Number(probe.headers.get('content-length'));
    await probe.body?.cancel();
    const knownSize = Number.isSafeInteger(size) && size > 0 ? size : null;
    if ((knownSize && knownSize > maximum) || (!knownSize && (!video || preparedArtifact))) {
        throw new ShareMediaError('media_too_large', 'Download only · Too large for Share / Save');
    }
    let blob;
    let mime;
    let disposition = null;
    let cleanup = null;
    if (video) {
        const stored = await streamVideoToOpfs(url, knownSize, output, onState, {
            mime: expectedMime,
            maximum,
            storageDirectoryFactory,
            randomUuid,
            now,
            fetcher,
        });
        if (stored) {
            return Object.freeze({
                file: stored.file,
                title: output.label || 'Save It media',
                cleanup: stored.cleanup,
            });
        }
        if (!knownSize) {
            throw new ShareMediaError('media_too_large', 'Download only · Too large for Share / Save');
        }
    }
    if (preparedArtifact) {
        const loaded = await loadPreparedBlob(url, knownSize, onState, { requestFactory });
        blob = loaded.blob;
        mime = loaded.mime;
        disposition = loaded.disposition;
    } else {
        const response = await fetcher(url);
        if (!response.ok) {
            throw new ShareMediaError('share_unavailable', 'The file could not be prepared for sharing.');
        }
        blob = await responseBlob(response, knownSize, onState);
        mime = mediaType(response.headers.get('content-type')) || mediaType(blob.type);
        disposition = response.headers.get('content-disposition');
    }
    if (blob.size !== knownSize || blob.size > maximum || !mime) {
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
        cleanup,
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
