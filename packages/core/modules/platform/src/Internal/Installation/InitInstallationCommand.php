<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use Billium\Platform\Contracts\Installation;
use Illuminate\Console\Command;

/** Explicit bootstrap of an installation; never run implicitly by a request, a worker or server boot (D55). */
final class InitInstallationCommand extends Command
{
    protected $signature = 'billium:installation:init {--installation-ref= : installation ref, default billium.installation_ref}';

    protected $description = 'Create the installation state and the accepting module rows of the enabled Billium modules';

    public function handle(Installation $installation): int
    {
        $option = $this->option('installation-ref');
        $ref = is_string($option) && $option !== '' ? $option : config('billium.installation_ref');
        if (! is_string($ref) || $ref === '') {
            $this->error('Set --installation-ref or billium.installation_ref (BILLIUM_INSTALLATION_REF).');

            return self::FAILURE;
        }
        $installation->init($ref);
        $this->info("Billium installation {$ref} initialized.");

        return self::SUCCESS;
    }
}
