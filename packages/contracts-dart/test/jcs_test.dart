import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

void main() {
  const codec = Codec();

  test('sorts keys by UTF-16 code units, numeric-looking keys as text', () {
    expect(
      codec.canonicalizeJson(
        r'{"￠":1,"😀":2,"é":3,"a":4,"B":5,"10":6,"9":7,"":8}',
      ),
      '{"":8,"10":6,"9":7,"B":5,"a":4,"é":3,"😀":2,"￠":1}',
    );
  });

  test('keeps empty objects and empty lists apart', () {
    expect(codec.canonicalizeJson('{"o":{},"a":[]}'), '{"a":[],"o":{}}');
  });

  test(
    'escapes control characters in lowercase hex and leaves the solidus alone',
    () {
      expect(
        codec.canonicalizeJson(r'["\u000f\u001F\u007f\/ \"\\"]'),
        '["\\u000f\\u001f\u007f/ \\"\\\\"]',
      );
    },
  );

  test('canonicalizes a decoded value like the same JSON text', () {
    expect(
      codec.canonicalize({
        'b': [1, 2],
        'a': null,
      }),
      codec.canonicalizeJson('{"b":[1,2],"a":null}'),
    );
  });

  for (final text in [
    '{"a":1.5}',
    '{"a":1.0}',
    '[1e2]',
    '[9007199254740993]',
    '[-9007199254740992]',
    '[18446744073709551616]',
    '{"a":',
  ]) {
    test('refuses $text', () {
      expect(
        () => codec.canonicalizeJson(text),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'problem',
            ProblemCode.validationInvalid,
          ),
        ),
      );
    });
  }

  test('refuses an unpaired surrogate', () {
    expect(
      () => codec.canonicalize('\ud800'),
      throwsA(isA<ProblemException>()),
    );
  });

  test('keeps the digest of the worked example of D32', () {
    const json =
        '{"schema":"payment.execute.v1","commandKey":"0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10","operationRef":"op-1","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-1","intentRef":"int-1"},"installationRef":"inst-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-1","merchantAccountRef":"ma-1","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"sha256:00","requestedAt":"2026-10-17T12:00:00Z"}';
    expect(
      codec.digestJson(json),
      'sha256:4e51ca0273f40946bfc6ada7f414e9fd8d6228786142c0391070fa51132f4b52',
    );
  });

  test('leaves a nested digest member in place', () {
    expect(
      codec.digestJson('{"outer":{"digest":"x"},"digest":"y"}'),
      codec.digestJson('{"outer":{"digest":"x"}}'),
    );
  });
}
