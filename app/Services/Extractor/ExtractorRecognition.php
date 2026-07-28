<?php

namespace App\Services\Extractor;

use App\Enums\MediaPlatform;

final readonly class ExtractorRecognition
{
    public function __construct(
        public string $requestId,
        public MediaPlatform $platform,
        public string $normalizedUrl,
        public string $status = 'not_implemented',
        public string $mediaType = 'unknown',
        public ?array $metadata = null,
        public array $assets = [],
        public array $capabilities = [],
    ) {}
}
