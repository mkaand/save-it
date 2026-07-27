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
}
