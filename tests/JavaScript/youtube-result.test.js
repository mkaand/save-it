import assert from 'node:assert/strict';
import test from 'node:test';

import {
    formatDuration,
    normalizeYouTubeAudioFormats,
    normalizeYouTubeVideoFormats,
    youtubeAudioFormatLabel,
    youtubeVideoFormatLabel,
} from '../../resources/js/youtube-result.js';

test('normalizes YouTube video formats without media URLs', () => {
    const formats = normalizeYouTubeVideoFormats([
        {
            format_id: '137',
            container: 'mp4',
            video_codec: 'avc1.640028',
            video_codec_family: 'h264',
            resolution: '1920×1080',
            fps: 30,
            bitrate_kbps: 2500,
            estimated_filesize: 55000000,
            has_audio: false,
            requires_merge: true,
        },
        {
            format_id: '../unsafe',
            container: 'mp4',
            video_codec_family: 'h264',
        },
    ]);

    assert.equal(formats.length, 1);
    assert.equal(formats[0].formatId, '137');
    assert.equal(formats[0].requiresMerge, true);
    assert.equal('url' in formats[0], false);
    assert.match(youtubeVideoFormatLabel(formats[0]), /MP4 1920×1080 · H\.264/);
    assert.match(youtubeVideoFormatLabel(formats[0]), /video and audio will be merged/);
});

test('normalizes M4A audio and renders bitrate', () => {
    const formats = normalizeYouTubeAudioFormats([
        {
            format_id: '140',
            container: 'm4a',
            audio_codec: 'mp4a.40.2',
            bitrate_kbps: 129,
        },
    ]);

    assert.equal(formats.length, 1);
    assert.equal(formats[0].container, 'm4a');
    assert.match(youtubeAudioFormatLabel(formats[0]), /M4A · AAC-LC · 129 kbps/);
});

test('formats durations without throwing on missing data', () => {
    assert.equal(formatDuration(212000), '3:32');
    assert.equal(formatDuration(3723000), '1:02:03');
    assert.equal(formatDuration(null), 'Duration unavailable');
});
