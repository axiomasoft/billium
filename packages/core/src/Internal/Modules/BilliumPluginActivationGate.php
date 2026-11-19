<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Modules;

use Billium\Core\Configuration\BilliumOptions;
use Override;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;

#[Internal]
final readonly class BilliumPluginActivationGate implements PluginActivationGate
{
    public function __construct(private BilliumOptions $options) {}

    #[Override]
    public function isEnabled(string $pluginId): bool
    {
        return match ($pluginId) {
            'axiomasoft.billium.ledger' => $this->options->moduleEnabled('ledger'),
            'axiomasoft.billium.execution' => $this->options->moduleEnabled('execution'),
            'axiomasoft.billium.usage' => $this->options->moduleEnabled('usage'),
            'axiomasoft.billium.schedule' => $this->options->moduleEnabled('schedule'),
            'axiomasoft.billium.rating' => $this->options->moduleEnabled('rating'),
            'axiomasoft.billium.documents' => $this->options->moduleEnabled('documents'),
            default => true,
        };
    }
}
