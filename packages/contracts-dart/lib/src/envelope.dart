/// A validated envelope of the spec revision.
///
/// [raw] is the received JSON object (unknown optional members of open envelopes included); treat it as
/// read-only. The typed members come from the generated subclasses.
abstract base class Envelope {
  /// Creates an envelope over [raw] with its [schema] name.
  Envelope({required this.raw, required this.schema});

  /// The received object.
  final Map<String, Object?> raw;

  /// The schema name, for example `payment.execute.v1`.
  final String schema;
}
