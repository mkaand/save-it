import assert from 'node:assert/strict';
import test from 'node:test';

import { File } from 'node:buffer';
import { createShareSession } from '../../resources/js/share-session.js';

import {
    canOfferMobileShare,
    isShareAbortError,
    loadPreparedBlob,
    openShareSheet,
    prepareShareMedia,
    responseBlob,
    shareErrorMessage,
    ShareMediaError,
    sharedFilename,
    streamVideoToOpfs,
    transferPercent,
} from '../../resources/js/mobile-share.js';

function preparedBlobRequest({ blob, status = 200, headers = {}, progress = [], failure = null, calls = null }) {
    return () => {
        const request = {
            open(method, url) {
                calls?.push({ method, url });
            },
            getResponseHeader(name) {
                return headers[name.toLowerCase()] || null;
            },
            send() {
                assert.equal(this.responseType, 'blob');
                if (failure) {
                    this[failure]?.();

                    return;
                }
                for (const loaded of progress) {
                    this.onprogress?.({ loaded });
                }
                this.status = status;
                this.response = blob;
                this.onload?.();
            },
        };

        return request;
    };
}

const share = (eligible = null, size = null) => ({
    eligible,
    reason: eligible === false ? 'too_large' : null,
    max_bytes: 104_857_600,
    size_bytes: size,
});

function opfsFixture({ file, failWrite = false, failClose = false, failGetFile = false, entries = [] } = {}) {
    const writes = [];
    const removed = [];
    const sessions = new Map();
    const writable = {
        async write(chunk) {
            if (failWrite) {
                throw new Error('raw write failure');
            }
            writes.push(chunk);
        },
        async close() {
            if (failClose) {
                throw new Error('raw close failure');
            }
        },
        async abort() {},
    };
    const fileHandle = {
        createWritable: async () => writable,
        getFile: async () => {
            if (failGetFile) {
                throw new Error('raw getFile failure');
            }
            return file;
        },
    };
    const tempRoot = {
        async getDirectoryHandle(name, options) {
            if (options?.create) {
                const session = { getFileHandle: async () => fileHandle, kind: 'directory' };
                sessions.set(name, session);
                return session;
            }
            return sessions.get(name);
        },
        async removeEntry(name) {
            removed.push(name);
            sessions.delete(name);
        },
        async *entries() {
            yield* entries;
        },
    };
    const root = { getDirectoryHandle: async () => tempRoot };

    return { root, writes, removed };
}

function preparedStream(chunks, type = 'video/mp4') {
    return new Response(new ReadableStream({
        start(controller) {
            chunks.forEach((chunk) => controller.enqueue(chunk));
            controller.close();
        },
    }), { status: 200, headers: { 'content-type': type } });
}

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

test('prepares a direct image with an unknown transfer size without fabricating progress', async () => {
    const originalFile = globalThis.File;
    const originalWindow = globalThis.window;
    const states = [];
    globalThis.File = File;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };

    try {
        const prepared = await prepareShareMedia({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'image/jpeg',
            label: 'Image 1 of 5',
            share: share(null),
        }, (state) => states.push(state), {
            fetcher: async (_url, options = {}) => options.headers?.Range
                ? new Response(new Uint8Array([0]), { status: 206 })
                : new Response(new ReadableStream({
                    start(controller) {
                        controller.enqueue(new Uint8Array(20));
                        controller.enqueue(new Uint8Array(30));
                        controller.close();
                    },
                }), { status: 200, headers: { 'content-type': 'image/jpeg' } }),
        });

        assert.equal(prepared.file.type, 'image/jpeg');
        assert.equal(prepared.file.size, 50);
        assert.deepEqual(states, [
            { phase: 'loading', percent: null },
            { phase: 'loading', percent: null },
        ]);
    } finally {
        globalThis.File = originalFile;
        globalThis.window = originalWindow;
    }
});

test('keeps the share limit while reading an image with an unknown transfer size', async () => {
    const response = new Response(new ReadableStream({
        start(controller) {
            controller.enqueue(new Uint8Array(3));
            controller.close();
        },
    }), { headers: { 'content-type': 'image/jpeg' } });

    await assert.rejects(
        () => responseBlob(response, null, () => {}, 2),
        (error) => error instanceof ShareMediaError && error.code === 'media_too_large',
    );
});

