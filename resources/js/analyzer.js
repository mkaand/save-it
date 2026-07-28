import {
    addRecentFetch,
    clearRecentFetches,
    readRecentFetches,
} from './recent-fetches.js';
import {
    mountPlatformIcons,
    platformIcon,
} from './platform-icons.js';
import {
    instagramAssetLabel,
    normalizeInstagramAssets,
} from './instagram-result.js';
import {
    normalizeXAssets,
    safeResultImageUrl,
    xAssetLabel,
} from './x-result.js';
import {
    formatDuration,
    normalizeYouTubeAudioFormats,
    normalizeYouTubeVideoFormats,
    youtubeAudioFormatLabel,
    youtubeVideoFormatLabel,
} from './youtube-result.js';

function browserStorage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function element(tag, className, text) {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function thumbnail(url, title, className) {
    const wrapper = element('div', className);
    const fallback = element('span', 'thumbnail-fallback', 'SI');
    wrapper.append(fallback);

    const safeUrl = safeResultImageUrl(url);

    if (!safeUrl) {
        return wrapper;
    }

    const image = new Image();
    image.alt = `${title} thumbnail`;
    image.loading = 'lazy';
    image.referrerPolicy = 'no-referrer';
    image.addEventListener('load', () => fallback.remove());
    image.addEventListener('error', () => image.remove());
    image.src = safeUrl;
    wrapper.prepend(image);

    return wrapper;
}

function initNavigation() {
    const toggle = document.querySelector('[data-nav-toggle]');
    const navigation = document.querySelector('[data-site-nav]');

    if (!toggle || !navigation) {
        return;
    }

    function setOpen(open, returnFocus = false) {
        toggle.setAttribute('aria-expanded', String(open));
        navigation.classList.toggle('is-open', open);

        if (open) {
            navigation.querySelector('a')?.focus();
        } else if (returnFocus) {
            toggle.focus();
        }
    }

    toggle.addEventListener('click', () => {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    navigation.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false, true);
        }
    });

    document.addEventListener('click', (event) => {
        if (
            toggle.getAttribute('aria-expanded') === 'true'
            && !navigation.contains(event.target)
            && !toggle.contains(event.target)
        ) {
            setOpen(false);
        }
    });
}

