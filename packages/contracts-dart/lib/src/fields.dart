import 'generated/problem_codes.g.dart';
import 'money.dart';
import 'problem_exception.dart';

/// Typed member access for the generated envelope classes; the object already passed the schema.
String requireString(Map<String, Object?> raw, String key) =>
    optionalString(raw, key) ?? (throw _missing(key));

String? optionalString(Map<String, Object?> raw, String key) {
  final value = raw[key];
  if (value == null) return null;
  return value is String ? value : throw _wrongType(key, 'string');
}

int requireInt(Map<String, Object?> raw, String key) =>
    optionalInt(raw, key) ?? (throw _missing(key));

int? optionalInt(Map<String, Object?> raw, String key) {
  final value = raw[key];
  if (value == null) return null;
  return value is int ? value : throw _wrongType(key, 'integer');
}

Money requireMoney(Map<String, Object?> raw, String key) =>
    optionalMoney(raw, key) ?? (throw _missing(key));

Money? optionalMoney(Map<String, Object?> raw, String key) {
  final value = raw[key];
  return value == null ? null : Money.fromWire(value);
}

Map<String, Object?> requireObject(Map<String, Object?> raw, String key) =>
    optionalObject(raw, key) ?? (throw _missing(key));

Map<String, Object?>? optionalObject(Map<String, Object?> raw, String key) {
  final value = raw[key];
  if (value == null) return null;
  return value is Map ? value.cast() : throw _wrongType(key, 'object');
}

List<Object?> requireList(Map<String, Object?> raw, String key) =>
    optionalList(raw, key) ?? (throw _missing(key));

List<Object?>? optionalList(Map<String, Object?> raw, String key) {
  final value = raw[key];
  if (value == null) return null;
  return value is List ? value.cast<Object?>() : throw _wrongType(key, 'array');
}

bool requireBool(Map<String, Object?> raw, String key) =>
    optionalBool(raw, key) ?? (throw _missing(key));

bool? optionalBool(Map<String, Object?> raw, String key) {
  final value = raw[key];
  if (value == null) return null;
  return value is bool ? value : throw _wrongType(key, 'boolean');
}

ProblemException _missing(String key) => ProblemException(
  ProblemCode.validationInvalid,
  "member '$key' is required",
);

ProblemException _wrongType(String key, String type) => ProblemException(
  ProblemCode.validationInvalid,
  "member '$key' must be $type",
);
