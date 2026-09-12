import {
    openShareSheet,
    prepareShareMedia,
    shareErrorMessage,
} from './mobile-share.js';

export function createShareSession(output, {
    onDownloadOnly = () => {},
    onError = () => {},
    onState = () => {},
    open = openShareSheet,
    prepare = prepareShareMedia,
} = {}) {
    let generation = 0;
    let phase = 'idle';
    let prepared = null;
    let preparing = false;

    function publish(state = { phase }) {
        onState(state);
    }

    function release() {
        generation += 1;
        prepared = null;
        if (!preparing && phase !== 'sharing') {
            phase = 'idle';
            publish();
        }
    }

    function activate() {
        if (preparing || phase === 'sharing') {
            return Promise.resolve();
        }

        if (prepared) {
            phase = 'sharing';
            publish();
            let opening;
            try {
                // Keep navigator.share() in this exact trusted click call stack.
                opening = open(prepared);
            } catch (error) {
                phase = 'ready';
                publish();
                const message = shareErrorMessage(error);
                if (message) {
                    onError(message);
                }

                return Promise.resolve();
            }

            return Promise.resolve(opening).then(() => {
                prepared = null;
                phase = 'idle';
                publish();
            }).catch((error) => {
                phase = 'ready';
                publish();
                const message = shareErrorMessage(error);
                if (message) {
                    onError(message);
                }
            });
        }

        preparing = true;
        const currentGeneration = generation;
        return Promise.resolve(prepare(output, (state) => {
            if (currentGeneration !== generation) {
                return;
            }

            phase = state.phase;
            publish(state);
        })).then((result) => {
            if (currentGeneration !== generation) {
                return;
            }

            prepared = result;
            phase = 'ready';
            publish();
        }).catch((error) => {
            if (currentGeneration !== generation) {
                return;
            }

            prepared = null;
            phase = 'idle';
            if (error?.code === 'media_too_large') {
                onDownloadOnly(error);
            } else {
                const message = shareErrorMessage(error);
                if (message) {
                    onError(message);
                }
            }
            publish();
        }).finally(() => {
            preparing = false;
            if (currentGeneration !== generation) {
                phase = 'idle';
                publish();
            }
        });
    }

    return Object.freeze({
        activate,
        release,
        get phase() {
            return phase;
        },
        get prepared() {
            return prepared;
        },
    });
}
