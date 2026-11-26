<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Invoices\UpdateInvoiceMetadata;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** One worker of the issue × metadata race (ACC-CON-17): worker 0 issues, the others update metadata with their own keys. */
final readonly class InvoiceIssueMetadataScenario implements Scenario
{
    public function __construct(Application $app, int $index)
    {
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller('seller-a', 'test', PlatformTestCase::INSTALLATION, 'actor-'.$index)));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token(PlatformTestCase::INSTALLATION, processRef: 'process-'.$index)));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
    }

    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        $ledger = $app->make(Ledger::class);
        $scope = new BillingAccountScope('seller-a', 'test', 'account-a');
        $rounds = is_int($args['rounds'] ?? null) ? $args['rounds'] : throw new InvalidArgumentException('rounds');
        if ($rounds < 1) {
            throw new InvalidArgumentException('rounds');
        }
        $outcomes = [];
        for ($round = 1; $round <= $rounds; $round++) {
            $this->rendezvous($app, $round, 3);
            try {
                if ($index === 0) {
                    $outcome = $ledger->issueInvoice(new IssueInvoice('issue-'.$round, $scope, 'invoice-'.$round));
                    $kind = 'issue';
                } else {
                    $metadata = $index === 1 ? ['note' => 'note-'.$round] : ['foo' => 'bar-'.$round];
                    $outcome = $ledger->updateInvoiceMetadata(new UpdateInvoiceMetadata('meta-'.$round.'-'.$index, $scope, 'update-'.$round.'-'.$index, 'invoice-'.$round, $metadata));
                    $kind = $index === 1 ? 'note' : 'foo';
                }
                $outcomes[] = ['kind' => $kind, 'accepted' => $outcome->accepted, 'problem' => $outcome->problem?->value];
            } catch (ProblemException $exception) {
                $outcomes[] = ['kind' => $index === 0 ? 'issue' : ($index === 1 ? 'note' : 'foo'), 'accepted' => false, 'problem' => $exception->problem->value];
            }
        }

        return ['rounds' => $outcomes];
    }

    private function rendezvous(Application $app, int $round, int $workers): void
    {
        $pdo = $app['db']->connection('pgsql')->getPdo();
        if (! $pdo instanceof PDO) {
            throw new RuntimeException('Invoice race rendezvous requires PDO');
        }
        $key = 1_740_000_000 + $round;
        $lock = $pdo->prepare('SELECT pg_advisory_lock_shared(?)');
        $lock->execute([$key]);
        $waiting = $pdo->prepare(<<<'SQL'
            SELECT count(*) FROM pg_locks
            WHERE locktype = 'advisory' AND classid = 0 AND objid = ? AND objsubid = 1
              AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
              AND mode = 'ShareLock' AND granted
            SQL);
        $deadline = microtime(true) + 20;
        do {
            $waiting->execute([$key]);
            if ((int) $waiting->fetchColumn() >= $workers) {
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Invoice race workers did not meet for round '.$round);
            }
            usleep(20_000);
        } while (true);
    }
}
