import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:billium_contracts/src/generated/envelopes.g.dart'
    show envelopeBuilders;
import 'package:test/test.dart';

List<String> snapshot(String kind) => (jsonDecode(
  File('../../spec/identities/snapshots/$kind.json').readAsStringSync(),
) as List<Object?>).cast<String>();

void main() {
  test('matches the problem-code snapshot', () {
    final codes = [for (final code in ProblemCode.values) code.value]..sort();
    expect(codes, snapshot('problem-code'));
  });

  test('matches the type-map for envelope classes', () {
    final map = (jsonDecode(
      File('../../spec/type-map.json').readAsStringSync(),
    ) as List<Object?>).cast<Map<String, Object?>>();
    final classes = [
      for (final entry in map)
        if (entry['schema'] != null) entry['dart']! as String,
    ]..sort();
    expect(envelopeBuilders.keys.toList()..sort(), classes);
  });

  test('matches the spec-revision snapshot', () {
    expect(snapshot('spec-revision'), supportedSpecRevisions);
    expect(supportedSpecRevisions, containsAll(['2026-10', '2026-12']));
    expect(specRevision, '2026-12');
  });
}
