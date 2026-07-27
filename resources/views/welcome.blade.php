<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Analyze supported media URLs and preview planned download formats with Save It. No account required.">
    <meta name="theme-color" content="#0b1220">
    <meta property="og:title" content="Save It — Download media. Keep it simple.">
    <meta property="og:description" content="Paste a supported media URL, analyze the available format plan, and keep recent fetches in your browser.">
    <meta property="og:url" content="https://save.allmy.win/">
    <meta property="og:type" content="website">
    <link rel="canonical" href="https://save.allmy.win/">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <title>Save It — Simple media downloads</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>

    <header class="site-header" data-site-header>
        <div class="shell header-inner">
            <a class="brand" href="#top" aria-label="Save It home">
                <span class="brand-mark" aria-hidden="true">
                    <span></span>
                </span>
                <span>Save It</span>
            </a>

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

            <nav class="site-nav" id="site-navigation" aria-label="Primary navigation" data-site-nav>
                <a href="#platforms">Platforms</a>
                <a href="#how-it-works">How it works</a>
                <a href="#privacy">Privacy</a>
            </nav>
        </div>
    </header>

    <main id="main-content">
        <section class="hero" id="top">
            <div class="hero-orbit hero-orbit-one" aria-hidden="true"></div>
            <div class="hero-orbit hero-orbit-two" aria-hidden="true"></div>

            <div class="shell hero-grid">
                <div class="hero-copy">
                    <p class="eyebrow"><span></span> Simple by design. Private by default.</p>
                    <h1>Download media.<br><em>Keep it simple.</em></h1>
                    <p class="hero-lead">
                        Paste a supported media URL, analyze the format plan, and choose what you need.
                        No account required.
                    </p>

                    <ul class="hero-points" aria-label="Save It benefits">
                        <li><span aria-hidden="true">✓</span> Fast URL analysis</li>
                        <li><span aria-hidden="true">✓</span> Clear format previews</li>
                        <li><span aria-hidden="true">✓</span> Browser-local history</li>
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
                        <p class="field-help" id="url-help">HTTP and HTTPS links from a listed platform are accepted.</p>
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
                        <h2 id="platform-heading">Built for the links you use.</h2>
                    </div>
                    <p>URL recognition is ready today. Download availability expands with the media engine.</p>
                </div>

                <div class="platform-grid">
                    <article class="platform-card is-primary">
                        <span class="platform-monogram" aria-hidden="true">YT</span>
                        <div><h3>YouTube</h3><p>Videos and compatible format planning</p></div>
                        <span class="status-badge status-mvp">MVP</span>
                    </article>
                    <article class="platform-card is-primary">
                        <span class="platform-monogram" aria-hidden="true">YS</span>
                        <div><h3>YouTube Shorts</h3><p>Short-form URL detection</p></div>
                        <span class="status-badge status-mvp">MVP</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-monogram" aria-hidden="true">IG</span>
                        <div><h3>Instagram</h3><p>URL analysis foundation</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-monogram" aria-hidden="true">TT</span>
                        <div><h3>TikTok</h3><p>URL analysis foundation</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-monogram" aria-hidden="true">X</span>
                        <div><h3>X</h3><p>URL analysis foundation</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-monogram" aria-hidden="true">FB</span>
                        <div><h3>Facebook</h3><p>URL analysis foundation</p></div>
                        <span class="status-badge">Planned</span>
                    </article>
                    <article class="platform-card">
                        <span class="platform-monogram" aria-hidden="true">in</span>
                        <div><h3>LinkedIn</h3><p>Early URL analysis support</p></div>
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
                <p class="recent-intro">Your five latest successful analyses stay on this device.</p>
                <div class="recent-grid" data-recent-list></div>
            </div>
        </section>

        <section class="steps-section" id="how-it-works" aria-labelledby="steps-heading">
            <div class="shell">
                <div class="section-heading centered-heading">
                    <p class="eyebrow"><span></span> A straightforward flow</p>
                    <h2 id="steps-heading">From link to format plan in three steps.</h2>
                </div>

                <ol class="steps-grid">
                    <li>
                        <span class="step-number">01</span>
                        <span class="step-icon" aria-hidden="true">⌁</span>
                        <h3>Paste</h3>
                        <p>Add a URL from one of the recognized platforms.</p>
                    </li>
                    <li>
                        <span class="step-number">02</span>
                        <span class="step-icon" aria-hidden="true">◎</span>
                        <h3>Analyze</h3>
                        <p>Save It validates the link and prepares a safe preview.</p>
                    </li>
                    <li>
                        <span class="step-number">03</span>
                        <span class="step-icon" aria-hidden="true">↓</span>
                        <h3>Choose</h3>
                        <p>Review planned formats. Real downloads arrive with the media engine.</p>
                    </li>
                </ol>
            </div>
        </section>

        <section class="privacy-section" id="privacy" aria-labelledby="privacy-heading">
            <div class="shell privacy-grid">
                <div class="privacy-copy">
                    <p class="eyebrow light-eyebrow"><span></span> Clear privacy choices</p>
                    <h2 id="privacy-heading">Your recent links stay close.</h2>
                    <p>
                        Save It does not require an account. URL analysis is sent to the application server,
                        while Recent Fetches remain in this browser and are never synced between devices.
                    </p>
                    <p>You can clear browser-local history at any time.</p>
                </div>
                <ul class="privacy-list">
                    <li><span aria-hidden="true">01</span><div><strong>No account</strong><p>Analyze without creating a profile.</p></div></li>
                    <li><span aria-hidden="true">02</span><div><strong>Local recents</strong><p>Only minimal preview details are stored locally.</p></div></li>
                    <li><span aria-hidden="true">03</span><div><strong>In your control</strong><p>Clear your recent list whenever you choose.</p></div></li>
                </ul>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="shell footer-inner">
            <div>
                <a class="brand footer-brand" href="#top">
                    <span class="brand-mark" aria-hidden="true"><span></span></span>
                    <span>Save It</span>
                </a>
                <p>Simple media analysis, with less noise.</p>
            </div>
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
