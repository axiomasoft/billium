import 'dart:async';
import 'dart:convert';
import 'dart:io';

Future<void> main() async {
  final input = jsonDecode(await stdin.transform(utf8.decoder).join()) as Map;
  final client = HttpClient()..findProxy = (_) => 'DIRECT';
  final uri = Uri.parse('http://127.0.0.1:${input['port']}${input['path']}');
  final duration = Duration(milliseconds: input['timeoutMs'] as int);
  Future<Map<String, Object?>> send() async {
    final request = await client.openUrl(input['method'] as String, uri);
    (input['headers'] as Map).forEach(
      (name, value) => request.headers.set(name as String, value as String),
    );
    if (request.method == 'POST') {
      final bytes = utf8.encode(input['body'] as String);
      request.contentLength = bytes.length;
      request.add(bytes);
    }
    final response = await request.close();
    final headers = <String, String>{};
    response.headers.forEach(
      (name, values) => headers[name] = values.join(','),
    );
    return {
      'status': response.statusCode,
      'headers': headers,
      'body': await response.transform(utf8.decoder).join(),
    };
  }

  try {
    stdout.write(jsonEncode(await send().timeout(duration)));
  } on TimeoutException {
    stdout.write(jsonEncode({'transport': 'deadline'}));
  } on HttpException {
    stdout.write(jsonEncode({'transport': 'closed'}));
  } finally {
    client.close(force: true);
  }
}
