<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Internal\Errors\UsageProblems;

#[Api]
final readonly class SourceFinalityContract
{
    /** @var list<string> */
    public array $requiredSources;

    /** @param list<string> $requiredSources */
    public function __construct(
        public string $contractRef,
        public int $version,
        array $requiredSources,
        public int $lateGraceMicros,
        public LatePolicy $postFinalPolicy,
        public bool $reopenAllowed,
    ) {
        if ($contractRef === '' || $version < 1 || $requiredSources === [] || $lateGraceMicros < 0
            || ($reopenAllowed && $postFinalPolicy !== LatePolicy::AcceptAdjust)) {
            throw UsageProblems::policyUnknown('invalid finality contract');
        }
        foreach ($requiredSources as $source) {
            if ($source === '') {
                throw UsageProblems::policyUnknown('empty source');
            }
        }
        $requiredSources = array_values(array_unique($requiredSources));
        sort($requiredSources, SORT_STRING);
        $this->requiredSources = $requiredSources;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'contractRef' => $this->contractRef, 'version' => $this->version, 'requiredSources' => $this->requiredSources,
            'lateGraceMicros' => $this->lateGraceMicros, 'postFinalPolicy' => $this->postFinalPolicy->value,
            'reopenAllowed' => $this->reopenAllowed,
        ];
    }
}
