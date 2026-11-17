<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Queries;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ExecutionReads
{
    public function __construct(
        private DatabaseManager $databases,
        private BilliumOptions $options,
        private CallerContext $callers,
        private ExecutionStore $store,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(Connection): T  $read
     * @return T
     */
    public function snapshot(Closure $read): mixed
    {
        $db = $this->databases->connection($this->options->connection);
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Execution queries require an independent read-only root');
        }

        return $db->transaction(static function () use ($db, $read): mixed {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $read($db);
        });
    }

    public function select(ExecutionScope $scope, string $sourceAuthority): FinancialScope
    {
        return PaymentIdentity::select($this->callers->current(), $scope, $sourceAuthority);
    }

    /** @return list<string> */
    public function sources(ExecutionScope $scope): array
    {
        $allowed = [];
        foreach (['remote-billium', 'standalone-billium'] as $source) {
            if (in_array($source, $this->callers->current()->sourceAuthorities, true)) {
                $this->select($scope, $source);
                $allowed[] = $source;
            }
        }
        if ($allowed === []) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Execution query needs an authenticated execution source');
        }

        return $allowed;
    }

    public function operation(ExecutionScope $scope, string $ref, ?string $sourceAuthority = null): OperationRecord
    {
        self::validate($ref);
        $sources = $sourceAuthority === null ? $this->sources($scope) : [$this->select($scope, $sourceAuthority)->sourceAuthority];
        $operation = $this->store->operation($scope, $ref);
        if ($operation === null || ! in_array($operation->binding->executionProfile, $sources, true)) {
            throw self::unknown();
        }

        return $operation;
    }

    public static function table(Connection $db, string $name, ExecutionScope $scope): Builder
    {
        return $db->table('billium_execution_'.$name)->where([
            'installation_ref' => $scope->installationRef,
            'seller_ref' => $scope->sellerRef,
            'environment' => $scope->environment,
        ]);
    }

    public static function validate(string ...$selectors): void
    {
        foreach ($selectors as $selector) {
            if ($selector === '' || str_contains($selector, "\0")) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid execution query selector');
            }
        }
    }

    public static function unknown(): ProblemException
    {
        return new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown execution reference');
    }
}
