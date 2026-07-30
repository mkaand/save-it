<?php

namespace App\Services\Downloads;

class UpstreamUrlPolicy
{
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
        }

        return $url;
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
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
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
