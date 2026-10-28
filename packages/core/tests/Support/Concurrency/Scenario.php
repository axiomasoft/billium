<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support\Concurrency;

use Illuminate\Foundation\Application;

interface Scenario
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function run(Application $app, int $index, array $args): array;
}
