<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Inbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Tests\PlatformTestCase;
use Closure;
use stdClass;

/** Committed-data base of inbox/feed proofs: a caller granted one source authority, a journal table, envelope builders. */
abstract class InboxTestCase extends PlatformTestCase
{
    public const string AUTHORITY = 'embedded-commerz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [self::AUTHORITY]));
        self::createJournal();
    }

    protected function tearDown(): void
    {
        try {
            TestEnvironment::pdo(owner: true)->exec('DROP TABLE IF EXISTS billium_test_journal');
        } finally {
            parent::tearDown();
        }
    }

    public static function createJournal(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        $owner->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS billium_test_journal (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                kind text NOT NULL,
                ref text NOT NULL,
                source_sequence bigint NOT NULL,
                written_at timestamptz NOT NULL DEFAULT clock_timestamp()
            )
            SQL);
        $owner->exec('TRUNCATE billium_test_journal RESTART IDENTITY');
        $runtime = self::pdoValue(TestEnvironment::pdo(), 'SELECT current_user');
        $owner->exec('GRANT SELECT, INSERT ON billium_test_journal TO "'.str_replace('"', '""', self::text($runtime)).'"');
    }

    protected function feed(): FeedPartitions
    {
        return $this->application()->make(FeedPartitions::class);
    }

    /** @return list<string> journal refs in write order */
    protected function journal(string $kind = 'live'): array
    {
        return array_map(static fn (array $row): string => self::text($row['ref']), $this->table('SELECT ref FROM billium_test_journal WHERE kind = ? ORDER BY id', [$kind]));
    }

    /** A decoded observation envelope with a real contentDigest (BC-ID-12); `$salt` changes content under one identity. */
    protected function envelope(
        int $sequence,
        ?string $event = null,
        string $lineage = 'L1',
        string $operation = 'op-1',
        int $revision = 1,
        string $partition = 'part-1',
        string $authority = self::AUTHORITY,
        string $seller = 'seller-a',
        string $environment = 'test',
        string $salt = '',
    ): stdClass {
        return self::build($sequence, $event, $lineage, $operation, $revision, $partition, $authority, $seller, $environment, $salt);
    }

    public static function build(
        int $sequence,
        ?string $event = null,
        string $lineage = 'L1',
        string $operation = 'op-1',
        int $revision = 1,
        string $partition = 'part-1',
        string $authority = self::AUTHORITY,
        string $seller = 'seller-a',
        string $environment = 'test',
        string $salt = '',
    ): stdClass {
        $body = (object) [
            'schema' => 'payment.observation.v1', 'eventId' => $event ?? "evt-{$lineage}-{$sequence}", 'sourceAuthority' => $authority,
            'sourceLineage' => $lineage, 'partitionRef' => $partition, 'sourceSequence' => $sequence, 'installationRef' => self::INSTALLATION,
            'sellerRef' => $seller, 'environment' => $environment, 'operationRef' => $operation, 'operationRevision' => $revision,
            'state' => 'pending'.$salt,
        ];
        $body->contentDigest = (new Codec)->digest($body, 'contentDigest');

        return $body;
    }

    /** One root operation, as the owning facade runs it: the body result is returned after commit. */
    protected function root(Closure $body, FinancialMode $mode = FinancialMode::Active): mixed
    {
        $captured = null;
        $spec = $mode->financial()
            ? $this->spec('ledger.observation_apply')
            : $this->spec('platform.inbox_custody', mode: $mode, class: ModuleOperationClass::CallbackCustody, modules: ['platform']);
        $this->runner()->run($spec, function (TransactionContext $context) use ($body, &$captured): Receipt {
            $captured = $body($context);

            return self::receipt();
        });

        return $captured;
    }

    protected function custody(stdClass $envelope, CustodyHandler $handler, FinancialMode $mode = FinancialMode::Active): CustodyResult
    {
        $result = $this->root(fn (TransactionContext $context): CustodyResult => $this->feed()->process($context, 'payment.observation', $envelope, $handler), $mode);

        return $result instanceof CustodyResult ? $result : self::fail('custody returned no result');
    }

    protected function drain(int $partitionId, CustodyHandler $handler): CustodyResult
    {
        $result = $this->root(fn (TransactionContext $context): CustodyResult => $this->feed()->drain($context, $partitionId, $handler));

        return $result instanceof CustodyResult ? $result : self::fail('drain returned no result');
    }

    /**
     * Drains one sequence per new root until nothing is next.
     *
     * @return list<CustodyResult>
     */
    protected function drainAll(int $partitionId, CustodyHandler $handler): array
    {
        $results = [];
        while (($result = $this->drain($partitionId, $handler))->disposition->value !== 'idle') {
            $results[] = $result;
            if ($result->drainPartitionId === null) {
                break;
            }
        }

        return $results;
    }

    protected function seedPartition(
        int $accounted,
        string $lineage = 'L1',
        string $partition = 'part-1',
        string $authority = self::AUTHORITY,
        string $state = 'active',
        string $seller = 'seller-a',
        int $custody = -1,
    ): int {
        return self::integer($this->value(<<<'SQL'
            INSERT INTO billium_feed_partition (channel, source_authority, source_lineage, seller_ref, environment, partition_ref, custody_sequence, accounted_sequence, state)
            VALUES ('payment.observation', ?, ?, ?, 'test', ?, ?, ?, ?) RETURNING id
            SQL, [$authority, $lineage, $seller, $partition, $custody < 0 ? $accounted : $custody, $accounted, $state]));
    }

    /** @return array<string, mixed> */
    protected function partitionRow(int $id): array
    {
        return $this->row('SELECT * FROM billium_feed_partition WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed> */
    protected function inbox(string $event): array
    {
        return $this->row('SELECT * FROM billium_inbox_message WHERE external_event_id = ?', [$event]);
    }
}
