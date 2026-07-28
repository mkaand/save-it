<?php

namespace App\Services\Extractor;

use RuntimeException;

final class ExtractorException extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        public readonly int $httpStatus,
        public readonly string $requestId,
        string $message,
    ) {
        parent::__construct($message);
    }
}
