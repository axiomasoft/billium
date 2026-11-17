<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration\RatingMigration;

use Billium\Core\Tests\Integration\CompositionTestCase;
use Billium\Rating\Contracts\RatingQuoter;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class P4ModuleCompositionTest extends CompositionTestCase
{
    protected function modules(): array
    {
        return ['platform', 'rating'];
    }

    public function test_rating_alone_installs_its_schema_and_quoter_without_forcing_other_modules(): void
    {
        self::assertNotNull($this->owner()->scalar("SELECT to_regclass('public.billium_rating_price_version')::text"));
        self::assertInstanceOf(RatingQuoter::class, $this->application()->make(RatingQuoter::class));
        foreach (['billium_usage_fact', 'billium_schedule_billing_occurrence', 'billium_obligation'] as $table) {
            self::assertNull($this->owner()->scalar("SELECT to_regclass('public.{$table}')::text"));
        }
        self::assertSame(['platform', 'rating'], $this->owner()->table('billium_module_state')->orderBy('module_id')->pluck('module_id')->all());
    }
}
