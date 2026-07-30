import assert from 'node:assert/strict';
import test from 'node:test';

import {
    linkedinAssetLabel,
    linkedinAuthor,
    normalizeLinkedInAssets,
    safeLinkedInMediaUrl,
} from '../../resources/js/linkedin-result.js';

const imageUrl = 'https://media.licdn.com/dms/image/example';
const previewUrl = `/api/downloads/${'e'.repeat(48)}.${'f'.repeat(64)}`;

test('normalizes LinkedIn image, video, and carousel metadata', () => {
    const assets = normalizeLinkedInAssets([
        {
            id: 'asset-1',
            order: 1,
            type: 'image',
            url: imageUrl,
            preview_url: previewUrl,
            width: 1200,
            height: 627,
        },
        {
            id: 'asset-2',
            order: 2,
            type: 'video',
            url: 'https://dms.licdn.com/playlist/vid/example',
            preview_url: previewUrl,
            duration_ms: 12000,
            variants: [
                {
                    url: 'https://dms.licdn.com/video/mp4-720p-30fp/source',
                    mime_type: 'video/mp4',
                    quality_label: '720p MP4',
                    bitrate: 979082,
                    is_preferred: true,
                },
                {
                    url: 'https://dms.licdn.com/video/mp4-640p-30fp/source',
                    mime_type: 'video/mp4',
                    quality_label: '640p MP4',
                    bitrate: 750872,
                    is_preferred: false,
                },
            ],
        },
    ]);

    assert.deepEqual(assets.map((asset) => asset.type), ['image', 'video']);
    assert.equal(linkedinAssetLabel(assets[0]), 'Image · 1200×627');
    assert.equal(linkedinAssetLabel(assets[1]), 'Native video');
    assert.deepEqual(
        assets[1].variants.map((variant) => variant.qualityLabel),
        ['720p MP4', '640p MP4'],
    );
    assert.equal('downloadUrl' in assets[1], false);
    assert.equal(assets[0].thumbnailUrl, previewUrl);
    assert.equal(assets[1].variants[0].url, null);
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
    assert.deepEqual(normalizeLinkedInAssets([{ type: 'image', thumbnail_url: imageUrl }]), []);
});
