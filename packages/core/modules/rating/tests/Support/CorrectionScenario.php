<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Support;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;

final class CorrectionScenario implements Scenario
{
    public function run(Application $app, int $index, array $args): array
    {
        RatingScenario::prepare($app);
        if ($index === 0 && ($args['issue'] ?? false)) {
            $outcome = $app->make(Ledger::class)->issueInvoice(new IssueInvoice(commandKey: 'concurrent-issue', scope: new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'original-account'), invoiceRef: 'invoice-1'));

            return ['accepted' => $outcome->accepted, 'action' => 'issue'];
        }
        if ($index === 0 && ($args['revise'] ?? false)) {
            $app->make(OccurrenceService::class)->applyScheduleRevision(new ApplyScheduleRevision(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a', expectedRevision: 1, expectedRowVersion: 1, newRevision: 2, terms: Terms::make(['componentRef' => 'component-1', 'anchorLocal' => '2026-10-15T00:00:00', 'purpose' => 'usage_settlement']), effectiveFrom: new DateTimeImmutable('2026-01-01Z'), inputsDigest: 'sha256:'.str_repeat('b', 64), commandKey: 'concurrent-revision'));

            return ['accepted' => true, 'action' => 'revision'];
        }
        $alternatives = $args['alternatives'] ?? [];
        if (! is_array($alternatives)) {
            throw new LogicException('Invalid correction alternatives');
        }
        $encoded = $alternatives === [] ? $args['prepared'] ?? null : $alternatives[$index % count($alternatives)];
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        $prepared = $bytes === false ? null : unserialize($bytes, ['allowed_classes' => true]);
        if (! $prepared instanceof PreparedCorrection) {
            throw new LogicException('Invalid correction fixture');
        }
        try {
            $receipt = $app->make(RatingCorrection::class)->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'correction-worker-'.$index));

            return ['accepted' => true, 'body' => $receipt->body(), 'pid' => $app->make(DatabaseManager::class)->connection('pgsql')->scalar('SELECT pg_backend_pid()')];
        } catch (ProblemException $error) {
            return ['accepted' => false, 'detail' => $error->detail];
        }
    }
}
