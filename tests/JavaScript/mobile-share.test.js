import assert from 'node:assert/strict';
import test from 'node:test';

import { File } from 'node:buffer';

import {
    canOfferMobileShare,
    isShareAbortError,
    openShareSheet,
    prepareShareMedia,
    responseBlob,
    shareErrorMessage,
    ShareMediaError,
    sharedFilename,
} from '../../resources/js/mobile-share.js';

const share = (eligible = null, size = null) => ({
    eligible,
    reason: eligible === false ? 'too_large' : null,
    max_bytes: 104_857_600,
    size_bytes: size,
});

test('adds a trusted media extension to an extensionless video label', () => {
    assert.equal(sharedFilename({ label: '720p MP4' }, 'video/mp4'), '720p MP4.mp4');
});

test('uses trusted MIME mappings for image and future audio sharing', () => {
    assert.equal(sharedFilename({ label: 'Original image' }, 'image/jpeg'), 'Original image.jpg');
    assert.equal(sharedFilename({ label: 'Preview' }, 'image/png'), 'Preview.png');
    assert.equal(sharedFilename({ label: 'Preview' }, 'image/webp'), 'Preview.webp');
    assert.equal(sharedFilename({ label: 'Audio' }, 'audio/m4a'), 'Audio.m4a');
    assert.equal(sharedFilename({ label: 'Audio' }, 'audio/mpeg'), 'Audio.mp3');
});

test('uses a safe Content-Disposition filename without duplicate extensions', () => {
    assert.equal(
        sharedFilename({ label: 'Ignored' }, 'video/mp4', "attachment; filename*=UTF-8''safe%20video.mp4"),
        'safe video.mp4',
    );
    assert.equal(
        sharedFilename({ label: 'Ignored' }, 'image/jpeg', 'inline; filename="../../image.jpg"'),
        'image.jpg',
    );
});

test('sanitizes control characters and path separators in fallback labels', () => {
    assert.equal(sharedFilename({ label: 'bad/\\name\r\n' }, 'image/png'), 'bad-name.png');
});

test('keeps the safe MP4 extension when a prepared Share file supplies a filename', () => {
    assert.equal(sharedFilename({ filename: 'Rick Astley.mp4' }, 'video/mp4'), 'Rick Astley.mp4');
});

test('reports actual stream transfer progress from downloaded bytes', async () => {
    const states = [];
    const response = new Response(new ReadableStream({
        start(controller) {
            controller.enqueue(new Uint8Array(27));
            controller.enqueue(new Uint8Array(54));
            controller.enqueue(new Uint8Array(19));
            controller.close();
        },
    }), { headers: { 'content-type': 'video/mp4' } });

    const blob = await responseBlob(response, 100, (state) => states.push(state));

    assert.equal(blob.size, 100);
    assert.deepEqual(states, [
        { phase: 'loading', percent: 0 },
        { phase: 'loading', percent: 27 },
        { phase: 'loading', percent: 81 },
        { phase: 'loading', percent: 100 },
    ]);
});

test('uses Loading without a percentage when stream reading is unavailable', async () => {
    const states = [];
    const blob = new Blob(['media'], { type: 'video/mp4' });
    const response = {
        body: null,
        blob: async () => blob,
    };

    assert.equal(await responseBlob(response, 5, (state) => states.push(state)), blob);
    assert.deepEqual(states, [{ phase: 'loading', percent: null }]);
});

test('prepares an MP4 without opening the share sheet, then opens it without refetching', async () => {
    const originalFetch = globalThis.fetch;
    const originalNavigator = globalThis.navigator;
    const originalFile = globalThis.File;
    const originalWindow = globalThis.window;
    const states = [];
    const calls = [];
    let shareCalls = 0;

    globalThis.File = File;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {
            canShare: () => true,
            share: () => {
                shareCalls += 1;
                return Promise.resolve();
            },
        },
    });
    globalThis.fetch = async (url, options = {}) => {
        calls.push([url instanceof URL ? url.pathname : url, options]);
        if (calls.length === 1) {
            return new Response(JSON.stringify({ data: {
                url: '/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                mime_type: 'video/mp4',
                filename: 'prepared.mp4',
            } }), { status: 200, headers: { 'content-type': 'application/json' } });
        }
        if (calls.length === 2) {
            return new Response(new Uint8Array([0]), {
                status: 206,
                headers: { 'content-range': 'bytes 0-0/100' },
            });
        }
        return new Response(new ReadableStream({
            start(controller) {
                controller.enqueue(new Uint8Array(27));
                controller.enqueue(new Uint8Array(73));
                controller.close();
            },
        }), { headers: { 'content-type': 'video/mp4' } });
    };

    try {
        const prepared = await prepareShareMedia({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'video/mp4',
            label: 'Video',
            share: share(true, 82_036_850),
        }, (state) => states.push(state));

        assert.equal(shareCalls, 0);
        assert.equal(prepared.file instanceof File, true);
        assert.equal(calls.length, 3);
        const opening = openShareSheet(prepared);
        assert.equal(shareCalls, 1);
        await opening;
    } finally {
        globalThis.fetch = originalFetch;
        globalThis.File = originalFile;
        globalThis.window = originalWindow;
        Object.defineProperty(globalThis, 'navigator', { configurable: true, value: originalNavigator });
    }

    assert.equal(calls.filter(([url]) => url === '/api/share-preparations').length, 1);
    assert.deepEqual(states, [
        { phase: 'preparing' },
        { phase: 'loading', percent: null },
        { phase: 'loading', percent: 0 },
        { phase: 'loading', percent: 27 },
        { phase: 'loading', percent: 100 },
    ]);
});

