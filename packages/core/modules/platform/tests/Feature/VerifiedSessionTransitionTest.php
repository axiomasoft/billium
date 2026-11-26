<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Data\Runtime\AccountCoverage;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\NamespaceDisposition;
use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\UnresolvedDisposition;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Platform\Data\Installation\FinancialSessionView;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Tests\PlatformTestCase;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('D62')]
#[Group('pg')]
final class VerifiedSessionTransitionTest extends PlatformTestCase
{
    public function test_opaque_references_do_not_open_or_activate_a_session(): void
    {
        $this->installation()->init(self::INSTALLATION);
        try {
            $this->installation()->openSession('open', 'owner-1', 1, 1, 1, 'opaque-evidence');
            self::fail('unverified open accepted');
        } catch (TransitionRefused $exception) {
            self::assertSame('verified_authority_required', $exception->reason);
        }
        self::assertSame(0, $this->rows('billium_financial_session'));
        $this->recovery();
        $this->activationRefused('verified_authority_required');
    }

    public function test_transition_evidence_is_bound_to_action_actor_args_epoch_owner_and_expiry(): void
    {
        $this->recovery();
        $good = $this->authorization();
        foreach ([
            ['action' => 'open'], ['transitionRef' => 'foreign-ref'], ['expectedLocalEpoch' => 2],
            ['requestDigest' => 'sha256:'.str_repeat('0', 64)], ['audience' => 'billium.psp_dispatch.v1'],
            ['expiresAt' => new DateTimeImmutable('-1 second')],
            ['owner' => new OwnerSessionIdentity('foreign-installation', 'owner-1', 1, 1, 1)],
            ['owner' => new OwnerSessionIdentity(self::INSTALLATION, 'owner-2', 2, 1, 1)],
        ] as $change) {
            $this->override(new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), $change)));
            $this->activationRefused('verified_authority_required');
        }
        $this->override($good);
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'foreign-actor'));
        $this->activationRefused('verified_authority_required');
    }

    public function test_activation_requires_matching_retirement_certificate_nonce_and_complete_scope(): void
    {
        $this->recovery();
        $good = $this->authorization();
        self::assertNotNull($good->activationCertificate);
        $certificate = $good->activationCertificate;
        foreach ([
            ['activationCertificate' => null], ['retirementProof' => null], ['externalAdmissionRef' => 'foreign-admission'],
            ['evidenceRef' => 'foreign-evidence'], ['nonce' => 'replayed-nonce'],
        ] as $change) {
            $this->override(new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), $change)));
            $this->activationRefused('activation_certificate_invalid');
        }
        foreach ([
            ['certificateRef' => 'foreign-certificate'], ['localEpoch' => 2], ['actorRef' => 'foreign-actor'],
            ['verdict' => 'pending'], ['expiresAt' => new DateTimeImmutable('-1 second')],
            ['retirementProofDigest' => 'sha256:'.str_repeat('0', 64)],
        ] as $change) {
            $this->override(new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), [
                'activationCertificate' => new ActivationCertificate(...array_replace(get_object_vars($certificate), $change)),
            ])));
            $this->activationRefused('activation_certificate_invalid');
        }
        foreach (['namespaceStates', 'projectionValidationReceipts', 'downstreamValidationReceipts'] as $field) {
            $coverage = new RecoveryCoverage(...array_replace(get_object_vars($certificate->coverage), [$field => []]));
            $this->override(new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), [
                'activationCertificate' => new ActivationCertificate(...array_replace(get_object_vars($certificate), ['coverage' => $coverage])),
            ])));
            $this->activationRefused('activation_coverage_incomplete');
        }
    }

    public function test_a_certificate_only_admits_its_explicitly_active_namespaces(): void
    {
        $this->recovery();
        $good = $this->authorization();
        self::assertNotNull($good->activationCertificate);
        $coverage = new RecoveryCoverage(...array_replace(get_object_vars($good->activationCertificate->coverage), ['namespaceStates' => [
            new NamespaceDisposition(new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:allowed', 'remote-billium'), 'active', 'sha256:allowed'),
            new NamespaceDisposition(new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:closed'), 'sealed', 'sha256:closed'),
            new NamespaceDisposition(new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:allowed', 'standalone-billium'), 'sealed', 'sha256:closed-source'),
        ]]));
        $authorization = new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), [
            'activationCertificate' => new ActivationCertificate(...array_replace(get_object_vars($good->activationCertificate), ['coverage' => $coverage])),
        ]));
        $this->override($authorization);
        $this->activate();
        $token = FixedSessionAdmission::token(self::INSTALLATION);
        $this->tokens->set($token);
        $this->authority->issueProcess($token);
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', ['remote-billium', 'standalone-billium'], ['execution:allowed', 'execution:closed']));
        foreach ([null, new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:closed', 'remote-billium'),
            new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:allowed', 'standalone-billium'),
            new FinancialScope(self::INSTALLATION, 'seller-a', 'test', null, 'remote-billium'),
            new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:allowed')] as $scope) {
            try {
                $this->runner()->run($this->spec(financialScope: $scope), static fn (): PlatformReceipt => self::receipt());
                self::fail('closed or implicit namespace admitted');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::InstallationQuarantined, $exception->problem);
            }
        }
        $allowed = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:allowed', 'remote-billium');
        self::assertTrue($this->runner()->run($this->spec(financialScope: $allowed), static fn (): PlatformReceipt => self::receipt())->accepted);
    }

    public function test_partial_activation_cannot_hide_a_closed_namespace_under_a_wildcard(): void
    {
        $this->recovery();
        $good = $this->authorization();
        self::assertNotNull($good->activationCertificate);
        $coverage = new RecoveryCoverage(...array_replace(get_object_vars($good->activationCertificate->coverage), ['namespaceStates' => [
            new NamespaceDisposition(new FinancialScope(self::INSTALLATION, null, null, null), 'active', 'sha256:active'),
            new NamespaceDisposition(new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'execution:closed'), 'sealed', 'sha256:closed'),
        ]]));
        $this->override(new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), [
            'activationCertificate' => new ActivationCertificate(...array_replace(get_object_vars($good->activationCertificate), ['coverage' => $coverage])),
        ])));
        $this->activationRefused('activation_scope_overlap');
    }

    public function test_safely_pending_requires_retained_reservation_and_account_coverage(): void
    {
        $this->recovery();
        $good = $this->authorization();
        self::assertNotNull($good->activationCertificate);
        $account = new AccountCoverage('merchant-1', 'provider-account-1', new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 minute'), 'simulated-complete-watermark', 'sha256:inventory', 'simulated-provider-qualification');
        $pending = new UnresolvedDisposition('operation-1', 'merchant-1', 'pending', 'safely_pending', 'reservation-1', 'still tracked');
        foreach ([
            [[], [$pending], 'activation_coverage_incomplete'],
            [[$account], [new UnresolvedDisposition('operation-1', 'merchant-1', 'unknown', 'assumed_absent', null, 'timeout')], 'activation_unresolved_disposition'],
            [[$account], [new UnresolvedDisposition('operation-1', 'merchant-1', 'pending', 'safely_pending', null, 'missing reservation')], 'activation_unresolved_disposition'],
        ] as [$accounts, $unresolved, $reason]) {
            $coverage = new RecoveryCoverage(...array_replace(get_object_vars($good->activationCertificate->coverage), [
                'perAccountWatermarks' => $accounts, 'unresolved' => $unresolved,
            ]));
            $this->override(new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), [
                'activationCertificate' => new ActivationCertificate(...array_replace(get_object_vars($good->activationCertificate), ['coverage' => $coverage])),
            ])));
            $this->activationRefused($reason);
        }
        $coverage = new RecoveryCoverage(...array_replace(get_object_vars($good->activationCertificate->coverage), [
            'perAccountWatermarks' => [$account], 'unresolved' => [$pending],
        ]));
        $this->override(new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), [
            'activationCertificate' => new ActivationCertificate(...array_replace(get_object_vars($good->activationCertificate), ['coverage' => $coverage])),
        ])));
        self::assertSame('active', $this->activate()->state);
        self::assertSame('safely_pending', $coverage->unresolved[0]->permittedDisposition);
        self::assertSame('reservation-1', $coverage->unresolved[0]->heldReservationRef);
    }

    public function test_external_adoption_survives_local_rollback_and_retries_the_same_request(): void
    {
        $this->recovery();
        $good = $this->authorization();
        $this->owner()->unprepared("CREATE FUNCTION public.billium_test_activation_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'simulated activation crash'; END $$; CREATE TRIGGER billium_test_activation_fault BEFORE UPDATE ON public.billium_financial_session FOR EACH ROW EXECUTE FUNCTION public.billium_test_activation_fault()");
        try {
            $this->activate();
            self::fail('activation fault did not abort');
        } catch (QueryException $exception) {
            self::assertStringContainsString('simulated activation crash', $exception->getMessage());
        } finally {
            $this->owner()->unprepared('DROP TRIGGER billium_test_activation_fault ON public.billium_financial_session; DROP FUNCTION public.billium_test_activation_fault()');
        }
        self::assertSame('recovery', $this->installation()->currentSession(self::INSTALLATION)?->state);
        self::assertSame(0, $this->rows('billium_financial_session_event', "transition = 'activated'"));
        self::assertSame($good, $this->authorization());
        self::assertSame('active', $this->activate()->state);
        self::assertSame('active', $this->activate()->state);
        self::assertSame(1, $this->rows('billium_financial_session_event', "transition = 'activated'"));
        try {
            $this->authority->issueTransition('activate', 'activate', [1, 'different-certificate', 'admission', 'evidence']);
            self::fail('external journal rebound a transition reference');
        } catch (LogicException $exception) {
            self::assertStringContainsString('cannot be rebound', $exception->getMessage());
        }
    }

    public function test_unverified_sealing_and_seal_do_not_close_an_active_session(): void
    {
        $this->activeSession();
        foreach (['beginSealing', 'seal'] as $method) {
            try {
                $this->installation()->{$method}('unverified-'.$method, 1);
                self::fail('unverified close accepted');
            } catch (TransitionRefused $exception) {
                self::assertSame('verified_authority_required', $exception->reason);
            }
            self::assertSame('active', $this->installation()->currentSession(self::INSTALLATION)?->state);
        }
    }

    private function recovery(): void
    {
        $this->installation()->init(self::INSTALLATION);
        $this->authority->issueTransition('open', 'open', ['owner-1', 1, 1, 1, 'open-evidence']);
        $this->installation()->openSession('open', 'owner-1', 1, 1, 1, 'open-evidence');
    }

    private function authorization(): VerifiedSessionAuthorization
    {
        return $this->authority->issueTransition('activate', 'activate', [1, 'certificate', 'admission', 'evidence']);
    }

    private function activate(): FinancialSessionView
    {
        return $this->installation()->activateSession('activate', 1, 'certificate', 'admission', 'evidence');
    }

    private function activationRefused(string $reason): void
    {
        try {
            $this->activate();
            self::fail('unverified activation accepted');
        } catch (TransitionRefused $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertSame('recovery', $this->installation()->currentSession(self::INSTALLATION)?->state);
        self::assertSame(0, $this->rows('billium_financial_session_event', "transition = 'activated'"));
    }

    private function override(VerifiedSessionAuthorization $authorization): void
    {
        $this->application()->instance(FinancialAuthorityEvidence::class, new readonly class($authorization, $this->authority) implements FinancialAuthorityEvidence
        {
            public function __construct(private VerifiedSessionAuthorization $authorization, private FinancialAuthorityEvidence $inner) {}

            public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
            {
                return $this->inner->process($installationRef, $processRef, $nonce);
            }

            public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
            {
                return $transitionRef === 'activate' ? $this->authorization : $this->inner->transition($installationRef, $transitionRef);
            }
        });
    }
}
