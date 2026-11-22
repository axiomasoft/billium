<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Migration;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\NamespaceDisposition;
use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Platform\Contracts\Migration\SourceHandoffHost;
use Billium\Platform\Contracts\Migration\TargetHandoffHost;
use Billium\Platform\Data\Feed\FeedCursor;
use Billium\Platform\Data\Feed\FeedPosition;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Feed\FeedSelection;
use Billium\Platform\Data\Migration\HandoffActivation;
use Billium\Platform\Data\Migration\HandoffPlan;
use Billium\Platform\Data\Migration\HandoffRecoveryRequired;
use Billium\Platform\Data\Migration\LedgerScope;
use Billium\Platform\Data\Migration\MigrationManifest;
use Billium\Platform\Data\Migration\ReconciliationChecksum;
use Billium\Platform\Data\Migration\SealedTail;
use Billium\Platform\Data\Migration\SourceFreezeCertificate;
use Billium\Platform\Data\Migration\TargetNoEffectsProof;
use Billium\Platform\Data\Migration\TargetReconciliation;
use Billium\Platform\Internal\Migration\HostAuthorityHandoff;
use Billium\Platform\Internal\Outbox\FeedTokens;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('P9.4')]
final class MigrationEvidenceTest extends TestCase
{
    public function test_repository_evidence_is_reproducible_from_the_fixture_handoff(): void
    {
        $path = dirname(__DIR__, 6).'/fixtures/integration/import/schema/handoff/valid-handoff-evidence.json';
        self::assertJsonStringEqualsJsonFile($path, json_encode(self::localEvidence(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    public static function localEvidence(): array
    {
        $expiry = new DateTimeImmutable('2099-11-22T14:00:00+00:00');
        [$handoff, $source, $target, $plan] = self::fixture($expiry);
        $handoff->stage($plan);
        $seal = $handoff->freeze($plan);
        $manifest = $handoff->snapshot($seal);
        $receipt = $handoff->verify($manifest);
        [$retirement, $cert] = self::certificates($plan->source, $plan->target, $plan->target->owner, expiresAt: $expiry);
        $target->allowedActivation = $auth = new HandoffActivation($manifest->digest(), $receipt->digest(), $retirement, $cert);
        $handoff->activate($manifest, $auth);
        $body = [
            'schema' => 'billium.migration.handoff_evidence.v1', 'evidenceClass' => 'local_fixture', 'verifiedAt' => '2026-11-22T10:00:00Z',
            'usesCommitNoAsFreshnessProof' => false, 'reliesOnLocalActiveFlag' => false,
            'sourceFreezeCertificate' => $seal->toArray(), 'migrationManifest' => $manifest->toArray(),
            'sealedTail' => ($source->sealedTail($manifest) ?? throw new HandoffRecoveryRequired('Fixture tail missing'))->toArray(),
            'targetReceipt' => $receipt->toArray(),
            'activation' => ['requested' => $target->active, 'tailVerified' => true, 'reconciliationEqual' => true, 'unresolvedBlockers' => [],
                'retirementProof' => ['proofRef' => $retirement->proofRef, 'oldOwner' => get_object_vars($retirement->oldOwner),
                    'issuedAt' => $retirement->issuedAt->format(DATE_RFC3339), 'digest' => $retirement->signatureDigest,
                    'stoppedProducerManifestDigest' => $retirement->stoppedProducerManifestDigest, 'terminatedWorkerManifestDigest' => $retirement->terminatedWorkerManifestDigest,
                    'revokedEgressProofDigest' => $retirement->revokedEgressProofDigest, 'inFlightRequestManifestDigest' => $retirement->inFlightRequestManifestDigest,
                    'issuerRef' => $retirement->issuerRef],
                'activationCertificate' => ['certificateRef' => $cert->certificateRef, 'newOwner' => get_object_vars($cert->newOwner), 'localEpoch' => $cert->localEpoch,
                    'retirementProofDigest' => $cert->retirementProofDigest, 'backupLineageRef' => $cert->coverage->backupLineageRef, 'actorRef' => $cert->actorRef,
                    'verdict' => $cert->verdict, 'expiresAt' => $cert->expiresAt->format(DATE_RFC3339), 'nonce' => $cert->nonce, 'digest' => $cert->signatureDigest,
                    'manifestDigest' => $auth->manifestDigest, 'targetReceiptDigest' => $auth->targetReceiptDigest]],
        ];

        return [...$body, 'digest' => (new Codec)->digest($body)];
    }

    public function test_mgate_03_closes_every_mutator_and_drains_before_any_source_proof(): void
    {
        [$handoff, $source, $target, $plan] = self::fixture();
        self::refused(fn () => $handoff->freeze($plan));
        $handoff->stage($plan);
        self::assertTrue($source->beginWrite('ui'));
        self::refused(fn () => $handoff->freeze($plan));
        foreach (SourceFreezeCertificate::MUTATORS as $mutator) {
            self::assertFalse($source->beginWrite($mutator), $mutator);
        }
        $source->finishWrite();
        $freeze = $handoff->freeze($plan);
        $manifest = $handoff->snapshot($freeze);
        $receipt = $handoff->verify($manifest);
        self::assertSame($receipt->digest(), $handoff->verify($manifest)->digest());
        self::assertSame(1, $receipt->checksums[0]->counts['obligations']);
        self::assertFalse($target->active);
        $auth = self::authorization($manifest, $receipt);
        self::refused(fn () => $handoff->activate($manifest, $auth));
        $target->allowedActivation = $auth;
        $handoff->activate($manifest, $auth);
        self::assertTrue($target->active);
        self::assertFalse($source->beginWrite('admin'));
    }

    public function test_tampered_snapshot_bytes_are_not_adopted(): void
    {
        [$handoff, $source, $target, $plan] = self::fixture();
        $handoff->stage($plan);
        $manifest = $handoff->snapshot($handoff->freeze($plan));
        $source->tamperTransfer = true;
        self::refused(fn () => $handoff->verify($manifest));
        self::assertNull($target->receipt);
        self::assertFalse($target->active);
    }

    public function test_mgate_04_even_rehashed_mismatches_require_recovery(): void
    {
        [$handoff, $source, $target, $plan] = self::fixture();
        $handoff->stage($plan);
        $freeze = $handoff->freeze($plan);
        $manifest = $handoff->snapshot($freeze);
        $receipt = $handoff->verify($manifest);
        $auth = self::authorization($manifest, $receipt);
        $target->allowedActivation = $auth;

        $badSeal = new SourceFreezeCertificate($plan, $freeze->freezeGeneration, 'wrong-seal', $freeze->sealReceiptDigest, $freeze->closedMutators, $freeze->signatureDigest);
        $otherCursor = $source->cursor($manifest, new FeedPosition('18446744073709551605', '6'));
        foreach ([
            ['freeze' => $badSeal],
            ['snapshotToken' => 'other-snapshot'],
            ['snapshotDigest' => hashFixture('other-snapshot')],
            ['retentionLeaseRef' => 'other-lease'],
            ['safeFeedCursor' => $otherCursor],
            ['selection' => FixtureSourceHandoff::selection($plan->source->feed, ['payment.effect.outcome.v1'])],
            ['retentionLeaseExpiresAt' => new DateTimeImmutable('-1 second')],
            ['checksums' => [self::summary([['revision' => 'fact-1', 'amount' => 4]])]],
        ] as $replacement) {
            $different = new MigrationManifest(...array_replace(get_object_vars($manifest), $replacement));
            self::refused(fn () => $handoff->activate($different, $auth));
        }

        $target->receipt = new TargetReconciliation(
            $receipt->receiptRef, $receipt->manifestDigest, $receipt->tailDigest, $receipt->adoptedCursor,
            [self::summary([['revision' => 'fact-1', 'amount' => 4]])], $receipt->dispositionsDigest,
        );
        self::refused(fn () => $handoff->activate($manifest, $auth));
        $target->receipt = null;
        self::refused(fn () => $handoff->activate($manifest, $auth));
        $target->receipt = $receipt;
        $source->tailRetained = false;
        self::refused(fn () => $handoff->activate($manifest, $auth));
        $source->tailRetained = true;
        $source->leaseLive = false;
        self::refused(fn () => $handoff->activate($manifest, $auth));
        $source->leaseLive = true;
        $source->terminalPosition = new FeedPosition('18446744073709551603', '6');
        self::refused(fn () => $handoff->activate($manifest, $auth));
        self::assertFalse($target->active);
    }

    public function test_partial_freeze_and_unpinned_selection_digest_are_invalid(): void
    {
        [$handoff, , , $plan] = self::fixture();
        $handoff->stage($plan);
        $freeze = $handoff->freeze($plan);
        $manifest = $handoff->snapshot($freeze);
        try {
            new SourceFreezeCertificate($plan, 1, 'seal', $freeze->sealReceiptDigest, ['api', 'ui'], $freeze->signatureDigest);
            self::fail('An incomplete freeze was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('freeze certificate', $error->getMessage());
        }
        $selection = new FeedSelection(...array_replace(get_object_vars($manifest->selection), ['selectionDigest' => hashFixture('unbound')]));
        $this->expectException(InvalidArgumentException::class);
        new MigrationManifest(...array_replace(get_object_vars($manifest), ['selection' => $selection]));
    }

    public function test_mgate_05_only_current_lineage_and_separate_certificate_can_activate(): void
    {
        [$handoff, $source, $target, $plan] = self::fixture();
        $handoff->stage($plan);
        $manifest = $handoff->snapshot($handoff->freeze($plan));
        $receipt = $handoff->verify($manifest);
        $foreign = self::authorization($manifest, $receipt, lineage: 'restored-copy');
        $target->allowedActivation = $foreign;
        self::refused(fn () => $handoff->activate($manifest, $foreign));
        $wrongOwner = self::authorization($manifest, $receipt, owner: $plan->source->owner);
        $target->allowedActivation = $wrongOwner;
        self::refused(fn () => $handoff->activate($manifest, $wrongOwner));
        $valid = self::authorization($manifest, $receipt);
        $target->allowedActivation = $valid;
        $source->continuity = false;
        self::refused(fn () => $handoff->activate($manifest, $valid));
        self::assertFalse($target->active);
    }

    public function test_rollback_requires_no_target_commit_and_a_new_source_epoch(): void
    {
        [$handoff, $source, $target, $plan] = self::fixture();
        $handoff->stage($plan);
        $freeze = $handoff->freeze($plan);
        $successor = new OwnerSessionIdentity($plan->source->owner->installationRef, 'new-source-session', 3, 3, 3);
        [$retirement, $certificate] = self::certificates($plan->source, $plan->source, $successor);
        $source->allowedResume = $certificate;
        $handoff->rollback($freeze, $retirement, $certificate);
        self::assertTrue($source->beginWrite('api'));
        self::assertTrue($target->fenced);
        self::refused(fn () => $handoff->snapshot($freeze));

        [$handoff, $source, $target, $plan] = self::fixture();
        $handoff->stage($plan);
        $freeze = $handoff->freeze($plan);
        $manifest = $handoff->snapshot($freeze);
        $receipt = $handoff->verify($manifest);
        $target->allowedActivation = $activation = self::authorization($manifest, $receipt);
        $handoff->activate($manifest, $activation);
        $target->recordMonetaryWrite();
        $source->allowedResume = $certificate;
        self::refused(fn () => $handoff->rollback($freeze, $retirement, $certificate));
        self::assertFalse($source->beginWrite('cron'));
        self::assertTrue($target->active);
    }

    public function test_activated_target_without_a_write_may_be_fenced_before_source_is_reopened(): void
    {
        [$handoff, $source, $target, $plan] = self::fixture();
        $handoff->stage($plan);
        $freeze = $handoff->freeze($plan);
        $manifest = $handoff->snapshot($freeze);
        $receipt = $handoff->verify($manifest);
        $target->allowedActivation = $activation = self::authorization($manifest, $receipt);
        $handoff->activate($manifest, $activation);
        $successor = new OwnerSessionIdentity($plan->source->owner->installationRef, 'new-source-session', 3, 3, 3);
        [$retirement, $certificate] = self::certificates($plan->source, $plan->source, $successor);
        $source->allowedResume = $certificate;

        $handoff->rollback($freeze, $retirement, $certificate);

        self::assertTrue($target->fenced);
        self::assertTrue($source->beginWrite('import'));
        self::refused(fn () => $handoff->activate($manifest, $activation));
    }

    /** @return array{HostAuthorityHandoff,FixtureSourceHandoff,FixtureTargetHandoff,HandoffPlan} */
    private static function fixture(?DateTimeImmutable $expiresAt = null): array
    {
        $source = new FixtureSourceHandoff($expiresAt);
        $target = new FixtureTargetHandoff($source);
        $source->target = $target;
        $src = new LedgerScope(new FeedScope('inst', 'seller', 'test', 'source-lineage'), new OwnerSessionIdentity('inst', 'source', 1, 1, 1), ['receivable'], ['ledger']);
        $dst = new LedgerScope(new FeedScope('inst', 'seller', 'test', 'target-lineage'), new OwnerSessionIdentity('inst', 'target', 2, 2, 2), ['receivable'], ['ledger']);

        return [new HostAuthorityHandoff($source, $target), $source, $target, new HandoffPlan('handoff', $src, $dst)];
    }

    private static function authorization(MigrationManifest $manifest, TargetReconciliation $receipt, ?string $lineage = null, ?OwnerSessionIdentity $owner = null): HandoffActivation
    {
        [$retirement, $certificate] = self::certificates($manifest->plan->source, $manifest->plan->target, $owner ?? $manifest->plan->target->owner, $lineage);

        return new HandoffActivation($manifest->digest(), $receipt->digest(), $retirement, $certificate);
    }

    /** @return array{RetirementProof,ActivationCertificate} */
    private static function certificates(LedgerScope $source, LedgerScope $target, OwnerSessionIdentity $owner, ?string $lineage = null, ?DateTimeImmutable $expiresAt = null): array
    {
        $past = new DateTimeImmutable('2026-10-15T08:00:00Z');
        $digest = hashFixture('proof');
        $retirement = new RetirementProof('retire', $source->owner, $digest, $digest, $digest, $digest, 'fixture', $past, $digest);
        $scope = new FinancialScope($target->feed->installationRef, $target->feed->sellerRef, $target->feed->environment, null);
        $coverage = new RecoveryCoverage($lineage ?? $source->feed->lineage, $past, [], $digest, $digest, $digest, ['projection'], ['downstream'], [],
            [new NamespaceDisposition($scope, 'active', $digest)]);
        $certificate = new ActivationCertificate('activate', $owner, 2, $retirement->signatureDigest, $coverage,
            'operator', 'activate', 'fixture handoff', $expiresAt ?? new DateTimeImmutable('+1 hour'), 'once', $digest);

        return [$retirement, $certificate];
    }

    /** @param list<array{revision:string,amount:int}> $facts */
    public static function summary(array $facts): ReconciliationChecksum
    {
        $counts = array_fill_keys(ReconciliationChecksum::COUNTS, count($facts));
        $totals = array_fill_keys(ReconciliationChecksum::TOTALS, (string) array_sum(array_column($facts, 'amount')));

        return new ReconciliationChecksum('seller', 'USD', $counts, $totals, (new Codec)->digest($facts));
    }

    private static function refused(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected recoveryRequired');
        } catch (HandoffRecoveryRequired $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }
}

function hashFixture(string $value): string
{
    return 'sha256:'.hash('sha256', $value);
}

/** Fixture authority state stands outside the simulated ledger attachment. */
final class FixtureSourceHandoff implements SourceHandoffHost
{
    private const array SNAPSHOT = [['revision' => 'fact-1', 'amount' => 5]];

    public bool $tamperTransfer = false;

    public bool $continuity = true;

    public bool $leaseLive = true;

    public bool $tailRetained = true;

    public ?FeedPosition $terminalPosition = null;

    public ?ActivationCertificate $allowedResume = null;

    public FixtureTargetHandoff $target;

    private bool $admissionClosed = false;

    private int $inFlight = 0;

    private ?SourceFreezeCertificate $freeze = null;

    private ?MigrationManifest $manifest = null;

    public function __construct(private readonly ?DateTimeImmutable $expiresAt) {}

    public function beginWrite(string $mutator): bool
    {
        if ($this->admissionClosed || ! in_array($mutator, SourceFreezeCertificate::MUTATORS, true)) {
            return false;
        }
        $this->inFlight++;

        return true;
    }

    public function finishWrite(): void
    {
        $this->inFlight--;
    }

    public function freeze(HandoffPlan $plan): SourceFreezeCertificate
    {
        $this->admissionClosed = true;
        if ($this->inFlight !== 0) {
            throw new HandoffRecoveryRequired('Fixture root transactions have not drained');
        }

        return $this->freeze ??= new SourceFreezeCertificate($plan, 1, 'host-seal', hashFixture('sealed'), SourceFreezeCertificate::MUTATORS, hashFixture('signed-seal'));
    }

    public function isSealed(SourceFreezeCertificate $freeze): bool
    {
        return $this->admissionClosed && $this->continuity && $this->freeze?->digest() === $freeze->digest();
    }

    public function snapshot(SourceFreezeCertificate $freeze): MigrationManifest
    {
        $selection = self::selection($freeze->plan->source->feed, ['invoice.issued.v1']);
        $lease = 'pinned-lease';
        $cursor = self::tokens()->issue($selection->subscriptionRef, $selection->scope, $selection->selectionDigest, new FeedPosition('18446744073709551604', '5'), $lease);

        return $this->manifest ??= new MigrationManifest($freeze->plan, $freeze, 'snapshot-token', (new Codec)->digest(self::SNAPSHOT), $selection, $cursor,
            $lease, $this->expiresAt ?? new DateTimeImmutable('+1 hour'), [MigrationEvidenceTest::summary(self::SNAPSHOT)]);
    }

    public function sealedTail(MigrationManifest $manifest): ?SealedTail
    {
        if (! $this->isSealed($manifest->freeze) || ! $this->leaseLive || ! $this->tailRetained || $this->manifest?->digest() !== $manifest->digest()) {
            return null;
        }

        return new SealedTail($manifest->digest(), $this->cursor($manifest, $this->terminalPosition ?? new FeedPosition('18446744073709551604', '7')),
            (new Codec)->digest($this->tailPayload()));
    }

    /** @return list<array{revision:string,amount:int}> */
    public function snapshotPayload(): array
    {
        return $this->tamperTransfer ? [['revision' => 'fact-1', 'amount' => 4]] : self::SNAPSHOT;
    }

    /** @return list<array{revision:string,amount:int}> */
    public function tailPayload(): array
    {
        // The initial snapshot can include this economically identical fact before it is visible below the D52 horizon.
        return self::SNAPSHOT;
    }

    public function cursor(MigrationManifest $manifest, FeedPosition $position): FeedCursor
    {
        return self::tokens()->issue($manifest->selection->subscriptionRef, $manifest->selection->scope, $manifest->selection->selectionDigest, $position, $manifest->retentionLeaseRef);
    }

    public function resumeSource(SourceFreezeCertificate $freeze, TargetNoEffectsProof $target, RetirementProof $retirement, ActivationCertificate $certificate): bool
    {
        if (! $this->isSealed($freeze) || $target !== $this->target->noEffects || $certificate !== $this->allowedResume) {
            return false;
        }
        $this->admissionClosed = false;
        $this->freeze = null;
        $this->manifest = null;

        return true;
    }

    /** @param list<string> $types */
    public static function selection(FeedScope $feed, array $types): FeedSelection
    {
        $revision = 'events-2026-10-v1';
        $schemas = ['billium.event.v1'];
        $digest = (new Codec)->digest(['scope' => $feed->toArray(), 'eventContractRevision' => $revision,
            'envelopeSchemas' => $schemas, 'eventTypes' => $types]);

        return new FeedSelection('selected-feed', $feed, $revision, $schemas, $types, $digest,
            self::tokens()->issue('selected-feed', $feed, $digest, new FeedPosition));
    }

    private static function tokens(): FeedTokens
    {
        return new FeedTokens(str_repeat('fixture-key-', 4));
    }
}

final class FixtureTargetHandoff implements TargetHandoffHost
{
    public bool $active = false;

    public bool $fenced = false;

    public ?HandoffActivation $allowedActivation = null;

    public ?TargetReconciliation $receipt = null;

    public ?TargetNoEffectsProof $noEffects = null;

    private bool $wrote = false;

    private ?string $staged = null;

    public function __construct(private readonly FixtureSourceHandoff $source) {}

    public function stageRecovery(HandoffPlan $plan): void
    {
        $this->staged ??= $plan->digest();
    }

    public function isRecovering(HandoffPlan $plan): bool
    {
        return $plan->digest() === $this->staged && ! $this->active && ! $this->fenced;
    }

    public function applyAndVerify(MigrationManifest $manifest, SealedTail $tail): void
    {
        $snapshot = $this->source->snapshotPayload();
        $events = $this->source->tailPayload();
        $codec = new Codec;
        if (! $this->isRecovering($manifest->plan) || $this->source->sealedTail($manifest)?->digest() !== $tail->digest()
            || $codec->digest($snapshot) !== $manifest->snapshotDigest || $codec->digest($events) !== $tail->eventsDigest) {
            return;
        }
        $facts = [];
        foreach ([...$snapshot, ...$events] as $fact) {
            $existing = $facts[$fact['revision']] ?? $fact;
            if ($existing !== $fact) {
                return;
            }
            $facts[$fact['revision']] = $fact;
        }
        $checksum = MigrationEvidenceTest::summary(array_values($facts));
        if ($codec->canonicalize($checksum->toArray()) !== $codec->canonicalize($manifest->checksums[0]->toArray())) {
            return;
        }
        $this->receipt ??= new TargetReconciliation('target-receipt', $manifest->digest(), $tail->digest(), $tail->terminalCursor,
            [$checksum], $codec->digest(array_keys($facts)));
    }

    public function reconciliation(MigrationManifest $manifest): ?TargetReconciliation
    {
        return $this->receipt;
    }

    public function activate(MigrationManifest $manifest, TargetReconciliation $receipt, HandoffActivation $authorization): bool
    {
        if (! $this->isRecovering($manifest->plan) || $authorization !== $this->allowedActivation || $receipt !== $this->receipt
            || $this->source->sealedTail($manifest)?->digest() !== $receipt->tailDigest) {
            return false;
        }
        $this->active = true;

        return true;
    }

    public function fenceForRollback(HandoffPlan $plan): ?TargetNoEffectsProof
    {
        if ($this->staged !== $plan->digest() || $this->wrote) {
            return null;
        }
        $this->fenced = true;
        $this->active = false;

        return $this->noEffects ??= new TargetNoEffectsProof($plan->digest(), 'target-sealed', hashFixture('target-fenced'));
    }

    public function recordMonetaryWrite(): void
    {
        if (! $this->active) {
            throw new HandoffRecoveryRequired('Fixture target is not active');
        }
        $this->wrote = true;
    }
}
