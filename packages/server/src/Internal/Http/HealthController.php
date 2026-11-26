<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Server\Internal\Operations\ServerReadinessCheck;
use Illuminate\Http\Response;
use Packman\Attributes\Internal;

#[Internal]
final readonly class HealthController
{
    public function live(): Response
    {
        return new Response('', 200, ['Cache-Control' => 'no-store']);
    }

    public function ready(ServerReadinessCheck $readiness): Response
    {
        return new Response('', $readiness->failure() === null ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
