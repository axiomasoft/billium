import 'dart:convert';

import 'generated/problem_codes.g.dart';
import 'problem_exception.dart';

const _maxSafe = 9007199254740991;

/// RFC 8785 canonical JSON for strings, integers, booleans, null, lists and maps (D32).
///
/// Doubles and integers outside the exact IEEE 754 range are refused (BC-ID-16): `jsonEncode` escapes
/// strings differently from RFC 8785, so the serializer is written here.
String canonicalizeValue(Object? value) {
  final buffer = StringBuffer();
  _write(buffer, value);
  return buffer.toString();
}

void _write(StringBuffer out, Object? value) {
  switch (value) {
    case null:
      out.write('null');
    case bool():
      out.write(value ? 'true' : 'false');
    case int():
      if (value.abs() > _maxSafe) {
        throw ProblemException(
          ProblemCode.validationInvalid,
          'integer $value is outside the exact IEEE 754 range',
        );
      }
      out.write(value);
    case String():
      _string(out, value);
    case List<Object?>():
      out.write('[');
      for (var i = 0; i < value.length; i++) {
        if (i > 0) out.write(',');
        _write(out, value[i]);
      }
      out.write(']');
    case Map<Object?, Object?>():
      // String.compareTo compares UTF-16 code units, which is the order RFC 8785 asks for.
      final keys = [for (final key in value.keys) key as String]..sort();
      out.write('{');
      for (var i = 0; i < keys.length; i++) {
        if (i > 0) out.write(',');
        _string(out, keys[i]);
        out.write(':');
        _write(out, value[keys[i]]);
      }
      out.write('}');
    default:
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'a JSON number that is not an integer cannot be canonicalized',
      );
  }
}

void _string(StringBuffer out, String value) {
  out.write('"');
  final units = value.codeUnits;
  for (var i = 0; i < units.length; i++) {
    final unit = units[i];
    if (unit >= 0xD800 && unit <= 0xDBFF) {
      final paired =
          i + 1 < units.length &&
          units[i + 1] >= 0xDC00 &&
          units[i + 1] <= 0xDFFF;
      if (!paired) throw _notUtf8();
      out.writeCharCode(unit);
      out.writeCharCode(units[++i]);
      continue;
    }
    if (unit >= 0xDC00 && unit <= 0xDFFF) throw _notUtf8();
    switch (unit) {
      case 0x22:
        out.write(r'\"');
      case 0x5C:
        out.write(r'\\');
      case 0x08:
        out.write(r'\b');
      case 0x0C:
        out.write(r'\f');
      case 0x0A:
        out.write(r'\n');
      case 0x0D:
        out.write(r'\r');
      case 0x09:
        out.write(r'\t');
      case < 0x20:
        out.write('\\u${unit.toRadixString(16).padLeft(4, '0')}');
      default:
        out.writeCharCode(unit);
    }
  }
  out.write('"');
}

ProblemException _notUtf8() => const ProblemException(
  ProblemCode.validationInvalid,
  'a string is not valid Unicode',
);

/// Parses JSON text; a syntax error is `billium.validation.invalid`.
Object? parseJson(String text) {
  try {
    return jsonDecode(text);
  } on FormatException catch (error) {
    throw ProblemException(
      ProblemCode.validationInvalid,
      'invalid JSON: ${error.message}',
    );
  }
}
