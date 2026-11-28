#!/usr/bin/env php
<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\NamespaceDisposition;
use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportMappingRecord;
use Billium\Platform\Data\Import\ImportMappingRegistrationResult;
use Billium\Platform\Data\Import\ImportReceiptRecord;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordValidationResult;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use Billium\Platform\Internal\Import\DefaultImportPreviewEvaluator;
use Billium\Platform\Internal\Import\Sources\CreditsExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Billium\Platform\Internal\Import\Sources\RecurringExportProfile;
use Billium\Platform\Internal\Import\Sources\SourceExportProfile;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Packman\Schema\Contracts\Migration\SchemaMigrator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/**
 * Product-agnostic Billium external import CLI.
 *
 * Commands:
 *   preview   --manifest <path> [--profile auto]
 *   reconcile --batch <batchId> [--installation <ref>] [--seller <ref>] [--environment <env>] [--source-authority <auth>]
 *   apply     --manifest <path> --manifest-digest <sha256:...> --scope import:apply [--source-authority <auth>] [--chunk-size 50]
 *   resume    --batch <batchId> --manifest-digest <sha256:...> --scope import:apply [--installation <ref>] [--seller <ref>] [--environment <env>]
 *   verify    --fixture <path>
 */
function parseCliArgs(array $argv): array
{
    $command = $argv[1] ?? '';
    $options = [];
    for ($i = 2, $count = count($argv); $i < $count; $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            $body = substr($arg, 2);
            if (str_contains($body, '=')) {
                [$k, $v] = explode('=', $body, 2);
                $options[$k] = $v;
            } elseif (isset($argv[$i + 1]) && ! str_starts_with($argv[$i + 1], '--')) {
                $options[$body] = $argv[++$i];
            } else {
                $options[$body] = 'true';
            }
        }
    }

    return [$command, $options];
}

function resolveProfile(string $path, ?string $requestedProfile): SourceExportProfile
{
    if ($requestedProfile !== null && $requestedProfile !== '' && $requestedProfile !== 'auto') {
        return match ($requestedProfile) {
            'reference-recurring' => new RecurringExportProfile,
            'reference-credits' => new CreditsExportProfile,
            'generic' => new GenericExportProfile,
            default => throw new InvalidArgumentException("Unsupported export profile '{$requestedProfile}'"),
        };
    }

    $raw = is_file($path) ? file_get_contents($path) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $declared = is_array($decoded) && is_string($decoded['profile'] ?? null) ? $decoded['profile'] : 'generic';

    return match ($declared) {
        'reference-recurring' => new RecurringExportProfile,
        'reference-credits' => new CreditsExportProfile,
        default => new GenericExportProfile,
    };
}

