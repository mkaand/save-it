<?php

namespace Tests\Feature;

use App\Services\Downloads\DownloadException;
use App\Services\Downloads\UpstreamUrlPolicy;
use App\Services\Previews\RecentPreviewStore;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RecentPreviewStoreTest extends TestCase
{
    /** @var array<int, string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_cached_preview_remains_available_after_the_provider_source_fails(): void
    {
        $url = 'https://i.ytimg.com/vi/example/preview.png';
        Http::fake([
            $url => Http::response($this->png(), 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) strlen($this->png()),
            ]),
        ]);
        $store = new RecentPreviewStore($this->policy($url));
        $identifier = $store->issue($this->asset($url));
        $cached = (new RecentPreviewStore(Mockery::mock(UpstreamUrlPolicy::class)))->resolve($identifier);

        $this->assertNotNull($cached);
        $this->assertSame('image/png', $cached['mime_type']);
        $this->assertStringNotContainsString('ytimg', json_encode($cached, JSON_THROW_ON_ERROR));
        $this->files[] = $cached['path'];

        Http::fake([$url => Http::response('', 410)]);
        $this->get("/api/previews/{$identifier}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        Http::assertNothingSent();
    }

    public function test_invalid_image_mime_is_rejected_before_it_is_cached(): void
    {
        $url = 'https://i.ytimg.com/vi/example/preview.txt';
        Http::fake([$url => Http::response('not an image', 200, ['Content-Type' => 'text/plain'])]);

        $this->expectException(DownloadException::class);
        (new RecentPreviewStore($this->policy($url)))->issue($this->asset($url));
    }

    public function test_declared_oversize_preview_is_rejected_before_streaming(): void
    {
        $url = 'https://i.ytimg.com/vi/example/preview.jpg';
        Http::fake([$url => Http::response('', 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => '524289',
        ])]);

        $this->expectException(DownloadException::class);
        (new RecentPreviewStore($this->policy($url)))->issue($this->asset($url));
    }

    private function policy(string $url): UpstreamUrlPolicy
    {
        $policy = Mockery::mock(UpstreamUrlPolicy::class);
        $policy->shouldReceive('validate')->once()->with($url, 'youtube')->andReturn($url);

        return $policy;
    }

    /** @return array<string, mixed> */
    private function asset(string $url): array
    {
        return ['provider' => 'youtube', 'upstream_url' => $url];
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL6xQAAAABJRU5ErkJggg==', true) ?: '';
    }
}
