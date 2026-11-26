<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class SelectedFinancialScopeTest extends PlatformTestCase
{
    private const string OWNER = 'merchant:Europe/42';

    public function test_process_grants_cover_one_selected_tuple_and_refuse_mixed_or_missing_qualifiers(): void
    {
        $this->activeSession();
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1',
            ['remote-billium', 'standalone-billium'], [self::OWNER, 'subsidiary']));
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $first = self::scope(self::OWNER, 'remote-billium');
        $second = self::scope('subsidiary', 'standalone-billium');
        $this->authority->issueProcess($token, [$first, $second]);
        self::assertTrue($this->write($first, 'first')->accepted);
        self::assertTrue($this->write($second, 'second')->accepted);
        foreach ([self::scope(self::OWNER, 'standalone-billium'), self::scope(null, 'remote-billium'),
            self::scope(self::OWNER, null), null] as $uncovered) {
            $this->refuse($uncovered, 'missing', ProblemCode::InstallationQuarantined);
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['missing']));
        self::assertSame(2, $this->rows('billium_test_effect'));

        $this->authority->issueProcess($token, [self::scope(null, 'remote-billium')]);
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'receiver', ['remote-billium']));
        self::assertTrue($this->write(self::scope(null, 'remote-billium'), 'receiver')->accepted);
        $this->refuse($first, 'forged-owner', ProblemCode::AuthDenied);
    }

    public function test_scoped_replay_checks_current_caller_and_binds_both_dimensions_to_the_receipt(): void
    {
        $this->activeSession();
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1',
            ['standalone-billium', 'remote-billium'], [self::OWNER, 'other']));
        $selected = self::scope(self::OWNER, 'remote-billium');
        self::assertTrue($this->write($selected, 'key')->accepted);
        self::assertTrue($this->write($selected, 'key')->replayed);
        $this->refuse(self::scope('other', 'remote-billium'), 'key', ProblemCode::IdempotencyConflict);
        $this->refuse(self::scope(self::OWNER, 'standalone-billium'), 'key', ProblemCode::IdempotencyConflict);
        $this->refuse(null, 'key', ProblemCode::IdempotencyConflict);
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [self::OWNER, 'remote-billium']));
        $this->refuse($selected, 'key', ProblemCode::AuthDenied);
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [], [self::OWNER, 'remote-billium']));
        $this->refuse($selected, 'key', ProblemCode::AuthDenied);
        foreach ([new FinancialScope('other', 'seller-a', 'test', null),
            new FinancialScope(self::INSTALLATION, null, 'test', null),
            new FinancialScope(self::INSTALLATION, 'seller-a', null, null)] as $foreign) {
            $this->refuse($foreign, 'foreign', ProblemCode::AuthDenied);
        }
        self::assertSame(1, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['key']));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['foreign']));
        self::assertSame(1, $this->rows('billium_test_effect'));
    }

    public function test_session_control_replays_the_legacy_digest_and_binds_new_qualified_receipts(): void
    {
        $this->activeSession();
        $claims = ['open', self::INSTALLATION, 'actor-1', 'seller-a', 'test', [], 'owner-1', 1, 1, 1, 'evidence:open-1'];
        $legacy = 'sha256:'.hash('sha256', json_encode($claims, JSON_THROW_ON_ERROR));
        self::assertSame($legacy, $this->value("SELECT digest FROM billium_idempotency_receipt WHERE command_key = 'open-1'"));
        self::assertSame(1, $this->installation()->openSession('open-1', 'owner-1', 1, 1, 1, 'evidence:open-1')->epoch);
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [], [self::OWNER]));
        try {
            $this->installation()->openSession('open-1', 'owner-1', 1, 1, 1, 'evidence:open-1');
            self::fail('A changed owner grant replayed a legacy receipt');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
        $this->authority->issueTransition('sealing', 'closing', [1]);
        $this->installation()->beginSealing('closing', 1);
        $digest = 'sha256:'.hash('sha256', json_encode(['sealing', self::INSTALLATION, 'actor-1', 'seller-a',
            'test', [], 1, ['intentOwnerNamespaces.v1' => [self::OWNER]]], JSON_THROW_ON_ERROR));
        self::assertSame($digest, $this->value("SELECT digest FROM billium_idempotency_receipt WHERE command_key = 'closing'"));
    }

    private static function scope(?string $owner, ?string $source): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', $owner, $source);
    }

    private function write(?FinancialScope $scope, string $key): StoredOutcome
    {
        return $this->runner()->run($this->spec(key: $key, financialScope: $scope),
            static fn (TransactionContext $context) => self::receipt(['effect' => self::effect($context, $key)]));
    }

    private function refuse(?FinancialScope $scope, string $key, ProblemCode $problem): void
    {
        try {
            $this->write($scope, $key);
            self::fail('Operation admitted outside the selected scope');
        } catch (ProblemException $exception) {
            self::assertSame($problem, $exception->problem);
        }
    }
}
