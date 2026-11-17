<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Inbox;

use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Foundation\Application;

/**
 * One worker of a feed race: it delivers one envelope in its own root Tx and then drains whatever its result points at,
 * one sequence per new root, exactly as a production worker must (no inline handler loop). `sequences[i]`/`partitions[i]`
 * select the delivery of worker i; the handler double sleeps inside the root and may lock a shared row of rank 70.
 */
final readonly class CustodyScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function __construct(Application $app, int $index, array $args)
    {
        $authority = isset($args['authority']) && is_string($args['authority']) ? $args['authority'] : InboxTestCase::AUTHORITY;
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller('seller-a', 'test', PlatformTestCase::INSTALLATION, 'actor-'.$index, [$authority])));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::fromArray(['processRef' => 'process-'.$index])));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
        $app->make(OperationRunner::class);
    }

    public function run(Application $app, int $index, array $args): array
    {
        usleep(self::at($args, 'delays', $index, 0) * 1000);
        $sequence = self::at($args, 'sequences', $index, $index + 1);
        $partition = self::text($args, 'partitions', $index, 'part-1');
        $lock = isset($args['lock_effect']) && is_int($args['lock_effect']) ? $args['lock_effect'] : null;
        $handler = new JournalHandler([], self::at($args, 'sleeps', $index, 0), $lock === null ? null : static function (TransactionContext $context) use ($lock): void {
            $context->lock(new LockRequest(LockRank::Obligation, 'billium_test_effect', [$lock]));
            usleep(100_000);
        });
        $envelope = InboxTestCase::build($sequence, "evt-{$partition}-{$sequence}", partition: $partition);
        $feed = $app->make(FeedPartitions::class);

        $result = $this->root($app, static fn (TransactionContext $context): CustodyResult => $feed->process($context, 'payment.observation', $envelope, $handler));
        $first = $result->disposition->value;
        $drained = 0;
        while ($result->drainPartitionId !== null) {
            $partitionId = $result->drainPartitionId;
            $result = $this->root($app, static fn (TransactionContext $context): CustodyResult => $feed->drain($context, $partitionId, $handler));
            if ($result->disposition === CustodyDisposition::Idle) {
                break;
            }
            $drained++;
        }

        return ['first' => $first, 'status' => $result->status, 'drained' => $drained];
    }

    private function root(Application $app, \Closure $body): CustodyResult
    {
        $caller = $app->make(CallerContext::class)->current();
        $spec = new OperationSpec('ledger.observation_apply', $caller->sellerRef, $caller->environment, FinancialMode::Active, ModuleOperationClass::NewIntent, ['ledger', 'platform'], null, null, 'ledger.test_effect');
        $captured = null;
        $app->make(OperationRunner::class)->run($spec, static function (TransactionContext $context) use ($body, &$captured): Receipt {
            $captured = $body($context);

            return PlatformTestCase::receipt();
        });

        return $captured instanceof CustodyResult ? $captured : throw new \LogicException('No custody result');
    }

    /** @param array<string, mixed> $args */
    private static function at(array $args, string $key, int $index, int $default): int
    {
        $list = $args[$key] ?? [];

        return is_array($list) && isset($list[$index]) && is_int($list[$index]) ? $list[$index] : $default;
    }

    /** @param array<string, mixed> $args */
    private static function text(array $args, string $key, int $index, string $default): string
    {
        $list = $args[$key] ?? [];

        return is_array($list) && isset($list[$index]) && is_string($list[$index]) ? $list[$index] : $default;
    }
}
