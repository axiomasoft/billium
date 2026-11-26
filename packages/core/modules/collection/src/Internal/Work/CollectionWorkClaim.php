<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Work;

use Billium\Collection\Internal\Support\RowCast;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use DateTimeImmutable;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class CollectionWorkClaim
{
    public int $stepId;

    public function __construct(
        public FinancialScope $scope,
        public FinancialMode $mode,
        public int $epoch,
        public int $fence,
        public DateTimeImmutable $until,
        public stdClass $authorization,
    ) {
        $this->stepId = RowCast::integer($authorization->step_id, 'step_id');
    }
}
