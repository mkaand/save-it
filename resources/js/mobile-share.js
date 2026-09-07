const MAX_SHARE_BYTES = 25 * 1024 * 1024;
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

export function canOfferMobileShare(output) {
    return Boolean(
        window.matchMedia?.('(pointer: coarse)').matches
        && navigator.share
        && output?.available
        && output?.delivery === 'proxy'
        && sameOriginUrl(delivery(output)),
    );
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

export async function shareMedia(output) {
    let url = sameOriginUrl(delivery(output));
    if (!url) {
        throw new Error('This file is no longer available. Analyze the URL again.');
    }
    if (mediaType(output?.mime_type) === 'video/mp4') {
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
        const preparedUrl = sameOriginPreparationUrl(data?.data?.url);
        if (!preparation.ok || !preparedUrl || data?.data?.mime_type !== 'video/mp4') {
            throw new Error('The file could not be prepared for sharing.');
        }
        url = preparedUrl;
        output = { ...output, filename: data.data.filename, mime_type: data.data.mime_type };
    }
    const probe = await fetch(url, { headers: { Range: 'bytes=0-0' } });
    const range = probe.headers.get('content-range') || '';
    const match = /^bytes 0-0\/([0-9]+)$/.exec(range);
    const size = match ? Number(match[1]) : Number(probe.headers.get('content-length'));
    await probe.body?.cancel();
    if (!Number.isSafeInteger(size) || size < 1 || size > MAX_SHARE_BYTES) {
        throw new Error('Use Download for this file. It is too large to prepare for sharing.');
    }
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error('The file could not be prepared for sharing.');
    }
    const blob = await response.blob();
    const mime = mediaType(response.headers.get('content-type')) || mediaType(blob.type);
    if (blob.size !== size || blob.size > MAX_SHARE_BYTES) {
        throw new Error('The file could not be prepared for sharing.');
    }
    const file = new File([blob], sharedFilename(output, mime || blob.type, response.headers.get('content-disposition')), {
        type: mime || mediaType(blob.type) || 'application/octet-stream',
    });
    if (navigator.canShare && !navigator.canShare({ files: [file] })) {
        throw new Error('Sharing is not available for this file on this device.');
    }
    try {
        await navigator.share({ files: [file], title: output.label || 'Save It media' });
    } catch (error) {
        if (error?.name !== 'AbortError') {
            throw error;
        }
    }
}
