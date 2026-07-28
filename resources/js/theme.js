export const THEME_STORAGE_KEY = 'save-it.theme.v1';
export const THEME_MODES = Object.freeze(['system', 'light', 'dark']);

export function normalizeThemeMode(value) {
    return THEME_MODES.includes(value) ? value : 'system';
}

export function readThemeMode(storage) {
    if (!storage) {
        return 'system';
    }

    try {
        const stored = storage.getItem(THEME_STORAGE_KEY);
        const mode = normalizeThemeMode(stored);

        if (stored && mode === 'system' && stored !== 'system') {
            storage.removeItem(THEME_STORAGE_KEY);
        }

        return mode;
    } catch {
        return 'system';
    }
}

export function writeThemeMode(storage, mode) {
    const normalized = normalizeThemeMode(mode);

    if (!storage) {
        return false;
    }

    try {
        if (normalized === 'system') {
            storage.removeItem(THEME_STORAGE_KEY);
        } else {
            storage.setItem(THEME_STORAGE_KEY, normalized);
        }

        return true;
    } catch {
        return false;
    }
}

export function resolveTheme(mode, prefersDark = false) {
    const normalized = normalizeThemeMode(mode);

    return normalized === 'system'
        ? (prefersDark ? 'dark' : 'light')
        : normalized;
}

function browserStorage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function updateThemeColor(theme) {
    const meta = document.querySelector('meta[name="theme-color"]');

    if (meta) {
        meta.content = theme === 'dark' ? '#0b1220' : '#f5f6f1';
    }
}

export function setControlState(toggle, systemButton, mode, theme) {
    if (toggle) {
        const switchesTo = theme === 'dark' ? 'light' : 'dark';
        toggle.setAttribute('aria-checked', String(theme === 'dark'));
        toggle.setAttribute('aria-label', `Switch to ${switchesTo} theme`);
        toggle.title = `Switch to ${switchesTo} theme`;
    }

    if (systemButton) {
        const followsSystem = mode === 'system';
        systemButton.setAttribute('aria-pressed', String(followsSystem));
        systemButton.setAttribute('aria-label', 'Use automatic system theme');
        systemButton.title = 'Use automatic system theme';
    }
}

export function initTheme() {
    const toggle = document.querySelector('[data-theme-toggle]');
    const systemButton = document.querySelector('[data-theme-system]');
    const media = window.matchMedia?.('(prefers-color-scheme: dark)');
    const storage = browserStorage();
    let mode = readThemeMode(storage);

    function apply() {
        const theme = resolveTheme(mode, Boolean(media?.matches));
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.themeMode = mode;
        document.documentElement.style.colorScheme = theme;
        updateThemeColor(theme);
        setControlState(toggle, systemButton, mode, theme);
    }

    toggle?.addEventListener('click', () => {
        const current = resolveTheme(mode, Boolean(media?.matches));
        mode = current === 'dark' ? 'light' : 'dark';
        writeThemeMode(storage, mode);
        apply();
    });

    systemButton?.addEventListener('click', () => {
        mode = 'system';
        writeThemeMode(storage, mode);
        apply();
    });

    media?.addEventListener?.('change', () => {
        if (mode === 'system') {
            apply();
        }
    });

    apply();
}