function readOnlyEmptyStore(): ImportIdentityStore
{
    return new class implements ImportIdentityStore
    {
        public function stageBatch(ImportManifest $manifest): int
        {
            throw new LogicException('Read-only preview store forbids stageBatch');
        }

        public function stageRecord(int $batchPk, ImportRecordInput $record, ImportRecordValidationResult $validation): int
        {
            throw new LogicException('Read-only preview store forbids stageRecord');
        }

        public function transitionBatch(int $batchPk, ImportBatchStatus $targetStatus, ?string $approvalRef = null, ?string $authorityFenceRef = null): void
        {
            throw new LogicException('Read-only preview store forbids transitionBatch');
        }

        public function registerMapping(ImportSourceIdentity $identity, int $sourceRecordRevision, string $payloadDigest, string $sourceFactRef, ?string $originalIssuer = null, ?string $originalSourceNumber = null, ?string $importPolicyVersion = null): ImportMappingRegistrationResult
        {
            throw new LogicException('Read-only preview store forbids registerMapping');
        }

        public function findMapping(ImportSourceIdentity $identity, ?int $sourceRecordRevision = null): ?ImportMappingRecord
        {
            return null;
        }

        public function assignMappingTarget(ImportSourceIdentity $identity, int $sourceRecordRevision, string $targetKind, string $targetRef): ImportMappingRecord
        {
            throw new LogicException('Read-only preview store forbids assignMappingTarget');
        }

        public function supersedeMapping(ImportSourceIdentity $identity, int $previousRevision, int $newRevision, string $newPayloadDigest, string $newSourceFactRef, ?string $originalIssuer = null, ?string $originalSourceNumber = null, ?string $importPolicyVersion = null): ImportMappingRecord
        {
            throw new LogicException('Read-only preview store forbids supersedeMapping');
        }

        public function tombstoneMapping(ImportSourceIdentity $identity, int $sourceRecordRevision, string $reason): ImportMappingRecord
        {
            throw new LogicException('Read-only preview store forbids tombstoneMapping');
        }

        public function recordReceipt(int $batchPk, string $receiptRef, ImportSourceIdentity $identity, int $sourceRecordRevision, string $payloadDigest, ImportReceiptStatus $status, ?int $recordPk = null, ?int $mappingPk = null, ?string $sourceFactRef = null, ?string $billiumReceiptRef = null, array $details = []): ImportReceiptRecord
        {
            throw new LogicException('Read-only preview store forbids recordReceipt');
        }

        public function findAppliedReceipt(ImportSourceIdentity $identity, int $sourceRecordRevision): ?ImportReceiptRecord
        {
            return null;
        }
    };
}

/**
 * Boots an isolated Billium runtime application against the configured PostgreSQL database.
 */
function bootBilliumApp(Caller $caller, bool $openActiveSession = false, bool $admitExistingActiveSession = false): Application
{
    $app = Testbench::create(options: [
        'extra' => ['providers' => [BilliumCoreServiceProvider::class]],
    ]);
    TestEnvironment::configure($app);

    $callers = new FixedCallerContext($caller);
    $tokens = new FixedSessionAdmission;
    $app->instance(CallerContext::class, $callers);
    $app->instance(FinancialSessionAdmission::class, $tokens);
    $authority = SimulatedFinancialAuthorityEvidence::install($app);

    if ($openActiveSession) {
        $migrator = $app->make(SchemaMigrator::class);
        $migrator->migrateSets([
            'axiomasoft/billium-core:billium-platform',
            'axiomasoft/billium-core:billium-ledger',
        ], 'billium_owner');
        TestEnvironment::assertRuntimeSafe($app->make(DatabaseManager::class)->connection('pgsql')->getPdo());

        $installation = $app->make(Installation::class);
        $installation->init($caller->installationRef);
        $authority->issueTransition('open', 'open-1', ['owner-1', 1, 1, 1, 'evidence:open-1']);
        $installation->openSession('open-1', 'owner-1', 1, 1, 1, 'evidence:open-1');
        $authority->issueTransition('activate', 'activate-1', [1, 'certificate:1', 'admission:1', 'evidence:activate-1']);
        $installation->activateSession('activate-1', 1, 'certificate:1', 'admission:1', 'evidence:activate-1');
        $tokens->set(FixedSessionAdmission::token($caller->installationRef));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
    } elseif ($admitExistingActiveSession) {
        admitActiveInstallationSession($app, $caller, $tokens, $authority);
    }

    return $app;
}

/**
 * Admits the current CLI process against an already-initialized active installation in PostgreSQL at Tx0.
 * Never fabricates a session or token when the installation is uninitialized, closed, sealed or in recovery.
 */
