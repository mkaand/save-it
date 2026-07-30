<?php

namespace App\Services\Downloads;

use RuntimeException;

final class DownloadException extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        public readonly int $httpStatus,
        string $message,
        /** @var array<string, string> */
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
