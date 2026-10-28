<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Core\Data\Modules\CompatibilityInput;
use Billium\Core\Data\Modules\ModuleManifest;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Modules\ModuleLifecycle;
use PHPUnit\Framework\TestCase;

/** Fixtures derived from the packaged registry: every invalid case is the real document with one thing broken. */
abstract class ModuleTestCase extends TestCase
{
    /** Host ports the default embedded-commerz composition needs. */
    protected const array LEDGER_PORTS = ['AuthContext', 'SellerContext', 'AuthorizationVerdict', 'FinancialSessionAdmission'];

    /** @return array<string, mixed> */
    protected static function document(): array
    {
        $json = file_get_contents(dirname(__DIR__, 3).'/resources/modules.json');
        self::assertIsString($json);
        $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $change
     */
    protected static function catalog(?callable $change = null): ModuleCatalog
    {
        $document = self::document();

        return new ModuleCatalog(ModuleManifest::fromArray($change === null ? $document : $change($document)));
    }

    /**
     * Replaces the member of one module descriptor in a document.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $members
     * @return array<string, mixed>
     */
    protected static function withModule(array $document, string $moduleId, array $members): array
    {
        $modules = $document['modules'];
        self::assertIsArray($modules);
        foreach ($modules as $index => $module) {
            self::assertIsArray($module);
            if ($module['moduleId'] === $moduleId) {
                $modules[$index] = [...$module, ...$members];
            }
        }
        $document['modules'] = $modules;

        return $document;
    }

    /** @param list<string> $enabled
     * @param  list<string>  $ports
     */
    protected static function input(string $composition, array $enabled, array $ports = self::LEDGER_PORTS): CompatibilityInput
    {
        return new CompatibilityInput($composition, $enabled, $ports);
    }

    protected static function state(string $moduleId, ModuleLifecycleState $state): ModuleState
    {
        return new ModuleState('inst_1', $moduleId, $state, 1, 0);
    }

    protected static function lifecycle(): ModuleLifecycle
    {
        return new ModuleLifecycle(self::catalog());
    }
}
