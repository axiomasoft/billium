<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Inbox;

use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Tests\PlatformTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class FeedRecoveryQueriesTest extends PlatformTestCase
{
    public function test_recovery_metadata_is_scoped_to_the_caller_channel_authority_and_partition(): void
    {
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_feed_partition(channel,source_authority,source_lineage,seller_ref,environment,partition_ref,state)
            VALUES ('observations','authority-a','L1','seller-a','test','part-1','recovery_required'),
                   ('observations','authority-a','L1','seller-b','test','part-1','active'),
                   ('observations','authority-a','L1','seller-a','live','part-1','active'),
                   ('observations','authority-b','L1','seller-a','test','part-1','active'),
                   ('observations','authority-a','L1','seller-a','test','part-2','active')
            SQL);
        $this->bootstrap('seller-a', 'test');
        $this->bootstrap('seller-b', 'test');
        $this->bootstrap('seller-a', 'live');
        $result = $this->read('authority-a', 'part-1');
        self::assertTrue($result['recoveryRequired']);
        self::assertSame('loading', $result['bootstrapStatus']);
        self::assertCount(2, $result['snapshots']);
        foreach ($result['snapshots'] as $snapshot) {
            $payload = self::arr($snapshot['payload']);
            self::assertSame('seller-a', $payload['seller_ref']);
            self::assertSame('test', $payload['environment']);
            self::assertSame('authority-a', $payload['source_authority']);
            self::assertSame('part-1', $payload['partition_ref']);
        }
        self::assertFalse($this->read('authority-b', 'part-1')['recoveryRequired']);
        self::assertFalse($this->read('authority-a', 'part-2')['recoveryRequired']);
        $this->callers->set(new Caller('seller-other', 'test', self::INSTALLATION, 'actor'));
        self::assertSame(['recoveryRequired' => false, 'bootstrapStatus' => 'not_started', 'snapshots' => []], $this->read());
    }

    public function test_reader_stays_on_the_supplied_repeatable_read_snapshot(): void
    {
        $db = self::connection('pgsql');
        $db->transaction(function () use ($db): void {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $reader = $this->application()->make(FeedRecoveryQueries::class);
            self::assertSame('not_started', $reader->state('pgsql', 'observations')['bootstrapStatus']);
            $this->bootstrap('seller-a', 'test');
            self::assertSame('not_started', $reader->state('pgsql', 'observations')['bootstrapStatus']);
            self::assertSame(1, $db->transactionLevel());
        });
        self::assertSame('loading', $this->read()['bootstrapStatus']);
    }

    public function test_reader_refuses_a_missing_or_writable_transaction(): void
    {
        $reader = $this->application()->make(FeedRecoveryQueries::class);
        foreach ([false, true] as $transaction) {
            $db = self::connection('pgsql');
            if ($transaction) {
                $db->beginTransaction();
            }
            try {
                $reader->state('pgsql', 'observations');
                self::fail('A recovery read must share an explicitly read-only snapshot');
            } catch (LogicException $error) {
                self::assertStringContainsString('REPEATABLE READ READ ONLY', $error->getMessage());
            } finally {
                if ($transaction) {
                    $db->rollBack();
                }
            }
        }
    }

    private function bootstrap(string $seller, string $environment): void
    {
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_feed_bootstrap_session(bootstrap_ref,channel,source_authority,source_lineage,seller_ref,environment,
                partition_ref,cursor_sequence,snapshot_digest,item_count,source_retention_lease_ref)
            VALUES (?,'observations','authority-a','L1',?,?,'part-1',20,?,0,'lease')
            SQL, ['boot-'.$seller.'-'.$environment, $seller, $environment, 'sha256:'.str_repeat('a', 64)]);
    }

    /** @return array{recoveryRequired: bool, bootstrapStatus: string, snapshots: list<array<string, mixed>>} */
    private function read(?string $authority = null, ?string $partition = null): array
    {
        $db = self::connection('pgsql');

        return $db->transaction(function () use ($db, $authority, $partition): array {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $this->application()->make(FeedRecoveryQueries::class)->state('pgsql', 'observations', $authority, $partition);
        });
    }
}
