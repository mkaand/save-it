import assert from 'node:assert/strict';
import test from 'node:test';

import {
    linkedinAssetLabel,
    linkedinAuthor,
    normalizeLinkedInAssets,
    safeLinkedInMediaUrl,
} from '../../resources/js/linkedin-result.js';

const imageUrl = 'https://media.licdn.com/dms/image/example';

test('normalizes LinkedIn image, video, and carousel metadata', () => {
    const assets = normalizeLinkedInAssets([
        {
            id: 'asset-1',
            order: 1,
            type: 'image',
            url: imageUrl,
            thumbnail_url: imageUrl,
            width: 1200,
            height: 627,
        },
        {
            id: 'asset-2',
            order: 2,
            type: 'video',
            url: 'https://dms.licdn.com/playlist/vid/example',
            thumbnail_url: imageUrl,
            duration_ms: 12000,
        },
    ]);

    assert.deepEqual(assets.map((asset) => asset.type), ['image', 'video']);
    assert.equal(linkedinAssetLabel(assets[0]), 'Image · 1200×627');
    assert.equal(linkedinAssetLabel(assets[1]), 'Native video');
    assert.equal('downloadUrl' in assets[1], false);
});

test('rejects unsafe LinkedIn media URL variants', () => {
    assert.equal(safeLinkedInMediaUrl(imageUrl), imageUrl);
    assert.equal(safeLinkedInMediaUrl('https://licdn.com.evil.example/image'), null);
    assert.equal(safeLinkedInMediaUrl('https://media.licdn.com:8443/image'), null);
    assert.equal(safeLinkedInMediaUrl('http://media.licdn.com/image'), null);
    assert.equal(safeLinkedInMediaUrl('javascript:alert(1)'), null);
});

test('handles missing metadata and corrupt assets without throwing', () => {
    assert.equal(linkedinAuthor(null), null);
    assert.equal(linkedinAuthor({ author_name: ' Example Organization ' }), 'Example Organization');
    assert.deepEqual(normalizeLinkedInAssets(null), []);
    assert.deepEqual(normalizeLinkedInAssets([{ type: 'image', url: 'https://example.com/image' }]), []);
});
