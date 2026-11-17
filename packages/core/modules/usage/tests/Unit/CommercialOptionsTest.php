<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Unit;

use Billium\Core\Configuration\BilliumOptions;
use Packman\Config\Exceptions\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class CommercialOptionsTest extends TestCase
{
    public function test_default_configuration_keeps_commercial_modules_opt_in(): void
    {
        $config = require dirname(__DIR__, 4).'/config/billium.php';
        $options = BilliumOptions::fromArray($config);

        self::assertFalse($options->usage->enabled);
        self::assertFalse($options->rating->enabled);
        self::assertFalse($options->schedule->enabled);
        self::assertSame(['platform', 'ledger'], $options->modules->enabled);
    }

    public function test_commercial_flags_compile_without_changing_the_desired_module_set(): void
    {
        $options = BilliumOptions::fromArray([
            'usage' => ['enabled' => true],
            'rating' => ['enabled' => true],
            'schedule' => ['enabled' => true],
            'modules' => ['enabled' => ['platform', 'usage', 'rating', 'schedule']],
        ]);

        self::assertTrue($options->usage->enabled);
        self::assertTrue($options->rating->enabled);
        self::assertTrue($options->schedule->enabled);
        self::assertSame(['platform', 'usage', 'rating', 'schedule'], $options->modules->enabled);
    }

    public function test_commercial_sections_reject_misspelled_flags(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('billium.rating.enable');

        BilliumOptions::fromArray(['rating' => ['enable' => true]]);
    }

    public function test_commercial_flags_do_not_coerce_boolean_strings(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('billium.schedule.enabled');

        BilliumOptions::fromArray(['schedule' => ['enabled' => 'false']]);
    }
}
