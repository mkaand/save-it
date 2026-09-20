import assert from 'node:assert/strict';
import test from 'node:test';

import {
    resetTurnstileChallenge,
    shareStateAriaLabel,
    shareStateLabel,
} from '../../resources/js/analyzer.js';

test('uses the compact Tap Again ready label with an explanatory accessible label', () => {
    assert.equal(shareStateLabel(), 'Share / Save');
    assert.equal(shareStateLabel('preparing'), 'Preparing…');
    assert.equal(shareStateLabel('loading', 27), 'Loading 27%');
    assert.equal(shareStateLabel('ready'), 'Tap Again');
    assert.equal(shareStateLabel('sharing'), 'Opening…');
    assert.equal(
        shareStateAriaLabel('ready', 'Tap Again', '1080p MP4'),
        'Tap again to share or save 1080p MP4',
    );
});

test('resets an optional Turnstile challenge without affecting disabled installs', () => {
    let resets = 0;
    resetTurnstileChallenge({ turnstile: { reset: () => { resets += 1; } } });
    resetTurnstileChallenge({});
    assert.equal(resets, 1);
});
