<?php

namespace App\Services\Downloads;

class UpstreamUrlPolicy
{
    /** @var array<string, array<int, string>> */
    private static array $pinnedResolvers = [];

    /** @var array<string, array<int, string>> */
    private const HOSTS = [
        'x' => ['pbs.twimg.com', 'video.twimg.com'],
        'instagram' => ['.cdninstagram.com', '.fbcdn.net'],
        'linkedin' => ['.licdn.com'],
        'youtube' => ['.googlevideo.com', 'i.ytimg.com', 'img.youtube.com'],
    ];

    public function validate(string $url, string $provider, bool $resolveDns = true): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || ! $this->hostAllowed($host, $provider)
        ) {
            throw $this->unsafe();
        }

        if ($resolveDns) {
            $addresses = $this->resolveAddresses($host);
            if ($addresses === []) {
                throw new DownloadException(
                    'upstream_unavailable',
                    503,
                    'The media source is temporarily unavailable.',
                );
            }
            foreach ($addresses as $address) {
                if (! $this->isPublicAddress($address)) {
                    throw $this->unsafe();
                }
            }
            self::$pinnedResolvers[$this->cacheKey($url, $provider)] = $this->curlResolve($host, $addresses);
        }

        return $url;
    }

    /** @return array{url: string, curl_resolve: array<int, string>} */
    public function prepareRequest(string $url, string $provider): array
    {
        $this->validate($url, $provider, false);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $addresses = $this->resolveAddresses($host);
        if ($addresses === []) {
            throw new DownloadException(
                'upstream_unavailable',
                503,
                'The media source is temporarily unavailable.',
            );
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw $this->unsafe();
            }
        }

        $pinned = array_map(fn (string $address): string => str_contains($address, ':')
            ? "[{$address}]"
            : $address, $addresses);

        return [
            'url' => $url,
            'curl_resolve' => ["{$host}:443:".implode(',', $pinned)],
        ];
    }

    /** @return array<int, string> */
    public static function curlResolveFor(string $url, string $provider): array
    {
        return self::$pinnedResolvers[self::cacheKey($url, $provider)] ?? [];
    }

    public function redirect(string $baseUrl, string $location, string $provider): string
    {
        if (str_starts_with($location, '/')) {
            $parts = parse_url($baseUrl);
            $location = 'https://'.($parts['host'] ?? '').$location;
        }

        return $this->validate($location, $provider);
    }

    /** @return array<int, string> */
    protected function resolveAddresses(string $host): array
    {
        $addresses = [];
        foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function hostAllowed(string $host, string $provider): bool
    {
        foreach (self::HOSTS[$provider] ?? [] as $allowed) {
            if ($allowed[0] === '.') {
                if ($host !== substr($allowed, 1) && str_ends_with($host, $allowed)) {
                    return true;
                }
            } elseif (hash_equals($allowed, $host)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return false;
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /** @param array<int, string> $addresses
     * @return array<int, string>
     */
    private function curlResolve(string $host, array $addresses): array
    {
        $pinned = array_map(fn (string $address): string => str_contains($address, ':')
            ? "[{$address}]"
            : $address, $addresses);

        return ["{$host}:443:".implode(',', $pinned)];
    }

    private static function cacheKey(string $url, string $provider): string
    {
        return $provider.'|'.$url;
    }

    private function unsafe(): DownloadException
    {
        return new DownloadException(
            'unsafe_media_source',
            422,
            'The media source did not pass the download security policy.',
        );
    }
}
