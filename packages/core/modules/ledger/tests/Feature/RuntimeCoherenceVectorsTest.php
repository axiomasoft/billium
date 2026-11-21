<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\MoneyMathFactory;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\TestEnvironment;
use BilliumConformance\LedgerAdapter;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Process\Process;

/** D60: execute the independent examples against guarded runtime writes, then check durable coherence. */
#[Group('D60')]
#[Group('pg')]
final class RuntimeCoherenceVectorsTest extends TestCase
{
    private static ?PDO $owner = null;

    public function test_worked_examples_and_durable_counters_agree(): void
    {
        $root = dirname(__DIR__, 6);
        $adapter = new LedgerAdapter;
        $paths = [...glob($root.'/fixtures/vectors/ledger/conservation/*.json') ?: [],
            ...glob($root.'/fixtures/vectors/payment/observation/apply-*.json') ?: []];
        self::assertCount(37, $paths, '17 conservation and 20 observation worked examples');
        try {
            foreach ($paths as $path) {
                $document = self::document(file_get_contents($path));
                $id = self::text($document->id);
                self::assertSame(['php'], $document->profiles);
                $script = self::arr($document->script);
                $expect = self::arr($document->expect);
                self::assertSame('ledger.reset', self::object($script[0])->op);
                $adapter->given(self::object($document->given));
                $receipts = [];
                foreach ($script as $index => $raw) {
                    $step = self::object($raw);
                    $op = self::text($step->op);
                    $args = self::object($step->args);
                    $label = $id.' step '.$index.' '.$op;
                    $expected = self::object($expect[$index]);
                    try {
                        if ($op === 'money.allocate') {
                            $math = MoneyMathFactory::create();
                            $parts = $math->allocate(Money::fromWire($args->total), self::arr($args->ratios));
                            $output = ['parts' => array_map(static fn (Money $part): array => $part->toWire(), $parts)];
                        } else {
                            $output = $adapter->run($op, $args);
                        }
                    } catch (ProblemException $problem) {
                        self::assertObjectHasProperty('error', $expected, $label.' '.$problem->getMessage());
                        self::assertSame(self::object($expected->error)->code, $problem->problem->value, $label);

                        continue;
                    }
                    self::assertObjectHasProperty('ok', $expected, $label);
                    foreach (get_object_vars(self::object($expected->ok)) as $key => $value) {
                        self::assertArrayHasKey($key, $output, $label);
                        self::assertSame(self::normalized($value), self::normalized($output[$key]), $label.' '.$key);
                    }
                    if ($op === 'ledger.allocate') {
                        $receipts[] = $output['receiptId'] ?? null;
                    }
                }
                $this->assertDurableCoherence($id);
                if (str_ends_with($id, 'idempotent-replay-same-receipt')) {
                    self::assertCount(2, $receipts);
                    self::assertSame($receipts[0], $receipts[1]);
                    self::assertNotNull($receipts[0]);
                }
                if (str_ends_with($id, 'stored-refusal-replay')) {
                    self::assertSame(1, self::scalar("SELECT count(*) FROM billium_idempotency_receipt WHERE command_key='key-a1' AND outcome='refused' AND result->>'problem'='billium.allocation.exceeds_available'"));
                }
                if (str_ends_with($id, 'apply-repeated-economic-snapshot-new-evidence')) {
                    self::assertSame(2, self::scalar('SELECT count(*) FROM billium_inbox_message'));
                    self::assertSame(1, self::scalar('SELECT count(*) FROM billium_observed_payment_revision'));
                    self::assertSame(1, self::scalar('SELECT count(*) FROM billium_allocation'));
                    self::assertSame(1, self::scalar("SELECT count(*) FROM billium_inbox_message WHERE payload->>'evidenceRef'='ev-new'"), 'new immutable evidence remains in custody');
                }
                if (str_ends_with($id, 'apply-missing-intent-observed-visible')) {
                    self::assertSame(2, self::scalar('SELECT accounted_sequence FROM billium_feed_partition'));
                }
                if (str_ends_with($id, 'apply-changed-refund-comparison-basis')) {
                    self::assertSame(2, self::scalar('SELECT count(DISTINCT comparison_basis_digest) FROM billium_ledger_discrepancy'));
                    self::assertSame(2, self::scalar("SELECT count(*) FROM billium_discrepancy_event WHERE kind='resolved'"));
                }
            }
        } finally {
            $adapter->close();
        }
    }

    public function test_codec_only_protocol_needs_no_database_and_missing_database_is_an_error(): void
    {
        $root = dirname(__DIR__, 6);
        $environment = array_fill_keys(['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD'], false);
        $messages = [
            ['type' => 'start', 'protocol' => 1], ['type' => 'config', 'profile' => 'php'],
            ['type' => 'run', 'id' => 'test/lazy', 'given' => new stdClass, 'script' => [
                ['op' => 'money.parse', 'args' => ['wire_text' => '{"amount":"30.00","currency":"RUB"}']],
            ]],
        ];
        $input = implode("\n", array_map(static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR), $messages))."\n";
        $codec = new Process([PHP_BINARY, $root.'/conformance/adapters/php/bin/adapter.php'], $root, $environment, $input."{\"type\":\"stop\"}\n");
        $codec->mustRun();
        $lines = explode("\n", trim($codec->getOutput()));
        self::assertCount(3, $lines, 'stdout contains protocol only');
        self::assertContains('ledger.observe', self::arr(self::document($lines[0])->ops));
        self::assertSame((new Codec)->canonicalize('{"minor":"3000","currency":"RUB"}'), self::normalized(self::object(self::arr(self::document($lines[2])->outputs)[0])->ok));

