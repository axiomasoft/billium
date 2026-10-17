import 'dart:convert';

import 'generated/spec_data.g.dart';

/// Every schema of the revision by `$id`.
final Map<String, Object?> schemas = _object(jsonDecode(schemasJson));

/// Envelope registry by schema name: the schema `$id` and the ordered variants that pick a class.
final Map<String, EnvelopeEntry> envelopeRegistry = {
  for (final entry in _object(jsonDecode(envelopeRegistryJson)).entries)
    entry.key: EnvelopeEntry._(_object(entry.value)),
};

/// One schema of the envelope registry.
final class EnvelopeEntry {
  EnvelopeEntry._(Map<String, Object?> data)
    : schemaId = data['schemaId']! as String,
      variants = [
        for (final variant in data['variants']! as List<Object?>)
          EnvelopeVariant._(_object(variant)),
      ];

  /// The `$id` of the schema document.
  final String schemaId;

  /// Candidates in order: the first whose `requires` member is present (or has none) wins.
  final List<EnvelopeVariant> variants;
}

/// An envelope class a schema can decode into.
final class EnvelopeVariant {
  EnvelopeVariant._(Map<String, Object?> data)
    : className = data['class']! as String,
      digestMember = data['digestMember']! as String,
      verifyDigest = data['verifyDigest']! as bool,
      requires = data['requires'] as String?;

  /// The generated class name.
  final String className;

  /// The member left out of the digest: `digest`, or `contentDigest` of the observation.
  final String digestMember;

  /// Whether a carried digest must equal the computed one (closed command envelopes).
  final bool verifyDigest;

  /// A member whose presence selects this variant.
  final String? requires;
}

Map<String, Object?> _object(Object? value) => (value! as Map).cast();
