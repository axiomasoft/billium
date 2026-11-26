<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;

#[Internal]
final readonly class RequestCaller implements CallerContext
{
    public function __construct(private Application $app, private CallerContext $fallback) {}

    public function current(): Caller
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;
        $caller = $request instanceof Request ? $request->attributes->get(self::class) : null;

        return $caller instanceof Caller ? $caller : $this->fallback->current();
    }

    /** @template T
     * @param  Closure(): T  $consume
     * @return T
     */
    public static function run(Request $request, Caller $caller, Closure $consume): mixed
    {
        $attributes = $request->attributes;
        $previous = $attributes->get(self::class);
        $attributes->set(self::class, $caller);

        try {
            return $consume();
        } finally {
            $attributes->remove(self::class);
            if ($previous instanceof Caller) {
                $attributes->set(self::class, $previous);
            }
        }
    }
}
