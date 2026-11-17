<?php

declare(strict_types=1);

namespace Billium\Testkit\Restore;

use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\RecoveryCoverage;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use LogicException;
use Packman\Attributes\Api;

/** Independently durable, simulated host adoption. Reload verifies the journal outside the transaction; financial guards read the process-local cache. */
#[Api(since: '1.0')]
final class RestoreSessionAuthority implements FinancialAuthorityEvidence
{
    /** @var array<string,VerifiedSessionAuthorization> */
    private array $adopted = [];

    public function __construct(private readonly FinancialAuthorityEvidence $base, private readonly DurableJournal $journal, private readonly string $key)
    {
        foreach ($journal->records() as $event) {
            if (! $event instanceof JournalEvent || $event->kind !== 'adopt' || ! is_string($event->data['body'] ?? null) || ! is_string($event->data['mac'] ?? null)) {
                throw new LogicException('Invalid deployment adoption record');
            }
            $body = $event->data['body'];
            if (! hash_equals(hash_hmac('sha256', $body, $key), $event->data['mac'])) {
                throw new LogicException('Forged deployment adoption record');
            }
            $adoption = unserialize((string) base64_decode($body, true));
            if (! $adoption instanceof VerifiedSessionAuthorization) {
                throw new LogicException('Unknown adoption type');
            }
            $this->adopted[$adoption->transitionRef] = $adoption;
        }
    }

    public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
    {
        return $this->base->process($installationRef, $processRef, $nonce);
    }

    public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
    {
        $adopted = $this->adopted[$transitionRef] ?? null;
        if ($adopted !== null) {
            return $adopted->owner->installationRef === $installationRef ? $adopted : null;
        }

        return in_array($transitionRef, ['restore-open', 'restore-activate'], true) ? null : $this->base->transition($installationRef, $transitionRef);
    }

    public function adopt(VerifiedSessionAuthorization $authorization, RetirementProof $retirement, ?RecoveryCoverage $coverage = null): VerifiedSessionAuthorization
    {
        $certificate = $authorization->activationCertificate;
        if ($coverage !== null && $certificate !== null) {
            $fields = array_replace(get_object_vars($certificate), ['coverage' => $coverage, 'retirementProofDigest' => $retirement->signatureDigest]);
            unset($fields['signatureDigest']);
            $certificate = new ActivationCertificate($certificate->certificateRef, $certificate->newOwner, $certificate->localEpoch,
                $retirement->signatureDigest, $coverage, $certificate->actorRef, $certificate->verdict, $certificate->reason,
                $certificate->expiresAt, $certificate->nonce, 'sha256:'.hash_hmac('sha256', serialize($fields), $this->key));
        } elseif ($authorization->action !== 'open' || $certificate !== null) {
            throw new LogicException('Activation requires inspected restore coverage');
        }
        $fields = array_replace(get_object_vars($authorization), ['retirementProof' => $retirement, 'activationCertificate' => $certificate]);
        unset($fields['verificationDigest']);
        $adoption = new VerifiedSessionAuthorization($authorization->transitionRef, $authorization->action, $authorization->owner,
            $authorization->expectedLocalEpoch, $authorization->requestDigest, $authorization->evidenceRef, $certificate, $retirement,
            $authorization->externalAdmissionRef, $authorization->issuerRef, $authorization->audience, $authorization->expiresAt, $authorization->nonce,
            'sha256:'.hash_hmac('sha256', serialize($fields), $this->key));
        $body = base64_encode(serialize($adoption));
        if (isset($this->adopted[$adoption->transitionRef])) {
            if (serialize($this->adopted[$adoption->transitionRef]) !== serialize($adoption)) {
                throw new LogicException('Cannot replace an existing authority adoption');
            }

            return $adoption;
        }
        $this->journal->append(new JournalEvent('adopt', ['body' => $body, 'mac' => hash_hmac('sha256', $body, $this->key)], ProcessIdentity::current()));
        $this->adopted[$adoption->transitionRef] = $adoption;

        return $adoption;
    }
}
