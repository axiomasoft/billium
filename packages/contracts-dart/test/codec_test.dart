import 'dart:convert';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

const _execute =
    '{"schema":"payment.execute.v1","commandKey":"0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10","operationRef":"op-1","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-1","intentRef":"int-1"},"installationRef":"inst-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-1","merchantAccountRef":"ma-1","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"sha256:00","requestedAt":"2026-10-17T12:00:00Z"}';

/// [base] with [changes] applied; a null value removes the member.
String envelope(String base, Map<String, Object?> changes) {
  final data = (jsonDecode(base) as Map).cast<String, Object?>();
  changes.forEach((key, value) {
    if (value == null) {
      data.remove(key);
    } else {
      data[key] = value;
    }
  });
  return jsonEncode(data);
}

ProblemCode decodeRefusal(String json) {
  try {
    const Codec().decode(json);
  } on ProblemException catch (error) {
    return error.problem;
  }
  fail('expected a ProblemException');
}

void main() {
  const codec = Codec();

  test('decodes a command into its typed envelope', () {
    final execute = codec.decode(_execute);
    expect(execute, isA<ExecutePayment>());
    execute as ExecutePayment;
    expect(execute.schema, 'payment.execute.v1');
    expect(execute.commandKey, '0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10');
    expect(execute.routingEpoch, 1);
    expect(execute.money.minor, BigInt.from(149000));
    expect(execute.digest, isNull);
    expect(execute.returnContextRef, isNull);
  });

  test('accepts the digest of the canonical form and refuses another', () {
    final digest = codec.digestJson(_execute);
    expect(
      (codec.decode(
        envelope(_execute, {'digest': digest}),
      ) as ExecutePayment).digest,
      digest,
    );
    expect(
      decodeRefusal(envelope(_execute, {'digest': 'sha256:${'0' * 64}'})),
      ProblemCode.validationInvalid,
    );
  });

  test('picks the find request or response by the status member', () {
    const request =
        '{"schema":"payment.find.v1","installationRef":"i","environment":"test","sellerRef":"s","operationRef":"op-1"}';
    const absent =
        '{"schema":"payment.find.v1","operationRef":"op-1","status":"absent","absenceCertificate":{"scope":"retention"}}';
    expect(codec.decode(request), isA<FindOperation>());
    expect(codec.decode(absent), isA<FindResult>());
  });

  test('keeps unknown optional members of an open envelope and refuses them on a command', () {
    final outcome =
        '{"schema":"payment.outcome.v1","operationRef":"op-1","revision":3,"status":"succeeded","money":{"amount":"1490.00","currency":"RUB"},"capturedTotal":"1490.00","refundedTotal":"0.00","bindingRevision":"sha256:00","sellerRef":"s","merchantAccountRef":"ma-1","providerEvidenceRef":"ev-1","occurredAt":"2026-10-17T12:03:41Z","digest":"sha256:${'a' * 64}","future":{"x":1}}';
    final decoded = codec.decode(outcome);
    expect(decoded, isA<PaymentOutcome>());
    expect(codec.encode(decoded), contains('"future":{"x":1}'));
    expect(
      decodeRefusal(envelope(_execute, {'future': 1})),
      ProblemCode.validationInvalid,
    );
  });

  test('reports the first failed stage: syntax, schema, fields, amounts', () {
    expect(decodeRefusal('{'), ProblemCode.validationInvalid);
    expect(decodeRefusal('[]'), ProblemCode.validationInvalid);
    expect(decodeRefusal('{"schema":7}'), ProblemCode.validationInvalid);
    expect(
      decodeRefusal(
        envelope(_execute, {'schema': 'payment.execute.v2', 'money': null}),
      ),
      ProblemCode.schemaUnsupported,
    );
    expect(
      decodeRefusal(
        envelope(_execute, {
          'money': {'amount': '1490.0', 'currency': 'RUB'},
          'sellerRef': null,
        }),
      ),
      ProblemCode.validationInvalid,
    );
    expect(
      decodeRefusal(
        envelope(_execute, {
          'money': {'amount': '1490.0', 'currency': 'RUB'},
        }),
      ),
      ProblemCode.moneyScaleInvalid,
    );
    expect(
      decodeRefusal(
        envelope(_execute, {
          'money': {'amount': '0.00', 'currency': 'RUB'},
        }),
      ),
      ProblemCode.validationInvalid,
    );
  });

  test('refuses an integer member outside the exact IEEE 754 range', () {
    expect(
      decodeRefusal(
        _execute.replaceFirst(
          '"routingEpoch":1',
          '"routingEpoch":9007199254740992',
        ),
      ),
      ProblemCode.validationInvalid,
    );
    expect(
      codec.decode(
        _execute.replaceFirst(
          '"routingEpoch":1',
          '"routingEpoch":9007199254740991',
        ),
      ),
      isA<ExecutePayment>(),
    );
  });

  test('checks outcome totals against the scale of the money currency', () {
    final outcome =
        '{"schema":"payment.outcome.v1","operationRef":"op-1","revision":3,"status":"succeeded","money":{"amount":"1490","currency":"JPY"},"capturedTotal":"1490","refundedTotal":"0","bindingRevision":"b","sellerRef":"s","merchantAccountRef":"ma-1","providerEvidenceRef":"ev-1","occurredAt":"2026-10-17T12:03:41Z","digest":"sha256:${'a' * 64}"}';
    expect(codec.decode(outcome), isA<PaymentOutcome>());
    expect(
      decodeRefusal(
        outcome.replaceFirst(
          '"capturedTotal":"1490"',
          '"capturedTotal":"1490.00"',
        ),
      ),
      ProblemCode.moneyScaleInvalid,
    );
    expect(
      decodeRefusal(
        outcome.replaceFirst('"capturedTotal":"1490"', '"capturedTotal":1490'),
      ),
      ProblemCode.moneyScaleInvalid,
    );
  });

  test('decodes a problem only when it matches its registry entry', () {
    const problem =
        '{"code":"billium.idempotency.in_flight","category":"idempotency","retryable":true,"sameKey":true,"receipt":"none","title":"In flight","status":409}';
    final decoded = codec.decodeProblem(problem);
    expect(decoded.code, ProblemCode.idempotencyInFlight);
    expect(decoded.status, 409);
    expect(
      () => codec.decodeProblem(
        problem.replaceFirst('"retryable":true', '"retryable":false'),
      ),
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
