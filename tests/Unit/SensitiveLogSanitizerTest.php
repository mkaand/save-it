<?php

namespace Tests\Unit;

use App\Logging\SensitiveLogSanitizer;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SensitiveLogSanitizerTest extends TestCase
{
    #[Test]
    public function it_redacts_tokens_credentials_and_sensitive_query_values(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Warning,
            message: 'Authorization: Bearer secret /api/downloads/'.str_repeat('a', 48).'.'.str_repeat('b', 64).'?sig=value',
            context: ['cookie' => 'session=value', 'provider' => 'instagram'],
        );

        $safe = (new SensitiveLogSanitizer)($record);

        $this->assertStringNotContainsString('secret', $safe->message);
        $this->assertStringNotContainsString('value', $safe->message);
        $this->assertSame('[REDACTED]', $safe->context['cookie']);
        $this->assertSame('instagram', $safe->context['provider']);
    }
}
