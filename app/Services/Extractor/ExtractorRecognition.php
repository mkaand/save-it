<?php

namespace App\Services\Extractor;

use App\Enums\MediaPlatform;

final readonly class ExtractorRecognition
{
    public function __construct(
        public string $requestId,
        public MediaPlatform $platform,
        public string $normalizedUrl,
    ) {}
}
