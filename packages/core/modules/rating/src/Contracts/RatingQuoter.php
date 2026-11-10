<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Data\Rating\Quote;
use Billium\Rating\Data\Rating\QuoteRequest;

#[Api]
interface RatingQuoter
{
    public function quote(QuoteRequest $request): Quote;
}
