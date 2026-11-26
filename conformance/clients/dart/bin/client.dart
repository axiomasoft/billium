import 'dart:convert';
import 'dart:io';

import 'package:billium_client_conformance/billium_client_conformance.dart';

/// JSON stdin/stdout for the test-only consumer. Public contracts only (D9).
Future<void> main() async {
  final archive = Platform.environment['BILLIUM_ARCHIVE_ROOT'];
  if (archive != null && archive.isNotEmpty) {
    final script = Platform.script.toFilePath();
    if (!script.startsWith(archive)) {
      stderr.writeln('client resolved outside the pinned archive');
      exit(1);
    }
  }
  final input = jsonDecode(await stdin.transform(utf8.decoder).join()) as Object?;
  if (input is! Map) {
    stderr.writeln('client input must be an object');
    exit(1);
  }
  final message = Map<String, Object?>.from(input);
  final client = ConformanceClient();
  final Object? output;
  if (message['op'] == 'batch') {
    final cases = message['cases'];
    if (cases is! List) {
      stderr.writeln('batch cases must be a list');
      exit(1);
    }
    output = {
      'results': [
        for (final item in cases)
          if (item is Map) client.execute(Map<String, Object?>.from(item)),
      ],
    };
  } else {
    output = client.execute(message);
  }
  stdout.write(jsonEncode(output));
}
