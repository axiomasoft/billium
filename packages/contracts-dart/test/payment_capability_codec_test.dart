import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:crypto/crypto.dart';
import 'package:test/test.dart';

void main() {
  const codec = Codec();
  final files = [
    for (final group in ['capabilities', 'effects'])
      ...Directory('../../fixtures/vectors/payment/$group')
          .listSync()
          .whereType<File>(),
  ];
  test('capability/effect fixtures are collected', () {
    expect(files.length, greaterThan(30));
  });
  for (final file in files) {
    final vector = jsonDecode(file.readAsStringSync()) as Map;
    test('P5.1 ${vector['id']}', () {
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
        final envelope = codec.decode(json);
        expect(envelope.schema, expected['ok']['schema']);
        expect(codec.digestOf(envelope), expected['ok']['digest']);
        expect(codec.encode(envelope), codec.canonicalizeJson(json));
      }
    });
  }
  test('new commands are outside a pinned pilot client', () {
    const old = Codec(supportedSchemas: ['payment.execute.v1']);
    final vector = jsonDecode(
      File('../../fixtures/vectors/payment/effects/authorize-command.json')
          .readAsStringSync(),
    ) as Map;
    expect(
      () => old.decode(vector['script'][0]['args']['json_text'] as String),
      throwsA(
        isA<ProblemException>().having(
          (e) => e.problem,
          'code',
          ProblemCode.schemaUnsupported,
        ),
      ),
    );
  });
  test('simulated callback signature and changed-byte controls', () {
    final vector = jsonDecode(
      File(
        '../../fixtures/vectors/payment/capabilities/callback-signature-fixture.json',
      ).readAsStringSync(),
    ) as Map;
    final fixture = vector['given']['authenticity'] as Map;
    expect(fixture['scheme'], 'fixture-hmac-sha256');
    final hmac = Hmac(sha256, utf8.encode(fixture['key'] as String));
    final signature = hmac
        .convert(utf8.encode(fixture['body'] as String))
        .toString();
    expect(signature, fixture['signature']);
    expect(signature, isNot(fixture['wrongSignature']));
    expect(
      hmac.convert(utf8.encode(fixture['alteredBody'] as String)).toString(),
      isNot(fixture['signature']),
    );
  });
}
