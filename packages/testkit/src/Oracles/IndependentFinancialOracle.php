<?php

declare(strict_types=1);

namespace Billium\Testkit\Oracles;

use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\EffectKind;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ProviderSettlement;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Packman\Attributes\Api;
use RuntimeException;

/** Independent arithmetic over immutable external effects; no production reducer/calculator. */
#[Api(since: '1.0')]
final class IndependentFinancialOracle
{
    /** @var array<string,array{authorized:int,captured:int,refunded:int,voided:int}> */
    private array $observed = [];

    /** @var array<string,true> */
    private array $facts = [];

    /** @var array<string,string> */
    private array $factDigests = [];

    /** @param array{authorized:int,captured:int,refunded:int,voided:int} $delta */
    public function observe(string $businessRef, string $factRef, array $delta): void
    {
        foreach ($delta as $amount) {
            if ($amount < 0) {
                throw new RuntimeException('Negative oracle effect');
            }
        }
        $key = $businessRef.':'.$factRef;
        $digest = hash('sha256', serialize($delta));
        if (isset($this->facts[$key])) {
            if ($this->factDigests[$key] !== $digest) {
                throw new RuntimeException('Conflicting oracle fact');
            }

            return;
        }
        $totals = $this->totals($businessRef);
        foreach ($delta as $name => $amount) {
            if ($amount > PHP_INT_MAX - $totals[$name]) {
                throw new RuntimeException('Oracle integer overflow');
            }
            $totals[$name] += $amount;
        }
        $this->observed[$businessRef] = $totals;
        $this->facts[$key] = true;
        $this->factDigests[$key] = $digest;
    }

    /** @return array{authorized:int,captured:int,refunded:int,voided:int} */
    public function totals(string $businessRef): array
    {
        return $this->observed[$businessRef] ?? ['authorized' => 0, 'captured' => 0, 'refunded' => 0, 'voided' => 0];
    }

    /** @return array{capture:int,refund:int,void:int} */
    public function capacities(string $businessRef, int $captureReserved = 0, int $refundReserved = 0, int $voidReserved = 0): array
    {
        if (min($captureReserved, $refundReserved, $voidReserved) < 0) {
            throw new RuntimeException('Negative reservation');
        }
        $t = $this->totals($businessRef);
        if (($t['authorized'] === 0 && $t['voided'] !== 0) || ($t['authorized'] !== 0 && ($t['captured'] > $t['authorized'] || $t['voided'] > $t['authorized'] - $t['captured']))) {
            throw new RuntimeException('Oracle authorization capacity exceeded');
        }
        $authorization = $t['authorized'] === 0 ? 0 : $t['authorized'] - $t['captured'] - $t['voided'];
        $remainingRefund = $t['captured'] - $t['refunded'];
        if ($authorization < 0 || $remainingRefund < 0 || $captureReserved > $authorization || $voidReserved > $authorization - $captureReserved || $refundReserved > $remainingRefund) {
            throw new RuntimeException('Oracle capacity exceeded');
        }

        return ['capture' => $authorization - $captureReserved - $voidReserved, 'refund' => $remainingRefund - $refundReserved, 'void' => $authorization - $captureReserved - $voidReserved];
    }

    public static function businessIdentity(FrozenBinding $binding): string
    {
        return hash('sha256', serialize([$binding->scope, $binding->merchantAccountRef, $binding->rootOperationRef]));
    }

    public function ingestProvider(DurableJournal $journal): void
    {
        $records = $journal->records();
        foreach ($records as $record) {
            if (! $record instanceof ProviderRecord) {
                continue;
            }
            $status = $record->status;
            foreach ($records as $settlement) {
                if ($settlement instanceof ProviderSettlement && $settlement->identity === $record->identity) {
                    $status = $settlement->status;
                }
            }
            if ($status !== 'succeeded') {
                continue;
            }
            $delta = ['authorized' => 0, 'captured' => 0, 'refunded' => 0, 'voided' => 0];
            $amount = $record->request->amount->minor;
            match ($record->kind) {
                EffectKind::Charge => $delta['captured'] = $amount,
                EffectKind::Authorize => $delta['authorized'] = $amount,
                EffectKind::Capture => $delta['captured'] = $amount,
                EffectKind::Refund => $delta['refunded'] = $amount,
                EffectKind::VoidAuthorization => $delta['voided'] = $amount,
            };
            $business = self::businessIdentity($record->request->binding);
            $this->observe($business, $record->identity, $delta);
        }
    }

    public function assertSendCount(DurableJournal $journal, string $operationRef, int $expected): void
    {
        $count = 0;
        foreach ($journal->records() as $record) {
            if ($record instanceof JournalEvent && $record->kind === 'provider.call' && ($record->data['operation'] ?? null) === $operationRef) {
                $count++;
            }
        }
        if ($count !== $expected) {
            throw new RuntimeException('Unexpected actual provider send count: '.$count);
        }
    }

    /** @param array{authorized:int,captured:int,refunded:int,voided:int} $applied */
    public function assertApplied(string $businessRef, array $applied): void
    {
        if ($this->totals($businessRef) !== $applied) {
            throw new RuntimeException('Observed and applied totals diverge');
        }
    }

    public function assertCallCount(DurableJournal $journal, string $operationRef, int $expected): void
    {
        $actual = 0;
        foreach ($journal->records() as $record) {
            if ($record instanceof JournalEvent && $record->kind === 'provider.call' && ($record->data['operation'] ?? null) === $operationRef) {
                $actual++;
            }
        }
        if ($actual !== $expected) {
            throw new RuntimeException('External call count differs: expected '.$expected.', observed '.$actual);
        }
    }
}
