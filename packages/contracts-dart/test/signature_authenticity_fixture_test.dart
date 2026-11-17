import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:crypto/crypto.dart';
import 'package:test/test.dart';

void main() {
  final fixtureFile = File(
    '../../fixtures/qualification/authenticity/callback.json',
  );
  final fixture =
      jsonDecode(fixtureFile.readAsStringSync()) as Map<String, dynamic>;
  final transcript = fixture['transcript'] as Map<String, dynamic>;
  const codec = Codec();

  for (final testCase
      in (fixture['cases'] as List).cast<Map<String, dynamic>>()) {
    final id = testCase['id'] as String;
    test('simulated callback signature $id', () {
      final canonical = codec.canonicalize({
        ...transcript,
        ...?testCase['changes'] as Map<String, dynamic>?,
      });
      if (id == 'authentic') {
        expect(canonical, fixture['canonicalUtf8']);
      }
      final key = decodeHex(
        (testCase['keyHex'] ?? fixture['keyHex']) as String,
      );
      final expected =
          (testCase['signatureHex'] ?? fixture['signatureHex']) as String;
      final actual = Hmac(
        sha256,
        key,
      ).convert(utf8.encode(canonical)).toString();
      final wrongControl =
          id == 'bad-signature-control' &&
          Platform.environment['BILLIUM_AUTHENTICITY_WRONG_CONTROL'] == '1';
      expect(
        actual == expected,
        (testCase['accepted'] as bool) || wrongControl,
      );
    });
  }
}

List<int> decodeHex(String value) => [
  for (var offset = 0; offset < value.length; offset += 2)
    int.parse(value.substring(offset, offset + 2), radix: 16),
];
