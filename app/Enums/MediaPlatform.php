<?php

namespace App\Enums;

enum MediaPlatform: string
{
    case YouTube = 'youtube';
    case YouTubeShorts = 'youtube_shorts';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case X = 'x';
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';

    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::YouTubeShorts => 'YouTube Shorts',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::X => 'X',
            self::Facebook => 'Facebook',
            self::LinkedIn => 'LinkedIn',
        };
    }

    public function mediaType(): string
    {
        return $this === self::YouTubeShorts ? 'short_video' : 'video';
    }
}
