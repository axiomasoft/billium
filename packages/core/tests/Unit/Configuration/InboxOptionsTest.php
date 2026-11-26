<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Configuration;

use Billium\Core\Configuration\InboxOptions;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboxOptionsTest extends TestCase
{
    public function test_valid_section_compiles_every_key(): void
    {
        $validator = new ConfigurationValidator;
        $options = InboxOptions::fromSection([
            'retry_backoff_seconds' => 0,
            'retry_budget' => 1,
            'handler_compatibility' => ['ledger.observe' => ['v1']],
            'retention_floor_seconds' => 86_400,
        ], 'billium.inbox', $validator);
        $validator->failIfAny();

        self::assertSame(0, $options->retryBackoffSeconds);
        self::assertSame(1, $options->retryBudget);
        self::assertSame(['ledger.observe' => ['v1']], $options->handlerCompatibility);
        self::assertSame(86_400, $options->retentionFloorSeconds);
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidSections(): array
    {
        return [
            'section not an array' => ['inbox', 'billium.inbox: must be an associative array'],
            'negative backoff' => [['retry_backoff_seconds' => -1], 'billium.inbox.retry_backoff_seconds: must be an integer >= 0'],
            'string backoff' => [['retry_backoff_seconds' => '5'], 'billium.inbox.retry_backoff_seconds: must be an integer >= 0'],
            'zero budget' => [['retry_budget' => 0], 'billium.inbox.retry_budget: must be an integer >= 1'],
            'scalar compatibility' => [['handler_compatibility' => 'v1'], 'billium.inbox.handler_compatibility: must be an array'],
            'list compatibility' => [['handler_compatibility' => ['v1']], 'billium.inbox.handler_compatibility: must be keyed by string handler identifiers'],
            'negative floor' => [['retention_floor_seconds' => -1], 'billium.inbox.retention_floor_seconds: must be null or an integer >= 0'],
            'unknown key' => [['retry_budgett' => 3], 'billium.inbox.retry_budgett: unknown key (did you mean "retry_budget"?)'],
        ];
    }

    /** Invalid values are reported and replaced by the defaults, so one bad key never hides another. */
    #[DataProvider('invalidSections')]
    public function test_invalid_values_fail_closed_and_fall_back_to_defaults(mixed $raw, string $message): void
    {
        $validator = new ConfigurationValidator;
        $options = InboxOptions::fromSection($raw, 'billium.inbox', $validator);

        self::assertEquals(new InboxOptions, $options);
        try {
            $validator->failIfAny();
            self::fail('Invalid inbox configuration was accepted');
        } catch (InvalidConfiguration $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
