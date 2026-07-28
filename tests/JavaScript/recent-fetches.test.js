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
