import 'dart:io';

import 'package:billium_client_conformance/billium_client_conformance.dart';
import 'package:test/test.dart';

void main() {
  const pilot =
      '{"schema":"payment.execute.v1","commandKey":"7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87","operationRef":"op-8f3a21","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-10234","intentRef":"int-10234-1"},"installationRef":"inst-eu-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-acme","merchantAccountRef":"ma-main","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"bind-7c91d0e2","returnContextRef":"rc-10234","requestedAt":"2026-10-17T12:00:00Z"}';

  test('library imports public contracts only', () {
    final source = File('lib/src/conformance_client.dart').readAsStringSync();
    expect(source.contains('package:billium_sdk'), isFalse);
    expect(source.contains('dart:io'), isFalse);
    expect(source.contains('CancelOperation'), isFalse);
  });

  test('pilot prepare matches the P2 golden and cancellation does not cancel', () {
    final golden = File(
      '../../../fixtures/golden/canon/digest/pilot-execute-canonical.txt',
    ).readAsStringSync().trimRight();
    final client = ConformanceClient();
    final prepared = client.execute({
      'id': 'pilot',
      'op': 'prepare',
      'jsonText': pilot,
    });
    expect((prepared['prepared'] as Map)['bytes'], golden);
    expect(prepared['attempts'], isEmpty);

    final cancelled = client.execute({
      'id': 'cancel',
      'op': 'send',
      'jsonText': pilot,
      'path': '/billium/v1/sellers/seller-acme/payments/execute',
      'transport': [
        {'transport': 'cancelled-after-send'},
      ],
    });
    expect(cancelled['outcome'], 'unresolved');
    expect(cancelled['cancelInvoked'], isFalse);
    expect(cancelled['newKey'], isFalse);
    expect((cancelled['recovery'] as Map)['commandKey'], '7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87');
    expect((cancelled['attempts'] as List).single['path'], '/billium/v1/sellers/seller-acme/payments/execute');

    final unknown = client.execute({
      'id': 'unknown',
      'op': 'prepare',
      'jsonText': '${pilot.substring(0, pilot.length - 1)},"note":"x"}',
    });
    expect(unknown['outcome'], 'refused');
    expect((unknown['error'] as Map)['code'], 'billium.validation.invalid');
    expect(unknown['attempts'], isEmpty);
  });
}
