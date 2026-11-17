<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Merchants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ResolvedCredentials;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/** The host authenticates secret-store continuity outside Tx; this decorator checks durable account/action permissions. */
#[Internal]
final readonly class ContinuityMerchantCredentials implements MerchantCredentials
{
    public function __construct(private MerchantCredentials $host, private DatabaseMerchantRegistry $registry, private DatabaseManager $databases, private BilliumOptions $options) {}

    public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
    {
        $db = $this->databases->connection($this->options->connection);
        if ($db->transactionLevel() !== 0) {
            throw new LogicException('Credential resolution must run outside every root transaction');
        }
        $now = new DateTimeImmutable;
        if (! $this->registry->allowed($revision->scope, $revision->revisionRef, $purpose, $now)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Historical recovery action is disabled or beyond its attested retention');
        }
        $resolved = $this->host->resolve($revision, $purpose);
        if ($resolved->merchantAccountRef !== $revision->merchantAccountRef || $resolved->purpose !== $purpose || $resolved->expiresAt <= $now || $resolved->continuityProofRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Credential resolver substituted historical identity or purpose');
        }
        $row = $db->table('billium_execution_merchant_credential_revision')->where('installation_ref', $revision->scope->installationRef)->where('seller_ref', $revision->scope->sellerRef)->where('environment', $revision->scope->environment)->where('revision_ref', $revision->revisionRef)->where('merchant_account_ref', $revision->merchantAccountRef)->where('purpose', $purpose)->where('credential_version_ref', $resolved->credentialVersionRef)->where('credential_ref', $resolved->secretRef->locator)->where('identity_continuity_evidence_ref', $resolved->continuityProofRef)->where('valid_from', '<=', $now)->where('valid_until', '>', $now)->where('valid_until', '>=', $resolved->expiresAt)->first();
        if ($row === null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Credential continuity is not durably authorized for this exact account/action/horizon');
        }

        return $resolved;
    }
}
