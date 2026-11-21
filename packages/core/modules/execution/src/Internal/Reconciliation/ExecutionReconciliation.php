<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reconciliation;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\QualifiedInventoryDriver;
use Billium\Execution\Contracts\ReconciliationCommands;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\QualifiedInventoryPage;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Disputes\InventoryComparison;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Internal\Storage\ExecutionRows;
use Closure;
use DateTimeImmutable;
use Exception;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ExecutionReconciliation implements ReconciliationCommands
{
    /**
     * @param  Closure():PaymentDrivers  $drivers
     * @param  Closure():AdapterQualification  $qualifications
     */
    public function __construct(private ReconciliationJournal $journal, private ExecutionStore $store, private CallerContext $callers,
        private MerchantRegistry $merchants, private Closure $drivers, private ContinuityMerchantCredentials $credentials,
        private Closure $qualifications, private TransportBoundary $boundary, private InventoryItems $items, private TargetedRecovery $recovery, private InventoryComparison $comparisons) {}

    public function targeted(ExecutionScope $scope, string $runRef, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil): StoredOutcome
    {
        return $this->recovery->recover($scope, $runRef, $attemptRef, $worker, $leaseUntil);
    }

    public function inventory(ExecutionScope $scope, string $sourceAuthority, string $runRef, string $merchantRevisionRef,
        string $currency, DateTimeImmutable $from, DateTimeImmutable $through): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        $caller = $this->callers->current();
        PaymentIdentity::select($caller, $scope, $sourceAuthority);
        if (! in_array('execution.reconcile', $caller->permissions, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Account inventory requires an authenticated reconciliation operator');
        }
        $merchant = $this->store->merchantRevision($scope, $merchantRevisionRef);
        $window = new RecoveryWindow($scope, $sourceAuthority, $runRef, $merchant, $currency, $from, $through);
        $cursor = null;
        $previousReport = null;
        $runId = 0;
        $start = $this->journal->write($scope, $sourceAuthority, 'execution.begin_reconciliation', function (TransactionContext $ctx) use ($window, &$cursor, &$previousReport, &$runId): ExecutionReceipt {
            $run = $this->journal->current($ctx, $window);
            $cursor = is_string($run->inventory_cursor) ? $run->inventory_cursor : null;
            $runId = ExecutionRows::integer($run->id);
            $previousReport = $this->journal->db($ctx)->table('billium_execution_recon_page')->where('run_id', $runId)->orderBy('page_no')->value('report_ref');

            return $window->receipt(ExecutionRows::text($run->state));
        });
        if ($start->body['status'] === 'complete' || $start->body['status'] === 'parked') {
            return $start;
        }
        try {
            $capability = $this->merchants->capabilities($merchant->capabilitySnapshotRef);
            $drivers = ($this->drivers)();
            $driver = $drivers->resolve($merchant->driverId, $merchant->driverContractVersion);
            if (! $driver instanceof QualifiedInventoryDriver || $capability->digest !== $merchant->capabilityDigest
                || $capability->driverContractVersion !== $driver->contractVersion() || $capability->driverId !== $merchant->driverId
                || $capability->merchantAccountRef !== $merchant->merchantAccountRef
                || ExecutionCodec::differs($capability, $drivers->capabilities($merchant))) {
                throw new ProblemException(ProblemCode::CapabilityUnsupported, 'The frozen merchant lacks a qualified bounded inventory reader');
            }
            $capability->requireCapability(DriverCapability::InventoryComplete, $scope->environment === 'production');
            $qualification = ($this->qualifications)();
            foreach ($capability->supportedEffects as $effect) {
                $qualification->verify($merchant, $capability, $effect);
            }
            $credentials = $this->credentials->resolve($merchant, 'reconciliation');
            $page = $driver->inventoryWindow($merchant, $credentials, $currency, $sourceAuthority, $from, $through, $cursor);
            if ($page->routeDigest !== $window->routeDigest($credentials->credentialVersionRef) || $page->credentialVersionRef !== $credentials->credentialVersionRef
                || $page->sourceAuthority !== $sourceAuthority || $page->ownerNamespace !== $scope->intentOwnerNamespace
                || $page->currency !== $currency || $page->from->format('U.u') !== $from->format('U.u') || $page->through->format('U.u') !== $through->format('U.u') || $page->cursor !== $cursor
                || $page->qualificationEvidenceRef !== $capability->qualificationEvidenceRef
                || ($previousReport !== null && $page->reportRef !== $previousReport)
                || ($page->completeThrough !== null && ($page->nextCursor !== null || $page->completeThrough < $through))) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Provider page does not cover the requested report, lineage, horizon or cursor');
            }
            if ($page->nextCursor === null && $page->completeThrough === null) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Provider cannot attest the terminal page');
            }
            $pageNo = $this->stage($window, $runId, $page);
            foreach ($page->items as $offset => $item) {
                $this->items->record($window, $runId, $pageNo, $offset, $item, $capability);
            }

            return $this->journal->finish($window, $cursor, $page->nextCursor === null ? 'complete' : 'running',
                $page->nextCursor, $page->completeThrough === null ? null : ExecutionRows::stamp($through),
                ExecutionCodec::digest([$page->reportRef, $page->sourceDigest, $page->cursor, $page->nextCursor, $page->items]));
        } catch (Exception $failure) {
            $unsupported = $failure instanceof ProblemException && $failure->problem === ProblemCode::CapabilityUnsupported;
            if ($unsupported) {
                $this->journal->write($scope, $sourceAuthority, 'execution.park_inventory', function (TransactionContext $ctx) use ($window): ExecutionReceipt {
                    $this->journal->current($ctx, $window);
                    $this->comparisons->review($ctx, $window, $window->merchant->revisionRef, 'manual_required', null,
                        $window->merchant->capabilitySnapshotRef, $window->merchant->capabilityDigest, ['status' => 'qualified_inventory_required'],
                        ['lookupState' => 'inventory_unsupported', 'credentialVersionRef' => $window->merchant->credentialVersionRef]);

                    return $window->receipt('manual_required');
                });
            }
            $this->journal->finish($window, $cursor, $unsupported ? 'parked' : 'incomplete', $cursor, null, null);
            throw $failure;
        }
    }

    private function stage(RecoveryWindow $w, int $runId, QualifiedInventoryPage $page): int
    {
        $number = 0;
        $this->journal->write($w->scope, $w->source, 'execution.stage_inventory_page', function (TransactionContext $ctx) use ($w, $runId, $page, &$number): ExecutionReceipt {
            $run = $this->journal->current($ctx, $w);
            if ($run->inventory_cursor !== $page->cursor || $run->state === 'complete') {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Another worker advanced the inventory run');
            }
            $pages = $this->journal->db($ctx)->table('billium_execution_recon_page')->where('run_id', $runId);
            $old = (clone $pages)->where('cursor_in', $page->cursor)->first();
            $content = ExecutionCodec::digest($page->items);
            if ($old !== null) {
                if ($old->credential_version_ref !== $page->credentialVersionRef || $old->report_ref !== $page->reportRef || $old->source_digest !== $page->sourceDigest || $old->cursor_out !== $page->nextCursor
                    || $old->items_digest !== $content || (($old->complete_through === null) !== ($page->completeThrough === null))
                    || (is_string($old->complete_through) && new DateTimeImmutable($old->complete_through)->format('U.u') !== $page->completeThrough?->format('U.u'))) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Provider replaced an immutable inventory page');
                }
                $number = ExecutionRows::integer($old->page_no);
            } else {
                if ($page->nextCursor !== null && ($page->nextCursor === $page->cursor || (clone $pages)->where('cursor_in', $page->nextCursor)->exists())) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Provider repeated a prior cursor');
                }
                $number = ExecutionRows::integer((clone $pages)->max('page_no') ?? 0) + 1;
                $pages->insert(['run_id' => $runId, 'page_no' => $number, 'cursor_in' => $page->cursor, 'cursor_out' => $page->nextCursor,
                    'report_ref' => $page->reportRef, 'credential_version_ref' => $page->credentialVersionRef, 'source_digest' => $page->sourceDigest, 'items_digest' => $content,
                    'complete_through' => $page->completeThrough === null ? null : ExecutionRows::stamp($page->completeThrough), 'item_count' => count($page->items)]);
            }

            return $w->receipt('staged');
        });

        return $number;
    }
}
