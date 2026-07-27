import assert from 'node:assert/strict';
import test from 'node:test';

import {
    readThemeMode,
    resolveTheme,
    THEME_STORAGE_KEY,
    writeThemeMode,
} from '../../resources/js/theme.js';

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

test('defaults to the system theme without storage', () => {
    assert.equal(readThemeMode(null), 'system');
    assert.equal(resolveTheme('system', false), 'light');
    assert.equal(resolveTheme('system', true), 'dark');
});

test('stores explicit light and dark preferences', () => {
    const storage = new MemoryStorage();

    assert.equal(writeThemeMode(storage, 'dark'), true);
    assert.equal(readThemeMode(storage), 'dark');
    assert.equal(writeThemeMode(storage, 'light'), true);
    assert.equal(readThemeMode(storage), 'light');
});

test('system mode removes an explicit preference', () => {
    const storage = new MemoryStorage();
    storage.setItem(THEME_STORAGE_KEY, 'dark');

    assert.equal(writeThemeMode(storage, 'system'), true);
    assert.equal(storage.getItem(THEME_STORAGE_KEY), null);
});

test('corrupt preferences safely reset to system', () => {
    const storage = new MemoryStorage();
    storage.setItem(THEME_STORAGE_KEY, 'sepia');

    assert.equal(readThemeMode(storage), 'system');
    assert.equal(storage.getItem(THEME_STORAGE_KEY), null);
});

test('blocked storage falls back without throwing', () => {
    const blocked = {
        getItem() {
            throw new Error('blocked');
        },
        setItem() {
            throw new Error('blocked');
        },
    };

    assert.equal(readThemeMode(blocked), 'system');
    assert.equal(writeThemeMode(blocked, 'dark'), false);
});
