import assert from 'node:assert/strict';
import test from 'node:test';
import { prepareYouTubeDownload, prepareYouTubeShare } from '../../resources/js/youtube-preparation.js';
import { createShareSession } from '../../resources/js/share-session.js';

const token = `${'a'.repeat(48)}.${'b'.repeat(64)}`;
const statusUrl = '/api/download-jobs/123e4567-e89b-12d3-a456-426614174000';
const downloadUrl = `/api/downloads/${token}`;
const output = () => ({
    available: true, preparation: 'youtube_mux', delivery: 'job', job_url: '/api/download-jobs', job_token: token,
    mime_type: 'video/mp4', share_supported: true, share: { eligible: null, max_bytes: 104857600 },
});
const response = (data) => new Response(JSON.stringify({ data }), { headers: { 'content-type': 'application/json' } });

test('both actions reuse one artifact; real phases survive and Share waits for a trusted second activation', async () => {
    const media = output();
    const states = [];
    let postCount = 0;
    let staged = 0;
    let shared = 0;
    const queue = [
        ['processing', 'Downloading video', null],
        ['processing', 'Downloading video', 42],
        ['processing', 'Downloading audio', 80],
        ['processing', 'Merging', null],
        ['processing', 'Finalizing', null],
        ['ready', 'Ready', 100],
    ];
    const options = {
        sleep: async () => {},
        fetcher: async (url, init) => {
            if (init.method === 'POST') {
                postCount += 1;
                assert.deepEqual(JSON.parse(init.body), { token });
                return response({ status_url: statusUrl });
            }
            assert.equal(url, statusUrl);
            const [status, stage, progress] = queue.shift();
            return response({ status, stage, progress, size: 123, download_url: downloadUrl });
        },
        prepare: async (proxy) => {
            staged += 1;
            assert.equal(proxy.download_url, downloadUrl);
            assert.equal(proxy.delivery, 'proxy');
            assert.equal(proxy.share.max_bytes, 104857600);
            return { file: 'prepared-file' };
        },
    };
    const session = createShareSession(media, {
        prepare: (value, state) => prepareYouTubeShare(value, state, options),
        open: (prepared) => { assert.equal(prepared.file, 'prepared-file'); shared += 1; },
        onState: (state) => states.push(state),
    });
    await Promise.all([prepareYouTubeDownload(media, () => {}, options), session.activate()]);
    assert.equal(postCount, 1);
    assert.equal(staged, 1);
    assert.equal(shared, 0);
    assert.equal(session.phase, 'ready');
    assert.ok(states.some((s) => s.stage === 'Downloading video' && s.percent === null));
    assert.ok(states.some((s) => s.stage === 'Downloading video' && s.percent === 42));
    assert.ok(states.some((s) => s.stage === 'Merging' && s.percent === null));
    await session.activate();
    assert.equal(shared, 1);
    assert.equal((await prepareYouTubeDownload(media, () => {}, options)).downloadUrl, downloadUrl);
    assert.equal(postCount, 1);
});

test('over-100MiB artifact remains downloadable but never enters share staging', async () => {
    const media = output();
    let requests = 0;
    const options = { sleep: async () => {}, fetcher: async () => {
        requests += 1;
        return requests === 1 ? response({ status_url: statusUrl }) : response({
            status: 'ready', stage: 'Ready', progress: 100, size: 104857601, download_url: downloadUrl,
        });
    }, prepare: () => assert.fail('Oversized artifact was staged') };
    await assert.rejects(prepareYouTubeShare(media, () => {}, options), { code: 'media_too_large' });
    assert.equal((await prepareYouTubeDownload(media, () => {}, options)).downloadUrl, downloadUrl);
    assert.equal(requests, 2);
});

test('unsupported containers never offer a fake share path; failures do not create prepared state or loop', async () => {
    await assert.rejects(prepareYouTubeShare({ ...output(), share_supported: false }), /download only/);
    const media = output();
    let requests = 0;
    const options = { sleep: async () => {}, fetcher: async () => {
        requests += 1;
        return requests === 1 ? response({ status_url: statusUrl }) : response({
            status: 'failed', stage: 'Failed', progress: null, error: { message: 'Source expired' },
        });
    } };
    await assert.rejects(prepareYouTubeDownload(media, () => {}, options), /Source expired/);
    await assert.rejects(prepareYouTubeDownload(media, () => {}, options), /Source expired/);
    assert.equal(requests, 2);
});
