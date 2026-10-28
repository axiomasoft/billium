<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Console;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use LogicException;

final class RegisterEventContractsCommand extends Command
{
    protected $signature = 'billium:event-contracts:register {--database= : Explicit installation/upgrade owner connection}';

    protected $description = 'Explicitly register the configured module event contracts (D63)';

    public function handle(DatabaseManager $databases, ModuleRegistry $modules): int
    {
        $database = $this->option('database');
        $enabled = config('billium.modules.enabled');
        if (! is_string($database) || $database === '' || ! is_array($enabled)) {
            $this->error('An explicit owner database connection and module configuration are required');

            return self::FAILURE;
        }
        $registrar = new DatabaseEventContractRegistrar($databases->connection($database), $modules);
        try {
            $databases->connection($database)->transaction(function () use ($modules, $registrar, $enabled): void {
                foreach ($modules->manifest()->modules as $descriptor) {
                    $registrar->register($registrar->plan($descriptor, in_array($descriptor->moduleId, $enabled, true)));
                }
            });

            return self::SUCCESS;
        } catch (LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
