<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\EventEnvelopeV2;
use Billium\Contracts\Data\Envelopes\ExecutionCertifiedNoEffectEvent;
use Billium\Contracts\Data\Envelopes\ExecutionRepairEvent;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

it('runs the independent effect, repair, certified no-effect and feed vectors while preserving strict v1', function (): void {
    $root = dirname(__DIR__, 4).'/fixtures/vectors/';
    $files = array_merge(
        glob($root.'event/v2/*.json') ?: [],
        glob($root.'event/repair/*.json') ?: [],
        glob($root.'event/certified-no-effect/*.json') ?: [],
        glob($root.'payment/effects/*.json') ?: [],
        glob($root.'feed/*.json') ?: [],
        [$root.'event/envelope/event-type-unknown.json'],
    );
    $codec = new Codec;
    foreach ($files as $file) {
        $vector = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $json = $vector['script'][0]['args']['json_text'];
        $expected = $vector['expect'][0];
        try {
            $activeCodec = $vector['id'] === 'event/v2/catalog-valid' ? Codec::forRevision('2026-10') : $codec;
            $value = $activeCodec->decode($json);
            expect(isset($expected['error']))->toBeFalse($vector['id']);
            expect($value->schema)->toBe($expected['ok']['schema']);
            expect($activeCodec->digestOf($value))->toBe($expected['ok']['digest']);
            expect($activeCodec->encode($value))->toBe($activeCodec->canonicalize($json));
        } catch (ProblemException $error) {
            expect($error->problem->value)->toBe($expected['error']['code'] ?? 'unexpected refusal: '.$vector['id']);
        }
    }
});

it('rejects v2 in a pinned old client and preserves the old canonical digest bytes', function (): void {
    $root = dirname(__DIR__, 4).'/fixtures/vectors/';
    $new = json_decode((string) file_get_contents($root.'event/v2/valid.json'), true, 512, JSON_THROW_ON_ERROR)['script'][0]['args']['json_text'];
    expect((new Codec)->decode($new))->toBeInstanceOf(EventEnvelopeV2::class);
    try {
        new Codec(['billium.event.v1'])->decode($new);
        throw new LogicException('Pinned old client must reject v2');
    } catch (ProblemException $error) {
        expect($error->problem)->toBe(ProblemCode::SchemaUnsupported);
    }
    $old = json_decode((string) file_get_contents($root.'event/envelope/valid.json'), true, 512, JSON_THROW_ON_ERROR);
    $json = $old['script'][0]['args']['json_text'];
    $codec = new Codec(['billium.event.v1']);
    expect($codec->digestOf($codec->decode($json)))->toBe($old['expect'][0]['ok']['digest']);
    expect($codec->encode($codec->decode($json)))->toBe((new Codec)->encode((new Codec)->decode($json)));
});

it('exposes typed repair envelopes only to capable clients', function (): void {
    $root = dirname(__DIR__, 4).'/fixtures/vectors/event/repair/valid.json';
    $vector = json_decode((string) file_get_contents($root), true, 512, JSON_THROW_ON_ERROR);
    $json = $vector['script'][0]['args']['json_text'];
    $codec = new Codec(['billium.event.v3']);
    $event = $codec->decode($json);
    expect($event)->toBeInstanceOf(ExecutionRepairEvent::class);
    assert($event instanceof ExecutionRepairEvent);
    expect($event->eventType)->toBe('execution.repair_recorded.v1');
    expect($event->eventContractRevision)->toBe('events-2026-11-v3');
    expect($event->aggregateRef)->toBe($event->payload->repairRef);
    expect($event->digest)->toBe($vector['expect'][0]['ok']['digest']);
    expect($codec->encode($event))->toBe($json);
    try {
        new Codec(['billium.event.v1', 'billium.event.v2'])->decode($json);
        throw new LogicException('Pinned legacy clients must reject the new repair envelope');
    } catch (ProblemException $error) {
        expect($error->problem)->toBe(ProblemCode::SchemaUnsupported);
    }
});

it('exposes typed certified no-effect events to capable direct clients', function (): void {
    $root = dirname(__DIR__, 4).'/fixtures/vectors/';
    $vector = json_decode((string) file_get_contents($root.'event/certified-no-effect/valid.json'), true, 512, JSON_THROW_ON_ERROR);
    $json = $vector['script'][0]['args']['json_text'];
    $codec = new Codec(['billium.event.v4']);
    $event = $codec->decode($json);
    expect($event)->toBeInstanceOf(ExecutionCertifiedNoEffectEvent::class);
    assert($event instanceof ExecutionCertifiedNoEffectEvent);
    expect($event->eventType)->toBe('payment.certified_no_effect.v1');
    expect($event->eventContractRevision)->toBe('events-2026-11-v4');
    expect($event->installationRef)->toBe('installation-a');
    expect($event->environment)->toBe('test');
    expect($event->aggregateRef)->toBe($event->payload->operationRef);
    expect($event->revision)->toBe($event->payload->revision);
    expect($event->digest)->toBe($vector['expect'][0]['ok']['digest']);
    expect($codec->encode($event))->toBe($json);
    try {
        new Codec(['billium.event.v1', 'billium.event.v2', 'billium.event.v3'])->decode($json);
        throw new LogicException('Pinned legacy clients must reject the v4 envelope');
    } catch (ProblemException $error) {
        expect($error->problem)->toBe(ProblemCode::SchemaUnsupported);
    }

    $pageVector = json_decode((string) file_get_contents($root.'feed/v1-page-valid.json'), true, 512, JSON_THROW_ON_ERROR);
    $page = json_decode($pageVector['script'][0]['args']['json_text'], false, 512, JSON_THROW_ON_ERROR);
    $page->events = [$event->raw];
    $page->digest = $codec->digest($page);
    try {
        new Codec()->decode($codec->canonicalize($page));
        throw new LogicException('The v1 page must reject a v4 event');
    } catch (ProblemException $error) {
        expect($error->problem)->toBe(ProblemCode::ValidationInvalid);
    }
});

it('checks negotiated event capabilities throughout a feed page', function (): void {
    $root = dirname(__DIR__, 4).'/fixtures/vectors/feed/';
    $mixed = json_decode((string) file_get_contents($root.'mixed-page-valid.json'), true, 512, JSON_THROW_ON_ERROR)['script'][0]['args']['json_text'];
    $old = new Codec(['billium.feed.page.v1', 'billium.event.v1']);
    try {
        $old->decode($mixed);
        throw new LogicException('The second event requires a schema outside the pinned client capabilities');
    } catch (ProblemException $error) {
        expect($error->problem)->toBe(ProblemCode::SchemaUnsupported);
    }
    $capable = new Codec(['billium.feed.page.v1', 'billium.event.v1', 'billium.event.v2']);
    expect($capable->encode($capable->decode($mixed)))->toBe($capable->canonicalize($mixed));
    $v1 = json_decode((string) file_get_contents($root.'v1-page-valid.json'), true, 512, JSON_THROW_ON_ERROR)['script'][0]['args']['json_text'];
    expect($old->encode($old->decode($v1)))->toBe($old->canonicalize($v1));
});
