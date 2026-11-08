<?php

declare(strict_types=1);

namespace Billium\Core\Data\Modules;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Enums\Modules\TransitionBlockerCode;
use Billium\Core\Internal\Modules\Shape;

/** Blockers that must be zero before the module may become retired; the base set is mandatory for every module. */
#[Api]
final readonly class RetirementPolicy
{
    /** @var list<TransitionBlockerCode> */
    public const array BASE = [
        TransitionBlockerCode::PendingOperations,
        TransitionBlockerCode::UnknownEffects,
        TransitionBlockerCode::FeedGaps,
        TransitionBlockerCode::UnexportedFacts,
        TransitionBlockerCode::OutboxInboxGaps,
        TransitionBlockerCode::HistoricalReaderMissing,
        TransitionBlockerCode::DependentModuleActive,
    ];

    /** @param list<TransitionBlockerCode> $blockers */
    public function __construct(public array $blockers)
    {
        foreach (self::BASE as $code) {
            if (! in_array($code, $blockers, true)) {
                throw Shape::fail('retirementPolicy', "must block on {$code->value}");
            }
        }
    }

    public function blocksOn(TransitionBlockerCode $code): bool
    {
        return in_array($code, $this->blockers, true);
    }

    public static function fromArray(mixed $data, string $context): self
    {
        $data = Shape::object($data, ['blockers'], $context);

        return new self(array_map(
            static fn (string $value): TransitionBlockerCode => TransitionBlockerCode::tryFrom($value)
                ?? throw Shape::fail($context, "unknown blocker {$value}"),
            Shape::strings($data, 'blockers', $context),
        ));
    }
}
