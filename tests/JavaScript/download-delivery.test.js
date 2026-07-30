import assert from 'node:assert/strict';
import test from 'node:test';

import {
    normalizeDownloadDelivery,
    normalizeJobStart,
    normalizeJobStatus,
} from '../../resources/js/download-delivery.js';

const token = `${'a'.repeat(48)}.${'b'.repeat(64)}`;

test('accepts only same-origin signed proxy and job delivery references', () => {
    assert.deepEqual(normalizeDownloadDelivery({
        available: true,
        delivery: 'proxy',
        download_url: `/api/downloads/${token}`,
    }), {
        type: 'proxy',
        url: `/api/downloads/${token}`,
    });
    assert.deepEqual(normalizeDownloadDelivery({
        available: true,
        delivery: 'job',
        job_url: '/api/download-jobs',
        job_token: token,
    }), {
        type: 'job',
        url: '/api/download-jobs',
        token,
    });
    assert.equal(normalizeDownloadDelivery({
        available: true,
        delivery: 'proxy',
        download_url: 'https://video.twimg.com/private.mp4',
    }), null);
});

test('accepts only same-origin UUID job status paths', () => {
    assert.equal(normalizeJobStart({
        data: { status_url: '/api/download-jobs/123e4567-e89b-12d3-a456-426614174000' },
    }), '/api/download-jobs/123e4567-e89b-12d3-a456-426614174000');
    assert.equal(normalizeJobStart({
        data: { status_url: 'https://example.com/job' },
    }), null);
});

test('normalizes bounded progress and rejects unsafe ready links', () => {
    assert.deepEqual(normalizeJobStatus({
        data: {
            status: 'processing',
            stage: 'Merging',
            progress: 70,
            download_url: null,
        },
    }), {
        status: 'processing',
        stage: 'Merging',
        progress: 70,
        downloadUrl: null,
        error: null,
    });
    assert.equal(normalizeJobStatus({
        data: {
            status: 'ready',
            stage: 'Ready',
            progress: 100,
            download_url: 'https://example.com/file',
        },
    })?.downloadUrl, null);
});