export function initAnalyzer() {
    const card = document.querySelector('[data-analyzer]');

    if (!card) {
        return;
    }

    const form = card.querySelector('[data-analyzer-form]');
    const input = card.querySelector('[data-url-input]');
    const pasteButton = card.querySelector('[data-paste-button]');
    const submitButton = card.querySelector('[data-analyze-button]');
    const submitLabel = card.querySelector('[data-analyze-label]');
    const status = card.querySelector('[data-analyzer-status]');
    const error = card.querySelector('[data-url-error]');
    const resultSection = document.querySelector('[data-result-section]');
    const resultPanel = document.querySelector('[data-result-panel]');
    const recentSection = document.querySelector('[data-recent-section]');
    const recentList = document.querySelector('[data-recent-list]');
    const clearButton = document.querySelector('[data-clear-recent]');
    const storage = browserStorage();
    let recentItems = readRecentFetches(storage);
    let clearTimer;

    function setError(message = '') {
        error.textContent = message;
        error.hidden = !message;
        input.setAttribute('aria-invalid', String(Boolean(message)));
    }

    function setStatus(message, type = '') {
        status.textContent = message;
        status.classList.toggle('is-error', type === 'error');
        status.classList.toggle('is-success', type === 'success');
    }

    function setLoading(loading) {
        card.classList.toggle('is-loading', loading);
        submitButton.disabled = loading;
        input.disabled = loading;
        pasteButton.disabled = loading;
        submitLabel.textContent = loading ? 'Analyzing…' : 'Analyze URL';
    }

    function renderResult(data) {
        resultPanel.replaceChildren();

        const media = thumbnail(data.thumbnail_url, data.title, 'result-media');
        const copy = element('div', 'result-copy');
        const meta = element('div', 'result-meta');
        const platform = element('span', 'result-pill result-platform');
        const icon = platformIcon(data.platform, 'platform-logo result-platform-logo');
        if (icon) {
            platform.append(icon);
        }
        platform.append(document.createTextNode(data.platform_label));
        meta.append(
            platform,
            element('span', 'result-pill', data.media_type.replaceAll('_', ' ')),
            element('span', 'result-pill', data.status),
        );

        const title = element('h3', '', data.title);
        const url = element('p', 'result-url', data.url);
        url.title = data.url;
        const outputLabel = element('p', 'output-label', 'Planned output options');
        const outputs = element('div', 'output-grid');
        const isYouTube = ['youtube', 'youtube_shorts'].includes(data.platform);
        const assets = data.platform === 'x'
            ? normalizeXAssets(data.assets)
            : (data.platform === 'instagram' ? normalizeInstagramAssets(data.assets) : []);

        if (assets.length > 0) {
            const assetLabel = element(
                'p',
                'output-label',
                `${assets.length} media ${assets.length === 1 ? 'asset' : 'assets'}`,
            );
            const assetGrid = element('div', 'x-asset-grid');

            assets.forEach((asset) => {
                const card = element('article', 'x-asset-card');
                const preview = thumbnail(
                    asset.thumbnailUrl,
                    data.platform === 'instagram'
                        ? instagramAssetLabel(asset)
                        : xAssetLabel(asset),
                    'x-asset-preview',
                );
                const details = element('div', 'x-asset-details');
                details.append(
                    element(
                        'strong',
                        '',
                        data.platform === 'instagram'
                            ? instagramAssetLabel(asset)
                            : xAssetLabel(asset),
                    ),
                    element(
                        'span',
                        '',
                        asset.variants.length > 0
                            ? `${asset.variants.length} source variants`
                            : 'Original image metadata',
                    ),
                );
                card.append(preview, details);
                assetGrid.append(card);
            });

            copy.append(assetLabel, assetGrid);
        }

        if (isYouTube) {
            const summary = element('div', 'youtube-summary');
            const channel = data.metadata?.channel || 'Channel unavailable';
            summary.append(
                element('span', '', channel),
                element('span', '', formatDuration(data.metadata?.duration_ms)),
                element('span', '', `${data.video_formats?.length || 0} video formats`),
                element('span', '', `${data.audio_formats?.length || 0} audio formats`),
            );

            const groups = element('div', 'youtube-format-groups');
            const videoGroup = element('section', 'youtube-format-group');
            const audioGroup = element('section', 'youtube-format-group');
            videoGroup.append(element('h4', '', 'Video qualities'));
            audioGroup.append(element('h4', '', 'Audio qualities'));

            normalizeYouTubeVideoFormats(data.video_formats).slice(0, 10).forEach((format) => {
                videoGroup.append(element('span', 'youtube-format-chip', youtubeVideoFormatLabel(format)));
            });
            normalizeYouTubeAudioFormats(data.audio_formats).slice(0, 8).forEach((format) => {
                audioGroup.append(element('span', 'youtube-format-chip', youtubeAudioFormatLabel(format)));
            });
            groups.append(videoGroup, audioGroup);
            copy.append(summary, groups);
        }

        data.outputs.forEach((output) => {
            const button = element('button', 'output-option');
            button.type = 'button';
            button.disabled = true;
            button.setAttribute('aria-label', `${output.label}: ${output.detail}. Coming in the next step.`);
            button.append(
                element('strong', '', output.label),
                element('span', '', `${output.detail} · Coming next`),
            );
            outputs.append(button);
        });

        copy.prepend(meta, title, url);
        copy.append(
            outputLabel,
            outputs,
                element('p', 'output-note', data.status === 'ready'
                    ? `${data.platform_label} metadata is ready. Download and stream merging arrive in PR #9.`
                    : 'This is a format preview. Download controls become available with the media engine.'),
        );
        resultPanel.append(media, copy);
        resultSection.hidden = false;
        resultSection.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'start',
        });
    }

    function renderRecent() {
        recentList.replaceChildren();
        recentSection.hidden = recentItems.length === 0;

        recentItems.forEach((item) => {
            const article = element('article', 'recent-card');
            const select = element('button', 'recent-select');
            select.type = 'button';
            select.setAttribute('aria-label', `Use ${item.title} URL`);
            select.append(thumbnail(item.thumbnailUrl, item.title, 'recent-thumb'));

            const body = element('div', 'recent-body');
            const platform = element('span', 'recent-platform');
            const icon = platformIcon(item.platform, 'platform-logo recent-platform-logo');
            if (icon) {
                platform.append(icon);
            }
            platform.append(document.createTextNode(item.platformLabel));
            body.append(platform, element('h3', '', item.title));
            const url = element('p', 'recent-url', item.url);
            url.title = item.url;
            body.append(url, element('time', 'recent-time', new Intl.DateTimeFormat(undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }).format(new Date(item.analyzedAt))));
            select.append(body);
            select.addEventListener('click', () => {
                input.value = item.url;
                setError();
                setStatus('Recent URL added to the analyzer. Review it, then choose Analyze URL.');
                input.focus();
            });

            const analyzeAgain = element('button', 'recent-analyze', 'Analyze again');
            analyzeAgain.type = 'button';
            analyzeAgain.addEventListener('click', () => {
                input.value = item.url;
                form.requestSubmit();
            });

            article.append(select, analyzeAgain);
            recentList.append(article);
        });
    }

    async function analyze() {
        const submittedUrl = input.value.trim();
        setError();

        if (!submittedUrl) {
            setError('Paste a media URL to analyze.');
            setStatus('The URL field needs your attention.', 'error');
            input.focus();
            return;
        }

        setLoading(true);
        setStatus('Analyzing the URL…');

        try {
            const response = await fetch('/api/analyze', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ url: submittedUrl }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message = payload?.errors?.url?.[0]
                    || (response.status === 429 ? 'Too many analysis requests. Please wait a moment.' : null)
                    || payload?.error?.message
                    || payload?.message
                    || 'The URL could not be analyzed right now.';
                throw new Error(message);
            }

            renderResult(payload.data);
            recentItems = addRecentFetch(storage, {
                platform: payload.data.platform,
                platformLabel: payload.data.platform_label,
                mediaType: payload.data.media_type,
                url: payload.data.url,
                title: payload.data.title,
                thumbnailUrl: payload.data.thumbnail_url,
            });
            renderRecent();
            setStatus('Analysis complete. Planned formats are ready to review.', 'success');
        } catch (requestError) {
            const message = requestError instanceof TypeError
                ? 'Network error. Check your connection and try again.'
                : requestError.message;
            setError(message);
            setStatus(message, 'error');
            input.focus();
        } finally {
            setLoading(false);
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        analyze();
    });

    input.addEventListener('input', () => {
        if (error.textContent) {
            setError();
            setStatus('Ready when you are.');
        }
    });

    if (navigator.clipboard?.readText) {
        pasteButton.hidden = false;
        pasteButton.addEventListener('click', async () => {
            try {
                input.value = await navigator.clipboard.readText();
                setError();
                setStatus('Clipboard URL added. Choose Analyze URL when ready.');
                input.focus();
            } catch {
                setStatus('Clipboard access was not available. Paste the URL manually.', 'error');
            }
        });
    }

    clearButton.addEventListener('click', () => {
        if (!clearButton.classList.contains('is-confirming')) {
            clearButton.classList.add('is-confirming');
            clearButton.textContent = 'Confirm clear';
            clearTimer = window.setTimeout(() => {
                clearButton.classList.remove('is-confirming');
                clearButton.textContent = 'Clear all';
            }, 5000);
            return;
        }

        window.clearTimeout(clearTimer);
        clearRecentFetches(storage);
        recentItems = [];
        renderRecent();
        clearButton.classList.remove('is-confirming');
        clearButton.textContent = 'Clear all';
        setStatus('Recent Fetches cleared from this browser.');
    });

    renderRecent();
}

export function initPage() {
    mountPlatformIcons();
    initNavigation();
    initAnalyzer();

    const year = document.querySelector('[data-current-year]');
    if (year) {
        year.textContent = String(new Date().getFullYear());
    }
}
