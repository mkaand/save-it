<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_landing_page_returns_http_200(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_landing_page_contains_application_name(): void
    {
        $this->get('/')->assertSee('Media Downloader');
    }

    public function test_health_endpoint_returns_http_200(): void
    {
        $this->getJson('/health')->assertOk();
    }

    public function test_health_endpoint_returns_expected_json(): void
    {
        $this->getJson('/health')->assertExactJson([
            'status' => 'ok',
            'application' => 'Media Downloader',
        ]);
    }
}
