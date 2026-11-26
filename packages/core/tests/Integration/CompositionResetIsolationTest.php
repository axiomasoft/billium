<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Core\Tests\TestEnvironment;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CompositionResetIsolationTest extends CompositionTestCase
{
    public function test_second_setup_keeps_migrations_and_truncates_rows(): void
    {
        $before = (int) $this->owner()->scalar('SELECT count(*) FROM public.migrations');
        $runs = TestEnvironment::migrationRuns();
        self::assertGreaterThan(0, $before);
        $this->owner()->unprepared(<<<'SQL'
            INSERT INTO public.billium_module_state
                (installation_ref, module_id, state, config_revision, schema_version, generation)
            VALUES ('reset-isolation', 'platform', 'accepting', 1, 0, 1)
            SQL);
        $this->setUp();
        self::assertSame($before, (int) $this->owner()->scalar('SELECT count(*) FROM public.migrations'));
        self::assertSame($runs, TestEnvironment::migrationRuns());
        self::assertSame(0, (int) $this->owner()->scalar("SELECT count(*) FROM public.billium_module_state WHERE installation_ref = 'reset-isolation'"));
    }
}
