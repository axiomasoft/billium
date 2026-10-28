<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Repositories;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\ObligationDuePolicy;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Data\RecordObligation;

final class CommandPayload
{
    /** @return array<string, mixed> */
    public static function values(RecordObligation|AdjustObligation|RecordCustomerCredit|DeclareSettlementIntent $command): array
    {
        $values = get_object_vars($command);
        unset($values['commandKey']);
        $values['scope'] = get_object_vars($command->scope);
        if (isset($values['money']) && $values['money'] instanceof Money) {
            $values['money'] = ['minor' => (string) $values['money']->minor, 'currency' => $values['money']->currency->code];
        }
        if (isset($values['duePolicy']) && $values['duePolicy'] instanceof ObligationDuePolicy) {
            $policy = $values['duePolicy'];
            $values['duePolicy'] = [
                'dueAt' => $policy->dueAt?->format('Y-m-d\TH:i:s.uP'),
                'version' => $policy->duePolicyVersion, 'source' => $policy->sourcePolicyRef,
                'digest' => $policy->sourcePolicySnapshotDigest,
                'eligibleAt' => $policy->collectionEligibleAt?->format('Y-m-d\TH:i:s.uP'),
            ];
        }

        $payload = [];
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new \LogicException('Command field must be named');
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    public static function digest(RecordObligation|AdjustObligation|RecordCustomerCredit|DeclareSettlementIntent $command): string
    {
        return 'sha256:'.hash('sha256', (new Codec)->canonicalize(self::values($command)));
    }

    public static function validate(RecordObligation|AdjustObligation|RecordCustomerCredit|DeclareSettlementIntent $command): void
    {
        foreach ([$command->scope->sellerRef, $command->scope->environment, $command->scope->billingAccountRef] as $value) {
            if (strlen($value) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'scope references are bounded strings');
            }
        }
        foreach (get_object_vars($command) as $key => $value) {
            if (is_string($value) && ($value === '' || strlen($value) > 200)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, $key.' is a nonempty bounded string');
            }
        }
        if (! in_array($command->scope->environment, ['test', 'live'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unknown environment');
        }
        if ($command instanceof DeclareSettlementIntent) {
            if ($command->obligationRefs === [] || count($command->obligationRefs) !== count(array_unique($command->obligationRefs))) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'intent requires distinct ordered targets');
            }
            if (preg_match('/^[A-Z]{3}$/D', $command->currency) !== 1) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid currency');
            }
            Currency::of($command->currency);
        } else {
            if (Currency::of($command->money->currency->code)->scale !== $command->money->currency->scale) {
                throw new ProblemException(ProblemCode::MoneyScaleInvalid, 'currency scale differs from the frozen exponent table');
            }
            $amount = $command->money->minor;
            if (($command instanceof AdjustObligation && ($amount === 0 || $amount === PHP_INT_MIN))
                || ($command instanceof RecordObligation && $amount < 0)
                || ($command instanceof RecordCustomerCredit && $amount <= 0)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid minor amount for ledger command');
            }
            if ($command instanceof RecordObligation && preg_match('/^sha256:[0-9a-f]{64}$/D', $command->termsDigest) !== 1) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'termsDigest must be sha256');
            }
        }
    }
}
