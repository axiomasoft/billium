<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Installation;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Platform\Contracts\Installation\Installation;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;

/** Explicit bootstrap of an installation; never run implicitly by a request, a worker or server boot (D55). */
#[Internal]
final class InitInstallationCommand extends Command
{
    protected $signature = 'billium:installation:init {--installation-ref= : installation ref, default billium.installation_ref}';

    protected $description = 'Create the installation state and the accepting module rows of the enabled Billium modules';

    public function handle(Installation $installation, BilliumOptions $options): int
    {
        $option = $this->option('installation-ref');
        $ref = is_string($option) && $option !== '' ? $option : $options->installationRef;
        if (! is_string($ref) || $ref === '') {
            $this->error('Set --installation-ref or billium.installation_ref (BILLIUM_INSTALLATION_REF).');

            return self::FAILURE;
        }
        $installation->init($ref);
        $this->info("Billium installation {$ref} initialized.");

        return self::SUCCESS;
    }
}
