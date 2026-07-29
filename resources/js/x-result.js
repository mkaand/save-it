const MEDIA_HOSTS = new Set(['pbs.twimg.com', 'video.twimg.com']);
const IMAGE_HOSTS = new Set(['pbs.twimg.com', 'i.ytimg.com']);

function safeUrl(value, hosts) {
    if (typeof value !== 'string') {
        return null;
    }

    try {
        const parsed = new URL(value);
        return parsed.protocol === 'https:'
            && !parsed.username
            && !parsed.password
            && !parsed.port
            && hosts.has(parsed.hostname)
            ? parsed.toString()
            : null;
    } catch {
        return null;
    }
}

export function safeResultImageUrl(value) {
    if (safeUrl(value, IMAGE_HOSTS)) {
        return safeUrl(value, IMAGE_HOSTS);
    }

    if (typeof value !== 'string') {
        return null;
    }

    try {
        const parsed = new URL(value);
        const safeCdnHost = (
            parsed.hostname.endsWith('.cdninstagram.com')
            && parsed.hostname !== 'cdninstagram.com'
        ) || (
            parsed.hostname.endsWith('.licdn.com')
            && parsed.hostname !== 'licdn.com'
        );

        return parsed.protocol === 'https:'
            && !parsed.username
            && !parsed.password
            && !parsed.port
            && safeCdnHost
            ? parsed.toString()
            : null;
    } catch {
        return null;
    }
}

export function safeXMediaUrl(value) {
    return safeUrl(value, MEDIA_HOSTS);
}

export function normalizeXAssets(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.slice(0, 20).flatMap((asset, index) => {
        if (
            !asset
            || typeof asset !== 'object'
            || !['video', 'image', 'animated_gif'].includes(asset.type)
        ) {
            return [];
        }

        const url = safeXMediaUrl(asset.url);
        if (!url) {
            return [];
        }

        const variants = Array.isArray(asset.variants)
            ? asset.variants.slice(0, 12).flatMap((variant) => {
                const variantUrl = safeXMediaUrl(variant?.url);
                if (!variantUrl || !['video/mp4', 'application/x-mpegURL'].includes(variant?.mime_type)) {
                    return [];
                }

                return [{
                    mimeType: variant.mime_type,
                    qualityLabel: typeof variant.quality_label === 'string'
                        ? variant.quality_label.slice(0, 40)
                        : null,
                    bitrate: Number.isInteger(variant.bitrate) ? variant.bitrate : null,
                    isPreferred: variant.is_preferred === true,
                }];
            })
            : [];

        return [{
            id: typeof asset.id === 'string' ? asset.id : `asset-${index + 1}`,
            order: Number.isInteger(asset.order) ? asset.order : index + 1,
            type: asset.type,
            url,
            thumbnailUrl: safeResultImageUrl(asset.thumbnail_url),
            width: Number.isInteger(asset.width) ? asset.width : null,
            height: Number.isInteger(asset.height) ? asset.height : null,
            durationMs: Number.isInteger(asset.duration_ms) ? asset.duration_ms : null,
            variants,
        }];
    });
}

export function xAssetLabel(asset) {
    const type = asset.type === 'animated_gif'
        ? 'Animated GIF'
        : asset.type.charAt(0).toUpperCase() + asset.type.slice(1);
    const preferred = asset.variants.find((variant) => variant.isPreferred);
    const dimensions = preferred?.qualityLabel
        || (asset.width && asset.height ? `${asset.width}×${asset.height}` : null);

    return dimensions ? `${type} · ${dimensions}` : type;
}
