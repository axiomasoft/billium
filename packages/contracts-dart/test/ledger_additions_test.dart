import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

Map<String, Object?> readVector(String path) =>
    (jsonDecode(File(path).readAsStringSync()) as Map).cast();

void main() {
  const codec = Codec();
  const root = '../../fixtures/vectors/';
  final paths = [
    for (final file in Directory('${root}allocation/reallocate').listSync())
      if (file is File && file.path.endsWith('.json')) file.path,
    for (final name in [
      'allocation-exceeds_open',
      'allocation-exceeds_available',
      'invoice-transition_invalid',
      'reference-unknown',
    ])
      '${root}errors/codes/decode-$name.json',
  ]..sort();
  final refusal = isA<ProblemException>().having(
    (error) => error.problem,
    'problem',
    ProblemCode.validationInvalid,
  );

  for (final path in paths) {
    final vector = readVector(path);
    test('D47 ${vector['id']}', () {
      final step = ((vector['script'] as List).single as Map);
      final json = (step['args'] as Map)['json_text'] as String;
      final expected = ((vector['expect'] as List).single as Map);
      if (expected.containsKey('error')) {
        expect(() => codec.decode(json), throwsA(refusal));
        return;
      }
      final ok = expected['ok'] as Map;
      if (step['op'] == 'envelope.decode') {
        final decoded = codec.decode(json);
        expect(decoded, isA<ReallocatePayment>());
        final command = decoded as ReallocatePayment;
        final input = jsonDecode(json) as Map;
        expect(command.schema, ok['schema']);
        expect(codec.digestOf(command), ok['digest']);
        expect(command.paymentRef, input['paymentRef']);
        expect(command.policyVersion, input['policyVersion']);
        expect(command.mode, input['mode']);
        expect(command.digest, input['digest']);
        return;
      }
      final code = codec.decodeProblem(json).code;
      expect({
        'code': code.value,
        'category': code.category,
        'retryable': code.retryable,
        'sameKey': code.sameKey,
        'receipt': code.receipt,
      }, ok);
      final wrong = (jsonDecode(json) as Map).cast<String, Object?>();
      wrong['receipt'] = code.receipt == 'stored' ? 'none' : 'stored';
      expect(() => codec.decodeProblem(jsonEncode(wrong)), throwsA(refusal));
    });
  }

  test(
    'requires every reallocation member and validates key and policy syntax',
    () {
      final vector = readVector(
        '${root}allocation/reallocate/valid-reversal.json',
      );
      final step = ((vector['script'] as List).single as Map);
      final base = (jsonDecode(
        (step['args'] as Map)['json_text'] as String,
      ) as Map).cast<String, Object?>();
      final invalid = [
        for (final member in base.keys)
          Map<String, Object?>.of(base)..remove(member),
        for (final policy in [
          'refund',
          'Refund/1',
          'refund/v1',
          'refund/1/2',
          '',
        ])
          {...base, 'policyVersion': policy},
        {...base, 'commandKey': 'not-a-uuid'},
      ];
      for (final input in invalid) {
        expect(() => codec.decode(jsonEncode(input)), throwsA(refusal));
      }
    },
  );
}
