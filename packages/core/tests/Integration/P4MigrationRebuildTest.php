<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Core\Tests\TestEnvironment;
use Billium\Rating\Tests\RatingTestCase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class P4MigrationRebuildTest extends RatingTestCase
{
    public function test_retained_p4_functions_allow_rebuild_after_a_platform_and_ledger_fresh_migration(): void
    {
        $before = $this->functions();
        self::assertCount(6, $before);
        $root = dirname(__DIR__, 4);
        TestEnvironment::prepareFreshRebuild($this->application());
        $this->beforeApplicationDestroyed(fn () => TestEnvironment::restoreSuiteMigrations($this->application()));
        $fresh = $this->artisan('migrate:fresh', [
            '--database' => 'billium_owner', '--force' => true, '--realpath' => true,
            '--path' => [$root.'/packages/core/database/migrations/platform', $root.'/packages/core/database/migrations/ledger'],
        ]);
        self::assertInstanceOf(PendingCommand::class, $fresh);
        self::assertSame(0, $fresh->run());
        self::assertNull($this->value("SELECT to_regclass('public.billium_rating_rated_basis')"));
        self::assertSame($before, $this->functions());

        $rebuild = $this->artisan('packman:schema:migrate', [
            '--scope' => 'central', '--database' => 'billium_owner', '--modules' => true, '--force' => true,
        ]);
        self::assertInstanceOf(PendingCommand::class, $rebuild);
        self::assertSame(0, $rebuild->run());
        self::assertSame($before, $this->functions(), 'Function identity, ownership and security attributes must survive replacement');
        foreach (['billium_usage_fact', 'billium_rating_rated_basis', 'billium_schedule_billing_occurrence'] as $table) {
            self::assertNotNull($this->value('SELECT to_regclass(?)', ['public.'.$table]));
        }
        TestEnvironment::assertRuntimeSafe(TestEnvironment::pdo());
    }

    /** @return array<string, array<string, mixed>> */
    private function functions(): array
    {
        $rows = $this->table(<<<'SQL'
            SELECT proname, oid::text AS function_oid, pg_get_userbyid(proowner) AS owner,
                prosecdef AS security_definer, coalesce(proacl::text, '') AS acl,
                coalesce(proconfig::text, '') AS settings
            FROM pg_proc WHERE pronamespace='public'::regnamespace AND proname IN (
                'billium_rating_check_price_shape', 'billium_rating_frontier_guard',
                'billium_schedule_occurrence_guard', 'billium_schedule_correction_guard',
                'billium_usage_guard_member_erasure', 'billium_usage_erase_distinct_members'
            ) ORDER BY proname
            SQL);
        $functions = [];
        foreach ($rows as $row) {
            $functions[self::text($row['proname'])] = $row;
        }

        return $functions;
    }
}
