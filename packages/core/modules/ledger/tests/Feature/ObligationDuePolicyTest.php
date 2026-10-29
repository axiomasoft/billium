<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\AmendObligationDuePolicy;
use Billium\Ledger\Data\ObligationDuePolicy;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use DateTimeImmutable;
use PDOException;

final class ObligationDuePolicyTest extends LedgerTestCase
{
    public function test_amendment_requires_the_obligations_account_and_complete_provenance_in_the_guarded_routine(): void
    {
        $id = $this->obligation('scoped');
        $policy = new ObligationDuePolicy(null, 'host/2', 'policy', self::digest());
        try {
            $this->ledger()->amendObligationDuePolicy(new AmendObligationDuePolicy('wrong-account', $this->account('account-b'), 'scoped', $policy));
            self::fail('An amendment cannot cross billing accounts');
        } catch (ProblemException $refused) {
            self::assertSame(ProblemCode::ReferenceUnknown, $refused->problem);
        }
        try {
            $this->routine('amend_obligation_due_policy', [self::INSTALLATION, 'seller-a', 'test', 'account-a', $id,
                null, 'host/2', null, self::digest(), null, 'invalid-policy-receipt', self::digest()]);
            self::fail('A direct runtime command cannot disable due collection without complete provenance');
        } catch (PDOException $error) {
            self::assertStringContainsString(ProblemCode::ValidationInvalid->value, $error->getMessage());
        }
        self::assertSame(1, $this->value("SELECT revision FROM billium_obligation WHERE obligation_ref='scoped'"));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "kind='obligation_due_policy'"));
        self::assertSame(0, $this->rows('billium_outbox_event', "type='obligation.adjusted.v1'"));
    }

    public function test_due_query_uses_explicit_policy_boundaries_account_scope_and_keyset(): void
    {
        $due = new DateTimeImmutable('2026-10-28T10:00:00Z');
        $later = new DateTimeImmutable('2026-10-29T10:00:00Z');
        foreach (['first', 'second', 'delayed', 'other-account'] as $ref) {
            $policy = new ObligationDuePolicy($due, 'host/1', 'host-policy', self::digest(), $ref === 'delayed' ? $later : null);
            $this->ledger()->recordObligation(new RecordObligation('key-'.$ref, $this->account($ref === 'other-account' ? 'account-b' : 'account-a'), $ref,
                'basis-'.$ref, 'customer', 'component', Money::parse('1.00', 'RUB'), self::digest(), duePolicy: $policy))->orThrow();
        }
        $this->obligation('no-due');
        $q = $this->application()->make(LedgerQueries::class);
        self::assertSame([], $q->dueObligations('account-a', $due->modify('-1 microsecond'))['obligations']);
        $page = $q->dueObligations('account-a', $due, 1);
        self::assertSame(['first'], array_column(self::arr($page['obligations']), 'obligationRef'));
        $next = $q->dueObligations('account-a', $due, 1, self::text($page['nextCursor']));
        self::assertSame(['second'], array_column(self::arr($next['obligations']), 'obligationRef'));
        self::assertArrayNotHasKey('nextCursor', $next);
        self::assertSame(['first', 'second', 'delayed'], array_column(self::arr($q->dueObligations('account-a', new DateTimeImmutable('2099-01-01Z'))['obligations']), 'obligationRef'));
        self::assertSame(['other-account'], array_column(self::arr($q->dueObligations('account-b', $due)['obligations']), 'obligationRef'));
        foreach (['bad-cursor', self::text($page['nextCursor'])] as $cursor) {
            try {
                $q->dueObligations('account-b', $due, 1, $cursor);
                self::fail('Cursor cannot cross account or contain arbitrary data');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            }
        }
        $wire = $q->dispatch(json_encode(['schema' => 'billium.query.v1', 'installationRef' => self::INSTALLATION, 'sellerRef' => 'seller-a', 'environment' => 'test',
            'query' => 'dueObligations', 'selector' => ['billingAccountRef' => 'account-a'], 'asOf' => '2026-10-28T10:00:00Z', 'limit' => 2], JSON_THROW_ON_ERROR));
        (new Codec)->decode(json_encode($wire, JSON_THROW_ON_ERROR));
        $old = $this->queriesReceipt('obligation:basis-first');
        $command = new AmendObligationDuePolicy('disable-first', $this->account(), 'first',
            new ObligationDuePolicy(null, 'host/2', 'disabled-policy', self::digest('b')));
        $amended = $this->ledger()->amendObligationDuePolicy($command)->orThrow();
        $replay = $this->ledger()->amendObligationDuePolicy($command)->orThrow();
        self::assertTrue($replay->replayed);
        self::assertSame($amended->body, $replay->body);
        self::assertSame(2, $amended->body['revision']);
        self::assertSame('disabled-policy', $amended->body['source_policy_ref']);
        self::assertSame(self::digest('b'), $amended->body['source_policy_snapshot_digest']);
        self::assertNull($amended->body['due_at']);
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='obligation_due_policy'"));
        self::assertSame(1, $this->rows('billium_outbox_event', "type='obligation.adjusted.v1'"));
        try {
            $this->ledger()->amendObligationDuePolicy(new AmendObligationDuePolicy('disable-first', $this->account(), 'first',
                new ObligationDuePolicy($due, 'host/3', 'another-policy', self::digest())));
            self::fail('A reused key cannot replace its original policy');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }
        self::assertSame($old, $this->queriesReceipt('obligation:basis-first'));
        self::assertSame('host/2', $q->obligation('first')['duePolicyVersion']);
        self::assertSame(['second'], array_column(self::arr($q->dueObligations('account-a', $due)['obligations']), 'obligationRef'));
        $enable = $this->ledger()->amendObligationDuePolicy(new AmendObligationDuePolicy('enable-first', $this->account(), 'first',
            new ObligationDuePolicy($due, 'host/3', 'enabled-policy', self::digest(), $later)))->orThrow();
        self::assertSame(3, $enable->body['revision']);
        self::assertSame($amended->body, $this->ledger()->amendObligationDuePolicy($command)->orThrow()->body, 'old command replays after a later amendment');
        self::assertSame(['second'], array_column(self::arr($q->dueObligations('account-a', $due)['obligations']), 'obligationRef'));
        self::assertSame(['first', 'second', 'delayed'], array_column(self::arr($q->dueObligations('account-a', $later)['obligations']), 'obligationRef'));
        $this->callers->set(new Caller('seller-b', 'test', self::INSTALLATION, 'actor'));
        try {
            $q->dueObligations('account-a', $due);
            self::fail('Foreign billing account must be hidden');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ReferenceUnknown, $error->problem);
        }
    }

    /** @return array<string, mixed> */
    private function queriesReceipt(string $ref): array
    {
        return $this->application()->make(LedgerQueries::class)->receipt($ref);
    }

    public function test_null_due_date_never_becomes_automatic_and_explicit_boundary_is_retained(): void
    {
        $this->obligation('no-due');
        $due = new DateTimeImmutable('2026-10-28T10:00:00Z');
        $policy = new ObligationDuePolicy($due, 'host/1', 'policy', self::digest(), $due);
        $this->ledger()->recordObligation(new RecordObligation('due-key', $this->account(), 'due', 'due-basis', 'customer', 'component', Money::parse('1.00', 'RUB'), self::digest(), duePolicy: $policy));
        self::assertSame(['due'], array_column($this->table("SELECT obligation_ref FROM billium_obligation WHERE seller_ref='seller-a' AND environment='test' AND status='open' AND due_at <= '2099-01-01' ORDER BY due_at,id"), 'obligation_ref'));
        self::assertSame(0, $this->rows('billium_obligation', "due_at < '2026-10-28 10:00:00+00'"));
        self::assertSame('host/1', $this->value("SELECT due_policy_version FROM billium_obligation WHERE obligation_ref='due'"));
        self::assertSame('23514', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_obligation SET due_at=now(),revision=revision+1 WHERE obligation_ref='no-due'"));
        self::assertStringContainsString('WHERE (due_at IS NOT NULL)', self::text($this->value("SELECT indexdef FROM pg_indexes WHERE indexname='billium_obligation_due_idx'")));
    }
}
