<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Tests\TestEnvironment;
use Billium\Schedule\Tests\ScheduleTestCase;
use Billium\Schedule\Tests\Support\Terms;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class ScheduleSchemaTest extends ScheduleTestCase
{
    public function test_history_is_append_only_and_runtime_cannot_update_or_delete_it(): void
    {
        $this->commitQuote($this->quote(Terms::make()));
        $runtime = TestEnvironment::pdo();
        $owner = TestEnvironment::pdo(owner: true);
        foreach (['quote', 'revision', 'period'] as $suffix) {
            $table = 'billium_schedule_'.$suffix;
            self::assertSame('42501', self::sqlState(pdo: $runtime, sql: "UPDATE {$table} SET body=body"));
            self::assertSame('42501', self::sqlState(pdo: $runtime, sql: "DELETE FROM {$table}"));
            self::assertSame('55000', self::sqlState(pdo: $owner, sql: "UPDATE {$table} SET body=body"));
            self::assertSame('55000', self::sqlState(pdo: $owner, sql: "DELETE FROM {$table}"));
        }
        self::assertSame(1, $this->rows('billium_schedule_period'));
    }

    public function test_database_business_uniqueness_excludes_revision_and_preserves_seller_fk(): void
    {
        $this->commitQuote($this->quote(Terms::make()));
        $next = $this->quote(terms: Terms::make(['agreementRevision' => 2]), ordinal: 1, key: 'second');
        $this->commitQuote(quote: $next, key: 'second');
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame('23505', self::sqlState(pdo: $owner, sql: <<<'SQL'
            INSERT INTO billium_schedule_period
                (seller_ref,environment,period_ref,coverage_ref,occurrence_ref,component_ref,purpose,period_start,period_end,
                 agreement_ref,agreement_revision,funding_revision,period_digest,terms_digest,body)
            SELECT seller_ref,environment,'new-ref','new-coverage','new-occurrence',component_ref,purpose,period_start,period_end,
                 agreement_ref,2,funding_revision,period_digest,terms_digest,body
            FROM billium_schedule_period WHERE agreement_revision=1
            SQL));
        self::assertSame('23503', self::sqlState(pdo: $owner, sql: <<<'SQL'
            INSERT INTO billium_schedule_revision
                (seller_ref,environment,component_ref,agreement_ref,agreement_revision,cancellation_generation,funding_revision,
                 quote_ref,action,effective_at,terms_digest,body,receipt)
            SELECT 'foreign-seller',environment,component_ref,agreement_ref,agreement_revision,cancellation_generation,funding_revision,
                 quote_ref,action,effective_at,terms_digest,body,receipt
            FROM billium_schedule_revision WHERE agreement_revision=1
            SQL));
        self::assertSame(2, $this->rows('billium_schedule_period'));
    }

    public function test_explicit_module_install_has_real_schema_and_exact_migration_checksum(): void
    {
        $descriptor = $this->application()->make(ModuleRegistry::class)->descriptor('schedule');
        self::assertNotNull($descriptor);
        self::assertCount(3, $descriptor->migrationIdentities);
        foreach ($descriptor->migrationIdentities as $migration) {
            $path = dirname(__DIR__, 4).'/database/migrations/schedule/'.$migration->id.'.php';
            self::assertSame('sha256:'.hash_file('sha256', $path), $migration->checksum);
        }
        self::assertSame(3, $this->value("SELECT schema_version FROM billium_module_state WHERE module_id='schedule'"));
        self::assertCount(11, $descriptor->ownedTables);
    }
}
