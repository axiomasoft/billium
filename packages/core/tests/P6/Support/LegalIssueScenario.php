<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6\Support;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Laravel\DocumentsServiceProvider;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

final class LegalIssueScenario implements Scenario
{
    public static function prepare(Application $app): void
    {
        $app->instance(CallerContext::class, FixedCallerContext::default());
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token()));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
        $config = $app->make(Repository::class);
        $config->set('billium.modules.enabled', ['platform', 'ledger', 'documents']);
        $config->set('billium.documents.enabled', true);
        $app->register(DocumentsServiceProvider::class);
    }

    /** @param array<string, mixed> $args */
    public function run(Application $app, int $index, array $args): array
    {
        self::prepare($app);

        /** @var LegalDocumentIssuer $issuer */
        $issuer = $app->make(LegalDocumentIssuer::class);
        $scope = new BillingAccountScope('seller-a', 'test', 'account-a');

        $mode = (string) ($args['mode'] ?? 'same_basis');
        if ($mode === 'different_basis') {
            /** @var list<array{invoiceRef: string, finalizationRef: string, digest: string}> $bases */
            $bases = $args['bases'];
            $entry = $bases[$index];
            $cmd = new IssueLegalDocument(
                commandKey: 'worker-diff-'.$index,
                scope: $scope,
                invoiceRef: $entry['invoiceRef'],
                economicRef: $entry['invoiceRef'],
                documentRevision: 1,
                finalizationRef: $entry['finalizationRef'],
                approvedSnapshotDigest: $entry['digest'],
                seriesRef: (string) $args['seriesRef'],
                periodKey: (string) $args['periodKey'],
            );
        } else {
            $cmd = new IssueLegalDocument(
                commandKey: 'worker-same-'.$index,
                scope: $scope,
                invoiceRef: (string) $args['invoiceRef'],
                economicRef: (string) $args['invoiceRef'],
                documentRevision: 1,
                finalizationRef: (string) $args['finalizationRef'],
                approvedSnapshotDigest: (string) $args['digest'],
                seriesRef: (string) $args['seriesRef'],
                periodKey: (string) $args['periodKey'],
            );
        }

        try {
            $outcome = $issuer->issueInvoiceDocument($cmd);

            return [
                'accepted' => $outcome->accepted,
                'replayed' => $outcome->replayed,
                'legalNumber' => $outcome->body['legalNumber'] ?? null,
                'sequenceNumber' => $outcome->body['sequenceNumber'] ?? null,
                'legalDocumentRef' => $outcome->body['legalDocumentRef'] ?? null,
            ];
        } catch (ProblemException $error) {
            return [
                'accepted' => false,
                'problem' => $error->problem->value,
                'detail' => $error->detail,
            ];
        }
    }
}
