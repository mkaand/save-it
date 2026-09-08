<?php

namespace App\Services\Previews;

final class PrimaryPreviewResolver
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array<string, mixed>>  $assets
     */
    public function select(array $metadata, array $assets): PrimaryPreviewSelection
    {
        $orderedAssets = $this->orderedAssets($assets);
        $explicitSource = $this->source($metadata['thumbnail_url'] ?? null);

        if ($explicitSource !== null) {
            $asset = $this->assetForSource($explicitSource, $orderedAssets);

            return new PrimaryPreviewSelection(
                $explicitSource,
                'explicit',
                is_array($asset) ? $this->stringValue($asset['id'] ?? null) : null,
                is_array($asset) ? $this->positiveInt($asset['order'] ?? null) : null,
                is_array($asset) ? $this->stringValue($asset['type'] ?? null) : null,
                true,
            );
        }

        foreach ($orderedAssets as $asset) {
            $source = $this->previewSourceForAsset($asset);
            if ($source === null) {
                continue;
            }

            return new PrimaryPreviewSelection(
                $source,
                $this->source($asset['thumbnail_url'] ?? null) === null
                    ? 'asset_image_source'
                    : 'asset_thumbnail',
                $this->stringValue($asset['id'] ?? null),
                $this->positiveInt($asset['order'] ?? null),
                $this->stringValue($asset['type'] ?? null),
                false,
            );
        }

        return PrimaryPreviewSelection::unavailable();
    }

    /** @param array<string, mixed> $asset */
    public function previewSourceForAsset(array $asset): ?string
    {
        $thumbnail = $this->source($asset['thumbnail_url'] ?? null);
        if ($thumbnail !== null) {
            return $thumbnail;
        }

        return ($asset['type'] ?? null) === 'image'
            ? $this->source($asset['url'] ?? null)
            : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<int, array<string, mixed>>
     */
    private function orderedAssets(array $assets): array
    {
        usort($assets, function (array $left, array $right): int {
            $order = ($this->positiveInt($left['order'] ?? null) ?? PHP_INT_MAX)
                <=> ($this->positiveInt($right['order'] ?? null) ?? PHP_INT_MAX);

            return $order !== 0
                ? $order
                : strcmp($this->stringValue($left['id'] ?? null) ?? '', $this->stringValue($right['id'] ?? null) ?? '');
        });

        return $assets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<string, mixed>|null
     */
    private function assetForSource(string $source, array $assets): ?array
    {
        foreach ($assets as $asset) {
            if ($source === $this->previewSourceForAsset($asset)) {
                return $asset;
            }
        }

        return null;
    }

    private function source(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
