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

        $footer = substr($content, (int) strpos($content, '<footer'));
        $this->assertTrue(strpos($footer, 'footer-brand') < strpos($footer, 'footer-tagline'));
        $this->assertTrue(strpos($footer, 'footer-tagline') < strpos($footer, '<nav'));
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
}
