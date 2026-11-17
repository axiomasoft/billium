<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Data\Rating\Quote;
use Billium\Rating\Data\Rating\QuoteRequest;
use Packman\Attributes\Api;

#[Api]
interface RatingQuoter
{
    public function quote(QuoteRequest $request): Quote;
}
