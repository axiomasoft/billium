<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal;

use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Internal\Participants\DatabaseLedgerParticipants;
use Billium\Ledger\Internal\Repositories\LedgerPrivileges;
use Illuminate\Support\ServiceProvider;

final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Ledger::class, LedgerFacade::class);
        $this->app->bind(LedgerParticipants::class, DatabaseLedgerParticipants::class);
        $this->app->singleton(LedgerPrivileges::class, static function (): LedgerPrivileges {
            $name = config('billium.connection') ?? config('database.default');

            return new LedgerPrivileges(is_string($name) ? $name : 'default');
        });
    }

    public function boot(): void
    {
        // Capture runtime before migrate --database selects the migration owner.
        $this->app->make(LedgerPrivileges::class);
    }
}
