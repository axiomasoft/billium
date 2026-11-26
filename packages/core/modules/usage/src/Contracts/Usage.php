<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\UsageFact;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Data\Envelopes\UsageReceipt;
use Billium\Contracts\Data\Envelopes\UsageReceiptV2;
use Packman\Attributes\Api;

#[Api]
interface Usage
{
    public function registerMetric(MetricVersion $metric): void;

    public function submitUsageFact(UsageFactV2 $fact): UsageReceiptV2;

    public function acceptUsageFact(UsageFact $fact, UsageFactV1Attribution $attribution): UsageReceipt;
}
