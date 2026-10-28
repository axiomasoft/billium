import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

void main() {
  const codec = Codec();
  const root = '../../fixtures/vectors/query/read';
  final files = Directory(root).listSync().whereType<File>().where((file) {
    final name = file.uri.pathSegments.last;
    return name.startsWith('payment-status-') ||
        name.startsWith('due-obligations-') ||
        name == 'due-parameters-on-existing-query.json';
  }).toList()..sort((a, b) => a.path.compareTo(b.path));

  for (final file in files) {
    final vector = jsonDecode(file.readAsStringSync()) as Map;
    test('D60 ${vector['id']}', () {
      final step = ((vector['script'] as List).single as Map);
      final json = (step['args'] as Map)['json_text'] as String;
      final expected = ((vector['expect'] as List).single as Map);
      if (expected.containsKey('error')) {
        expect(
          () => codec.decode(json),
          throwsA(
            isA<ProblemException>().having(
              (error) => error.problem.value,
              'code',
              (expected['error'] as Map)['code'],
            ),
          ),
        );
        return;
      }
      final decoded = codec.decode(json);
      expect(decoded.schema, (expected['ok'] as Map)['schema']);
      switch (decoded.schema) {
        case 'ledger.payment_status.v1':
          expect(decoded, isA<PaymentStatus>());
        case 'ledger.due_obligations.v1':
          expect(decoded, isA<DueObligations>());
        default:
          expect(decoded, isA<BilliumQuery>());
      }
      expect(codec.encode(decoded), codec.canonicalizeJson(json));
    });
  }

  for (final name in ['payment-status-current', 'payment-status-unapplied']) {
    test('refuses unsafe projection $name', () {
      final vector =
          jsonDecode(File('$root/$name.json').readAsStringSync()) as Map;
      final step = ((vector['script'] as List).single as Map);
      final input =
          jsonDecode((step['args'] as Map)['json_text'] as String) as Map;
      final result = input['result'] as Map;
      if (name == 'payment-status-current') {
        result.remove('billingAccountRef');
      } else {
        (result['spendable'] as Map)['amount'] = '1.00';
      }
      expect(
        () => codec.decode(jsonEncode(input)),
        throwsA(
          isA<ProblemException>().having(
            (error) => error.problem,
            'problem',
            ProblemCode.validationInvalid,
          ),
        ),
      );
    });
  }

  test('keeps exceptional actual facts separate from last applied totals', () {
    final vector = jsonDecode(
      File('$root/payment-status-held.json').readAsStringSync(),
    ) as Map;
    final step = ((vector['script'] as List).single as Map);
    final decoded = codec.decode(
      (step['args'] as Map)['json_text'] as String,
    ) as PaymentStatus;
    final result = decoded.result;
    expect(
      ((result['observed'] as Map)['refundedTotal'] as Map)['amount'],
      '140.00',
    );
    final applied = result['applied'] as Map;
    expect((applied['capturedTotal'] as Map)['amount'], '100.00');
    expect((applied['refundedTotal'] as Map)['amount'], '70.00');
    expect(
      ((result['exposure'] as Map)['overRefunded'] as Map)['amount'],
      '40.00',
    );
    expect((result['financialHold'] as Map)['active'], isTrue);
    expect((result['spendable'] as Map)['amount'], '0.00');
  });
}
