<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

use Billium\Core\Tests\TestEnvironment;
use Closure;
use Throwable;

trait UsesFreshProfileDatabase
{
    private ?Closure $closeProfileDatabase = null;

    protected function setUp(): void
    {
        $this->closeProfileDatabase = TestEnvironment::openProfileDatabase();
        try {
            parent::setUp();
        } catch (Throwable $error) {
            $this->tearDown();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        $close = $this->closeProfileDatabase;
        $this->closeProfileDatabase = null;
        if ($close === null) {
            return;
        }
        try {
            parent::tearDown();
        } finally {
            $close();
        }
    }
}