test('loads prepared MP4s through a native Blob response with real byte progress', async () => {
    const states = [];
    const requests = [];
    const blob = new Blob([new Uint8Array(100)], { type: 'video/mp4' });

    const loaded = await loadPreparedBlob(
        new URL('https://save.allmy.win/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        100,
        (state) => states.push(state),
        {
            requestFactory: preparedBlobRequest({
                blob,
                headers: {
                    'content-type': 'video/mp4',
                    'content-disposition': 'attachment; filename="prepared.mp4"',
                },
                progress: [0, 27, 27, 15, 100, 101],
                calls: requests,
            }),
        },
    );

    assert.equal(loaded.blob, blob);
    assert.equal(loaded.mime, 'video/mp4');
    assert.equal(loaded.disposition, 'attachment; filename="prepared.mp4"');
    assert.deepEqual(requests, [{
        method: 'GET',
        url: 'https://save.allmy.win/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    }]);
    assert.deepEqual(states, [
        { phase: 'loading', percent: 0 },
        { phase: 'loading', percent: 27 },
        { phase: 'loading', percent: 100 },
        { phase: 'preparing', stage: 'Finalizing', percent: null },
    ]);
});

test('calculates native prepared-media progress for a 30 MB class artifact without allocating it', async () => {
    const states = [];
    const size = 30_301_218;
    const blob = Object.create(Blob.prototype);
    Object.defineProperties(blob, {
        size: { value: size },
        type: { value: 'video/mp4' },
    });

    await loadPreparedBlob(
        new URL('https://save.allmy.win/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        size,
        (state) => states.push(state),
        {
            requestFactory: preparedBlobRequest({
                blob,
                headers: { 'content-type': 'video/mp4' },
                progress: [15_150_609, size],
            }),
        },
    );

    assert.deepEqual(states, [
        { phase: 'loading', percent: 0 },
        { phase: 'loading', percent: 50 },
        { phase: 'loading', percent: 100 },
        { phase: 'preparing', stage: 'Finalizing', percent: null },
    ]);
});

for (const failure of ['onerror', 'onabort', 'ontimeout']) {
test(`rejects prepared-media XHR ${failure} without exposing browser details`, async () => {
    await assert.rejects(
        () => loadPreparedBlob(
            new URL('https://save.allmy.win/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            100,
            () => {},
            { requestFactory: preparedBlobRequest({ failure }) },
        ),
        (error) => error instanceof ShareMediaError
            && error.code === 'share_unavailable'
            && error.message === 'The prepared file could not be loaded for sharing.',
    );
});
}

test('rejects prepared-media size and MIME mismatches before creating a File', async () => {
    const sizeMismatch = new Blob([new Uint8Array(99)], { type: 'video/mp4' });
    const invalidMime = new Blob([new Uint8Array(100)], { type: 'text/plain' });
    const url = new URL('https://save.allmy.win/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    await assert.rejects(
        () => loadPreparedBlob(url, 100, () => {}, {
            requestFactory: preparedBlobRequest({
                blob: sizeMismatch,
                headers: { 'content-type': 'video/mp4' },
            }),
        }),
        ShareMediaError,
    );
    await assert.rejects(
        () => loadPreparedBlob(url, 100, () => {}, {
            requestFactory: preparedBlobRequest({
                blob: invalidMime,
                headers: { 'content-type': 'text/plain' },
            }),
        }),
        ShareMediaError,
    );
});

test('streams a video directly into OPFS without retaining response chunks', async () => {
    const originalFile = globalThis.File;
    const states = [];
    const file = new File([new Uint8Array(100)], 'prepared.mp4', { type: 'video/mp4' });
    const opfs = opfsFixture({ file });
    globalThis.File = File;
    try {
        const stored = await streamVideoToOpfs(
            new URL('https://save.allmy.win/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            100,
            { filename: 'prepared.mp4' },
            (state) => states.push(state),
            {
                storageDirectoryFactory: async () => opfs.root,
                randomUuid: () => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                now: () => 1_700_000_000_000,
                fetcher: async () => preparedStream([new Uint8Array(10), new Uint8Array(20), new Uint8Array(70)]),
            },
        );

        assert.equal(stored.file, file);
        assert.deepEqual(opfs.writes.map((chunk) => chunk.byteLength), [10, 20, 70]);
        assert.deepEqual(states, [
            { phase: 'loading', percent: 0 },
            { phase: 'loading', percent: 10 },
            { phase: 'loading', percent: 30 },
            { phase: 'loading', percent: 100 },
            { phase: 'preparing', stage: 'Finalizing', percent: null },
        ]);
        await stored.cleanup();
        await stored.cleanup();
        assert.deepEqual(opfs.removed, ['session-1700000000000-aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
    } finally {
        globalThis.File = originalFile;
    }
});

test('calculates the 30 MB video progress class without allocating a binary fixture', () => {
    const size = 30_301_218;
    assert.equal(transferPercent(0, size), 0);
    assert.equal(transferPercent(15_150_609, size), 50);
    assert.equal(transferPercent(size, size), 100);
    assert.equal(transferPercent(size + 1, size), 100);
    assert.equal(transferPercent(-1, size), null);
});

test('cleans only its OPFS session after stream failures without exposing browser errors', async () => {
    const originalFile = globalThis.File;
    const file = new File([new Uint8Array(100)], 'prepared.mp4', { type: 'video/mp4' });
    const opfs = opfsFixture({ file, failWrite: true });
    globalThis.File = File;
    try {
        await assert.rejects(
            () => streamVideoToOpfs(
                new URL('https://save.allmy.win/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
                100,
                { filename: 'prepared.mp4' },
                () => {},
                {
                    storageDirectoryFactory: async () => opfs.root,
                    randomUuid: () => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
                    now: () => 1_700_000_000_000,
                    fetcher: async () => preparedStream([new Uint8Array(100)]),
                },
            ),
            (error) => error instanceof ShareMediaError && error.message === 'The prepared file could not be loaded for sharing.',
        );
        assert.deepEqual(opfs.removed, ['session-1700000000000-cccccccc-cccc-cccc-cccc-cccccccccccc']);
    } finally {
        globalThis.File = originalFile;
    }
});

test('removes only stale Save It OPFS sessions and leaves unrelated entries alone', async () => {
    const originalFile = globalThis.File;
    const now = 1_700_000_000_000;
    const file = new File([new Uint8Array(100)], 'prepared.mp4', { type: 'video/mp4' });
    const old = `session-${now - (25 * 60 * 60 * 1000)}-eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee`;
    const fresh = `session-${now - (60 * 60 * 1000)}-ffffffff-ffff-ffff-ffff-ffffffffffff`;
    const opfs = opfsFixture({
        file,
        entries: [
            [old, { kind: 'directory' }],
            [fresh, { kind: 'directory' }],
            ['unrelated-data', { kind: 'directory' }],
        ],
    });
    globalThis.File = File;
    try {
        const stored = await streamVideoToOpfs(
            new URL('https://save.allmy.win/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            100,
            { filename: 'prepared.mp4' },
            () => {},
            {
                storageDirectoryFactory: async () => opfs.root,
                randomUuid: () => '11111111-1111-1111-1111-111111111111',
                now: () => now,
                fetcher: async () => preparedStream([new Uint8Array(100)]),
            },
        );
        await stored.cleanup();
        assert.deepEqual(opfs.removed, [
            old,
            'session-1700000000000-11111111-1111-1111-1111-111111111111',
        ]);
    } finally {
        globalThis.File = originalFile;
    }
});

test('prepares an MP4 without opening the share sheet, then opens it without refetching', async () => {
    const originalFetch = globalThis.fetch;
    const originalNavigator = globalThis.navigator;
    const originalFile = globalThis.File;
    const originalWindow = globalThis.window;
    const states = [];
    const calls = [];
    const opfs = opfsFixture({ file: new File([new Uint8Array(100)], 'prepared.mp4', { type: 'video/mp4' }) });
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
                status_url: '/api/share-preparation-jobs/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            } }), { status: 202, headers: { 'content-type': 'application/json' } });
        }
        if (calls.length === 2) {
            return new Response(JSON.stringify({ data: {
                status: 'processing', stage: 'Converting', progress: 42,
            } }), { status: 200, headers: { 'content-type': 'application/json' } });
        }
        if (calls.length === 3) {
            return new Response(JSON.stringify({ data: {
                status: 'ready', url: '/api/share-preparations/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                mime_type: 'video/mp4', filename: 'prepared.mp4', progress: 100,
            } }), { status: 200, headers: { 'content-type': 'application/json' } });
        }
        if (calls.length === 4) {
            return new Response(new Uint8Array([0]), {
                status: 206,
                headers: { 'content-range': 'bytes 0-0/100' },
            });
        }
        if (calls.length === 5) {
            return preparedStream([new Uint8Array(27), new Uint8Array(73)]);
        }
        throw new Error('Unexpected prepared-media request.');
    };

    try {
        const session = createShareSession({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'video/mp4',
            label: 'Video',
            share: share(true, 82_036_850),
        }, {
            onState: (state) => states.push(state),
            prepare: (output, onState) => prepareShareMedia(output, onState, {
            pollIntervalMs: 0,
            sleep: async () => {},
            storageDirectoryFactory: async () => opfs.root,
            randomUuid: () => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            now: () => 1_700_000_000_000,
            }),
        });
        await session.activate();

        assert.equal(shareCalls, 0);
        assert.equal(session.phase, 'ready');
        assert.equal(session.prepared.file instanceof File, true);
        assert.equal(calls.length, 5);
        const opening = session.activate();
        assert.equal(shareCalls, 1);
        await opening;
        assert.equal(calls.length, 5);
        assert.deepEqual(opfs.writes.map((chunk) => chunk.byteLength), [27, 73]);
        assert.deepEqual(opfs.removed, ['session-1700000000000-dddddddd-dddd-dddd-dddd-dddddddddddd']);
    } finally {
        globalThis.fetch = originalFetch;
        globalThis.File = originalFile;
        globalThis.window = originalWindow;
        Object.defineProperty(globalThis, 'navigator', { configurable: true, value: originalNavigator });
    }

    assert.equal(calls.filter(([url]) => url === '/api/share-preparations').length, 1);
    assert.deepEqual(states, [
        { phase: 'preparing', stage: 'Queued', percent: null },
        { phase: 'preparing', stage: 'Converting', percent: 42 },
        { phase: 'loading', percent: null },
        { phase: 'loading', percent: 0 },
        { phase: 'loading', percent: 27 },
        { phase: 'loading', percent: 100 },
        { phase: 'preparing', stage: 'Finalizing', percent: null },
        { phase: 'ready' },
        { phase: 'sharing' },
        { phase: 'idle' },
    ]);
});

test('uses OPFS for a direct small WebM video and opens it only on the second activation', async () => {
    const originalFile = globalThis.File;
    const originalNavigator = globalThis.navigator;
    const originalWindow = globalThis.window;
    const states = [];
    const calls = [];
    const opfs = opfsFixture({ file: new File([new Uint8Array(100)], 'direct.webm', { type: 'video/webm' }) });
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
    const fetcher = async (url, options = {}) => {
        calls.push([url instanceof URL ? url.pathname : url, options]);
        if (options.headers?.Range) {
            return new Response(new Uint8Array([0]), {
                status: 206,
                headers: { 'content-range': 'bytes 0-0/100' },
            });
        }

        return preparedStream([new Uint8Array(10), new Uint8Array(90)], 'video/webm');
    };

    try {
        const session = createShareSession({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'video/webm',
            label: 'Direct video',
            share: share(true, 3_000_000),
        }, {
            onState: (state) => states.push(state),
            prepare: (output, onState) => prepareShareMedia(output, onState, {
                storageDirectoryFactory: async () => opfs.root,
                randomUuid: () => '99999999-9999-9999-9999-999999999999',
                now: () => 1_700_000_000_000,
                fetcher,
            }),
        });

        await session.activate();
        assert.equal(session.phase, 'ready');
        assert.equal(shareCalls, 0);
        assert.deepEqual(opfs.writes.map((chunk) => chunk.byteLength), [10, 90]);
        assert.equal(calls.filter(([url]) => url === '/api/share-preparations').length, 0);

        await session.activate();
        assert.equal(shareCalls, 1);
        assert.equal(calls.length, 2);
        assert.deepEqual(opfs.removed, ['session-1700000000000-99999999-9999-9999-9999-999999999999']);
        assert.deepEqual(states, [
            { phase: 'loading', percent: null },
            { phase: 'loading', percent: 0 },
            { phase: 'loading', percent: 10 },
            { phase: 'loading', percent: 100 },
            { phase: 'preparing', stage: 'Finalizing', percent: null },
            { phase: 'ready' },
            { phase: 'sharing' },
            { phase: 'idle' },
        ]);
    } finally {
        globalThis.File = originalFile;
        globalThis.window = originalWindow;
        Object.defineProperty(globalThis, 'navigator', { configurable: true, value: originalNavigator });
    }
});

test('uses OPFS for a video with an unknown total and keeps progress indeterminate', async () => {
    const originalFile = globalThis.File;
    const originalWindow = globalThis.window;
    const states = [];
    const opfs = opfsFixture({ file: new File([new Uint8Array(100)], 'unknown.webm', { type: 'video/webm' }) });

    globalThis.File = File;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };
    try {
        const prepared = await prepareShareMedia({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'video/webm',
            label: 'Unknown total',
            share: share(true, 90_000_000),
        }, (state) => states.push(state), {
            storageDirectoryFactory: async () => opfs.root,
            randomUuid: () => 'abababab-abab-abab-abab-abababababab',
            now: () => 1_700_000_000_000,
            fetcher: async (_url, options = {}) => options.headers?.Range
                ? new Response(new Uint8Array([0]), { status: 206 })
                : preparedStream([new Uint8Array(40), new Uint8Array(60)], 'video/webm'),
        });

        assert.equal(prepared.file.size, 100);
        assert.deepEqual(opfs.writes.map((chunk) => chunk.byteLength), [40, 60]);
        assert.deepEqual(states, [
            { phase: 'loading', percent: null },
            { phase: 'loading', percent: null },
            { phase: 'preparing', stage: 'Finalizing', percent: null },
        ]);
        await prepared.cleanup();
    } finally {
        globalThis.File = originalFile;
        globalThis.window = originalWindow;
    }
});

test('uses the existing Blob fallback for direct video only when OPFS is unavailable', async () => {
    const originalFile = globalThis.File;
    const originalWindow = globalThis.window;
    const states = [];
    let requests = 0;

    globalThis.File = File;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };
    try {
        const prepared = await prepareShareMedia({
            delivery: 'proxy',
            download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mime_type: 'video/webm',
            label: 'Fallback video',
            share: share(true, 3_000_000),
        }, (state) => states.push(state), {
            storageDirectoryFactory: async () => null,
            fetcher: async (_url, options = {}) => {
                requests += 1;
                return options.headers?.Range
                    ? new Response(new Uint8Array([0]), { status: 206, headers: { 'content-range': 'bytes 0-0/100' } })
                    : preparedStream([new Uint8Array(100)], 'video/webm');
            },
        });

        assert.equal(prepared.file.type, 'video/webm');
        assert.equal(prepared.file.size, 100);
        assert.equal(requests, 2);
        assert.deepEqual(states, [
            { phase: 'loading', percent: null },
            { phase: 'loading', percent: 0 },
            { phase: 'loading', percent: 100 },
        ]);
    } finally {
        globalThis.File = originalFile;
        globalThis.window = originalWindow;
    }
});

test('keeps the 100 MiB video share limit before OPFS streaming begins', async () => {
    const originalWindow = globalThis.window;
    globalThis.window = { location: { origin: 'https://save.allmy.win' } };
    try {
        await assert.rejects(
            () => prepareShareMedia({
                delivery: 'proxy',
                download_url: '/api/downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                mime_type: 'video/webm',
                share: share(true, 104_857_601),
            }, () => {}, {
                storageDirectoryFactory: async () => {
                    throw new Error('OPFS must not be opened for oversized media');
                },
                fetcher: async () => new Response(new Uint8Array([0]), {
                    status: 206,
                    headers: { 'content-range': 'bytes 0-0/104857601' },
                }),
            }),
            (error) => error instanceof ShareMediaError && error.code === 'media_too_large',
        );
    } finally {
        globalThis.window = originalWindow;
    }
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
