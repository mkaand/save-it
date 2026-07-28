import assert from 'node:assert/strict';
import test from 'node:test';

import {
    instagramAssetLabel,
    normalizeInstagramAssets,
    safeInstagramMediaUrl,
} from '../../resources/js/instagram-result.js';

const imageUrl = 'https://scontent-lhr8-1.cdninstagram.com/v/image.jpg?token=temporary';

test('normalizes Instagram image and reel metadata without persistence payloads', () => {
    const assets = normalizeInstagramAssets([
        {
            id: 'asset-1',
            order: 1,
            type: 'image',
            url: imageUrl,
            thumbnail_url: imageUrl,
            width: 1080,
            height: 1350,
            variants: [],
        },
        {
            id: 'asset-2',
            order: 2,
            type: 'video',
            url: 'https://scontent-lhr8-1.cdninstagram.com/o1/video.mp4',
            thumbnail_url: imageUrl,
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

test('corrupt asset data is dropped without throwing', () => {
    assert.deepEqual(normalizeInstagramAssets(null), []);
    assert.deepEqual(
        normalizeInstagramAssets([{ type: 'video', url: 'javascript:alert(1)' }]),
        [],
    );
});
