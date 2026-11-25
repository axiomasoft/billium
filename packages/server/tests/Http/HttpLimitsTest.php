<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Http;

use Billium\Server\Internal\Http\HttpLimits;
use Packman\Config\Exceptions\InvalidConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpLimitsTest extends TestCase
{
    public function test_the_body_ceiling_and_the_default_are_accepted(): void
    {
        self::assertSame(HttpLimits::MAX_BODY_BYTES_CEILING, HttpLimits::fromArray(['max_body_bytes' => HttpLimits::MAX_BODY_BYTES_CEILING, 'max_depth' => 64])->maxBodyBytes);
        self::assertSame(1_048_576, HttpLimits::fromArray(['max_body_bytes' => 1_048_576, 'max_depth' => 64])->maxBodyBytes);
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRangeBodies(): iterable
    {
        yield 'zero' => [0];
        yield 'one byte above the ceiling' => [HttpLimits::MAX_BODY_BYTES_CEILING + 1];
        yield 'unsafe integer' => [PHP_INT_MAX];
    }

    #[DataProvider('outOfRangeBodies')]
    public function test_a_body_limit_outside_the_ceiling_is_refused_at_boot(int $bytes): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('billium-server.max_body_bytes: must be an integer between 1 and 16777216');

        HttpLimits::fromArray(['max_body_bytes' => $bytes, 'max_depth' => 64]);
    }
}