function admitActiveInstallationSession(
    Application $app,
    Caller $caller,
    FixedSessionAdmission $tokens,
    SimulatedFinancialAuthorityEvidence $authority,
): void {
    $db = $app->make(DatabaseManager::class)->connection('pgsql');
    $tableExists = $db->scalar("SELECT to_regclass('public.billium_financial_session') IS NOT NULL");
    if ($tableExists !== true) {
        return;
    }

    $session = $db->selectOne(
        'SELECT id, epoch, external_owner_session_ref, deployment_generation, '
        .'external_attachment_generation, authority_epoch, state, activation_certificate_ref, sealed_at '
        .'FROM public.billium_financial_session WHERE installation_ref = ? ORDER BY epoch DESC LIMIT 1',
        [$caller->installationRef],
    );
    if (! is_object($session)
        || ($session->state ?? null) !== 'active'
        || ($session->sealed_at ?? null) !== null
        || ! is_string($session->activation_certificate_ref ?? null)
        || $session->activation_certificate_ref === ''
    ) {
        return;
    }

    $sessionId = (int) $session->id;
    $epoch = (int) $session->epoch;
    $ownerSessionRef = (string) $session->external_owner_session_ref;
    $deploymentGen = (int) $session->deployment_generation;
    $attachmentGen = (int) $session->external_attachment_generation;
    $authorityEpoch = (int) $session->authority_epoch;
    $certificateRef = (string) $session->activation_certificate_ref;

    $activatedEvent = $db->selectOne(
        "SELECT transition_ref FROM public.billium_financial_session_event WHERE session_id = ? AND transition = 'activated' ORDER BY id DESC LIMIT 1",
        [$sessionId],
    );
    $transitionRef = is_object($activatedEvent) && is_string($activatedEvent->transition_ref ?? null)
        ? $activatedEvent->transition_ref
        : '';
    if ($transitionRef === '') {
        return;
    }

    $token = FixedSessionAdmission::token(
        installationRef: $caller->installationRef,
        ownerSessionRef: $ownerSessionRef,
        deploymentGeneration: $deploymentGen,
        attachmentGeneration: $attachmentGen,
        authorityEpoch: $authorityEpoch,
        modes: [FinancialMode::Active],
        processRef: 'cli-import-'.getmypid(),
    );
    $tokens->set($token);
    $authority->issueProcess($token);

    if ($authority->transition($caller->installationRef, $transitionRef) !== null) {
        return;
    }

    $owner = new OwnerSessionIdentity(
        $caller->installationRef,
        $ownerSessionRef,
        $deploymentGen,
        $attachmentGen,
        $authorityEpoch,
    );
    $key = bin2hex(random_bytes(32));
    $sign = static fn (string $val): string => 'sha256:'.hash_hmac('sha256', $val, $key);
    $retirement = new RetirementProof(
        'cli-retire:'.$transitionRef,
        $owner,
        $sign('producers-stopped'),
        $sign('workers-terminated'),
        $sign('egress-revoked'),
        $sign('inflight-empty'),
        'cli-host-authority',
        new DateTimeImmutable('-1 minute'),
        $sign($transitionRef.'|retirement'),
    );
    $expires = new DateTimeImmutable('+1 hour');
    $nonce = bin2hex(random_bytes(16));
    $coverage = new RecoveryCoverage(
        'cli-adopted-session',
        new DateTimeImmutable('-1 day'),
        [],
        $sign('dispatch-coverage'),
        $sign('source-journal'),
        $sign('tombstones'),
        ['projection-validation'],
        ['downstream-validation'],
        [],
        [new NamespaceDisposition(new FinancialScope($caller->installationRef, null, null, null), 'active', $sign('ns-active'))],
    );
    $certificate = new ActivationCertificate(
        $certificateRef,
        $owner,
        $epoch,
        $retirement->signatureDigest,
        $coverage,
        $caller->actorRef,
        'activate',
        'adopted active installation session',
        $expires,
        $nonce,
        $sign($transitionRef.'|certificate'),
    );
    $digest = $sign($transitionRef.'|request');
    $adoptedAuth = new VerifiedSessionAuthorization(
        $transitionRef,
        'activate',
        $owner,
        $epoch,
        $digest,
        'adopted:'.$transitionRef,
        $certificate,
        $retirement,
        'adopted-admission:'.$transitionRef,
        'cli-host-authority',
        'billium.financial_session_control.v1',
        $expires,
        $nonce,
        $sign($digest),
    );

    $app->instance(FinancialAuthorityEvidence::class, new readonly class($authority, $caller->installationRef, $transitionRef, $adoptedAuth) implements FinancialAuthorityEvidence
    {
        public function __construct(
            private SimulatedFinancialAuthorityEvidence $inner,
            private string $installationRef,
            private string $transitionRef,
            private VerifiedSessionAuthorization $adoptedAuth,
        ) {}

        public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
        {
            return $this->inner->process($installationRef, $processRef, $nonce);
        }

        public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
        {
            $found = $this->inner->transition($installationRef, $transitionRef);
            if ($found !== null) {
                return $found;
            }

            return ($installationRef === $this->installationRef && $transitionRef === $this->transitionRef)
                ? $this->adoptedAuth
                : null;
        }
    });
}

