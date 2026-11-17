<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Internal\Runner\UnboundFinancialAuthorityEvidence;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Platform\Tests\PlatformTestCase;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('D62')]
#[Group('pg')]
final class VerifiedAuthorityEvidenceTest extends PlatformTestCase
{
    public function test_unbound_evidence_refuses_even_with_an_active_database_and_a_matching_token(): void
    {
        $this->activeSession();
        $this->application()->instance(FinancialAuthorityEvidence::class, new UnboundFinancialAuthorityEvidence);
        $this->refused();
    }

    public function test_mutating_the_token_selector_cannot_issue_or_refresh_authority(): void
    {
        $this->activeSession();
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION));
        $this->refused();
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $this->authority->issueProcess($token);
        self::assertTrue($this->money()->accepted);
        $this->authority->forgetProcess($token);
        $this->refused();
    }

    public function test_forged_signature_never_populates_the_verified_cache(): void
    {
        $this->activeSession();
        $token = FixedSessionAdmission::token(self::INSTALLATION);
        $this->tokens->set($token);
        try {
            $this->authority->acceptProcess($token, [new FinancialScope(self::INSTALLATION, null, null, null)], str_repeat('0', 64));
            self::fail('forged signature accepted');
        } catch (LogicException $exception) {
            self::assertSame('Forged simulated process signature', $exception->getMessage());
        }
        $this->refused();
    }

    public function test_cache_evidence_must_match_every_process_token_claim_and_audience(): void
    {
        $this->activeSession();
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $original = $this->authority->process(self::INSTALLATION, $token->processRef, $token->nonce);
        self::assertNotNull($original);
        foreach ([
            ['token' => FixedSessionAdmission::token(self::INSTALLATION, processRef: 'foreign-process')],
            ['token' => FixedSessionAdmission::token(self::INSTALLATION)],
            ['audience' => 'billium.psp_dispatch.v1'],
            ['verifiedAt' => new DateTimeImmutable('+1 hour')],
            ['scopes' => []],
        ] as $changes) {
            $this->overrideProcess(new VerifiedProcessAdmission(...array_replace(get_object_vars($original), $changes)));
            $this->refused();
        }
    }

    public function test_expired_independently_issued_process_token_is_refused(): void
    {
        $this->activeSession();
        $token = FixedSessionAdmission::token(self::INSTALLATION, expiresIn: '-1 second');
        $this->authority->issueProcess($token);
        $this->tokens->set($token);
        $this->refused();
    }

    public function test_scope_checks_cover_seller_environment_installation_and_selected_namespace(): void
    {
        $this->activeSession();
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        foreach ([
            new FinancialScope('foreign-installation', null, null, null),
            new FinancialScope(self::INSTALLATION, 'foreign-seller', 'test', null),
            new FinancialScope(self::INSTALLATION, 'seller-a', 'live', null),
            new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:one'),
        ] as $scope) {
            $this->authority->issueProcess($token, [$scope]);
            $this->refused(); // Namespace-neutral financial work needs an explicitly signed neutral/wildcard scope.
        }
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [], ['execution:one', 'execution:two']));
        $one = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:one');
        $two = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:two');
        $this->authority->issueProcess($token, [$one]);
        $this->refused($two);
        self::assertTrue($this->money($one)->accepted);
        $this->authority->issueProcess($token, [$one, $two]);
        self::assertTrue($this->money($two)->accepted);
    }

    public function test_authority_acquisition_and_crypto_are_rejected_in_a_root_but_cached_checks_work(): void
    {
        $this->activeSession();
        $before = $this->authority->acquisitions;
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $this->runner()->run($this->spec(), function (TransactionContext $context) use ($token): Receipt {
            self::assertSame(1, $context->sessionEpoch());
            try {
                $this->authority->issueProcess($token);
                self::fail('authority acquisition inside financial transaction');
            } catch (LogicException $exception) {
                self::assertStringContainsString('before the transaction', $exception->getMessage());
            }

            return self::receipt();
        });
        self::assertSame($before, $this->authority->acquisitions);
        self::assertGreaterThan(0, $this->authority->cachedReads);
    }

    public function test_an_active_database_without_its_external_adoption_journal_does_not_admit_a_clone(): void
    {
        $this->activeSession();
        $token = $this->tokens->currentToken(self::INSTALLATION);
        self::assertNotNull($token);
        $verified = $this->authority->process(self::INSTALLATION, $token->processRef, $token->nonce);
        $this->application()->instance(FinancialAuthorityEvidence::class, new readonly class($verified) implements FinancialAuthorityEvidence
        {
            public function __construct(private ?VerifiedProcessAdmission $verified) {}

            public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
            {
                return $this->verified;
            }

            public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
            {
                return null;
            }
        });
        $this->refused();
    }

    private function overrideProcess(VerifiedProcessAdmission $verified): void
    {
        $this->application()->instance(FinancialAuthorityEvidence::class, new readonly class($verified, $this->authority) implements FinancialAuthorityEvidence
        {
            public function __construct(private VerifiedProcessAdmission $verified, private FinancialAuthorityEvidence $inner) {}

            public function process(string $installationRef, string $processRef, string $nonce): VerifiedProcessAdmission
            {
                return $this->verified;
            }

            public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
            {
                return $this->inner->transition($installationRef, $transitionRef);
            }
        });
    }

    private function money(?FinancialScope $scope = null): StoredOutcome
    {
        return $this->runner()->run($this->spec(financialScope: $scope), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, 'verified-authority')]));
    }

    private function refused(?FinancialScope $scope = null): void
    {
        $effects = $this->rows('billium_test_effect');
        $commits = $this->rows('billium_money_commit_log');
        try {
            $this->money($scope);
            self::fail('unverified financial admission accepted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::InstallationQuarantined, $exception->problem);
        }
        self::assertSame($effects, $this->rows('billium_test_effect'));
        self::assertSame($commits, $this->rows('billium_money_commit_log'));
    }
}
