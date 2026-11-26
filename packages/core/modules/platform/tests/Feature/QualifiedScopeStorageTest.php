<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class QualifiedScopeStorageTest extends PlatformTestCase
{
    public function test_observation_custody_links_and_command_history_are_append_only_for_runtime(): void
    {
        $runtime = TestEnvironment::pdo();
        foreach (['billium_feed_observation_root_scope', 'billium_feed_observation_scope_link', 'billium_feed_observation_command'] as $table) {
            self::assertTrue(self::connection('pgsql')->scalar("SELECT has_table_privilege(current_user,?,'SELECT') AND has_table_privilege(current_user,?,'INSERT')", [$table, $table]));
            self::assertFalse(self::connection('pgsql')->scalar("SELECT has_table_privilege(current_user,?,'UPDATE,DELETE,TRUNCATE')", [$table]));
            self::assertSame('42501', self::sqlState($runtime, 'DELETE FROM public.'.$table));
        }
        self::assertTrue(self::connection('pgsql')->scalar("SELECT has_table_privilege(current_user,'billium_observation_owner_binding','SELECT')"));
        self::assertFalse(self::connection('pgsql')->scalar("SELECT has_table_privilege(current_user,'billium_observation_owner_binding','INSERT,UPDATE,DELETE,TRUNCATE')"));
    }
}
