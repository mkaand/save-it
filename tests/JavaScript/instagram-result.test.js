import assert from 'node:assert/strict';
import test from 'node:test';

import {
    instagramAssetLabel,
    normalizeInstagramAssets,
    safeInstagramMediaUrl,
} from '../../resources/js/instagram-result.js';

const imageUrl = 'https://scontent-lhr8-1.cdninstagram.com/v/image.jpg?token=temporary';
const previewUrl = `/api/downloads/${'c'.repeat(48)}.${'d'.repeat(64)}`;

test('normalizes Instagram image and reel metadata without persistence payloads', () => {
    const assets = normalizeInstagramAssets([
        {
            id: 'asset-1',
            order: 1,
            type: 'image',
            url: imageUrl,
            preview_url: previewUrl,
            width: 1080,
            height: 1350,
            variants: [],
        },
        {
            id: 'asset-2',
            order: 2,
            type: 'video',
            url: 'https://scontent-lhr8-1.cdninstagram.com/o1/video.mp4',
            preview_url: previewUrl,
            width: 1080,
            height: 1920,
            variants: [{
                url: 'https://scontent-lhr8-1.cdninstagram.com/o1/video.mp4',
                mime_type: 'video/mp4',
                quality_label: '1080×1920',
                is_preferred: true,
            }],
        },
    ]);

    assert.deepEqual(assets.map((asset) => asset.type), ['image', 'video']);
    assert.equal(instagramAssetLabel(assets[0]), 'Image · 1080×1350');
    assert.equal(instagramAssetLabel(assets[1]), 'Reel / video · 1080×1920');
    assert.equal('downloadUrl' in assets[1], false);
    assert.equal(assets[0].thumbnailUrl, previewUrl);
    assert.equal(assets[1].url, null);
});

test('rejects attacker suffixes, custom ports, and non-HTTPS URLs', () => {
    assert.equal(safeInstagramMediaUrl(imageUrl), imageUrl);
    assert.equal(
        safeInstagramMediaUrl('https://cdninstagram.com.evil.example/image.jpg'),
        null,
    );
    assert.equal(
        safeInstagramMediaUrl('https://scontent.cdninstagram.com:8443/image.jpg'),
        null,
    );
    assert.equal(
        safeInstagramMediaUrl('http://scontent.cdninstagram.com/image.jpg'),
        null,
    );
});

test('accepts opaque same-origin persistent preview references', () => {
    const preview = `/api/previews/${'c'.repeat(48)}`;
    assert.equal(normalizeInstagramAssets([{
        id: 'image', order: 1, type: 'image', preview_url: preview, width: 1, height: 1, variants: [],
    }])[0].thumbnailUrl, preview);
});

test('corrupt asset data is dropped without throwing', () => {
    assert.deepEqual(normalizeInstagramAssets(null), []);
    const [sanitizedVideo] = normalizeInstagramAssets([{
        type: 'video',
        url: 'javascript:alert(1)',
    }]);
    assert.equal(sanitizedVideo.url, null);
    assert.equal(sanitizedVideo.thumbnailUrl, null);
    assert.deepEqual(
        normalizeInstagramAssets([{ type: 'image', thumbnail_url: imageUrl }]),
        [],
    );
});
