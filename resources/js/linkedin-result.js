const LINKEDIN_ASSET_SUFFIX = '.licdn.com';

export function safeLinkedInMediaUrl(value) {
    if (typeof value !== 'string') {
        return null;
    }

    try {
        const parsed = new URL(value);
        return parsed.protocol === 'https:'
            && !parsed.username
            && !parsed.password
            && !parsed.port
            && parsed.hostname.endsWith(LINKEDIN_ASSET_SUFFIX)
            && parsed.hostname !== LINKEDIN_ASSET_SUFFIX.slice(1)
            ? parsed.toString()
            : null;
    } catch {
        return null;
    }
}

export function normalizeLinkedInAssets(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.slice(0, 20).flatMap((asset, index) => {
        if (!asset || typeof asset !== 'object' || !['video', 'image'].includes(asset.type)) {
            return [];
        }

        const url = safeLinkedInMediaUrl(asset.url);
        if (!url) {
            return [];
        }

        return [{
            id: typeof asset.id === 'string' ? asset.id : `asset-${index + 1}`,
            order: Number.isInteger(asset.order) ? asset.order : index + 1,
            type: asset.type,
            url,
            thumbnailUrl: safeLinkedInMediaUrl(asset.thumbnail_url),
            width: Number.isInteger(asset.width) ? asset.width : null,
            height: Number.isInteger(asset.height) ? asset.height : null,
            durationMs: Number.isInteger(asset.duration_ms) ? asset.duration_ms : null,
            variants: [],
        }];
    });
}

export function linkedinAssetLabel(asset) {
    const type = asset.type === 'video' ? 'Native video' : 'Image';
    const dimensions = asset.width && asset.height ? `${asset.width}×${asset.height}` : null;

    return dimensions ? `${type} · ${dimensions}` : type;
}

export function linkedinAuthor(metadata) {
    if (!metadata || typeof metadata !== 'object') {
        return null;
    }

    for (const value of [metadata.author_name, metadata.author_handle]) {
        if (typeof value === 'string' && value.trim()) {
            return value.trim().slice(0, 160);
        }
    }

    return null;
}
