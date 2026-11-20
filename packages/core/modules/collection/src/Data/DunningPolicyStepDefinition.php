<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CollectionChannel;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DunningPolicyStepDefinition
{
    public function __construct(
        public int $stepNo,
        public CollectionChannel $channel,
        public int $delaySeconds,
        public int $backoffMultiplierBps = 10000,
        public int $maxBackoffSeconds = 0,
        public int $jitterMaxSeconds = 0,
        public bool $sendNotification = false,
        public ?string $notificationTemplateRef = null,
    ) {}
}
