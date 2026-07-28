<!DOCTYPE html>
<html lang="en" data-theme-mode="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="Analyze supported media URLs and preview planned download formats with Save It. No account required.">
    <meta name="theme-color" content="#0b1220">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Save It">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta property="og:title" content="Save It — Download media. Keep it simple.">
    <meta property="og:description" content="Paste a supported media URL, analyze the available format plan, and keep recent fetches in your browser.">
    <meta property="og:url" content="https://save.allmy.win/">
    <meta property="og:type" content="website">
    <link rel="canonical" href="https://save.allmy.win/">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
    <link rel="manifest" href="/site.webmanifest">
    <title>Save It — Simple media downloads</title>
    <script>
        (() => {
            try {
                const key = 'save-it.theme.v1';
                const stored = localStorage.getItem(key);
                const mode = ['light', 'dark'].includes(stored) ? stored : 'system';
                if (stored && mode === 'system') localStorage.removeItem(key);
                const theme = mode === 'system'
                    ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                    : mode;
                document.documentElement.dataset.theme = theme;
                document.documentElement.dataset.themeMode = mode;
                document.documentElement.style.colorScheme = theme;
            } catch {
                document.documentElement.dataset.theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>

    <header class="site-header" data-site-header>
        <div class="shell header-inner">
            <a class="brand" href="#top" aria-label="Save It home">
                <span class="brand-mark" aria-hidden="true">
                    <img src="/brand/save-it-mark.svg" alt="" width="32" height="32">
                </span>
                <span>Save It</span>
            </a>

            <nav class="site-nav" id="site-navigation" aria-label="Primary navigation" data-site-nav>
                <a href="#platforms">Platforms</a>
                <a href="#how-it-works">How it works</a>
                <a href="#privacy">Privacy</a>
            </nav>

            <div class="header-actions">
                <div class="theme-control" aria-label="Theme controls">
                    <button
                        class="theme-toggle"
                        type="button"
                        role="switch"
                        aria-checked="false"
                        aria-label="Switch to light theme"
                        data-theme-toggle
                    >
                        <span class="theme-sun" aria-hidden="true">☀</span>
                        <span class="theme-toggle-track" aria-hidden="true"><span></span></span>
                        <span class="theme-moon" aria-hidden="true">☾</span>
                    </button>
                    <button
                        class="theme-system"
                        type="button"
                        aria-pressed="true"
                        aria-label="Use automatic system theme"
                        title="Use automatic system theme"
                        data-theme-system
                    ><span aria-hidden="true">A</span><span class="sr-only">Automatic</span></button>
                </div>

                <button
                    class="nav-toggle"
                    type="button"
                    aria-expanded="false"
                    aria-controls="site-navigation"
                    data-nav-toggle
                >
                    <span class="sr-only">Toggle navigation</span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </header>

    <main id="main-content">
        <section class="hero" id="top">
            <div class="hero-orbit hero-orbit-one" aria-hidden="true"></div>
            <div class="hero-orbit hero-orbit-two" aria-hidden="true"></div>

            <div class="shell hero-grid">
                <div class="hero-copy">
                    <a
                        class="open-source-link"
                        href="https://github.com/mkaand/save-it"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Open source on GitHub — explore the Save It repository"
                    >
                        <img src="/brand/github-mark.svg" width="16" height="16" alt="" aria-hidden="true">
                        <span><strong>Open source on GitHub</strong><small>Built in public</small></span>
                        <span class="open-source-arrow" aria-hidden="true">↗</span>
                    </a>
                    <p class="eyebrow"><span></span> Fast preview. Less noise.</p>
                    <h1>Download media.<br><em>Keep it simple.</em></h1>
                    <p class="hero-lead">
                        Paste a recognized media link, preview the format plan, and choose what you need.
                        No account required.
                    </p>

                    <ul class="hero-points" aria-label="Save It benefits">
                        <li><span aria-hidden="true">✓</span> Fast analysis</li>
                        <li><span aria-hidden="true">✓</span> Clear previews</li>
                        <li><span aria-hidden="true">✓</span> Local recents</li>
                    </ul>
                </div>

                <div class="analyzer-card" data-analyzer>
                    <div class="analyzer-heading">
                        <div>
                            <p class="card-kicker">URL analyzer</p>
                            <h2>What would you like to save?</h2>
                        </div>
                        <span class="privacy-chip"><span aria-hidden="true"></span> No account</span>
                    </div>

                    <form class="analyzer-form" data-analyzer-form novalidate>
                        <label for="media-url">Media URL</label>
                        <div class="url-control">
                            <span class="url-icon" aria-hidden="true">↗</span>
                            <input
                                id="media-url"
                                name="url"
                                type="url"
                                inputmode="url"
                                autocomplete="url"
                                spellcheck="false"
                                placeholder="https://www.youtube.com/watch?v=..."
                                aria-describedby="url-help url-error"
                                aria-invalid="false"
                                required
                                data-url-input
                            >
                            <button class="paste-button" type="button" hidden data-paste-button>Paste</button>
                        </div>
                        <p class="field-help" id="url-help">Use an HTTP or HTTPS link from the platform roadmap.</p>
                        <p class="field-error" id="url-error" role="alert" data-url-error hidden></p>

                        <button class="analyze-button" type="submit" data-analyze-button>
                            <span data-analyze-label>Analyze URL</span>
                            <span class="button-arrow" aria-hidden="true">→</span>
                            <span class="spinner" aria-hidden="true"></span>
                        </button>
                    </form>

                    <p class="analyzer-status" aria-live="polite" aria-atomic="true" data-analyzer-status>
                        Ready when you are.
                    </p>
                </div>
            </div>
        </section>

        <section class="platform-section" id="platforms" aria-labelledby="platform-heading">
            <div class="shell">
                <div class="section-heading platform-heading">
                    <div>
                        <p class="eyebrow"><span></span> Platform roadmap</p>
                        <h2 id="platform-heading">Recognized links, honest status.</h2>
                    </div>
                    <p>X media extraction is available now. Download delivery and other providers remain on the roadmap.</p>
                </div>

                <div class="platform-grid">
                    <article class="platform-card">
                        <span class="platform-icon-slot" data-platform-icon="youtube" aria-hidden="true">YT</span>
                        <div><h3>YouTube</h3><p>Video URL preview</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-icon-slot" data-platform-icon="youtube_shorts" aria-hidden="true">YS</span>
                        <div><h3>YouTube Shorts</h3><p>Short-form detection</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-icon-slot" data-platform-icon="instagram" aria-hidden="true">IG</span>
                        <div><h3>Instagram</h3><p>Recognition only</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-icon-slot" data-platform-icon="tiktok" aria-hidden="true">TT</span>
                        <div><h3>TikTok</h3><p>Recognition only</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card is-primary" data-x-extractor-available>
                        <span class="platform-icon-slot" data-platform-icon="x" aria-hidden="true">X</span>
                        <div><h3>X</h3><p>Media extraction</p></div>
                        <span class="status-badge status-mvp">Available</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-icon-slot" data-platform-icon="facebook" aria-hidden="true">FB</span>
                        <div><h3>Facebook</h3><p>Recognition only</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-icon-slot" data-platform-icon="linkedin" aria-hidden="true">in</span>
                        <div><h3>LinkedIn</h3><p>Early URL preview</p></div>
                        <span class="status-badge status-beta">Beta</span>
                    </article>
                </div>
            </div>
        </section>

        <section class="result-section" aria-labelledby="result-heading" data-result-section hidden>
            <div class="shell">
                <div class="section-heading compact-heading">
                    <div>
                        <p class="eyebrow"><span></span> Analysis preview</p>
                        <h2 id="result-heading">Your media plan</h2>
                    </div>
                    <span class="preview-badge">Preview only</span>
                </div>
                <div class="result-panel" data-result-panel></div>
            </div>
        </section>

        <section class="recent-section" aria-labelledby="recent-heading" data-recent-section hidden>
            <div class="shell">
                <div class="section-heading compact-heading">
                    <div>
                        <p class="eyebrow"><span></span> This browser only</p>
                        <h2 id="recent-heading">Recent Fetches</h2>
                    </div>
                    <button class="text-button" type="button" data-clear-recent>Clear all</button>
                </div>
                <div class="recent-grid" data-recent-list></div>
            </div>
        </section>

        <section class="steps-section" id="how-it-works" aria-labelledby="steps-heading">
            <div class="shell steps-layout">
                <div class="section-heading steps-heading">
                    <div>
                        <p class="eyebrow"><span></span> How it works</p>
                        <h2 id="steps-heading">Three clear steps.</h2>
                    </div>
                </div>

                <ol class="steps-grid">
                    <li><span class="step-number">01</span><div><h3>Paste</h3><p>Add a recognized platform URL.</p></div></li>
                    <li><span class="step-number">02</span><div><h3>Analyze</h3><p>Validate it and prepare a safe preview.</p></div></li>
                    <li><span class="step-number">03</span><div><h3>Choose</h3><p>Review planned formats. Downloads are not active yet.</p></div></li>
                </ol>
            </div>
        </section>

        <section class="privacy-section" id="privacy" aria-labelledby="privacy-heading">
            <div class="shell privacy-grid">
                <div class="privacy-copy">
                    <p class="eyebrow"><span></span> Privacy, explained</p>
                    <h2 id="privacy-heading">No account. Browser-local recents.</h2>
                    <p>
                        URL analysis is sent to the application server. Your five recent previews stay in this browser,
                        are not synced, and can be cleared whenever you choose.
                    </p>
                </div>
                <details class="privacy-details">
                    <summary>What stays in this browser?</summary>
                    <div>
                        <p>Only minimal preview details such as platform, title, URL, thumbnail, and analysis time.</p>
                        <p>Save It does not promise absolute anonymity or claim that server logs do not exist.</p>
                    </div>
                </details>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="shell footer-inner">
            <a class="brand footer-brand" href="#top">
                <span class="brand-mark" aria-hidden="true">
                    <img src="/brand/save-it-mark.svg" alt="" width="28" height="28">
                </span>
                <span>Save It</span>
            </a>
            <p class="footer-tagline">Simple media analysis, with less noise.</p>
            <nav aria-label="Footer navigation">
                <a href="#platforms">Platforms</a>
                <a href="#how-it-works">How it works</a>
                <a href="#privacy">Privacy</a>
            </nav>
            <p class="copyright">© <span data-current-year></span> Save It</p>
        </div>
    </footer>
</body>
</html>