test('keeps a prepared image usable after an AbortError without refetching', async () => {
    const originalFetch = globalThis.fetch;
    const originalNavigator = globalThis.navigator;
    const originalFile = globalThis.File;
    const originalWindow = globalThis.window;
    let fetches = 0;
    let shareAttempts = 0;

    globalThis.File = File;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {
            canShare: () => true,
            share: () => {
                shareAttempts += 1;
                return shareAttempts === 1
                    ? Promise.reject(Object.assign(new Error('Dismissed'), { name: 'AbortError' }))
                    : Promise.resolve();
            },
        },
    });
    globalThis.fetch = async (url, options = {}) => {
        fetches += 1;
        if (options.headers?.Range) {
            return new Response(new Uint8Array([0]), { status: 206, headers: { 'content-range': 'bytes 0-0/1' } });
        }
        return new Response(new Uint8Array([0]), { headers: { 'content-type': 'image/jpeg' } });
    };

    try {
        const prepared = await prepareShareMedia({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'image/jpeg',
            label: 'Image',
            share: share(null),
        });
        assert.equal(fetches, 2);
        await assert.rejects(() => openShareSheet(prepared), isShareAbortError);
        assert.equal(shareErrorMessage(Object.assign(new Error('Dismissed'), { name: 'AbortError' })), null);
        await openShareSheet(prepared);
        assert.equal(fetches, 2);
        assert.equal(shareAttempts, 2);
    } finally {
        globalThis.fetch = originalFetch;
        globalThis.File = originalFile;
        globalThis.window = originalWindow;
        Object.defineProperty(globalThis, 'navigator', { configurable: true, value: originalNavigator });
    }
});

test('uses server eligibility to keep an 82 MiB MP4 shareable and a known 110 MiB asset download-only', () => {
    const originalNavigator = globalThis.navigator;
    const originalWindow = globalThis.window;
    globalThis.window = {
        location: { origin: 'https://save.allmy.win' },
        matchMedia: () => ({ matches: true }),
    };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { share: async () => {} },
    });

    try {
        const output = {
            available: true,
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        };
        assert.equal(canOfferMobileShare({ ...output, share: share(true, 82_036_850) }), true);
        assert.equal(canOfferMobileShare({ ...output, share: share(true, 28_413_689) }), true);
        assert.equal(canOfferMobileShare({ ...output, share: share(false, 115_343_360) }), false);
    } finally {
        globalThis.window = originalWindow;
        Object.defineProperty(globalThis, 'navigator', { configurable: true, value: originalNavigator });
    }
});

test('preserves the safe media_too_large preparation error instead of replacing it with a generic error', async () => {
    const originalFetch = globalThis.fetch;
    const originalWindow = globalThis.window;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };
    globalThis.fetch = async () => new Response(JSON.stringify({ error: {
        code: 'media_too_large',
        message: 'The prepared download exceeds the size limit.',
    } }), { status: 413, headers: { 'content-type': 'application/json' } });

    try {
        await assert.rejects(
            () => prepareShareMedia({
                delivery: 'proxy',
                download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                mime_type: 'video/mp4',
                share: share(null),
            }),
            (error) => error instanceof ShareMediaError
                && error.code === 'media_too_large'
                && error.message === 'The prepared download exceeds the size limit.'
                && error.status === 413,
        );
    } finally {
        globalThis.fetch = originalFetch;
        globalThis.window = originalWindow;
    }
});

test('maps browser share exceptions to safe retry messages without exposing raw details', () => {
    const notAllowed = Object.assign(
        new Error('The request is not allowed by the user agent or the platform in the current context.'),
        { name: 'NotAllowedError' },
    );

    assert.equal(shareErrorMessage(notAllowed), 'Tap Share / Save again to open the share sheet.');
    assert.equal(shareErrorMessage(Object.assign(new Error('raw security detail'), { name: 'SecurityError' })), 'The share sheet could not be opened. Please try again.');
    assert.equal(shareErrorMessage(new Error('raw unknown detail')), 'Sharing could not be prepared.');
});
