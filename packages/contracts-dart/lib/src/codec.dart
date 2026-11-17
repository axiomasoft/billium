import 'dart:convert';

import 'package:crypto/crypto.dart';

import 'envelope.dart';
import 'generated/envelopes.g.dart';
import 'generated/problem_codes.g.dart';
import 'jcs.dart';
import 'money.dart';
import 'p4_wire.dart';
import 'problem.dart';
import 'problem_exception.dart';
import 'schema_validator.dart';
import 'spec.dart';

const _problemSchema = 'urn:billium:spec:2026-10:problem/problem.json';

/// Strict decode and canonical encode of the envelopes of the spec revision, plus the digest of D32.
final class Codec {
  /// Creates a codec, optionally pinned to explicitly negotiated client capabilities.
  const Codec({this.supportedSchemas});

  final List<String>? supportedSchemas;

  /// Decodes a JSON text. Checks run in order: JSON syntax, `schema`, fields, amounts (BC-ENV-03).
  ///
  /// Throws [ProblemException] `billium.validation.invalid`, `billium.schema.unsupported` or
  /// `billium.money.scale_invalid`.
  Envelope decode(String json) {
    final value = parseJson(json);
    if (value is! Map || value['schema'] is! String) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'an envelope is an object with a string schema',
      );
    }
    final raw = value.cast<String, Object?>();
    final schema = raw['schema']! as String;
    if (supportedSchemas != null && !supportedSchemas!.contains(schema)) {
      throw const ProblemException(
        ProblemCode.schemaUnsupported,
        'Schema is outside the pinned client capabilities',
      );
    }
    final entry = envelopeRegistry[schema];
    if (entry == null) {
      throw ProblemException(
        ProblemCode.schemaUnsupported,
        "schema '$schema' is not in the revision",
      );
    }

    if (schema == 'billium.event.v2' &&
        raw['eventContractRevision'] != 'events-2026-10-v2') {
      throw const ProblemException(
        ProblemCode.schemaUnsupported,
        'unknown pinned event catalog',
      );
    }
    final problem = SchemaValidator(schemas).check(entry.schemaId, raw);
    if (problem != null) {
      throw ProblemException(problem, 'the object does not match $schema');
    }
    if (p4Schemas.contains(schema)) {
      validateP4Wire(raw);
      for (final child in raw.values) {
        if (child is Map && child.containsKey('schema')) {
          decode(canonicalizeValue(child));
        }
      }
    }

    final effect = schema == 'payment.effect.outcome.v1'
        ? raw
        : (schema == 'billium.event.v2' ? raw['payload'] : null);
    if (effect is Map &&
        effect['operationRef'] == effect['originalOperationRef']) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'effect operationRef must differ from its root',
      );
    }
    if (schema == 'billium.event.v2' &&
        effect is Map &&
        (raw['sellerRef'] != effect['sellerRef'] ||
            raw['aggregateRef'] != effect['operationRef'] ||
            raw['revision'] != effect['revision'])) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'effect envelope identity differs from its payload',
      );
    }
    final repair = schema == 'billium.event.v3' ? raw['payload'] : null;
    if (repair is Map && raw['aggregateRef'] != repair['repairRef']) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'repair envelope aggregateRef differs from its payload',
      );
    }
    final variant = entry.variants.firstWhere(
      (candidate) =>
          candidate.requires == null || raw.containsKey(candidate.requires),
      orElse: () => entry.variants.first,
    );
    final member = variant.digestMember;
    if (variant.verifyDigest &&
        raw.containsKey(member) &&
        raw[member] != digest(raw, member: member)) {
      throw ProblemException(
        ProblemCode.validationInvalid,
        '$member differs from the digest of the canonical form',
      );
    }
    if (schema == 'billium.feed.page.v1' && raw['events'] is List) {
      for (final event in raw['events']! as List) {
        // D63: nested events retain the same semantic and negotiated capability checks.
        decode(canonicalizeValue(event));
      }
    }
    return envelopeBuilders[variant.className]!(raw);
  }

  /// The canonical form (RFC 8785) of the envelope as received, `digest` included.
  String encode(Envelope envelope) => canonicalizeValue(envelope.raw);

  /// Parses a MoneyWire JSON text.
  Money decodeMoney(String json) => Money.fromWire(parseJson(json));

  /// The canonical form of a JSON text.
  String canonicalizeJson(String json) => canonicalizeValue(parseJson(json));

  /// The canonical form of a decoded JSON value.
  String canonicalize(Object? value) => canonicalizeValue(value);

  /// The digest of a JSON text; see [digest].
  String digestJson(String json, {String member = 'digest'}) =>
      digest(parseJson(json), member: member);

  /// `sha256:` and the lowercase hex SHA-256 of the canonical form without the top-level [member] (BC-ID-12, BC-ID-13).
  ///
  /// [member] is `digest`, or `contentDigest` of `payment.observation.v1`.
  String digest(Object? value, {String member = 'digest'}) {
    var subject = value;
    if (value is Map) {
      subject = Map<Object?, Object?>.of(value)..remove(member);
    }
    final hash = sha256.convert(utf8.encode(canonicalizeValue(subject)));
    return 'sha256:$hash';
  }

  /// The digest of a decoded envelope, computed over the object as received (BC-ID-12).
  String digestOf(Envelope envelope) => digest(
    envelope.raw,
    member: envelopeRegistry[envelope.schema]!.variants.first.digestMember,
  );

  /// Decodes a problem object.
  ///
  /// Throws `billium.validation.invalid` when the object breaks the problem schema or its registry entry.
  Problem decodeProblem(String json) {
    final value = parseJson(json);
    if (SchemaValidator(schemas).check(_problemSchema, value) != null ||
        value is! Map) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'not a problem object of the revision',
      );
    }
    final code = ProblemCode.tryParse(value['code']! as String);
    if (code == null ||
        value['category'] != code.category ||
        value['retryable'] != code.retryable ||
        value['sameKey'] != code.sameKey ||
        value['receipt'] != code.receipt) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'the problem members differ from the registry entry',
      );
    }
    final detail = value['detail'];
    final type = value['type'];
    final status = value['status'];
    final instance = value['instance'];
    return Problem(
      code: code,
      title: value['title']! as String,
      detail: detail is String ? detail : null,
      type: type is String ? type : null,
      status: status is int ? status : null,
      instance: instance is String ? instance : null,
    );
  }
}
