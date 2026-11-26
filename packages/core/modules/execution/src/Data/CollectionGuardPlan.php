<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CollectionGuardPlan
{
    /** @var list<string> */
    public array $participatingModuleIds;

    /** @var list<LockRequest> */
    public array $locks;

    /**
     * @param  array<mixed>  $participatingModuleIds
     * @param  array<mixed>  $locks
     */
    public function __construct(
        public string $authorizationRef,
        public int $issuanceGeneration,
        public DateTimeImmutable $issuedAt,
        public ExecutionScope $scope,
        array $participatingModuleIds,
        public ModuleOperationClass $operationClass,
        array $locks,
        public string $planDigest,
    ) {
        if ($authorizationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'authorizationRef must not be empty');
        }
        if (! array_is_list($participatingModuleIds)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'participatingModuleIds must be a list');
        }
        $participatingModuleIds = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'participatingModuleIds has an invalid element');
            }

            return $item;
        }, $participatingModuleIds);
        $this->participatingModuleIds = $participatingModuleIds;
        if (! array_is_list($locks)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'locks must be a list');
        }
        $locks = array_map(static function (mixed $item): LockRequest {
            if (! $item instanceof LockRequest) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'locks has an invalid element');
            }

            return $item;
        }, $locks);
        $this->locks = $locks;
        if ($planDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'planDigest must not be empty');
        }
    }
}
