const MAX_SHARE_BYTES = 25 * 1024 * 1024;

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

export function canOfferMobileShare(output) {
    return Boolean(
        window.matchMedia?.('(pointer: coarse)').matches
        && navigator.share
        && output?.available
        && output?.delivery === 'proxy'
        && sameOriginUrl(delivery(output)),
    );
}

export async function shareMedia(output) {
    const url = sameOriginUrl(delivery(output));
    if (!url) {
        throw new Error('This file is no longer available. Analyze the URL again.');
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
    if (blob.size !== size || blob.size > MAX_SHARE_BYTES) {
        throw new Error('The file could not be prepared for sharing.');
    }
    const file = new File([blob], `${String(output.label || 'save-it-media').replace(/[^A-Za-z0-9._ -]/g, '-')}`, {
        type: blob.type || 'application/octet-stream',
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
