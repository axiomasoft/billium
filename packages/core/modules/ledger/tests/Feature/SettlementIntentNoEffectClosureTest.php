<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Data\Settlements\NoEffectProof;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use Illuminate\Database\QueryException;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * FA02/D63-F04: the ledger participant accepts only a typed NoEffectProof of its own root, appends one immutable
 * closure with stable targets, replays it, and refuses a committed permit, a pending/unknown/sent effect, a stale
 * revision, a changed proof or an applied intent. Later actual evidence is retained and never reopens the intent.
 */
#[Group('FA02')]
#[Group('pg')]
final class SettlementIntentNoEffectClosureTest extends LedgerTestCase
{
    private function port(): LedgerParticipants
    {
        return $this->application()->make(LedgerParticipants::class);
    }

    private function declared(): void
    {
        $this->obligation('o1', 1000);
        $this->obligation('o2', 500);
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'intent-a', 'RUB', ['o2', 'o1']));
    }

    /** @param array<string, mixed> $proof overrides of the proof the collection owner mints in the same root */
    private function close(string $key, array $proof = [], int $revision = 1, bool $withProof = true, string $closure = 'closure-a', ?\Closure $after = null): StoredOutcome
    {
        return $this->runner()->run($this->spec(key: $key), function (TransactionContext $context) use ($proof, $revision, $withProof, $closure, $after): Receipt {
            $minted = $withProof ? new NoEffectProof(
                is_string($proof['rootRef'] ?? null) ? $proof['rootRef'] : $context->rootRef(), 'intent-a', $revision, 'step-1',
                is_string($proof['kind'] ?? null) ? $proof['kind'] : 'not_dispatched',
                is_string($proof['evidenceRef'] ?? null) ? $proof['evidenceRef'] : 'evidence:no-dispatch',
                ($proof['permit'] ?? false) === true, is_string($proof['effect'] ?? null) ? $proof['effect'] : 'none',
            ) : null;
            $receipt = $this->port()->closeSettlementIntentNoEffect($context, new CloseSettlementIntentNoEffect($this->account(), 'intent-a', $revision, 'step-1', $closure, 'RUB', 'cancelled', $minted));
            if ($after !== null) {
                $after();
            }

            return $receipt;
        });
    }

    public function test_a_valid_proof_appends_one_immutable_closure_with_stable_targets_and_replays(): void
    {
        $this->declared();
        $targets = $this->table('SELECT * FROM billium_settlement_target ORDER BY position');
        $first = $this->close('k-close');
        self::assertTrue($first->accepted);
        self::assertSame('ledger.settlement_intent_closure', $first->receiptClass);
        self::assertSame(['intent-closure:closure-a', 'closure-a', 2], [$first->body['receipt_ref'] ?? null, $first->body['closure_ref'] ?? null, $first->body['revision'] ?? null]);
        $closure = $this->row('SELECT proof_kind,proof_digest,proof_evidence_ref,step_authorization_ref,expected_revision FROM billium_settlement_intent_closure');
        self::assertSame('not_dispatched', $closure['proof_kind']);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', self::text($closure['proof_digest']));
        self::assertSame(['evidence:no-dispatch', 'step-1', 1], [$closure['proof_evidence_ref'], $closure['step_authorization_ref'], self::integer($closure['expected_revision'])]);
        self::assertSame(['closed_no_effect', 2], array_values(array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, $this->row('SELECT status,revision FROM billium_settlement_intent'))));
        self::assertSame($targets, $this->table('SELECT * FROM billium_settlement_target ORDER BY position'));

        // The same proof semantics in a later root (lost reply) replay the saved receipt.
        $again = $this->close('k-close-again');
        self::assertSame($first->body, $again->body);
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='settlement_intent_closure'"));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'UPDATE billium_settlement_intent_closure SET cause=cause'));
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), 'DELETE FROM billium_settlement_intent_closure'));
    }

    public function test_conflicting_digest_stale_revision_and_missing_or_foreign_proof_refuse(): void
    {
        $this->declared();
        try {
            $this->close('k-missing', withProof: false);
            self::fail('missing proof');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
        try {
            $this->close('k-foreign-root', ['rootRef' => 'another-root']);
            self::fail('proof of another root');
        } catch (LogicException $e) {
            self::assertStringContainsString('another root', $e->getMessage());
        }
        try {
            $this->close('k-stale', revision: 2);
            self::fail('stale revision');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
        self::assertTrue($this->close('k-close')->accepted);
        foreach (['changed evidence' => ['evidenceRef' => 'evidence:other'], 'changed kind' => ['kind' => 'not_admitted']] as $case => $change) {
            try {
                $this->close('k-'.$case, $change);
                self::fail($case);
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::IdempotencyConflict, $e->problem, $case);
            }
        }
        try {
            $this->close('k-second-closure', closure: 'closure-b');
            self::fail('one terminal closure per intent');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
    }

    public function test_committed_permit_and_pending_unknown_or_sent_effect_refuse_and_nothing_closes(): void
    {
        $this->declared();
        foreach ([
            'committed permit' => ['permit' => true],
            'pending effect' => ['effect' => 'pending'],
            'unknown effect' => ['effect' => 'unknown'],
            'sent effect' => ['effect' => 'sent'],
        ] as $case => $proof) {
            $outcome = $this->close('k-'.$case, $proof);
            self::assertSame(ProblemCode::OperationNotCancellable, $outcome->problem, $case);
        }
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
        self::assertSame('open', $this->value('SELECT status FROM billium_settlement_intent'));
        self::assertSame(1, self::integer($this->value('SELECT revision FROM billium_settlement_intent')));
    }

    public function test_an_applied_intent_refuses_and_root_rollback_leaves_no_closure(): void
    {
        $this->declared();
        try {
            $this->close('k-rollback', after: static fn () => throw new RuntimeException('collection guard failed after the closure'));
            self::fail('root fails');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('collection guard', $e->getMessage());
        }
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "kind='settlement_intent_closure'"));
        self::assertSame(1, self::integer($this->value('SELECT revision FROM billium_settlement_intent')));

        // An economic application exists (a payment of the intent): no-effect closure is impossible.
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,payment_ref,source_authority,source_lineage,operation_ref,kind,captured_minor,settlement_intent_id)
            VALUES('inst-test','seller-a','test','account-a','RUB','payment-a','authority-a','lineage-a','operation-a','charge',100,?)
            SQL, [$intent]);
        try {
            $this->close('k-applied', revision: 2);
            self::fail('applied intent');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
    }

    public function test_late_actual_evidence_is_retained_under_hold_and_never_reopens_the_intent(): void
    {
        $this->declared();
        self::assertTrue($this->close('k-close')->accepted);
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $owner = $this->owner();
        // Raw custody of a late provider fact stays possible: the observed journal is independent of the intent.
        $observed = self::integer($this->value(<<<'SQL'
            INSERT INTO billium_observed_payment(installation_ref,seller_ref,environment,source_authority,source_lineage,operation_ref,kind,revision,material_digest,state,currency,requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,provider_account_ref,evidence_ref)
            VALUES('inst-test','seller-a','test','authority-a','lineage-a','late-op','charge',1,?,'succeeded','RUB',100,100,100,0,0,'provider','evidence:late') RETURNING id
            SQL, [self::digest('c')]));
        $owner->insert(<<<'SQL'
            INSERT INTO billium_observed_payment_revision(installation_ref,seller_ref,environment,observed_payment_id,revision,material_digest,state,requested_minor,authorized_minor,captured_minor,refunded_minor,voided_minor,currency,evidence_ref)
            VALUES('inst-test','seller-a','test',?,1,?,'succeeded',100,100,100,0,0,'RUB','evidence:late')
            SQL, [$observed, self::digest('c')]);
        $owner->insert(<<<'SQL'
            INSERT INTO billium_ledger_discrepancy(installation_ref,seller_ref,environment,fingerprint,comparison_basis_digest,observed_payment_id,observed_revision,expected_minor,actual_minor,hold,reason)
            VALUES('inst-test','seller-a','test','late-after-closure',?,?,1,0,100,true,'actual_after_no_effect_closure')
            SQL, [self::digest('d'), $observed]);
        // The applied projection cannot attach the fact to the closed intent; the closure and targets stay as they are.
        try {
            $owner->insert(<<<'SQL'
                INSERT INTO billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,payment_ref,source_authority,source_lineage,operation_ref,kind,captured_minor,settlement_intent_id)
                VALUES('inst-test','seller-a','test','account-a','RUB','late-payment','authority-a','lineage-a','late-op','charge',100,?)
                SQL, [$intent]);
            self::fail('closed intent cannot receive an applied payment');
        } catch (QueryException $e) {
            self::assertInstanceOf(PDOException::class, $e->getPrevious() ?? $e);
            self::assertStringContainsString('billium.reference.unknown', $e->getMessage());
        }
        self::assertSame(1, $this->rows('billium_observed_payment_revision'));
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', 'hold'));
        self::assertSame('closed_no_effect', $this->value('SELECT status FROM billium_settlement_intent'));
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'DELETE FROM billium_observed_payment'));
    }
}
