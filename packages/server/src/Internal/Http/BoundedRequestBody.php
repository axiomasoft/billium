<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use ReflectionProperty;

/**
 * Reads a request body without accepting more than the configured byte limit.
 *
 * A declared Content-Length above the limit is refused before reading. Already buffered content is checked
 * in place. An unread stream is consumed in bounded chunks, up to one byte beyond the limit.
 */
#[Internal]
final class BoundedRequestBody
{
    private const int CHUNK_BYTES = 65536;

    /**
     * @param  positive-int  $maxBytes
     */
    public static function read(Request $request, int $maxBytes): string
    {
        $declared = $request->headers->get('Content-Length');
        if ($declared !== null && ! self::declaredWithin($declared, $maxBytes)) {
            throw new ProblemException(ProblemCode::ValidationInvalid);
        }

        // Symfony has no cached-content accessor. getContent() can drain an unread stream, and
        // getContent(true) copies Laravel's already buffered JSON into a temporary stream.
        $buffered = new ReflectionProperty(Request::class, 'content')->getValue($request);
        if (is_string($buffered)) {
            if (strlen($buffered) > $maxBytes) {
                throw new ProblemException(ProblemCode::ValidationInvalid);
            }

            return $buffered;
        }

        $stream = $request->getContent(true);
        if (! is_resource($stream)) {
            throw new ProblemException(ProblemCode::ValidationInvalid);
        }

        $body = '';
        // One byte past the limit is enough to know the body is too large.
        $budget = $maxBytes + 1;
        while ($budget > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(self::CHUNK_BYTES, $budget));
            if ($chunk === false) {
                throw new ProblemException(ProblemCode::ValidationInvalid);
            }
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            $budget -= strlen($chunk);
        }
        if ($budget <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid);
        }

        return $body;
    }

    private static function declaredWithin(string $declared, int $maxBytes): bool
    {
        if (preg_match('/^[0-9]+$/', $declared) !== 1) {
            return false;
        }
        $digits = ltrim($declared, '0');

        // Bound the decimal length before converting it to an integer.
        return strlen($digits) <= 16 && (int) $digits <= $maxBytes;
    }
}
