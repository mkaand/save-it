import assert from 'node:assert/strict';
import test from 'node:test';

import {
    normalizeXAssets,
    safeResultImageUrl,
    safeXMediaUrl,
    xAssetLabel,
} from '../../resources/js/x-result.js';

const previewUrl = `/api/downloads/${'a'.repeat(48)}.${'b'.repeat(64)}`;

function video(overrides = {}) {
    return {
        id: 'asset-1',
        order: 1,
        type: 'video',
        url: 'https://video.twimg.com/path/1280x720/video.mp4?tag=1',
        preview_url: previewUrl,
        width: 1280,
        height: 720,
        variants: [{
            url: 'https://video.twimg.com/path/1280x720/video.mp4?tag=1',
            mime_type: 'video/mp4',
            quality_label: '1280×720',
            bitrate: 2176000,
            is_preferred: true,
        }],
        ...overrides,
    };
}

test('normalizes X video metadata without retaining download URLs in storage data', () => {
    const assets = normalizeXAssets([video()]);

    assert.equal(assets.length, 1);
    assert.equal(assets[0].type, 'video');
    assert.equal(assets[0].variants[0].qualityLabel, '1280×720');
    assert.equal(xAssetLabel(assets[0]), 'Video · 1280×720');
    assert.equal(assets[0].url, null);
    assert.equal(assets[0].thumbnailUrl, previewUrl);
    assert.equal('url' in assets[0].variants[0], false);
});

test('supports image carousel and mixed media ordering', () => {
    const assets = normalizeXAssets([
        video(),
        {
            id: 'asset-2',
            order: 2,
            type: 'image',
            url: 'https://pbs.twimg.com/media/image.jpg?name=orig',
            preview_url: previewUrl,
            width: 1200,
            height: 800,
            variants: [],
        },
    ]);

    assert.deepEqual(assets.map((asset) => asset.type), ['video', 'image']);
    assert.deepEqual(assets.map((asset) => asset.order), [1, 2]);
    assert.equal(xAssetLabel(assets[1]), 'Image · 1200×800');
});

test('renders animated GIF transport semantics as metadata', () => {
    const [asset] = normalizeXAssets([video({ type: 'animated_gif' })]);

    assert.equal(xAssetLabel(asset), 'Animated GIF · 1280×720');
});

test('rejects attacker suffixes, custom ports, and non-HTTPS media URLs', () => {
    assert.equal(safeXMediaUrl('https://video.twimg.com/path/video.mp4'), 'https://video.twimg.com/path/video.mp4');
    assert.equal(safeXMediaUrl('https://video.twimg.com.evil.example/video.mp4'), null);
    assert.equal(safeXMediaUrl('https://video.twimg.com:8443/video.mp4'), null);
    assert.equal(safeXMediaUrl('http://video.twimg.com/video.mp4'), null);
    assert.equal(safeResultImageUrl('https://pbs.twimg.com/image.jpg'), 'https://pbs.twimg.com/image.jpg');
    assert.equal(safeResultImageUrl(`/api/previews/${'a'.repeat(48)}`), `/api/previews/${'a'.repeat(48)}`);
    assert.equal(safeResultImageUrl(previewUrl), previewUrl);
});

test('drops malformed and unsupported assets without throwing', () => {
    assert.deepEqual(normalizeXAssets(null), []);
    const [sanitizedVideo] = normalizeXAssets([{
        type: 'video',
        url: 'javascript:alert(1)',
    }]);
    assert.equal(sanitizedVideo.url, null);
    assert.equal(sanitizedVideo.thumbnailUrl, null);
    assert.deepEqual(normalizeXAssets([{
        type: 'image',
        thumbnail_url: 'https://pbs.twimg.com/image.jpg',
    }]), []);
});
