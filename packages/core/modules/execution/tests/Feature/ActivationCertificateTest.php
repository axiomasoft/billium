<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Platform\Data\Installation\FinancialSessionView;
use Billium\Platform\Exceptions\TransitionRefused;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
#[Group('COV-515')]
final class ActivationCertificateTest extends ExecutionTestCase
{
    #[TestWith(['missing'])]
    #[TestWith(['retirement'])]
    #[TestWith(['nonce'])]
    #[TestWith(['expired'])]
    #[TestWith(['coverage'])]
    #[TestWith(['lineage'])]
    public function test_forged_stale_unfenced_and_incomplete_activation_leave_recovery(string $failure): void
    {
        $good = $this->recover();
        $certificate = $good->activationCertificate;
        self::assertNotNull($certificate);
        $changed = match ($failure) {
            'missing' => null,
            'retirement' => new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), ['retirementProof' => null])),
            'nonce' => new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), ['nonce' => 'forged-nonce'])),
            'expired' => new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), ['expiresAt' => new DateTimeImmutable('-1 second')])),
            'coverage' => new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), ['activationCertificate' => new ActivationCertificate(...array_replace(get_object_vars($certificate), ['coverage' => new RecoveryCoverage(...array_replace(get_object_vars($certificate->coverage), ['projectionValidationReceipts' => []]))]))])),
            'lineage' => new VerifiedSessionAuthorization(...array_replace(get_object_vars($good), ['evidenceRef' => 'copied-clean-seal'])),
        };
        $this->override($changed);
        try {
            $this->activate();
            self::fail('Invalid certificate activated');
        } catch (TransitionRefused $exception) {
            self::assertNotSame('', $exception->reason);
        }
        self::assertSame('recovery', $this->installation()->currentSession(self::INSTALLATION)?->state);
        self::assertSame(0, $this->rows('billium_financial_session_event', "transition='activated' AND transition_ref='activate-2'"));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key='activate-2'"));
    }

    public function test_external_adoption_before_local_failure_resumes_same_certificate_nonce_and_epoch(): void
    {
        $authorization = $this->recover();
        $this->override(null);
        try {
            $this->activate();
            self::fail('Uncached adoption accepted');
        } catch (TransitionRefused $exception) {
            self::assertNotSame('', $exception->reason);
        }
        self::assertSame('recovery', $this->installation()->currentSession(self::INSTALLATION)?->state);
        $this->override($authorization);
        $first = $this->activate();
        $again = $this->activate();
        self::assertSame(2, $first->epoch);
        self::assertSame($first->toArray(), $again->toArray());
        self::assertSame(1, $this->rows('billium_financial_session_event', "transition_ref='activate-2'"));
        self::assertSame(2, $this->rows('billium_financial_session'));
        self::assertSame($authorization->nonce, $this->authority->transition(self::INSTALLATION, 'activate-2')?->nonce);
    }

    private function recover(): VerifiedSessionAuthorization
    {
        $this->authority->issueTransition('seal', 'activation-seal', [1]);
        $this->installation()->seal('activation-seal', 1);
        $this->authority->issueTransition('open', 'open-2', ['owner-2', 2, 2, 2, 'retired-old-owner']);
        $this->installation()->openSession('open-2', 'owner-2', 2, 2, 2, 'retired-old-owner');

        return $this->authority->issueTransition('activate', 'activate-2', [2, 'certificate:2', 'admission:2', 'activation-evidence']);
    }

    private function activate(): FinancialSessionView
    {
        return $this->installation()->activateSession('activate-2', 2, 'certificate:2', 'admission:2', 'activation-evidence');
    }

    private function override(?VerifiedSessionAuthorization $authorization): void
    {
        $this->application()->instance(FinancialAuthorityEvidence::class, new readonly class($this->authority, $authorization) implements FinancialAuthorityEvidence
        {
            public function __construct(private FinancialAuthorityEvidence $inner, private ?VerifiedSessionAuthorization $authorization) {}

            public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
            {
                return $this->inner->process($installationRef, $processRef, $nonce);
            }

            public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
            {
                return $transitionRef === 'activate-2' ? $this->authorization : $this->inner->transition($installationRef, $transitionRef);
            }
        });
    }
}
