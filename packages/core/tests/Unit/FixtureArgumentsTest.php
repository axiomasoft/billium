<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit;

use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Rating\Data\Pricing\Tier;
use Error;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

final class FixtureArgumentsTest extends TestCase
{
    public function test_dynamic_fixture_arguments_preserve_real_constructor_validation(): void
    {
        $arguments = ['index' => 0, 'lower' => '0', 'upper' => null, 'unitPrice' => '1', 'flatFee' => '0'];
        $tier = FixtureArguments::construct(class: Tier::class, arguments: $arguments);
        self::assertSame('1', $tier->unitPrice);
        $this->expectException(TypeError::class);
        FixtureArguments::construct(class: Tier::class, arguments: [...$arguments, 'index' => 'invalid']);
    }

    public function test_unrecognized_fixture_arguments_are_not_discarded(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Unknown named parameter');
        FixtureArguments::construct(class: Tier::class, arguments: ['unrecognized' => 'value']);
    }

    public function test_fixture_scalar_guard_refuses_a_non_string(): void
    {
        self::assertSame('exact', FixtureValues::text('exact'));
        $this->expectException(ExpectationFailedException::class);
        FixtureValues::text(1);
    }

    public function test_fixture_instance_guard_refuses_a_different_model(): void
    {
        $this->expectException(ExpectationFailedException::class);
        FixtureValues::instance(value: new stdClass, class: Tier::class);
    }
}
