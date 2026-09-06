const FORMAT_ID = /^[A-Za-z0-9._+-]{1,100}$/;

function positiveNumber(value) {
    return typeof value === 'number' && Number.isFinite(value) && value > 0
        ? value
        : null;
}

export function formatDuration(durationMs) {
    const duration = positiveNumber(durationMs);

    if (!duration) {
        return 'Duration unavailable';
    }

    const seconds = Math.floor(duration / 1000);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remaining = seconds % 60;

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${String(remaining).padStart(2, '0')}`
        : `${minutes}:${String(remaining).padStart(2, '0')}`;
}

export function normalizeYouTubeVideoFormats(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.slice(0, 30).flatMap((format) => {
        if (
            !format
            || typeof format !== 'object'
            || typeof format.format_id !== 'string'
            || !FORMAT_ID.test(format.format_id)
            || !['mp4', 'webm'].includes(format.container)
            || !['h264', 'h265', 'other'].includes(format.video_codec_family)
        ) {
            return [];
        }

        return [{
            formatId: format.format_id,
            container: format.container,
            codec: typeof format.video_codec === 'string' ? format.video_codec : null,
            codecFamily: format.video_codec_family,
            resolution: typeof format.resolution === 'string' ? format.resolution : null,
            fps: positiveNumber(format.fps),
            bitrate: positiveNumber(format.bitrate_kbps),
            estimatedFilesize: positiveNumber(format.estimated_filesize),
            hasAudio: format.has_audio === true,
            requiresMerge: format.requires_merge === true,
        }];
    });
}

export function normalizeYouTubeAudioFormats(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.slice(0, 20).flatMap((format) => {
        if (
            !format
            || typeof format !== 'object'
            || typeof format.format_id !== 'string'
            || !FORMAT_ID.test(format.format_id)
            || !['m4a', 'webm'].includes(format.container)
            || typeof format.audio_codec !== 'string'
        ) {
            return [];
        }

        return [{
            formatId: format.format_id,
            container: format.container,
            codec: format.audio_codec,
            bitrate: positiveNumber(format.bitrate_kbps),
            estimatedFilesize: positiveNumber(format.estimated_filesize),
        }];
    });
}

export function youtubeVideoFormatLabel(format) {
    const resolution = format.resolution || 'Video';
    const fps = format.fps ? ` · ${Math.round(format.fps)} fps` : '';
    const delivery = format.requiresMerge
        ? ' · video and audio will be merged'
        : (format.hasAudio ? ' · audio included' : ' · video only');
    const codec = format.codecFamily === 'h264' ? 'H.264'
        : (format.codecFamily === 'h265' ? 'H.265' : 'Compatible video');

    return `${format.container.toUpperCase()} ${resolution} · ${codec}${fps}${delivery}`;
}

export function youtubeAudioFormatLabel(format) {
    const bitrate = format.bitrate ? ` · ${Math.round(format.bitrate)} kbps` : '';

    const codec = format.codec === 'mp4a.40.2' ? 'AAC-LC'
        : (format.codec === 'mp4a.40.5' ? 'HE-AAC' : (format.codec.toLowerCase() === 'opus' ? 'Opus' : format.codec));
    return `${format.container.toUpperCase()} · ${codec}${bitrate}`;
}
