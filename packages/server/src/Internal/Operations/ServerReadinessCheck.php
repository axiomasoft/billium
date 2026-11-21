<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Operations;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Server\Internal\Readiness;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;

#[Internal]
final readonly class ServerReadinessCheck implements DoctorCheck
{
    public const string KEY = 'billium.server.readiness';

    public function __construct(
        private Application $app,
        private Readiness $delivery,
        private BilliumOptions $options,
        private DatabaseManager $databases,
    ) {}

    #[Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[Override]
    public function title(): string
    {
        return 'Billium server readiness';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        $failure = $this->failure();

        return $failure === null
            ? [DoctorFinding::ok(code: self::KEY, message: 'The server worker passed the readiness probe.')]
            : [DoctorFinding::error(code: $failure, message: 'The server worker is unready.', remediation: 'See the readiness identities in docs/server/operations.md.')];
    }

    public function failure(): ?string
    {
        try {
            $failure = $this->delivery->failure();
            if ($failure !== null) {
                return $failure;
            }

            $db = $this->databases->connection($this->options->connection);
            $runtime = $db->scalar(<<<'SQL'
                SELECT NOT pg_is_in_recovery()
                    AND current_setting('transaction_read_only') = 'off'
                    AND NOT has_schema_privilege(current_user, 'public', 'CREATE')
                    AND NOT EXISTS (SELECT FROM pg_roles WHERE pg_has_role(current_user, oid, 'MEMBER') AND
                        (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls OR rolname = 'pg_write_all_data'))
                    AND NOT EXISTS (SELECT FROM pg_database WHERE datname = current_database() AND pg_has_role(current_user, datdba, 'MEMBER'))
                    AND NOT EXISTS (SELECT FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relname LIKE 'billium\_%' AND pg_has_role(current_user, relowner, 'MEMBER'))
                SQL, [], false);
            if ($runtime !== true) {
                return 'billium.server.database_role_invalid';
            }
        } catch (Throwable) {
            return 'billium.server.database_unavailable';
        }

        $installation = $this->options->installationRef;
        if ($installation === null || ! $this->hasTrustedSession($installation)) {
            return 'billium.server.session_quarantined';
        }

        try {
            $accepting = $db->table('public.billium_module_state')->useWritePdo()
                ->where('installation_ref', $installation)->where('state', 'accepting')
                ->whereIn('module_id', $this->options->modules->enabled)->count();
            if ($accepting !== count($this->options->modules->enabled)) {
                return 'billium.server.modules_not_accepting';
            }
        } catch (Throwable) {
            return 'billium.server.database_unavailable';
        }

        if ($this->options->modules->composition !== 'remote-billium') {
            return null;
        }

        $execution = $this->options->execution;
        if (! $execution->outgoingEnabled || $execution->releaseEvidenceRef === null || $execution->retentionPolicyRef === null) {
            return 'billium.server.outgoing_disabled';
        }

        try {
            // This SPI checks deployment qualification and egress integrations without allocating a dispatch ticket.
            $this->app->make(ExecutionReadiness::class)->assertConfigured($execution->releaseEvidenceRef, $execution->retentionPolicyRef);
        } catch (Throwable) {
            return 'billium.server.execution_unqualified';
        }

        return null;
    }

    private function hasTrustedSession(string $installation): bool
    {
        try {
            $now = new DateTimeImmutable;
            $token = $this->app->make(FinancialSessionAdmission::class)->currentToken($installation);
            if ($token === null || $token->installationRef !== $installation || $token->expired($now) || ! $token->permits(FinancialMode::Active)) {
                return false;
            }
            $authority = $this->app->make(FinancialAuthorityEvidence::class);
            $process = $authority->process($installation, $token->processRef, $token->nonce);
            if ($process === null || $process->audience !== 'billium.financial_session.v1'
                || $process->verifiedAt > $now || $process->scopes === []) {
                return false;
            }
            $claims = get_object_vars($token);
            $verifiedClaims = get_object_vars($process->token);
            $claims['expiresAt'] = $token->expiresAt->format('U.u');
            $verifiedClaims['expiresAt'] = $process->token->expiresAt->format('U.u');
            if ($claims !== $verifiedClaims) {
                return false;
            }

            $session = (array) $this->databases->connection($this->options->connection)->selectOne(<<<'SQL'
                SELECT s.epoch, s.state, s.external_owner_session_ref AS owner_session_ref, s.deployment_generation,
                    s.external_attachment_generation AS attachment_generation,
                    s.authority_epoch, s.activation_certificate_ref, (
                        SELECT e.transition_ref FROM public.billium_financial_session_event e
                        WHERE e.session_id = s.id AND e.transition = 'activated' ORDER BY e.id DESC LIMIT 1
                    ) AS transition_ref
                FROM public.billium_financial_session s WHERE s.installation_ref = ? ORDER BY s.epoch DESC LIMIT 1
                SQL, [$installation], false);
            $epoch = $session['epoch'] ?? null;
            $transition = $session['transition_ref'] ?? null;
            if (! is_int($epoch) || ! is_string($transition) || ($session['state'] ?? null) !== 'active'
                || ($session['owner_session_ref'] ?? null) !== $token->ownerSessionRef || ($session['deployment_generation'] ?? null) !== $token->deploymentGeneration
                || ($session['attachment_generation'] ?? null) !== $token->attachmentGeneration || ($session['authority_epoch'] ?? null) !== $token->authorityEpoch) {
                return false;
            }

            $authorization = $authority->transition($installation, $transition);
            $certificate = $authorization?->activationCertificate;
            if ($authorization === null || $certificate === null || $authorization->retirementProof === null
                || $authorization->action !== 'activate' || $authorization->audience !== 'billium.financial_session_control.v1'
                || $authorization->transitionRef !== $transition || $authorization->expectedLocalEpoch !== $epoch
                || $authorization->expiresAt <= $now || $certificate->expiresAt <= $now || $certificate->verdict !== 'activate'
                || $certificate->localEpoch !== $epoch || $certificate->certificateRef !== ($session['activation_certificate_ref'] ?? null)
                || $certificate->nonce !== $authorization->nonce || get_object_vars($certificate->newOwner) !== get_object_vars($authorization->owner)
                || $certificate->retirementProofDigest !== $authorization->retirementProof->signatureDigest) {
                return false;
            }

            $owner = $certificate->newOwner;
            if ($owner->installationRef !== $installation || $owner->ownerSessionRef !== $token->ownerSessionRef
                || $owner->deploymentGeneration !== $token->deploymentGeneration || $owner->attachmentGeneration !== $token->attachmentGeneration
                || $owner->authorityEpoch !== $token->authorityEpoch) {
                return false;
            }

            foreach ($certificate->coverage->namespaceStates as $namespace) {
                if ($namespace->state !== 'active' || $namespace->scope->installationRef !== $installation) {
                    continue;
                }
                $covered = array_filter(get_object_vars($namespace->scope), static fn (mixed $value): bool => $value !== null);
                foreach ($process->scopes as $scope) {
                    $permitted = array_filter(get_object_vars($scope), static fn (mixed $value): bool => $value !== null);
                    if (array_intersect_key($covered, $permitted) === array_intersect_key($permitted, $covered)) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            // Host and DB exceptions may contain credentials or scoped references.
            return false;
        }

        return false;
    }
}
