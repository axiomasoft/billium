import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

void main() {
  final root = Directory('../../fixtures/vectors');
  final files = [
    for (final directory in [
      'event/v2',
      'event/repair',
      'event/certified-no-effect',
      'payment/effects',
      'feed',
    ])
      ...Directory('${root.path}/$directory').listSync().whereType<File>(),
    File('${root.path}/event/envelope/event-type-unknown.json'),
  ];
  const codec = Codec();
  final legacyRevisionCodec = Codec.forRevision('2026-10');
  for (final file in files) {
    final vector = jsonDecode(file.readAsStringSync()) as Map;
    final vectorId = vector['id'] as String;
    test('EVT-07 $vectorId', () {
      final json = vector['script'][0]['args']['json_text'] as String;
      final expected = vector['expect'][0] as Map;
      if (expected.containsKey('error')) {
        for (final targetCodec in [codec, legacyRevisionCodec]) {
          expect(
            () => targetCodec.decode(json),
            throwsA(
              isA<ProblemException>().having(
                (e) => e.problem.value,
                'code',
                expected['error']['code'],
              ),
            ),
          );
        }
      } else if (vectorId == 'event/v2/catalog-valid') {
        // Vector carries 2026-10 payloadSchemaRef URN; 2026-10 accepts it and 2026-12 rejects it.
        final value = legacyRevisionCodec.decode(json);
        expect(value.schema, expected['ok']['schema']);
        expect(legacyRevisionCodec.digestOf(value), expected['ok']['digest']);
        expect(
          legacyRevisionCodec.encode(value),
          legacyRevisionCodec.canonicalizeJson(json),
        );
        expect(
          () => codec.decode(json),
          throwsA(
            isA<ProblemException>().having(
              (e) => e.problem,
              'code',
              ProblemCode.validationInvalid,
            ),
          ),
        );
      } else {
        for (final targetCodec in [codec, legacyRevisionCodec]) {
          final value = targetCodec.decode(json);
          expect(value.schema, expected['ok']['schema']);
          expect(targetCodec.digestOf(value), expected['ok']['digest']);
          expect(targetCodec.encode(value), targetCodec.canonicalizeJson(json));
        }
      }
    });
  }
  test('pinned old consumer rejects v2 and preserves v1 bytes', () {
    const old = Codec(supportedSchemas: ['billium.event.v1']);
    final current = jsonDecode(
      File('${root.path}/event/v2/valid.json').readAsStringSync(),
    ) as Map;
    expect(
      () => old.decode(current['script'][0]['args']['json_text'] as String),
      throwsA(
        isA<ProblemException>().having(
          (e) => e.problem,
          'code',
          ProblemCode.schemaUnsupported,
        ),
      ),
    );
    final vector = jsonDecode(
      File('${root.path}/event/envelope/valid.json').readAsStringSync(),
    ) as Map;
    final json = vector['script'][0]['args']['json_text'] as String;
    expect(old.digestOf(old.decode(json)), vector['expect'][0]['ok']['digest']);
    expect(old.encode(old.decode(json)), codec.encode(codec.decode(json)));
  });
  test('exposes typed repair envelopes only to capable clients', () {
    const repairCodec = Codec(supportedSchemas: ['billium.event.v3']);
    const old = Codec(
      supportedSchemas: ['billium.event.v1', 'billium.event.v2'],
    );
    final vector = jsonDecode(
      File('${root.path}/event/repair/valid.json').readAsStringSync(),
    ) as Map;
    final json = vector['script'][0]['args']['json_text'] as String;
    final event = repairCodec.decode(json) as ExecutionRepairEvent;
    expect(event.eventType, 'execution.repair_recorded.v1');
    expect(event.eventContractRevision, 'events-2026-11-v3');
    expect(event.aggregateRef, event.payload['repairRef']);
    expect(event.digest, vector['expect'][0]['ok']['digest']);
    expect(repairCodec.encode(event), json);
    expect(
      () => old.decode(json),
      throwsA(
        isA<ProblemException>().having(
          (e) => e.problem,
          'code',
          ProblemCode.schemaUnsupported,
        ),
      ),
    );
  });
  test(
    'exposes typed certified no-effect events to capable direct clients',
    () {
      const direct = Codec(supportedSchemas: ['billium.event.v4']);
      const legacy = Codec(
        supportedSchemas: [
          'billium.event.v1',
          'billium.event.v2',
          'billium.event.v3',
        ],
      );
      final vector = jsonDecode(
        File('${root.path}/event/certified-no-effect/valid.json')
            .readAsStringSync(),
      ) as Map;
      final json = vector['script'][0]['args']['json_text'] as String;
      final event = direct.decode(json) as ExecutionCertifiedNoEffectEvent;
      expect(event.eventType, 'payment.certified_no_effect.v1');
      expect(event.eventContractRevision, 'events-2026-11-v4');
      expect(event.installationRef, 'installation-a');
      expect(event.environment, 'test');
      expect(event.aggregateRef, event.payload['operationRef']);
      expect(event.revision, event.payload['revision']);
      expect(event.digest, vector['expect'][0]['ok']['digest']);
      expect(direct.encode(event), json);
      expect(
        () => legacy.decode(json),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.schemaUnsupported,
          ),
        ),
      );

      final pageVector = jsonDecode(
        File('${root.path}/feed/v1-page-valid.json').readAsStringSync(),
      ) as Map;
      final page = jsonDecode(
        pageVector['script'][0]['args']['json_text'] as String,
      ) as Map<String, Object?>;
      page['events'] = [event.raw];
      page['digest'] = direct.digest(page);
      expect(
        () => codec.decode(direct.canonicalize(page)),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.validationInvalid,
          ),
        ),
      );
    },
  );
  test('checks negotiated event capabilities throughout a feed page', () {
    const old = Codec(
      supportedSchemas: ['billium.feed.page.v1', 'billium.event.v1'],
    );
    final mixed = jsonDecode(
      File('${root.path}/feed/mixed-page-valid.json').readAsStringSync(),
    ) as Map;
    final json = mixed['script'][0]['args']['json_text'] as String;
    expect(
      () => old.decode(json),
      throwsA(
        isA<ProblemException>().having(
          (e) => e.problem,
          'code',
          ProblemCode.schemaUnsupported,
        ),
      ),
    );
    const capable = Codec(
      supportedSchemas: [
        'billium.feed.page.v1',
        'billium.event.v1',
        'billium.event.v2',
      ],
    );
    expect(
      capable.encode(capable.decode(json)),
      capable.canonicalizeJson(json),
    );
    final v1 = jsonDecode(
      File('${root.path}/feed/v1-page-valid.json').readAsStringSync(),
    ) as Map;
    final v1Json = v1['script'][0]['args']['json_text'] as String;
    expect(old.encode(old.decode(v1Json)), old.canonicalizeJson(v1Json));
  });
}
