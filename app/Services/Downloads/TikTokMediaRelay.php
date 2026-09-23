<?php

namespace App\Services\Downloads;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class TikTokMediaRelay
{
    public function __construct(private readonly UpstreamUrlPolicy $policy) {}

    /** @param array<string, mixed> $asset */
    public function request(array $asset, ?string $range): Response
    {
        $source = $asset['source_page_url'] ?? null;
        $media = $asset['upstream_url'] ?? null;
        if (! is_string($source) || ! is_string($media)) {
            throw new DownloadException('invalid_download_token', 410, 'The download plan is invalid.');
        }

        $source = $this->policy->validate($source, 'tiktok_session');
        $media = $this->policy->validate($media, 'tiktok');
        $request = Http::accept('*/*')
            ->asJson()
            ->connectTimeout((float) config('services.extractor.connect_timeout_seconds'))
            ->timeout((float) config('services.downloads.timeout_seconds'))
            ->withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'proxy' => '',
            ]);
        if ($range !== null) {
            $request = $request->withHeader('Range', $range);
        }

        return $request->post(rtrim((string) config('services.extractor.base_url'), '/').'/v1/tiktok/media', [
            'source_page_url' => $source,
            'media_url' => $media,
        ]);
    }
}
