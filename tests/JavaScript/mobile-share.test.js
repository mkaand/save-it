import assert from 'node:assert/strict';
import test from 'node:test';

import { File } from 'node:buffer';

import { MAX_SHARE_BYTES, responseBlob, shareMedia, sharedFilename } from '../../resources/js/mobile-share.js';

test('keeps the Web Share preparation limit at 50 MiB', () => {
    assert.equal(MAX_SHARE_BYTES, 52_428_800);
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

test('prepares an MP4 once, then reports Loading and real transfer progress', async () => {
    const originalFetch = globalThis.fetch;
    const originalNavigator = globalThis.navigator;
    const originalFile = globalThis.File;
    const originalWindow = globalThis.window;
    const states = [];
    const calls = [];

    globalThis.File = File;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {
            canShare: () => true,
            share: async () => {},
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
        await shareMedia({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'video/mp4',
            label: 'Video',
        }, (state) => states.push(state));
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

test('does not surface an AbortError from a dismissed share sheet', async () => {
    const originalFetch = globalThis.fetch;
    const originalNavigator = globalThis.navigator;
    const originalFile = globalThis.File;
    const originalWindow = globalThis.window;

    globalThis.File = File;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {
            canShare: () => true,
            share: async () => { throw Object.assign(new Error('Dismissed'), { name: 'AbortError' }); },
        },
    });
    globalThis.fetch = async (url, options = {}) => {
        if (options.headers?.Range) {
            return new Response(new Uint8Array([0]), { status: 206, headers: { 'content-range': 'bytes 0-0/1' } });
        }
        return new Response(new Uint8Array([0]), { headers: { 'content-type': 'image/jpeg' } });
    };

    try {
        await assert.doesNotReject(() => shareMedia({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'image/jpeg',
            label: 'Image',
        }));
    } finally {
        globalThis.fetch = originalFetch;
        globalThis.File = originalFile;
        globalThis.window = originalWindow;
        Object.defineProperty(globalThis, 'navigator', { configurable: true, value: originalNavigator });
    }
});
