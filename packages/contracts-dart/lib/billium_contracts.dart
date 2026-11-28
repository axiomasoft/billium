/// Billium contracts v1: client-side types and codecs.
///
/// Dart is a contracts client; it never writes money state (REQ-OWN-18).
library;

export 'src/capabilities_negotiator.dart'
    show CapabilitiesNegotiator, CapabilityCompatibility;
export 'src/codec.dart' show Codec;
export 'src/envelope.dart' show Envelope;
export 'src/generated/envelopes.g.dart' hide envelopeBuilders;
export 'src/generated/problem_codes.g.dart' show ProblemCode;
export 'src/generated/spec_data.g.dart'
    show specRevision, specRevisionHashes, supportedSpecRevisions;
export 'src/money.dart' show Currency, Money;
export 'src/money_math.dart' show MoneyMath;
export 'src/problem.dart' show Problem;
export 'src/problem_exception.dart' show ProblemException;
export 'src/rounding_mode.dart' show RoundingMode;
