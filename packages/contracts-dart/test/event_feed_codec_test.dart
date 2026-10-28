import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

void main() {
  final root = Directory('../../fixtures/vectors');
  final files = [
    for (final directory in ['event/v2', 'payment/effects', 'feed'])
      ...Directory('${root.path}/$directory').listSync().whereType<File>(),
    File('${root.path}/event/envelope/event-type-unknown.json'),
  ];
  const codec = Codec();
  for (final file in files) {
    final vector = jsonDecode(file.readAsStringSync()) as Map;
    test('EVT-07 ${vector['id']}', () {
      final json = vector['script'][0]['args']['json_text'] as String;
      final expected = vector['expect'][0] as Map;
      if (expected.containsKey('error')) {
        expect(
          () => codec.decode(json),
          throwsA(
            isA<ProblemException>().having(
              (e) => e.problem.value,
              'code',
              expected['error']['code'],
            ),
          ),
        );
      } else {
        final value = codec.decode(json);
        expect(value.schema, expected['ok']['schema']);
        expect(codec.digestOf(value), expected['ok']['digest']);
        expect(codec.encode(value), codec.canonicalizeJson(json));
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
