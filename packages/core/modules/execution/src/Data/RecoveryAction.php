<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RecoveryAction
{
    /** @var list<string> */
    public array $evidenceRefs;

    /**
     * @param  array<mixed>  $evidenceRefs
     */
    public function __construct(
        public string $actionRef,
        public ExecutionScope $scope,
        public string $actionKind,
        array $evidenceRefs,
        public string $authorizationRef,
        public string $reason,
        public string $semanticDigest,
        public string $rootOperationRef,
    ) {
        if ($actionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'actionRef must not be empty');
        }
        if ($actionKind === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'actionKind must not be empty');
        }
        if (! array_is_list($evidenceRefs)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'evidenceRefs must be a list');
        }
        $evidenceRefs = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'evidenceRefs has an invalid element');
            }

            return $item;
        }, $evidenceRefs);
        $this->evidenceRefs = $evidenceRefs;
        if ($authorizationRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'authorizationRef must not be empty');
        }
        if ($reason === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'reason must not be empty');
        }
        if ($semanticDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'semanticDigest must not be empty');
        }
        if ($rootOperationRef === '' || strlen($rootOperationRef) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Recovery action requires an explicit bounded root operation ref');
        }
    }
}
