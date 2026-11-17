<?php

declare(strict_types=1);

namespace Billium\Testkit\Restore;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\AccountCoverage;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\NamespaceDisposition;
use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\ExecutionScope;
use Billium\Platform\Data\Installation\FinancialSessionView;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Packman\Attributes\Api;

/** Deployment simulation for this drill's one seller, account, currency and fully settled charge inventory. Acquisition runs outside the financial transaction. */
#[Api(since: '1.0')]
final readonly class RestoreCoverageInspector
{
    public function __construct(private string $path, private string $dumpHash, private DateTimeImmutable $horizon,
        private string $receiverDatabase, private string $run, private DispatchTicket $retiredTicket, private int $drainedProcess, private FinancialSessionView $seal, private string $key) {}

    public function retirement(): RetirementProof
    {
        $ticket = $this->retiredTicket->claims;
        $session = hash('sha256', serialize([$ticket->scope, $ticket->ownerSessionRef, $ticket->localSessionEpoch, $ticket->authorityEpoch, $ticket->deploymentGeneration, $ticket->attachmentGeneration, $ticket->audience]));
        $retired = array_any(new DurableJournal($this->path.'.host')->records(), static fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'host.retire' && $r->data['session'] === $session);
        $drained = array_any(new DurableJournal($this->path.'.control')->records(), fn (object $r): bool => $r instanceof JournalEvent && $r->kind === 'finish' && $r->pid === $this->drainedProcess);
        if (! $retired || ! $drained || $this->seal->state !== 'sealed' || $this->seal->sealedAt === null
            || $this->seal->installationRef !== $ticket->scope->installationRef || $this->seal->ownerSessionRef !== $ticket->ownerSessionRef
            || $this->seal->epoch !== $ticket->localSessionEpoch || $this->seal->authorityEpoch !== $ticket->authorityEpoch
            || $this->seal->attachmentGeneration !== $ticket->attachmentGeneration || $this->seal->deploymentGeneration !== $ticket->deploymentGeneration) {
            throw self::refused('Old worker and egress retirement incomplete');
        }
        $digests = [
            'producers' => hash('sha256', serialize($this->seal->toArray())),
            'workers' => hash('sha256', serialize([$this->drainedProcess, $drained])),
            'egress' => hash('sha256', serialize([$this->retiredTicket->ticketRef, $session, $retired])),
            'inflight' => hash('sha256', serialize([$ticket->attemptRef, $ticket->providerReference, $ticket->leaseFence])),
        ];

        return new RetirementProof('restore-retirement', new OwnerSessionIdentity($ticket->scope->installationRef, $ticket->ownerSessionRef,
            $ticket->deploymentGeneration, $ticket->attachmentGeneration, $ticket->authorityEpoch),
            $digests['producers'], $digests['workers'], $digests['egress'], $digests['inflight'], 'simulated-restore-host', new DateTimeImmutable,
            'sha256:'.hash_hmac('sha256', serialize($digests), $this->key));
    }

    public function inspect(Connection $db, ExecutionScope $scope, string $account): RecoveryCoverage
    {
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw self::refused('Host coverage acquisition inside transaction');
        }
        $this->retirement();
        $ticket = $this->retiredTicket->claims;
        $owned = ['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment];
        $operations = $db->table('billium_execution_operation')->where($owned)->where('intent_owner_namespace', $scope->intentOwnerNamespace)->where('merchant_account_ref', $account);
        $provider = array_values(array_filter(new DurableJournal($this->path)->records(), static fn (object $r): bool => $r instanceof ProviderRecord && get_object_vars($r->request->binding->scope) === get_object_vars($scope) && $r->request->binding->merchantAccountRef === $account));
        if ($provider === [] || get_object_vars($scope) !== get_object_vars($ticket->scope)) {
            throw self::refused('Provider scope has no authenticated inventory');
        }
        foreach ($provider as $r) {
            $op = (clone $operations)->where('operation_ref', $r->request->permit->operationRef)->first();
            $attempt = $db->table('billium_execution_attempt')->where($owned)->where('attempt_ref', $r->request->permit->attemptRef)->first();
            if ($op === null || $op->binding_digest !== $this->binding($r) || $attempt === null || $attempt->provider_reference !== $r->request->providerReference
                || $attempt->operation_ref !== $op->operation_ref || $attempt->request_digest !== $r->request->permit->immutableRequestDigest || $attempt->state !== 'succeeded') {
                throw self::refused('Provider inventory contains an unresolved original identity');
            }
        }
        if ($db->table('billium_execution_provider_work')->where($owned)->whereNotIn('state', ['processed'])->exists()) {
            throw self::refused('Raw provider custody awaits financial review');
        }
        $report = $db->table('billium_execution_reconciliation_run')->where($owned)->where('run_ref', $this->run)->first();
        if ($report === null || $report->state !== 'complete' || $report->completeness_watermark === null || $report->inventory_digest === null
            || $db->table('billium_execution_recon_item')->where('run_id', $report->id)->distinct()->count('operation_ref') !== count($provider)) {
            throw self::refused('Qualified paginated inventory is incomplete');
        }
        $binding = $db->table('billium_execution_recon_binding')->where('run_id', $report->id)->sole();
        $watermark = $db->table('billium_execution_recon_page')->where('run_id', $report->id)->whereNull('cursor_out')->value('complete_through');
        $through = new DateTimeImmutable(self::text($watermark));
        if ($report->merchant_account_ref !== $account || new DateTimeImmutable(self::text($report->recovery_horizon)) > $this->horizon
            || $binding->workflow !== 'inventory' || $binding->owner_namespace !== $scope->intentOwnerNamespace || $binding->source_authority !== $provider[0]->request->binding->executionProfile
            || $binding->revision_ref !== $provider[0]->request->binding->merchantRevisionRef || $binding->currency !== $provider[0]->request->amount->currency->code
            || $through < new DateTimeImmutable(self::text($binding->through_at)) || array_any($provider, static fn (ProviderRecord $r): bool => $r->acceptedAt > $through)
            || json_decode(self::text($report->unresolved), true, flags: JSON_THROW_ON_ERROR) !== []) {
            throw self::refused('Qualified inventory cannot attest the requested account, lineage and complete horizon');
        }
        $commands = $db->table('billium_execution_observation_emission')->where($owned)->whereIn('operation_ref', (clone $operations)->select('operation_ref'))->pluck('command_ref')->all();
        $receipts = [];
        foreach (new DurableJournal($this->path.'.receiver')->records() as $event) {
            if ($event instanceof JournalEvent && $event->kind === 'applied' && $event->data['database'] === $this->receiverDatabase && in_array($event->data['receipt'], $commands, true)) {
                $receipts[$event->data['receipt']] = self::text($event->data['binding']);
            }
        }
        if (count($receipts) !== count($commands) || count($commands) !== count($provider)) {
            throw self::refused('The independent receiver has incomplete application receipts');
        }
        $import = $db->table('billium_execution_original_identity_import')->where($owned)->where('intent_owner_namespace', $scope->intentOwnerNamespace)->sole();
        $namespace = $provider[0]->request->capabilities->details->lookupNamespace;
        $selection = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $provider[0]->request->binding->executionProfile);

        return new RecoveryCoverage('sha256:'.$this->dumpHash, $this->horizon, [new AccountCoverage($account, $namespace, $this->horizon, $through,
            self::text($report->completeness_watermark), self::text($report->inventory_digest), $provider[0]->request->capabilities->qualificationEvidenceRef)],
            self::text($import->manifest_digest), self::text($import->source_journal_digest), self::text($import->identity_digest), [self::text($report->inventory_digest)], array_values($receipts), [],
            [new NamespaceDisposition($selection, 'active', self::text($report->inventory_digest))]);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : throw self::refused('Incomplete external coverage record');
    }

    private function binding(ProviderRecord $record): string
    {
        foreach (new DurableJournal($this->path.'.host')->records() as $ticket) {
            if ($ticket instanceof DispatchTicket && $ticket->ticketRef === $record->request->permit->ticketRef) {
                return $ticket->claims->bindingDigest;
            }
        }
        throw self::refused('Provider request has no independent original writer ticket');
    }

    private static function refused(string $message): ProblemException
    {
        return new ProblemException(ProblemCode::RecoveryRequired, $message);
    }
}
