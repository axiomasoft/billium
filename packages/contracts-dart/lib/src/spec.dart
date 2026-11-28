import 'dart:convert';

import 'generated/problem_codes.g.dart';
import 'generated/spec_data.g.dart';
import 'problem_exception.dart';

/// Every schema of the default revision by `$id`.
final Map<String, Object?> schemas = schemasByRevision[specRevision]!;

/// Envelope registry of the default revision by schema name.
final Map<String, EnvelopeEntry> envelopeRegistry =
    envelopeRegistryByRevision[specRevision]!;

/// Every schema by spec revision and `$id`.
final Map<String, Map<String, Object?>> schemasByRevision = {
  for (final entry in schemasJsonByRevision.entries)
    entry.key: _object(jsonDecode(entry.value)),
};

/// Envelope registry by spec revision and schema name.
final Map<String, Map<String, EnvelopeEntry>> envelopeRegistryByRevision = {
  for (final entry in envelopeRegistryJsonByRevision.entries)
    entry.key: {
      for (final regEntry in _object(jsonDecode(entry.value)).entries)
        regEntry.key: EnvelopeEntry._(_object(regEntry.value)),
    },
};

/// Returns the schema map for [revision] or throws `billium.schema.unsupported`.
Map<String, Object?> schemasForRevision(String revision) {
  final found = schemasByRevision[revision];
  if (found == null) {
    throw ProblemException(
      ProblemCode.schemaUnsupported,
      "spec revision '$revision' is not supported",
    );
  }
  return found;
}

/// Returns the envelope registry for [revision] or throws `billium.schema.unsupported`.
Map<String, EnvelopeEntry> envelopeRegistryForRevision(String revision) {
  final found = envelopeRegistryByRevision[revision];
  if (found == null) {
    throw ProblemException(
      ProblemCode.schemaUnsupported,
      "spec revision '$revision' is not supported",
    );
  }
  return found;
}

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
