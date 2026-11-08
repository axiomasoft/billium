<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\UpdateInvoiceMetadata;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Foundation\Application;
use InvalidArgumentException;

/** One worker of the issue × metadata race (ACC-CON-17): worker 0 issues, the others update metadata with their own keys. */
final readonly class InvoiceIssueMetadataScenario implements Scenario
{
    public function __construct(Application $app, int $index)
    {
        $app->instance(CallerContext::class, new FixedCallerContext(new Caller('seller-a', 'test', PlatformTestCase::INSTALLATION, 'actor-'.$index)));
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token(PlatformTestCase::INSTALLATION, processRef: 'process-'.$index)));
    }

    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        $ledger = $app->make(Ledger::class);
        $scope = new BillingAccountScope('seller-a', 'test', 'account-a');
        $round = is_int($args['round'] ?? null) ? $args['round'] : throw new InvalidArgumentException('round');
        if ($index === 0) {
            $outcome = $ledger->issueInvoice(new IssueInvoice('issue-'.$round, $scope, 'invoice-'.$round));
            $kind = 'issue';
        } else {
            $metadata = $index === 1 ? ['note' => 'note-'.$round] : ['foo' => 'bar-'.$round];
            $outcome = $ledger->updateInvoiceMetadata(new UpdateInvoiceMetadata('meta-'.$round.'-'.$index, $scope, 'update-'.$round.'-'.$index, 'invoice-'.$round, $metadata));
            $kind = $index === 1 ? 'note' : 'foo';
        }

        return ['kind' => $kind, 'accepted' => $outcome->accepted, 'problem' => $outcome->problem?->value];
    }
}
