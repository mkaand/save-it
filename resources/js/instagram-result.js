const INSTAGRAM_ASSET_SUFFIX = '.cdninstagram.com';
const LOCAL_MEDIA_PATH = /^\/api\/(?:downloads\/[a-z0-9]{48}\.[a-f0-9]{64}|previews\/[a-z0-9]{48})$/;

export function safeInstagramMediaUrl(value) {
    if (typeof value !== 'string') {
        return null;
    }

    try {
        const parsed = new URL(value);
        return parsed.protocol === 'https:'
            && !parsed.username
            && !parsed.password
            && !parsed.port
            && parsed.hostname.endsWith(INSTAGRAM_ASSET_SUFFIX)
            && parsed.hostname !== INSTAGRAM_ASSET_SUFFIX.slice(1)
            ? parsed.toString()
            : null;
    } catch {
        return null;
    }
}

export function normalizeInstagramAssets(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.slice(0, 20).flatMap((asset, index) => {
        if (!asset || typeof asset !== 'object' || !['video', 'image'].includes(asset.type)) {
            return [];
        }

        const previewUrl = typeof asset.preview_url === 'string'
            && LOCAL_MEDIA_PATH.test(asset.preview_url)
            ? asset.preview_url
            : null;
        if (!previewUrl && asset.type === 'image') {
            return [];
        }

        const variants = Array.isArray(asset.variants)
            ? asset.variants.slice(0, 12).flatMap((variant) => {
                if (variant?.mime_type !== 'video/mp4') {
                    return [];
                }
                return [{
                    mimeType: variant.mime_type,
                    qualityLabel: typeof variant.quality_label === 'string'
                        ? variant.quality_label.slice(0, 40)
                        : null,
                    isPreferred: variant.is_preferred === true,
                }];
            })
            : [];

        return [{
            id: typeof asset.id === 'string' ? asset.id : `asset-${index + 1}`,
            order: Number.isInteger(asset.order) ? asset.order : index + 1,
            type: asset.type,
            url: null,
            thumbnailUrl: previewUrl,
            width: Number.isInteger(asset.width) ? asset.width : null,
            height: Number.isInteger(asset.height) ? asset.height : null,
            durationMs: Number.isInteger(asset.duration_ms) ? asset.duration_ms : null,
            variants,
        }];
    });
}

export function instagramAssetLabel(asset) {
    const type = asset.type === 'video' ? 'Reel / video' : 'Image';
    const dimensions = asset.width && asset.height ? `${asset.width}×${asset.height}` : null;
    return dimensions ? `${type} · ${dimensions}` : type;
}
