<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_landing_page_contains_the_save_it_experience(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Save It')
            ->assertSee('Download media.')
            ->assertSee('data-analyzer-form', false)
            ->assertSee('data-theme-toggle', false)
            ->assertSee('data-theme-system', false)
            ->assertSee('save-it.theme.v1', false)
            ->assertSee('data-platform-icon="youtube"', false)
            ->assertSee('data-platform-icon="linkedin"', false)
            ->assertSee('YouTube Shorts')
            ->assertSee('LinkedIn')
            ->assertSee('Beta')
            ->assertSee('data-recent-section', false)
            ->assertSee('data-instagram-extractor-available', false)
            ->assertSee('<details class="privacy-details">', false)
            ->assertDontSee('Login')
            ->assertDontSee('Register');
    }

    public function test_health_endpoint_is_safe_and_stateless(): void
    {
        $response = $this->getJson('/health');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Set-Cookie')
            ->assertExactJson([
                'status' => 'ok',
                'application' => 'Save It',
            ]);

        $this->assertStringNotContainsString('APP_KEY', $response->getContent());
        $this->assertStringNotContainsString('debug', strtolower($response->getContent()));
    }

    public function test_brand_icons_manifest_footer_and_automatic_theme_control_are_present(): void
    {
        $response = $this->get('/')->assertOk();
        $content = $response->getContent();

        $response
            ->assertSee('/brand/save-it-mark.svg', false)
            ->assertSee('/favicon.svg', false)
            ->assertSee('/apple-touch-icon.png', false)
            ->assertSee('/site.webmanifest', false)
            ->assertSee('apple-mobile-web-app-title', false)
            ->assertSee('content="Save It"', false)
            ->assertSee('aria-label="Use automatic system theme"', false)
            ->assertSee('class="footer-tagline"', false);
        $response
            ->assertSee('Built with AI-assisted development support from OpenAI ChatGPT and OpenAI Codex.')
            ->assertSee('data-instagram-extractor-available', false);

        $footer = substr($content, (int) strpos($content, '<footer'));
        $this->assertTrue(strpos($footer, 'footer-brand') < strpos($footer, 'footer-tagline'));
        $this->assertTrue(strpos($footer, 'footer-tagline') < strpos($footer, 'footer-ai-notice'));
        $this->assertTrue(strpos($footer, 'footer-ai-notice') < strpos($footer, '<nav'));
        $this->assertTrue(strpos($footer, '<nav') < strpos($footer, 'copyright'));

        foreach ([
            'public/favicon.ico',
            'public/favicon.svg',
            'public/apple-touch-icon.png',
            'public/icons/icon-192.png',
            'public/icons/icon-512.png',
            'public/icons/icon-maskable-512.png',
            'public/site.webmanifest',
        ] as $asset) {
            $this->assertFileExists(base_path($asset));
            $this->assertGreaterThan(0, filesize(base_path($asset)));
        }
    }

    public function test_social_preview_and_seo_metadata_are_complete(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('property="og:image"', false)
            ->assertSee('content="https://save.allmy.win/social/save-it-social-card.png"', false)
            ->assertSee('name="twitter:card" content="summary_large_image"', false)
            ->assertSee('name="twitter:title"', false)
            ->assertSee('name="twitter:description"', false)
            ->assertSee('name="twitter:image"', false)
            ->assertSee('rel="canonical" href="https://save.allmy.win/"', false);

        $this->assertFileExists(public_path('social/save-it-social-card.png'));
        $this->assertFileExists(public_path('robots.txt'));
        $this->assertFileExists(public_path('sitemap.xml'));
        $this->assertStringContainsString(
            'Sitemap: https://save.allmy.win/sitemap.xml',
            file_get_contents(public_path('robots.txt')),
        );
    }

    public function test_umami_loads_only_when_production_configuration_is_complete(): void
    {
        config()->set('services.umami.enabled', true);
        config()->set('services.umami.script_url', 'https://stats.allmy.win/script.js');
        config()->set('services.umami.website_id', 'test-website-id');

        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/')
            ->assertOk()
            ->assertSee('src="https://stats.allmy.win/script.js"', false)
            ->assertSee('data-website-id="test-website-id"', false);

        $this->app->detectEnvironment(fn () => 'local');
        $this->get('/')
            ->assertOk()
            ->assertDontSee('data-umami-enabled', false);
    }

    public function test_open_source_github_notice_is_safe_and_local(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Open source on GitHub')
            ->assertSee('https://github.com/mkaand/save-it', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('/brand/github-mark.svg', false)
            ->assertSee('data-x-extractor-available', false)
            ->assertDontSee('GitHub stars')
            ->assertDontSee('GitHub forks');
    }
}