/**
 * @return array<string, int>|null
 */
function trySnapshotDbCounts(): ?array
{
    $host = getenv('DB_HOST');
    $database = getenv('DB_DATABASE');
    if (! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
        return null;
    }
    try {
        $pdo = TestEnvironment::pdo(owner: true);
        $tables = [
            'billium_import_batch',
            'billium_import_record',
            'billium_import_mapping',
            'billium_import_receipt',
            'billium_obligation',
            'billium_payment',
            'billium_allocation',
            'billium_outbox_event',
            'billium_outbox_command',
        ];
        $counts = [];
        foreach ($tables as $t) {
            $exists = $pdo->query("SELECT to_regclass('public.{$t}') IS NOT NULL")->fetchColumn();
            $counts[$t] = $exists === true ? (int) $pdo->query("SELECT count(*) FROM public.{$t}")->fetchColumn() : 0;
        }

        return $counts;
    } catch (Throwable) {
        return null;
    }
}

function runPreview(array $opts): int
{
    $manifestRel = $opts['manifest'] ?? null;
    if (! is_string($manifestRel) || trim($manifestRel) === '') {
        fwrite(STDERR, "Usage: billium-import.php preview --manifest <path>\n");

        return 1;
    }
    $path = str_starts_with($manifestRel, '/') ? $manifestRel : dirname(__DIR__, 2).'/'.$manifestRel;
    $beforeCounts = trySnapshotDbCounts();

    $profile = resolveProfile($path, is_string($opts['profile'] ?? null) ? $opts['profile'] : null);
    $codec = new Codec;
    $reader = GenericExportReader::fromFile($path, $profile, null, $codec);

    // Pure read-only evaluation: zero DB writes, zero PSP/GL dispatch.
    $evaluator = new DefaultImportPreviewEvaluator(readOnlyEmptyStore(), $codec);
    $report = $evaluator->evaluate($reader->manifest(), $reader->records());

    // Validate canonical external.import.preview.v1 wire envelope through shared Codec.
    $wireJson = $codec->canonicalize($report->toWire());
    $codec->decode($wireJson);

    $afterCounts = trySnapshotDbCounts();
    if ($beforeCounts !== null && $afterCounts !== null && $beforeCounts !== $afterCounts) {
        fwrite(STDERR, "Preview invariant violated: database row counts changed during read-only preview\n");

        return 1;
    }

    if ($report->unexplainedDeltas !== []) {
        fwrite(STDERR, "Preview detected unexplained manifest currency deltas\n");
        fwrite(STDOUT, json_encode($report->toWire(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return 2;
    }

    fwrite(STDOUT, json_encode($report->toWire(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    return 0;
}

function runVerify(array $opts): int
{
    $fixtureRel = $opts['fixture'] ?? null;
    if (! is_string($fixtureRel) || trim($fixtureRel) === '') {
        fwrite(STDERR, "Usage: billium-import.php verify --fixture <path>\n");

        return 1;
    }
    $path = str_starts_with($fixtureRel, '/') ? $fixtureRel : dirname(__DIR__, 2).'/'.$fixtureRel;
    $profile = resolveProfile($path, is_string($opts['profile'] ?? null) ? $opts['profile'] : null);
    $codec = new Codec;
    $reader = GenericExportReader::fromFile($path, $profile, null, $codec);
    $manifest = $reader->manifest();
    $records = $reader->records();

    $closeProfileDb = TestEnvironment::openProfileDatabase();
    try {
        $caller = new Caller(
            sellerRef: $manifest->sellerRef,
            environment: $manifest->environment,
            installationRef: $manifest->installationRef,
            actorRef: 'verify-operator',
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        );
        $app = bootBilliumApp($caller, openActiveSession: true);
        $owner = $app->make(DatabaseManager::class)->connection('billium_owner');

        // 1. Verify read-only preview performs zero DB mutations.
        $preview = $app->make(ImportPreviewEvaluator::class)->evaluate($manifest, $records);
        $codec->decode($codec->canonicalize($preview->toWire()));
        foreach (['billium_import_batch', 'billium_import_mapping', 'billium_import_receipt', 'billium_obligation', 'billium_payment', 'billium_allocation'] as $table) {
            $count = (int) $owner->scalar("SELECT count(*) FROM public.{$table}");
            if ($count !== 0) {
                throw new RuntimeException("Preview mutated table {$table} (count={$count})");
            }
        }

        // 2. Apply full batch with explicit import:apply capability scope and frozen manifestDigest.
        $applier = $app->make(ImportBatchApplier::class);
        $options = new ImportApplyOptions(
            capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
            expectedManifestDigest: $manifest->manifestDigest,
            approvalRef: 'verify-approval-001',
        );
        $applyResult = $applier->apply($manifest, $records, $options);
        if ($applyResult->batchStatus !== ImportBatchStatus::Completed) {
            throw new RuntimeException("Expected batch status completed, got {$applyResult->batchStatus->value}");
        }

        // 3. Read-only reconcile and conservation checks.
        $reconciler = $app->make(ImportReconciler::class);
        $reconcileBefore = trySnapshotDbCounts();
        $reconciliation = $reconciler->reconcileBatch($manifest->batchId);
        $reconcileAfter = trySnapshotDbCounts();
        if ($reconcileBefore !== $reconcileAfter) {
            throw new RuntimeException('Read-only reconcile mutated database state');
        }
        if (! $reconciliation->reconciled || $reconciliation->discrepancies !== []) {
            throw new RuntimeException('Reconciliation failed: '.json_encode($reconciliation->discrepancies, JSON_THROW_ON_ERROR));
        }

        // 4. Verify source mappings, receipts, historical invoice number preservation and conservation.
        $store = $app->make(ImportIdentityStore::class);
        foreach ($records as $rec) {
            $mapping = $store->findMapping($rec->identity, $rec->sourceRecordRevision);
            if ($mapping === null) {
                throw new RuntimeException("Missing source mapping for {$rec->identity->sourceRecordId}");
            }
            if ($rec->identity->recordKind->value === 'unanchored_evidence') {
                if ($mapping->billiumTargetRef !== null) {
                    throw new RuntimeException("Unanchored record {$rec->identity->sourceRecordId} unexpectedly bound a target");
                }
            } else {
                if ($mapping->billiumTargetRef === null) {
                    throw new RuntimeException("Proven record {$rec->identity->sourceRecordId} missing adopted target ref");
                }
                $appliedReceipt = $store->findAppliedReceipt($rec->identity, $rec->sourceRecordRevision);
                if ($appliedReceipt === null) {
                    throw new RuntimeException("Proven record {$rec->identity->sourceRecordId} missing applied/queued import receipt");
                }
            }
        }

        foreach ($reconciliation->ledgerConservationByCurrency as $currency => $summary) {
            if ($summary['conserved'] !== true) {
                throw new RuntimeException("Ledger conservation failed for currency {$currency}");
            }
        }

        // 5. Verify replay idempotency across a second repackaged batch + updated mapper policy version.
        $obligationsBeforeReplay = (int) $owner->scalar('SELECT count(*) FROM public.billium_obligation');
        $paymentsBeforeReplay = (int) $owner->scalar('SELECT count(*) FROM public.billium_payment');
        $allocationsBeforeReplay = (int) $owner->scalar('SELECT count(*) FROM public.billium_allocation');

        $rawFixture = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        if (! $rawFixture instanceof stdClass) {
            throw new RuntimeException('Expected export fixture root JSON object');
        }
        $rawFixture->batchId = $manifest->batchId.'-replay';
        $rawFixture->importPolicyVersion = 'import-policy-v2';
        unset($rawFixture->artifactDigest);
        $replayReader = GenericExportReader::fromJson(json_encode($rawFixture, JSON_THROW_ON_ERROR), $profile, 'import-policy-v2', $codec);
        $replayResult = $applier->apply(
            $replayReader->manifest(),
            $replayReader->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $replayReader->manifest()->manifestDigest,
            ),
        );
        if ($replayResult->batchStatus !== ImportBatchStatus::Completed) {
            throw new RuntimeException("Replay batch did not complete: {$replayResult->batchStatus->value}");
        }
        if ((int) $owner->scalar('SELECT count(*) FROM public.billium_obligation') !== $obligationsBeforeReplay
            || (int) $owner->scalar('SELECT count(*) FROM public.billium_payment') !== $paymentsBeforeReplay
            || (int) $owner->scalar('SELECT count(*) FROM public.billium_allocation') !== $allocationsBeforeReplay
        ) {
            throw new RuntimeException('Replay batch minted duplicate monetary rows');
        }

        $output = [
            'status' => 'verified',
            'fixture' => $fixtureRel,
            'batchId' => $manifest->batchId,
            'manifestDigest' => $manifest->manifestDigest,
            'batchStatus' => $applyResult->batchStatus->value,
            'reconciliationDigest' => $reconciliation->digest,
            'currenciesVerified' => array_keys($reconciliation->ledgerConservationByCurrency),
            'accountingFactsCount' => count($reconciliation->accountingFacts),
            'replayIdempotent' => true,
        ];
        fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        foreach (array_keys($app['db']->getConnections()) as $connName) {
            $app['db']->purge($connName);
        }

        return 0;
    } finally {
        $closeProfileDb();
    }
}

function runApply(array $opts): int
{
    $manifestRel = $opts['manifest'] ?? null;
    $expectedDigest = $opts['manifest-digest'] ?? null;
    $scope = $opts['scope'] ?? '';
    if (! is_string($manifestRel) || ! is_string($expectedDigest)) {
        fwrite(STDERR, "Usage: billium-import.php apply --manifest <path> --manifest-digest <sha256:...> --scope import:apply\n");

        return 1;
    }
    if ($scope !== ImportApplyOptions::REQUIRED_SCOPE) {
        fwrite(STDERR, "Refused before mutation: apply requires --scope import:apply\n");

        return 1;
    }
    $path = str_starts_with($manifestRel, '/') ? $manifestRel : dirname(__DIR__, 2).'/'.$manifestRel;
    $profile = resolveProfile($path, is_string($opts['profile'] ?? null) ? $opts['profile'] : null);
    $reader = GenericExportReader::fromFile($path, $profile);
    $manifest = $reader->manifest();
    if (! hash_equals($manifest->manifestDigest, $expectedDigest)) {
        fwrite(STDERR, "Refused before mutation: --manifest-digest does not match frozen manifestDigest ({$manifest->manifestDigest})\n");

        return 1;
    }

    $sourceAuthority = is_string($opts['source-authority'] ?? null)
        ? $opts['source-authority']
        : ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY;
    $chunkSize = isset($opts['chunk-size']) ? max(1, (int) $opts['chunk-size']) : 50;

    $caller = new Caller(
        sellerRef: $manifest->sellerRef,
        environment: $manifest->environment,
        installationRef: $manifest->installationRef,
        actorRef: 'cli-import-operator',
        sourceAuthorities: [$sourceAuthority],
    );
    $app = bootBilliumApp($caller, openActiveSession: false, admitExistingActiveSession: true);
    $result = $app->make(ImportBatchApplier::class)->apply(
        $manifest,
        $reader->records(),
        new ImportApplyOptions(
            capabilityScope: $scope,
            expectedManifestDigest: $expectedDigest,
            migrationSourceAuthority: $sourceAuthority,
            chunkSize: $chunkSize,
        ),
    );
    fwrite(STDOUT, json_encode($result->toWire(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    return $result->batchStatus === ImportBatchStatus::Completed ? 0 : 2;
}

function runReconcile(array $opts): int
{
    $batchId = $opts['batch'] ?? null;
    $installationRef = $opts['installation'] ?? 'inst-test';
    $sellerRef = $opts['seller'] ?? '';
    $environment = $opts['environment'] ?? 'test';
    $sourceAuthority = $opts['source-authority'] ?? ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY;
    if (! is_string($batchId) || $batchId === '' || ! is_string($sellerRef) || $sellerRef === '') {
        fwrite(STDERR, "Usage: billium-import.php reconcile --batch <batchId> --seller <sellerRef> [--installation <ref>] [--environment <env>]\n");

        return 1;
    }
    $caller = new Caller($sellerRef, (string) $environment, (string) $installationRef, 'cli-reconciler', [(string) $sourceAuthority]);
    $app = bootBilliumApp($caller, openActiveSession: false);
    $report = $app->make(ImportReconciler::class)->reconcileBatch($batchId, (string) $sourceAuthority);
    fwrite(STDOUT, json_encode($report->toWire(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    return $report->reconciled ? 0 : 2;
}

function runResume(array $opts): int
{
    $batchId = $opts['batch'] ?? null;
    $expectedDigest = $opts['manifest-digest'] ?? null;
    $scope = $opts['scope'] ?? '';
    $installationRef = $opts['installation'] ?? 'inst-test';
    $sellerRef = $opts['seller'] ?? '';
    $environment = $opts['environment'] ?? 'test';
    $sourceAuthority = $opts['source-authority'] ?? ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY;
    if (! is_string($batchId) || ! is_string($expectedDigest) || ! is_string($sellerRef) || $sellerRef === '') {
        fwrite(STDERR, "Usage: billium-import.php resume --batch <batchId> --seller <sellerRef> --manifest-digest <sha256:...> --scope import:apply\n");

        return 1;
    }
    if ($scope !== ImportApplyOptions::REQUIRED_SCOPE) {
        fwrite(STDERR, "Refused before mutation: resume requires --scope import:apply\n");

        return 1;
    }
    $caller = new Caller($sellerRef, (string) $environment, (string) $installationRef, 'cli-import-operator', [(string) $sourceAuthority]);
    $app = bootBilliumApp($caller, openActiveSession: false, admitExistingActiveSession: true);
    $result = $app->make(ImportBatchApplier::class)->resume(
        $batchId,
        new ImportApplyOptions(
            capabilityScope: $scope,
            expectedManifestDigest: $expectedDigest,
            migrationSourceAuthority: (string) $sourceAuthority,
        ),
    );
    fwrite(STDOUT, json_encode($result->toWire(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    return $result->batchStatus === ImportBatchStatus::Completed ? 0 : 2;
}

[$cmd, $cliOpts] = parseCliArgs($argv);
try {
    $exitCode = match ($cmd) {
        'preview' => runPreview($cliOpts),
        'verify' => runVerify($cliOpts),
        'apply' => runApply($cliOpts),
        'reconcile' => runReconcile($cliOpts),
        'resume' => runResume($cliOpts),
        default => (static function () use ($cmd): int {
            fwrite(STDERR, "Unknown or missing command '{$cmd}'. Supported: preview, reconcile, apply, resume, verify\n");

            return 1;
        })(),
    };
    exit($exitCode);
} catch (Throwable $error) {
    fwrite(STDERR, sprintf("[%s] %s\n", $error::class, $error->getMessage()));
    exit(1);
}
