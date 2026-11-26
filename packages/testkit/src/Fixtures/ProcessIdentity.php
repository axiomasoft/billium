<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Packman\Attributes\Api;
use RuntimeException;

#[Api(since: '1.0')]
final class ProcessIdentity
{
    public static function current(): int
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new RuntimeException('No process identity');
        }

        return $pid;
    }
}
