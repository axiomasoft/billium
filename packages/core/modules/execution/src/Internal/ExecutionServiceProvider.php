<?php

declare(strict_types=1);

namespace Billium\Execution\Internal;

use Billium\Execution\Internal\Registrars\ExecutionRegistrar;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Internal;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class ExecutionServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [ExecutionRegistrar::class];

    public function register(): void
    {
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        $this->bootPackageRegistrars();
    }
}
