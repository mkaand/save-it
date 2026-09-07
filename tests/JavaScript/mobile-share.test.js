import assert from 'node:assert/strict';
import test from 'node:test';

import { sharedFilename } from '../../resources/js/mobile-share.js';

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
