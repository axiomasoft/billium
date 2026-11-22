<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Packman\Schema\Contracts\Migration\SchemaMigrator;

/**
 * Isolated Billium PostgreSQL runtime host and neutral fixture builders for conformance/import/php_behavior.php.
 */
final class ImportBehaviorHost
{
    public const string INSTALLATION = 'inst-test';

    public const string DIGEST_1 = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    public const string DIGEST_2 = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    public const string DIGEST_3 = 'sha256:3333333333333333333333333333333333333333333333333333333333333333';

    public readonly Application $app;

    public readonly FixedCallerContext $callers;

    public readonly ConnectionInterface $owner;

    public readonly Codec $codec;

    public readonly string $repoRoot;

    public function __construct()
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->codec = new Codec;

        $caller = new Caller(
            sellerRef: 'seller-recurring-1',
            environment: 'test',
            installationRef: self::INSTALLATION,
            actorRef: 'conformance-import-actor',
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        );
        $this->app = Testbench::create(options: [
            'extra' => ['providers' => [BilliumCoreServiceProvider::class]],
        ]);
        TestEnvironment::configure($this->app);

        $this->callers = new FixedCallerContext($caller);
        $tokens = new FixedSessionAdmission;
        $this->app->instance(CallerContext::class, $this->callers);
        $this->app->instance(FinancialSessionAdmission::class, $tokens);
        SimulatedFinancialAuthorityEvidence::install($this->app);

        $migrator = $this->app->make(SchemaMigrator::class);
        $migrator->migrateSets([
            'axiomasoft/billium-core:billium-platform',
            'axiomasoft/billium-core:billium-ledger',
        ], 'billium_owner');
        $this->owner = $this->app->make(DatabaseManager::class)->connection('billium_owner');
        TestEnvironment::assertRuntimeSafe($this->app->make(DatabaseManager::class)->connection('pgsql')->getPdo());

