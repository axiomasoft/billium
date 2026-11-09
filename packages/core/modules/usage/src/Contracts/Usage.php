<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Data\Envelopes\UsageReceiptV2;

interface Usage
{
    public function registerMetric(MetricVersion $metric): void;

    public function submitUsageFact(UsageFactV2 $fact): UsageReceiptV2;
}
