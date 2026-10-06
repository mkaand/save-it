import { normalizeDownloadDelivery, normalizeJobStart, normalizeJobStatus, responseJson } from './download-delivery.js';
import { prepareShareMedia, ShareMediaError } from './mobile-share.js';

// One in-flight/ready artifact per analyzed output, shared by both actions.
const preparations = new WeakMap();

export function canOfferYouTubeShare(output) {
    return Boolean(output?.preparation === 'youtube_mux'
        && output.share_supported === true && output.share?.eligible !== false
        && normalizeDownloadDelivery(output)?.type === 'job'
        && window.matchMedia?.('(pointer: coarse)').matches && navigator.share);
}

export function prepareYouTubeDownload(output, onState = () => {}, {
    fetcher = fetch,
    sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    pollIntervalMs = 1500,
} = {}) {
    const previous = preparations.get(output);
    if (previous) {
        previous.listeners.add(onState);
        if (previous.state) onState(previous.state);
        return previous.promise.finally(() => previous.listeners.delete(onState));
    }
    const entry = { listeners: new Set([onState]), state: null, promise: null };
    const publish = (state) => {
        entry.state = state;
        for (const listener of entry.listeners) listener(state);
    };
    entry.promise = (async () => {
        const delivery = normalizeDownloadDelivery(output);
        if (output?.preparation !== 'youtube_mux' || delivery?.type !== 'job') {
            throw new Error('This video cannot be prepared. Analyze the URL again.');
        }
        publish({ phase: 'preparing', stage: 'Preparing', percent: null });
        const response = await fetcher(delivery.url, {
            method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: delivery.token }),
        });
        const started = await responseJson(response);
        const statusUrl = normalizeJobStart(started);
        if (!response.ok || !statusUrl) throw new Error(started?.error?.message || 'Video preparation could not start.');
        // Includes the bounded two-job queue wait, not just one worker timeout.
        for (let attempt = 0; attempt < 1800; attempt += 1) {
            await sleep(pollIntervalMs);
            const polled = await fetcher(statusUrl, { headers: { Accept: 'application/json' } });
            const state = normalizeJobStatus(await responseJson(polled));
            if (!polled.ok || !state) throw new Error('Preparation expired. Analyze the URL again.');
            publish({ phase: 'preparing', stage: state.stage, percent: state.progress });
            if (state.status === 'failed') throw new Error(state.error || 'Video preparation failed.');
            if (state.status === 'ready' && state.downloadUrl && state.size) return state;
        }
        throw new Error('Preparation timed out. Analyze the URL again.');
    })();
    preparations.set(output, entry);
    return entry.promise.finally(() => entry.listeners.delete(onState));
}

export async function prepareYouTubeShare(output, onState, options = {}) {
    if (output.share_supported !== true) throw new Error('This format is available for download only.');
    const ready = await prepareYouTubeDownload(output, onState, options);
    if (ready.size > output.share.max_bytes) {
        throw new ShareMediaError('media_too_large', 'Download only · Too large for Share / Save');
    }
    return (options.prepare || prepareShareMedia)({
        ...output, delivery: 'proxy', download_url: ready.downloadUrl,
        share: { ...output.share, eligible: true, size_bytes: ready.size },
    }, onState);
}
