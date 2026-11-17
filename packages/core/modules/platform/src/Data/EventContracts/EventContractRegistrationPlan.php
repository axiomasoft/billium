<?php

declare(strict_types=1);

namespace Billium\Platform\Data\EventContracts;

use Billium\Core\Data\Modules\ModuleDescriptor;
use Packman\Attributes\Api;

/**
 * Result of validating a module manifest against the registry (D63). Only the explicit install/upgrade command may
 * apply `toRegister`; ordinary boot never registers. A plan with conflicts is never applied.
 */
#[Api(since: '1.0')]
final readonly class EventContractRegistrationPlan
{
    /**
     * @param  list<EventContractRegistration>  $toRegister
     * @param  list<EventContractRegistration>  $unchanged
     * @param  list<EventContractConflict>  $conflicts
     * @param  list<EventContractRegistration>  $toHistorical
     */
    public function __construct(public array $toRegister, public array $unchanged, public array $conflicts, public array $toHistorical = []) {}

    public function isApplicable(): bool
    {
        return $this->conflicts === [];
    }

    /**
     * Pure planning rule: a changed schema/digest/owner/bootstrap under an existing key conflicts; an unknown owner
     * conflicts; a disabled module that never registered gets no rows, and a used disabled module keeps its rows.
     *
     * @param  list<EventContractRegistration>  $registered  current registry rows
     * @param  list<string>  $knownModuleIds
     */
    public static function between(ModuleDescriptor $descriptor, array $registered, array $knownModuleIds, bool $enabled): self
    {
        $byKey = [];
        foreach ($registered as $row) {
            $byKey[$row->key()] = $row;
        }
        $toRegister = [];
        $unchanged = [];
        $conflicts = [];
        $historical = [];
        foreach ($descriptor->publicEvents as $declaration) {
            $wanted = EventContractRegistration::from($descriptor->moduleId, $declaration);
            $existing = $byKey[$wanted->key()] ?? null;
            if (! in_array($descriptor->moduleId, $knownModuleIds, true)) {
                $conflicts[] = new EventContractConflict($wanted->key(), $descriptor->moduleId, EventContractConflict::OWNER_UNKNOWN);
            } elseif ($existing !== null) {
                if ($existing->sameContract($wanted)) {
                    $unchanged[] = $existing;
                    if (! $enabled) {
                        $historical[] = $existing;
                    } elseif ($existing->status === EventContractRegistration::HISTORICAL) {
                        $toRegister[] = $wanted;
                    }
                } else {
                    $conflicts[] = new EventContractConflict($wanted->key(), $descriptor->moduleId, EventContractConflict::CHANGED_CONTRACT);
                }
            } elseif ($enabled) {
                $toRegister[] = $wanted;
            }
        }

        return new self($toRegister, $unchanged, $conflicts, $historical);
    }
}
