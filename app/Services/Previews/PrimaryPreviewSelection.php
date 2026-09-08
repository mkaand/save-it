<?php

namespace App\Services\Previews;

final readonly class PrimaryPreviewSelection
{
    public function __construct(
        public ?string $source,
        public ?string $sourceKind,
        public ?string $assetId,
        public ?int $assetOrder,
        public ?string $assetType,
        public bool $isExplicit,
    ) {}

    public static function unavailable(): self
    {
        return new self(null, null, null, null, null, false);
    }

    public function isAvailable(): bool
    {
        return $this->source !== null;
    }
}
