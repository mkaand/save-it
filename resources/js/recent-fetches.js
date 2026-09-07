export const RECENT_FETCHES_KEY = 'save-it.recent-fetches.v1';
export const RECENT_FETCHES_VERSION = 2;
export const RECENT_FETCHES_LIMIT = 5;

function safeUrl(value, allowNull = false) {
    if (allowNull && (value === null || value === undefined || value === '')) {
        return null;
    }

    if (typeof value !== 'string') {
        return allowNull ? null : '';
    }

    try {
        const parsed = new URL(value);
        return ['http:', 'https:'].includes(parsed.protocol) ? parsed.toString() : allowNull ? null : '';
    } catch {
        return allowNull ? null : '';
    }
}

function safeSameOriginUrl(value) {
    if (typeof value !== 'string') {
        return null;
    }

    if (/^\/api\/previews\/[a-z0-9]{48}$/.test(value)) {
        return value;
    }

    if (typeof window === 'undefined' || !window.location?.origin) {
        return null;
    }

    try {
        const url = new URL(value, window.location.origin);

        return url.origin === window.location.origin && /^\/api\/previews\/[a-z0-9]{48}$/.test(url.pathname)
            ? url.pathname
            : null;
    } catch {
        return null;
    }
}

export function normalizeRecentFetch(value, analyzedAt = new Date().toISOString()) {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const url = safeUrl(value.url);

    if (!url) {
        return null;
    }

    const timestamp = typeof value.analyzedAt === 'string' && !Number.isNaN(Date.parse(value.analyzedAt))
        ? value.analyzedAt
        : analyzedAt;

    return {
        platform: String(value.platform || 'unknown').slice(0, 40),
        platformLabel: String(value.platformLabel || 'Media').slice(0, 80),
        mediaType: String(value.mediaType || 'video').slice(0, 40),
        url,
        title: String(value.title || 'Untitled media').slice(0, 160),
        author: typeof value.author === 'string' && value.author.trim()
            ? value.author.trim().slice(0, 160)
            : null,
        // Only a same-origin preview reference may survive in browser storage.
        // Upstream/CDN media addresses and download delivery data are never persisted.
        thumbnailUrl: safeSameOriginUrl(value.thumbnailUrl),
        analyzedAt: timestamp,
    };
}

export function readRecentFetches(storage) {
    if (!storage) {
        return [];
    }

    try {
        const raw = storage.getItem(RECENT_FETCHES_KEY);

        if (!raw) {
            return [];
        }

        const payload = JSON.parse(raw);

        if (![1, RECENT_FETCHES_VERSION].includes(payload?.version) || !Array.isArray(payload.items)) {
            storage.removeItem(RECENT_FETCHES_KEY);
            return [];
        }

        return payload.items
            .map((item) => normalizeRecentFetch(item))
            .filter(Boolean)
            .slice(0, RECENT_FETCHES_LIMIT);
    } catch {
        try {
            storage.removeItem(RECENT_FETCHES_KEY);
        } catch {
            // Storage can be blocked while the rest of the page remains usable.
        }

        return [];
    }
}

export function writeRecentFetches(storage, items) {
    if (!storage) {
        return false;
    }

    try {
        storage.setItem(RECENT_FETCHES_KEY, JSON.stringify({
            version: RECENT_FETCHES_VERSION,
            items: items.slice(0, RECENT_FETCHES_LIMIT),
        }));

        return true;
    } catch {
        return false;
    }
}

export function addRecentFetch(storage, value, analyzedAt = new Date().toISOString()) {
    const item = normalizeRecentFetch(value, analyzedAt);

    if (!item) {
        return readRecentFetches(storage);
    }

    const items = readRecentFetches(storage).filter((existing) => existing.url !== item.url);
    const nextItems = [item, ...items].slice(0, RECENT_FETCHES_LIMIT);
    writeRecentFetches(storage, nextItems);

    return nextItems;
}

export function clearRecentFetches(storage) {
    if (!storage) {
        return false;
    }

    try {
        storage.removeItem(RECENT_FETCHES_KEY);
        return true;
    } catch {
        return false;
    }
}
