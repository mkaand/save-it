import assert from 'node:assert/strict';
import test from 'node:test';

import { createShareSession } from '../../resources/js/share-session.js';

test('first activation prepares once, ignores duplicate preparation taps, and reaches ready without opening the share sheet', async () => {
    const states = [];
    let prepareCalls = 0;
    let openCalls = 0;
    let resolvePreparation;
    const prepared = { file: {}, title: 'Media' };
    const session = createShareSession({}, {
        onState: (state) => states.push(state.phase),
        prepare: (_output, onState) => {
            prepareCalls += 1;
            onState({ phase: 'loading', percent: 0 });
            return new Promise((resolve) => {
                resolvePreparation = () => {
                    onState({ phase: 'loading', percent: 100 });
                    resolve(prepared);
                };
            });
        },
        open: () => {
            openCalls += 1;
            return Promise.resolve();
        },
    });

    const firstActivation = session.activate();
    await session.activate();
    assert.equal(prepareCalls, 1);
    resolvePreparation();
    await firstActivation;

    assert.equal(prepareCalls, 1);
    assert.equal(openCalls, 0);
    assert.equal(session.phase, 'ready');
    assert.equal(session.prepared, prepared);
    assert.deepEqual(states, ['loading', 'loading', 'ready']);
});

test('ready activation opens synchronously without a second preparation and ignores duplicate taps', async () => {
    let prepareCalls = 0;
    let openCalls = 0;
    let resolveShare;
    const session = createShareSession({}, {
        prepare: async () => {
            prepareCalls += 1;
            return { file: {}, title: 'Media' };
        },
        open: () => {
            openCalls += 1;
            return new Promise((resolve) => {
                resolveShare = resolve;
            });
        },
    });

    await session.activate();
    const opening = session.activate();

    assert.equal(openCalls, 1);
    assert.equal(prepareCalls, 1);
    assert.equal(session.phase, 'sharing');
    await session.activate();
    assert.equal(openCalls, 1);
    resolveShare();
    await opening;
    assert.equal(session.phase, 'idle');
    assert.equal(session.prepared, null);
});

test('AbortError and NotAllowedError preserve the prepared file for retry without refetching', async () => {
    const errors = [];
    let prepareCalls = 0;
    let openCalls = 0;
    const session = createShareSession({}, {
        onError: (message) => errors.push(message),
        prepare: async () => {
            prepareCalls += 1;
            return { file: {}, title: 'Media' };
        },
        open: () => {
            openCalls += 1;
            if (openCalls === 1) {
                return Promise.reject(Object.assign(new Error('Dismissed'), { name: 'AbortError' }));
            }
            if (openCalls === 2) {
                return Promise.reject(Object.assign(new Error('Raw browser error'), { name: 'NotAllowedError' }));
            }

            return Promise.resolve();
        },
    });

    await session.activate();
    await session.activate();
    assert.equal(session.phase, 'ready');
    assert.equal(session.prepared !== null, true);
    assert.deepEqual(errors, []);

    await session.activate();
    assert.equal(session.phase, 'ready');
    assert.equal(errors[0], 'Tap Share / Save again to open the share sheet.');
    assert.equal(errors[0].includes('Raw browser error'), false);

    await session.activate();
    assert.equal(prepareCalls, 1);
    assert.equal(openCalls, 3);
    assert.equal(session.phase, 'idle');
});

test('release invalidates pending and ready prepared files when analysis or output changes', async () => {
    let resolvePreparation;
    const session = createShareSession({}, {
        prepare: () => new Promise((resolve) => {
            resolvePreparation = resolve;
        }),
    });

    const preparing = session.activate();
    session.release();
    resolvePreparation({ file: {}, title: 'Media' });
    await preparing;

    assert.equal(session.phase, 'idle');
    assert.equal(session.prepared, null);

    const readySession = createShareSession({}, {
        prepare: async () => ({ file: {}, title: 'Media' }),
    });
    await readySession.activate();
    assert.equal(readySession.phase, 'ready');
    readySession.release();
    assert.equal(readySession.phase, 'idle');
    assert.equal(readySession.prepared, null);
});

test('a too-large preparation never enters ready state', async () => {
    let downloadOnly = 0;
    const session = createShareSession({}, {
        onDownloadOnly: () => {
            downloadOnly += 1;
        },
        prepare: async () => {
            const error = new Error('too large');
            error.code = 'media_too_large';
            throw error;
        },
    });

    await session.activate();

    assert.equal(downloadOnly, 1);
    assert.equal(session.phase, 'idle');
    assert.equal(session.prepared, null);
});