        $tokens->set(FixedSessionAdmission::token(self::INSTALLATION));
        $this->resetTables();
    }

    public function resetTables(): void
    {
        TestEnvironment::truncateBilliumTables($this->owner->getPdo());
        $installation = $this->app->make(Installation::class);
        SimulatedFinancialAuthorityEvidence::reset();
        $this->app->forgetInstance(SimulatedFinancialAuthorityEvidence::class);
        $authority = SimulatedFinancialAuthorityEvidence::install($this->app);
        $installation->init(self::INSTALLATION);
        $authority->issueTransition('open', 'open-1', ['owner-1', 1, 1, 1, 'evidence:open-1']);
        $installation->openSession('open-1', 'owner-1', 1, 1, 1, 'evidence:open-1');
        $authority->issueTransition('activate', 'activate-1', [1, 'certificate:1', 'admission:1', 'evidence:activate-1']);
        $installation->activateSession('activate-1', 1, 'certificate:1', 'admission:1', 'evidence:activate-1');
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->app);
    }

    public function shutdown(): void
    {
        foreach (array_keys($this->app['db']->getConnections()) as $name) {
            $this->app['db']->purge($name);
        }
    }

    public function asCaller(string $sellerRef): void
    {
        $this->callers->set(new Caller(
            sellerRef: $sellerRef,
            environment: 'test',
            installationRef: self::INSTALLATION,
            actorRef: 'conformance-import-actor',
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        ));
    }

    public function applier(): ImportBatchApplier
    {
        return $this->app->make(ImportBatchApplier::class);
    }

    public function reconciler(): ImportReconciler
    {
        return $this->app->make(ImportReconciler::class);
    }

    public function countRows(string $table, string $where = 'TRUE'): int
    {
        return (int) $this->owner->scalar("SELECT count(*) FROM public.{$table} WHERE {$where}");
    }

    /**
     * @param  list<ImportRecordInput>  $records
     * @return array<string, ImportRecordInput>
     */
    public function indexRecords(array $records): array
    {
        $indexed = [];
        foreach ($records as $r) {
            $indexed[$r->identity->sourceRecordId] = $r;
        }

        return $indexed;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, string>
     */
    public function factRefsMap(array $facts): array
    {
        $map = [];
        foreach ($facts as $f) {
            $map[(string) $f['sourceRecordId']] = (string) $f['sourceFactRef'];
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function genericReader(string $batchId, string $sellerRef, array $records): GenericExportReader
    {
        $doc = $this->buildArtifact($batchId, $sellerRef, $records);

        return GenericExportReader::fromJson(json_encode($doc, JSON_THROW_ON_ERROR), new GenericExportProfile, 'import-policy-v1', $this->codec);
    }

    /**
     * @param  list<array<string, mixed>>  $rawRecords
     * @return array<string, mixed>
     */
    public function buildArtifact(string $batchId, string $sellerRef, array $rawRecords): array
    {
        $grouped = [];
        foreach ($rawRecords as $r) {
            if (! is_array($r['money'] ?? null) || ! is_string($r['money']['currency'] ?? null)) {
                continue;
            }
            $kind = match ($r['recordType'] ?? '') {
                'obligation' => ImportRecordKind::ProvenObligation->value,
                'allocation' => ImportRecordKind::ProvenAllocation->value,
                'payment_evidence' => ImportRecordKind::ProvenPaymentEvidence->value,
                'refund_evidence' => ImportRecordKind::ProvenRefundEvidence->value,
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $curr = $r['money']['currency'];
            $money = Money::fromWire($r['money']);
            $key = $curr.'|'.$kind;
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['currency' => $curr, 'recordKind' => $kind, 'count' => 0, 'minor' => 0];
            }
            $grouped[$key]['count']++;
            $grouped[$key]['minor'] += $money->minor;
        }
        ksort($grouped, SORT_STRING);
        $totals = [];
        foreach ($grouped as $g) {
            $totals[] = [
                'sellerRef' => $sellerRef,
                'currency' => $g['currency'],
                'recordKind' => $g['recordKind'],
                'count' => $g['count'],
                'totalAmount' => (new Money($g['minor'], Currency::of($g['currency'])))->toWire(),
                'authoritativeCurrentBalance' => false,
            ];
        }

        return [
            'exportSchema' => 'source.export.v1',
            'profile' => 'generic',
            'exportVersion' => '1.0.0',
            'batchId' => $batchId,
            'sourceSystem' => 'host-billing/export',
            'sourceInstallation' => 'src-inst-1',
            'sourceBook' => 'book-main',
            'sourceExportRevision' => 1,
            'exportCutoff' => '2026-10-20T00:00:00Z',
            'installationRef' => self::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => $sellerRef,
            'importPolicyVersion' => 'import-policy-v1',
            'sanitizationPolicy' => ['piiRedacted' => true, 'secretsStripped' => true, 'policyVersion' => 'v1'],
            'totalsByCurrency' => $totals,
            'records' => $rawRecords,
            'recordCount' => count($rawRecords),
            'recordsChecksum' => 'sha256:'.hash('sha256', $this->codec->canonicalize($rawRecords)),
        ];
    }

    /** @return array<string, mixed> */
    public function rawObligation(string $id, string $amount, string $currency, string $priceVersionRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'obligation',
            'eventBusinessDate' => '2025-11-01T09:00:00Z',
            'originalBusinessRefs' => ['customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1', 'obligationRef' => 'src-'.$id],
            'partyRoles' => ['subjectRef' => 'subj-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [['evidenceRef' => 'ev-'.$id, 'kind' => 'price_snapshot', 'digest' => self::DIGEST_1, 'sanitized' => true]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'frozenPriceSnapshot' => ['basis' => 'frozen_historical', 'priceVersionRef' => $priceVersionRef, 'priceDigest' => self::DIGEST_1, 'currency' => $currency],
        ];
    }

    /** @return array<string, mixed> */
    public function rawAllocation(string $id, string $amount, string $currency, string $oblRef, string $payRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'allocation',
            'eventBusinessDate' => '2025-11-01T09:01:00Z',
            'originalBusinessRefs' => ['customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1', 'obligationRef' => $oblRef, 'paymentRef' => $payRef],
            'partyRoles' => ['subjectRef' => 'subj-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [['evidenceRef' => 'ev-'.$id, 'kind' => 'allocation_lineage', 'digest' => self::DIGEST_2, 'sanitized' => true]],
            'money' => ['amount' => $amount, 'currency' => $currency],
        ];
    }

    /** @return array<string, mixed> */
    public function rawPayment(string $id, string $amount, string $currency, string $origOpRef, string $payRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'payment_evidence',
            'eventBusinessDate' => '2025-11-01T09:02:00Z',
            'originalBusinessRefs' => ['customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1', 'paymentRef' => $payRef],
            'partyRoles' => ['subjectRef' => 'subj-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [['evidenceRef' => 'ev-'.$id, 'kind' => 'payment_provider_fact', 'digest' => self::DIGEST_3, 'sanitized' => true]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'fundingRefs' => [
                'sourceAuthority' => 'source-gateway',
                'fundingStatus' => 'captured',
                'providerAccountRef' => 'pa-acct-1',
                'providerOperationRef' => 'psp-op-'.$id,
                'originalOperationRef' => $origOpRef,
                'observedAt' => '2025-11-01T09:02:00Z',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function rawRefund(string $id, string $amount, string $currency, string $origOpRef): array
    {
        return [
            'sourceRecordId' => $id,
            'sourceRecordRevision' => 1,
            'recordType' => 'refund_evidence',
            'eventBusinessDate' => '2025-11-05T12:00:00Z',
            'originalBusinessRefs' => ['customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1', 'originalPaymentRef' => $origOpRef],
            'partyRoles' => ['subjectRef' => 'subj-1', 'customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1'],
            'evidenceRefs' => [['evidenceRef' => 'ev-'.$id, 'kind' => 'refund_provider_fact', 'digest' => self::DIGEST_3, 'sanitized' => true]],
            'money' => ['amount' => $amount, 'currency' => $currency],
            'fundingRefs' => [
                'sourceAuthority' => 'source-gateway',
                'fundingStatus' => 'refunded',
                'providerAccountRef' => 'pa-acct-1',
                'providerOperationRef' => 'psp-rfnd-'.$id,
                'originalOperationRef' => $origOpRef,
                'observedAt' => '2025-11-05T12:00:00Z',
            ],
        ];
    }
}
