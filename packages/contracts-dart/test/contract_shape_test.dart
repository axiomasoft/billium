import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

void main() {
  test('has no double in lib', () {
    final offenders = [
      for (final file in Directory(
        'lib',
      ).listSync(recursive: true).whereType<File>())
        if (file.path.endsWith('.dart') &&
            RegExp(
              r'\bdouble\b',
            ).hasMatch(file.readAsStringSync().replaceAll(RegExp(r'//.*'), '')))
          file.path,
    ];
    expect(offenders, isEmpty);
  });

  test('declares the revision the pubspec declares', () {
    final pubspec = File('pubspec.yaml').readAsStringSync();
    expect(pubspec, contains('spec_rev: "$specRevision"'));
  });

  test('lists the problem codes in the order and with the metadata of the registry', () {
    for (final revision in supportedSpecRevisions) {
      final registry = (jsonDecode(
        File('../../spec/revisions/$revision/problem-codes.json')
            .readAsStringSync(),
      ) as List<Object?>).cast<Map<String, Object?>>();
      final cases = [
        for (final code in ProblemCode.values)
          {
            'code': code.value,
            'category': code.category,
            'retryable': code.retryable,
            'sameKey': code.sameKey,
            'receipt': code.receipt,
          },
      ];
      final expected = [
        for (final entry in registry)
          {for (final key in cases.first.keys) key: entry[key]},
      ];
      expect(cases, expected);
    }
  });

  test('imports nothing from the package internals in the public library', () {
    final library = File('lib/billium_contracts.dart').readAsStringSync();
    expect(RegExp(r"export 'src/").allMatches(library), isNotEmpty);
    expect(library, isNot(contains('import ')));
  });
}
