import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

void main() {
  const codec = Codec();
  const root = '../../fixtures/vectors';
  for (final area in ['usage', 'rating']) {
    final files =
        Directory('$root/$area/contracts').listSync().whereType<File>().toList()
          ..sort((a, b) => a.path.compareTo(b.path));
    for (final file in files) {
      final vector = jsonDecode(file.readAsStringSync()) as Map;
      test('P4 ${vector['id']}', () {
        expect(vector['profiles'], contains('dart'));
        final step = (vector['script'] as List).single as Map;
        final json = (step['args'] as Map)['json_text'] as String;
        final expected = (vector['expect'] as List).single as Map;
        if (expected.containsKey('error')) {
          expect(
            () => codec.decode(json),
            throwsA(
              isA<ProblemException>().having(
                (e) => e.problem.value,
                'code',
                (expected['error'] as Map)['code'],
              ),
            ),
          );
          return;
        }
        final decoded = codec.decode(json);
        final ok = expected['ok'] as Map;
        expect(decoded.schema, ok['schema']);
        expect(codec.encode(decoded), codec.canonicalizeJson(json));
        final digestExpectation = ok['digest'];
        final expectedDigest = digestExpectation is Map
            ? File('../../fixtures/golden/${digestExpectation['golden']}')
                  .readAsStringSync()
                  .trimRight()
            : digestExpectation;
        expect(expectedDigest, isA<String>());
        expect(
          codec.digestOf(decoded),
          vector.containsKey('control')
              ? isNot(expectedDigest)
              : expectedDigest,
        );
        if (decoded.schema == 'usage.metric.v1') {
          expect(decoded, isA<MetricVersion>());
        }
        if (decoded.schema == 'rating.price.v1') {
          expect(decoded, isA<PriceVersion>());
        }
      });
    }
  }

  for (final set in ['obligation/prepare', 'occurrence/ensure', 'usage/fact']) {
    test('refuses unregistered v3 in $set', () {
      final vector = jsonDecode(
        File('$root/$set/schema-v3.json').readAsStringSync(),
      ) as Map;
      final step = (vector['script'] as List).single as Map;
      final expected = (vector['expect'] as List).single as Map;
      expect(
        (expected['error'] as Map)['code'],
        ProblemCode.schemaUnsupported.value,
      );
      expect(
        () => codec.decode((step['args'] as Map)['json_text'] as String),
        throwsA(
          isA<ProblemException>().having(
            (error) => error.problem,
            'code',
            ProblemCode.schemaUnsupported,
          ),
        ),
      );
    });
  }

  test('retains historical v1 usage digest', () {
    final v = jsonDecode(
      File('$root/usage/fact/valid-additive.json').readAsStringSync(),
    ) as Map;
    final step = (v['script'] as List).single as Map;
    final fact = codec.decode((step['args'] as Map)['json_text'] as String);
    expect(fact, isA<UsageFact>());
    expect(
      codec.digestOf(fact),
      ((v['expect'] as List).single as Map)['ok']['digest'],
    );
  });
}