        $ledger = new Process([PHP_BINARY, $root.'/conformance/adapters/php/bin/adapter.php'], $root, $environment,
            $input."{\"type\":\"run\",\"id\":\"test/missing-db\",\"given\":{},\"script\":[{\"op\":\"ledger.reset\",\"args\":{}}]}\n");
        self::assertNotSame(0, $ledger->run(), 'missing database is an adapter error, never a pass');
        self::assertStringContainsString('DB_DATABASE', $ledger->getErrorOutput().$ledger->getOutput());
        self::assertStringNotContainsString('"id":"test/missing-db","outputs"', $ledger->getOutput());
    }

    public function test_the_three_timed_out_vectors_join_the_parent_lease(): void
    {
        $root = dirname(__DIR__, 6);
        $messages = [['type' => 'start', 'protocol' => 1], ['type' => 'config', 'profile' => 'php']];
        $ids = ['bundle-two-components', 'credit-note-open-only', 'credit-note-reverse-allocations'];
        foreach ($ids as $id) {
            $vector = self::document(file_get_contents($root.'/fixtures/vectors/ledger/conservation/'.$id.'.json'));
            $messages[] = ['type' => 'run', 'id' => $vector->id, 'given' => $vector->given, 'script' => $vector->script];
        }
        $messages[] = ['type' => 'stop'];
        $input = implode("\n", array_map(static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR), $messages))."\n";
        // The parent already owns the database; an independent adapter would wait up to 1800s for the suite lease.
        $adapter = new Process([PHP_BINARY, $root.'/conformance/adapters/php/bin/adapter.php'], $root,
            TestEnvironment::suiteLeaseEnvironment(), $input, 30);
        $adapter->mustRun();
        $lines = explode("\n", trim($adapter->getOutput()));
        self::assertCount(5, $lines);
        foreach ($ids as $index => $id) {
            $result = self::document($lines[$index + 2]);
            self::assertSame('ledger/conservation/'.$id, $result->id);
            foreach (self::arr($result->outputs) as $output) {
                self::assertObjectHasProperty('ok', self::object($output));
            }
        }
    }

    private function assertDurableCoherence(string $id): void
    {
        self::assertSame(0, self::scalar(<<<'SQL'
            SELECT count(*) FROM billium_obligation o WHERE o.allocated_minor <>
                (SELECT coalesce(sum(a.amount_minor),0) FROM billium_allocation a WHERE a.obligation_id=o.id)
            SQL), $id.' obligation counters have exactly one owner');
        self::assertSame(0, self::scalar(<<<'SQL'
            SELECT count(*) FROM billium_payment p WHERE p.allocated_minor <>
                (SELECT coalesce(sum(a.amount_minor),0) FROM billium_allocation a WHERE a.payment_id=p.id)
            SQL), $id.' payment counters equal journal, including reversals');
        self::assertSame(0, self::scalar(<<<'SQL'
            SELECT count(*) FROM billium_customer_credit c WHERE c.remaining_minor <>
                c.amount_minor-(SELECT coalesce(sum(a.amount_minor),0) FROM billium_allocation a WHERE a.customer_credit_id=c.id)
            SQL), $id.' prepaid conservation');
        self::assertSame(0, self::scalar(<<<'SQL'
            SELECT count(*) FROM billium_allocation a LEFT JOIN billium_allocation_receipt r
              ON r.seller_ref=a.seller_ref AND r.environment=a.environment AND r.receipt_ref=a.receipt_ref WHERE r.id IS NULL
            SQL), $id.' journal and receipt commit together');
        self::assertSame(0, self::scalar(<<<'SQL'
            SELECT count(*) FROM billium_allocation a WHERE a.amount_minor>0 AND a.cause='observation'
              AND NOT EXISTS (SELECT 1 FROM billium_outbox_event e WHERE e.type='allocation.applied.v1'
                AND e.payload->>'receipt_ref'=a.receipt_ref AND e.payload->>'amount_minor'=a.amount_minor::text
                AND e.payload->>'obligation_ref'=(SELECT o.obligation_ref FROM billium_obligation o WHERE o.id=a.obligation_id))
            SQL), $id.' monetary journal and outbox commit together (BC-EVT-01)');
    }

    private static function scalar(string $sql): mixed
    {
        self::$owner ??= TestEnvironment::pdo(owner: true);
        $statement = self::$owner->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    /** @return list<mixed> */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertIsList($value);

        return $value;
    }

    private static function object(mixed $value): stdClass
    {
        self::assertInstanceOf(stdClass::class, $value);

        return $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function document(string|false $json): stdClass
    {
        self::assertIsString($json);
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $value);

        return $value;
    }

    private static function normalized(mixed $value): string
    {
        return (new Codec)->canonicalize(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
