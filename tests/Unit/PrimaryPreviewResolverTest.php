<?php

namespace Tests\Unit;

use App\Services\Previews\PrimaryPreviewResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrimaryPreviewResolverTest extends TestCase
{
    public function test_explicit_preview_takes_precedence_and_retains_matching_asset_context(): void
    {
        $assets = [
            $this->imageAsset(2),
            $this->imageAsset(1),
        ];
        $selection = (new PrimaryPreviewResolver)->select([
            'thumbnail_url' => $assets[0]['thumbnail_url'],
        ], $assets);

        $this->assertTrue($selection->isAvailable());
        $this->assertTrue($selection->isExplicit);
        $this->assertSame('explicit', $selection->sourceKind);
        $this->assertSame('asset-2', $selection->assetId);
        $this->assertSame(2, $selection->assetOrder);
        $this->assertSame('image', $selection->assetType);
    }

    #[DataProvider('derivedPreviewProvider')]
    public function test_selects_the_first_representable_visual_asset_in_deterministic_order(
        array $assets,
        ?string $expectedAssetId,
        ?string $expectedSourceKind,
    ): void {
        $selection = (new PrimaryPreviewResolver)->select([], $assets);

        $this->assertSame($expectedAssetId, $selection->assetId);
        $this->assertSame($expectedSourceKind, $selection->sourceKind);
        $this->assertSame($expectedAssetId !== null, $selection->isAvailable());
    }

    /**
     * @return array<string, array{array<int, array<string, mixed>>, ?string, ?string}>
     */
    public static function derivedPreviewProvider(): array
    {
        return [
            'image carousel uses first ordered image' => [
                [self::imageAsset(2), self::imageAsset(1)],
                'asset-1',
                'asset_thumbnail',
            ],
            'image before video uses the image' => [
                [self::videoAsset(2), self::imageAsset(1)],
                'asset-1',
                'asset_thumbnail',
            ],
            'video poster precedes a later image' => [
                [self::imageAsset(2), self::videoAsset(1)],
                'asset-1',
                'asset_thumbnail',
            ],
            'video without poster falls through to the next image' => [
                [self::videoAsset(1, null), self::imageAsset(2)],
                'asset-2',
                'asset_thumbnail',
            ],
            'video only without poster is unavailable' => [
                [self::videoAsset(1, null)],
                null,
                null,
            ],
            'image source is used when it has no thumbnail' => [
                [self::imageAsset(1, null)],
                'asset-1',
                'asset_image_source',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function imageAsset(int $order, ?string $thumbnail = 'https://example.test/image.jpg'): array
    {
        return [
            'id' => "asset-{$order}",
            'order' => $order,
            'type' => 'image',
            'thumbnail_url' => $thumbnail === null ? null : "https://example.test/image-{$order}.jpg",
            'url' => "https://example.test/image-{$order}.jpg",
        ];
    }

    /** @return array<string, mixed> */
    private static function videoAsset(int $order, ?string $thumbnail = 'https://example.test/poster.jpg'): array
    {
        return [
            'id' => "asset-{$order}",
            'order' => $order,
            'type' => 'video',
            'thumbnail_url' => $thumbnail === null ? null : "https://example.test/poster-{$order}.jpg",
            'url' => "https://example.test/video-{$order}.mp4",
        ];
    }
}
