<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Execution\Enums\EffectKind;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AdmissionPlan
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
        public ExecutionScope $scope,
        public FrozenBinding $binding,
        public string $operationRef,
        public EffectKind $kind,
        public Money $amount,
        public string $commandKey,
        public string $semanticDigest,
        public string $canonicalizationVersion,
        array $participatingModuleIds,
        array $locks,
        public ?CollectionGuardPlan $collectionGuard,
    ) {
        if ($operationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'operationRef must not be empty');
        }
        if ($commandKey === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'commandKey must not be empty');
        }
        if ($semanticDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'semanticDigest must not be empty');
        }
        if ($canonicalizationVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'canonicalizationVersion must not be empty');
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
    }
}
