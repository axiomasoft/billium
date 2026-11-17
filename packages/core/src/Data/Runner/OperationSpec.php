<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runner;

use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Declaration of one root operation (operation-runner). A keyed command carries its idempotency key and the semantic
 * digest of its payload; the same key with another digest conflicts (INV-05). `commitKind` marks a monetary mutation:
 * it appends a diagnostic money_commit_log row in the same root Tx. `modules` is the complete participating-module
 * closure: the runner admits every module in it (rank 25) for `operationClass` before any domain work, and a
 * participant outside it is refused (D55). financialScope selects the authenticated installation, seller, environment
 * and any owner/source used by this operation. Omitting the selection declares a namespace-neutral root. The runner
 * checks the selected grants before receipt lookup and admitted money roots require matching host evidence.
 */
#[Api(since: '1.0')]
final readonly class OperationSpec
{
    /** @var list<string> */
    public array $modules;

    /** @param list<string> $modules */
    public function __construct(
        public string $commandKind,
        public string $sellerRef,
        public string $environment,
        public FinancialMode $mode,
        public ModuleOperationClass $operationClass,
        array $modules,
        public ?string $commandKey = null,
        public ?string $digest = null,
        public ?string $commitKind = null,
        public ?FinancialScope $financialScope = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $commandKind) !== 1) {
            throw new InvalidArgumentException("Invalid command kind {$commandKind}");
        }
        if ($sellerRef === '' || $environment === '') {
            throw new InvalidArgumentException('Operation seller and environment are required');
        }
        if (($commandKey === null) !== ($digest === null)) {
            throw new InvalidArgumentException('An idempotency key needs its digest and vice versa (INV-05)');
        }
        foreach ([$commandKey, $digest] as $value) {
            if ($value !== null && ($value === '' || strlen($value) > 200)) {
                throw new InvalidArgumentException('Idempotency key and digest are nonempty bounded strings');
            }
        }
        if ($commitKind !== null && (! $mode->financial() || preg_match('/^[a-z][a-z0-9_.]*$/', $commitKind) !== 1)) {
            throw new InvalidArgumentException('Only an Active or Recovery operation is monetary and needs a valid commit kind');
        }
        foreach ($modules as $module) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $module) !== 1) {
                throw new InvalidArgumentException('Invalid participating module id');
            }
        }
        if (count($modules) !== count(array_unique($modules))) {
            throw new InvalidArgumentException('Duplicate participating module');
        }
        if ($mode->financial() && $modules === []) {
            throw new InvalidArgumentException('A financial operation declares its participating-module closure (D55)');
        }
        if ($mode === FinancialMode::SessionControl && $modules !== []) {
            throw new InvalidArgumentException('Session control takes no module admission');
        }
        sort($modules, SORT_STRING);
        $this->modules = $modules;
    }

    /** Neutral legacy roots require host evidence covering every source. A selected root may use only its declared source. */
    public function selectsSource(string $sourceAuthority): bool
    {
        return $this->financialScope === null || $this->financialScope->sourceAuthority === $sourceAuthority;
    }

    public function keyed(): bool
    {
        return $this->commandKey !== null;
    }

    public function monetary(): bool
    {
        return $this->commitKind !== null;
    }
}
