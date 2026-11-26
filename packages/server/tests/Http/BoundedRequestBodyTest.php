<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Http;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Server\Internal\Http\BoundedRequestBody;
use Illuminate\Http\Request;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

final class BoundedRequestBodyTest extends TestCase
{
    public function test_a_body_within_the_limit_is_returned_whole(): void
    {
        $body = '{"schema":"billium.query.v1"}';

        self::assertSame($body, BoundedRequestBody::read(self::request($body), strlen($body)));
        self::assertSame('', BoundedRequestBody::read(self::request(''), 1));
    }

    public function test_a_body_longer_than_one_chunk_is_read_completely(): void
    {
        $body = str_repeat('a', 200_000);

        self::assertSame($body, BoundedRequestBody::read(self::request(self::stream($body)), 200_000));
    }

    public function test_laravel_buffered_json_is_checked_without_copying_it_to_a_stream(): void
    {
        $body = '{"example":"'.str_repeat('x', 1000).'"}';
        $trackingRequest = new class extends Request
        {
            #[\Override]
            public function getContent(bool $asResource = false): string
            {
                if ($asResource) {
                    throw new LogicException('Unexpected stream copy of buffered content');
                }

                return parent::getContent();
            }
        };
        $request = $trackingRequest::createFromBase(SymfonyRequest::create(
            '/billium/v1/sellers/s/queries',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        ));

        self::assertSame($body, BoundedRequestBody::read($request, strlen($body)));
        $this->expectException(ProblemException::class);

        BoundedRequestBody::read($request, 1000);
    }

    public function test_an_oversized_stream_is_refused_after_reading_at_most_one_byte_past_the_limit(): void
    {
        $stream = self::stream(str_repeat('x', 4 * 1024 * 1024));

        try {
            BoundedRequestBody::read(self::request($stream), 1000);
            self::fail('An oversized body must be refused');
        } catch (ProblemException $refusal) {
            self::assertSame(ProblemCode::ValidationInvalid, $refusal->problem);
        }
        self::assertSame(1001, ftell($stream));
    }

    public function test_a_declared_length_above_the_limit_is_refused_before_reading(): void
    {
        $stream = self::stream('{}');

        try {
            BoundedRequestBody::read(self::request($stream, '1001'), 1000);
            self::fail('A declared oversized body must be refused');
        } catch (ProblemException $refusal) {
            self::assertSame(ProblemCode::ValidationInvalid, $refusal->problem);
        }
        self::assertSame(0, ftell($stream));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedOrHugeLengths(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'non numeric' => ['12abc'];
        yield 'above 2^53' => ['9007199254740992'];
        yield 'int overflow' => ['99999999999999999999999'];
    }

    #[DataProvider('malformedOrHugeLengths')]
    public function test_a_malformed_or_huge_declared_length_is_refused(string $declared): void
    {
        $this->expectException(ProblemException::class);

        BoundedRequestBody::read(self::request('{}', $declared), 9007199254740991);
    }

    public function test_a_declared_length_with_leading_zeros_within_the_limit_is_accepted(): void
    {
        self::assertSame('{}', BoundedRequestBody::read(self::request('{}', '00000000000000000002'), 2));
    }

    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * @param  string|resource  $content
     */
    private static function request(mixed $content, ?string $contentLength = null): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($contentLength !== null) {
            $server['CONTENT_LENGTH'] = $contentLength;
        }

        return Request::create('/billium/v1/sellers/s/queries', 'POST', server: $server, content: $content);
    }
}
