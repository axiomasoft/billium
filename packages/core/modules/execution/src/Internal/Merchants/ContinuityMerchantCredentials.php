<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Merchants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ResolvedCredentials;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/** The host authenticates secret-store continuity outside Tx; this decorator checks durable account/action permissions. */
#[Internal]
final readonly class ContinuityMerchantCredentials implements MerchantCredentials
{
    /** @param Closure():?MerchantCredentials $host */
    public function __construct(private Closure $host, private DatabaseMerchantRegistry $registry, private DatabaseManager $databases, private BilliumOptions $options) {}

    public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
    {
        $db = $this->databases->connection($this->options->connection);
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Credential resolution must run outside every root transaction');
        }
        $now = new DateTimeImmutable;
        if (! $this->registry->allowed($revision->scope, $revision->revisionRef, $purpose, $now)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Historical recovery action is disabled or beyond its attested retention');
        }
        $host = ($this->host)() ?? throw new ProblemException(ProblemCode::LookupUnavailable, 'MerchantCredentials host resolution is not configured');
        $resolved = $host->resolve($revision, $purpose);
        $this->registry->assertCredential($revision, $purpose, $resolved, new DateTimeImmutable);

        return $resolved;
    }
}
