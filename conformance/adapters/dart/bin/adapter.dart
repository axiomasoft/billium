// Conformance adapter of billium_contracts for Dart (protocol: conformance/README.md).
// JSON Lines on stdin/stdout, diagnostics on stderr; it uses the public API of the package only.
import 'dart:convert';
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';

const _ops = [
  'money.parse',
  'money.format',
  'money.add',
  'money.allocate',
  'money.round',
  'canon.canonicalize',
  'canon.digest',
  'envelope.decode',
  'envelope.roundtrip',
  'problem.decode',
  'codes.registry',
];

ProblemException _invalid(String detail) =>
    ProblemException(ProblemCode.validationInvalid, detail);

String _text(Map<String, Object?> args, String name) {
  final value = args[name];
  return value is String
      ? value
      : throw _invalid('argument $name must be a string');
}

Map<String, Object?> _problem(ProblemCode code) => {
  'code': code.value,
  'category': code.category,
  'retryable': code.retryable,
  'sameKey': code.sameKey,
  'receipt': code.receipt,
};

Map<String, Object?> _run(
  Codec codec,
  MoneyMath math,
  String op,
  Map<String, Object?> args,
) {
  switch (op) {
    case 'money.parse':
      final money = codec.decodeMoney(_text(args, 'wire_text'));
      return {'minor': money.minor.toString(), 'currency': money.currency.code};
    case 'money.format':
      final minor = _text(args, 'minor');
      if (!RegExp(r'^-?(0|[1-9][0-9]*)$').hasMatch(minor)) {
        throw _invalid('minor must be an integer string');
      }
      return {
        'wire': Money(
          BigInt.parse(minor),
          Currency.of(_text(args, 'currency')),
        ).toWire(),
      };
    case 'money.add':
      return {
        'wire': math
            .add(Money.fromWire(args['a']), Money.fromWire(args['b']))
            .toWire(),
      };
    case 'money.allocate':
      final ratios = args['ratios'];
      if (ratios is! List<Object?>) throw _invalid('ratios must be a list');
      final parts = math.allocate(Money.fromWire(args['total']), ratios);
      return {
        'parts': [for (final part in parts) part.toWire()],
      };
    case 'money.round':
      final mode =
          RoundingMode.tryParse(_text(args, 'mode')) ??
          (throw _invalid('unknown rounding mode'));
      return {
        'wire': math
            .round(
              _text(args, 'value'),
              Currency.of(_text(args, 'currency')),
              mode,
            )
            .toWire(),
      };
    case 'canon.canonicalize':
      return {'text': codec.canonicalizeJson(_text(args, 'json_text'))};
    case 'canon.digest':
      return {'digest': codec.digestJson(_text(args, 'json_text'))};
    case 'envelope.decode':
      final envelope = codec.decode(_text(args, 'json_text'));
      return {'schema': envelope.schema, 'digest': codec.digestOf(envelope)};
    case 'envelope.roundtrip':
      return {'text': codec.encode(codec.decode(_text(args, 'json_text')))};
    case 'problem.decode':
      return _problem(codec.decodeProblem(_text(args, 'json_text')).code);
    case 'codes.registry':
      return {
        'codes': [for (final code in ProblemCode.values) _problem(code)],
      };
    default:
      throw StateError('unknown op $op');
  }
}

Future<void> _send(Map<String, Object?> message) async {
  stdout.writeln(jsonEncode(message));
  await stdout.flush();
}

Future<void> main() async {
  const codec = Codec();
  const math = MoneyMath();
  await for (final line
      in stdin.transform(utf8.decoder).transform(const LineSplitter())) {
    final message = (jsonDecode(line) as Map).cast<String, Object?>();
    switch (message['type']) {
      case 'start':
        await _send({
          'type': 'ready',
          'adapter': 'dart',
          'spec_rev': specRevision,
          'ops': _ops,
        });
      case 'config':
        await _send({'type': 'ok'});
      case 'run':
        final outputs = <Map<String, Object?>>[];
        for (final step in message['script']! as List<Object?>) {
          final map = (step! as Map).cast<String, Object?>();
          try {
            outputs.add({
              'ok': _run(
                codec,
                math,
                map['op']! as String,
                (map['args']! as Map).cast<String, Object?>(),
              ),
            });
          } on ProblemException catch (error) {
            outputs.add({
              'error': {'code': error.problem.value, 'detail': error.detail},
            });
          }
        }
        await _send({
          'type': 'result',
          'id': message['id'],
          'outputs': outputs,
        });
      case 'stop':
        return;
      default:
        throw StateError('unknown message type ${message['type']}');
    }
  }
}
