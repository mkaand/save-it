import assert from 'node:assert/strict';
import test from 'node:test';

import {
    RECENT_FETCHES_KEY,
    addRecentFetch,
    clearRecentFetches,
    readRecentFetches,
} from '../../resources/js/recent-fetches.js';

class MemoryStorage {
    values = new Map();

    getItem(key) {
        return this.values.get(key) ?? null;
    }

    setItem(key, value) {
        this.values.set(key, value);
    }

    removeItem(key) {
        this.values.delete(key);
    }
}

function item(index, url = `https://www.youtube.com/watch?v=video0000${index}`) {
    return {
        platform: 'youtube',
        platformLabel: 'YouTube',
        mediaType: 'video',
        url,
        title: `Video ${index}`,
        thumbnailUrl: null,
    };
}

test('stores at most five recent fetches in newest-first order', () => {
    const storage = new MemoryStorage();

    for (let index = 0; index < 6; index += 1) {
        addRecentFetch(storage, item(index), `2026-07-27T20:0${index}:00.000Z`);
    }

    const entries = readRecentFetches(storage);
    assert.equal(entries.length, 5);
    assert.equal(entries[0].title, 'Video 5');
    assert.equal(entries[4].title, 'Video 1');
});

test('deduplicates normalized URLs and moves a repeat to the top', () => {
    const storage = new MemoryStorage();
    const repeatedUrl = 'https://www.youtube.com/watch?v=abc123DEF45';

    addRecentFetch(storage, item(1, repeatedUrl));
    addRecentFetch(storage, item(2));
    addRecentFetch(storage, { ...item(3, repeatedUrl), title: 'Updated title' });

    const entries = readRecentFetches(storage);
    assert.equal(entries.length, 2);
    assert.equal(entries[0].title, 'Updated title');
    assert.equal(entries[0].url, repeatedUrl);
});

test('resets corrupt JSON without throwing', () => {
    const storage = new MemoryStorage();
    storage.setItem(RECENT_FETCHES_KEY, '{broken-json');

    assert.deepEqual(readRecentFetches(storage), []);
    assert.equal(storage.getItem(RECENT_FETCHES_KEY), null);
});

test('continues safely when storage is unavailable', () => {
    const blockedStorage = {
        getItem() {
            throw new Error('blocked');
        },
        removeItem() {
            throw new Error('blocked');
        },
    };

    assert.deepEqual(readRecentFetches(blockedStorage), []);
    assert.equal(addRecentFetch(null, item(1)).length, 1);
    assert.equal(clearRecentFetches(null), false);
});

test('stores only the documented minimal fields', () => {
    const storage = new MemoryStorage();
    addRecentFetch(storage, { ...item(1), secret: 'do-not-store', headers: { token: 'no' } });

    const stored = JSON.parse(storage.getItem(RECENT_FETCHES_KEY));
    assert.deepEqual(Object.keys(stored.items[0]), [
        'platform',
        'platformLabel',
        'mediaType',
        'url',
        'title',
        'author',
        'thumbnailUrl',
        'analyzedAt',
    ]);
});

test('does not persist X asset arrays or expiring media URLs', () => {
    const storage = new MemoryStorage();
    addRecentFetch(storage, {
        ...item(1, 'https://x.com/example/status/123'),
        platform: 'x',
        assets: [{ url: 'https://video.twimg.com/expiring/video.mp4?token=temporary' }],
    });

    const stored = JSON.parse(storage.getItem(RECENT_FETCHES_KEY));
    assert.equal(stored.items[0].assets, undefined);
    assert.equal(JSON.stringify(stored).includes('video.twimg.com'), false);
});

test('does not persist expiring Instagram CDN thumbnails', () => {
    const storage = new MemoryStorage();
    addRecentFetch(storage, {
        ...item(1, 'https://www.instagram.com/p/Code123/'),
        platform: 'instagram',
        platformLabel: 'Instagram',
        thumbnailUrl: 'https://scontent.example.cdninstagram.com/image.jpg?token=temporary',
    });

    const stored = JSON.parse(storage.getItem(RECENT_FETCHES_KEY));
    assert.equal(stored.items[0].thumbnailUrl, null);
    assert.equal(JSON.stringify(stored).includes('cdninstagram.com'), false);
});

test('stores minimal LinkedIn history without expiring asset URLs', () => {
    const storage = new MemoryStorage();
    addRecentFetch(storage, {
        ...item(1, 'https://www.linkedin.com/feed/update/urn:li:activity:1234567890123456789/'),
        platform: 'linkedin',
        platformLabel: 'LinkedIn',
        mediaType: 'carousel',
        title: 'Public LinkedIn post',
        author: 'Example Organization',
        thumbnailUrl: 'https://media.licdn.com/dms/image/temporary',
        assets: [{ url: 'https://media.licdn.com/dms/image/expiring' }],
        headers: { cookie: 'must-not-persist' },
    });

    const stored = JSON.parse(storage.getItem(RECENT_FETCHES_KEY));
    assert.equal(stored.items[0].author, 'Example Organization');
    assert.equal(stored.items[0].thumbnailUrl, null);
    assert.equal(stored.items[0].assets, undefined);
    assert.equal(JSON.stringify(stored).includes('licdn.com'), false);
    assert.equal(JSON.stringify(stored).includes('cookie'), false);
});

test('stores YouTube history without upstream thumbnails, formats, or conversion payloads', () => {
    const storage = new MemoryStorage();
    addRecentFetch(storage, {
        ...item(1, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
        platform: 'youtube',
        platformLabel: 'YouTube',
        title: 'Public YouTube video',
        thumbnailUrl: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg',
        video_formats: [{ format_id: '137', direct_url: 'must-not-persist' }],
        audio_formats: [{ format_id: '140' }],
        conversion_plans: [{ id: 'mp3' }],
    });

    const stored = JSON.parse(storage.getItem(RECENT_FETCHES_KEY));
    assert.equal(stored.items[0].platform, 'youtube');
    assert.equal(
        stored.items[0].thumbnailUrl,
        null,
    );
    assert.equal(stored.items[0].video_formats, undefined);
    assert.equal(stored.items[0].audio_formats, undefined);
    assert.equal(stored.items[0].conversion_plans, undefined);
});

test('does not persist opaque Save It download tokens as thumbnails', () => {
    const storage = new MemoryStorage();
    addRecentFetch(storage, {
        ...item(1),
        thumbnailUrl: `/api/downloads/${'a'.repeat(48)}.${'b'.repeat(64)}`,
    });

    const stored = JSON.parse(storage.getItem(RECENT_FETCHES_KEY));
    assert.equal(stored.items[0].thumbnailUrl, null);
    assert.equal(JSON.stringify(stored).includes('/api/downloads/'), false);
});
