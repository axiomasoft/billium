import 'dart:convert';

import 'package:billium_contracts/billium_contracts.dart';

const _statusKeys = {
  'schema',
  'installationRef',
  'environment',
  'sellerRef',
  'result',
  'digest',
};

/// One encoded command. Retries reuse these bytes and this key (D58).
final class PreparedCommand {
  /// Creates the frozen command.
  const PreparedCommand({
    required this.bytes,
    required this.digest,
    required this.commandKey,
    required this.operationRef,
    required this.schema,
    required this.routingEpoch,
    required this.path,
  });

  /// Canonical request bytes.
  final String bytes;

  /// Digest of those bytes minus the digest member.
  final String digest;

  /// Caller command key. Null when the envelope has none.
  final String? commandKey;

  /// Operation reference. Null when the envelope has none.
  final String? operationRef;

  /// Envelope schema name.
  final String schema;

  /// Routing epoch frozen in the bytes. Null when the envelope has none.
  final int? routingEpoch;

  /// Request path. It is not part of the signed body.
  final String path;

  /// Wire view compared across languages. No class names.
  Map<String, Object?> export() => {
        'bytes': bytes,
        'digest': digest,
        'commandKey': commandKey,
        'operationRef': operationRef,
        'schema': schema,
        'routingEpoch': routingEpoch,
      };
}

/// Test-only transport client. It encodes once and never writes money (D9, D58, D61).
///
/// Default is one send. A later send exists only for an explicit bound: one 401
/// credential refresh, or a registry entry that is retryable and sameKey. Deadline
/// and caller cancellation return an unresolved handle and do not send `payment.cancel.v1`.
final class ConformanceClient {
  /// Runs one client vector.
  Map<String, Object?> execute(Map<String, Object?> case_) {
    final supported = case_['supportedSchemas'];
    final codec = Codec(
      supportedSchemas: supported is List
          ? [for (final item in supported) if (item is String) item]
          : null,
    );
    final op = case_['op'];
    final name = case_['id'];
    final id = name is String ? name : (op is String ? op : '');
    if (op == 'prepare' || op == 'send') {
      return _transport(codec, case_, id, prepareOnly: op == 'prepare');
    }
    if (op == 'project-status') return _project(codec, case_, id);
    if (op == 'feed-page') return _feed(codec, case_, id);
    if (op == 'capabilities-refresh') return _capabilities(codec, case_, id);
    if (op == 'prepare-pair') return _pair(codec, case_, id);
    return _failure(id, ProblemCode.validationInvalid);
  }

  Map<String, Object?> _transport(
    Codec codec,
    Map<String, Object?> case_,
    String id, {
    required bool prepareOnly,
  }) {
    final PreparedCommand prepared;
    try {
      prepared = _prepare(codec, _text(case_), _seal(case_), _path(case_));
    } on ProblemException catch (error) {
      return _failure(id, error.problem);
    }
    if (prepareOnly) {
      return _finalize(_base(id, 'prepared'), prepared, const []);
    }

    final script = [
      for (final item in _list(case_['transport']))
        if (item is Map) Map<String, Object?>.from(item),
    ];
    final maxAttempts = case_['maxAttempts'] is int
        ? case_['maxAttempts']! as int
        : 1;
    final deadlineMs =
        case_['deadlineMs'] is int ? case_['deadlineMs']! as int : 15000;
    final tokens = [
      for (final item in _list(case_['tokens']))
        if (item is String) item,
    ];
    final usable = tokens.isEmpty ? ['owner', 'refreshed'] : tokens;
    var tokenIndex = 0;
    var authRefreshed = false;
    var waited = 0;
    final attempts = <Map<String, Object?>>[];
    var cursor = 0;

    while (attempts.length < maxAttempts) {
      final authorization = 'Bearer ${usable[tokenIndex]}';
      final response = cursor < script.length
          ? script[cursor++]
          : <String, Object?>{'transport': 'closed'};
      attempts.add(_attempt(prepared, authorization));
      final transport = response['transport'];
      if (transport == 'deadline' ||
          transport == 'cancelled-after-send' ||
          transport == 'closed') {
        return _unresolved(id, prepared, attempts, waited);
      }
      final status = response['status'];
      final code = status is int ? status : 0;
      if (code == 401 && !authRefreshed && attempts.length < maxAttempts) {
        authRefreshed = true;
        if (tokenIndex + 1 < usable.length) tokenIndex++;
        continue;
      }
      final headers = response['headers'];
      final headerMap = headers is Map
          ? Map<Object?, Object?>.from(headers)
          : <Object?, Object?>{};
      final body = response['body'];
      final text = body is String ? body : '';
      if (code >= 400) {
        final problem = _problem(codec, text);
        final retryAfter = _retryAfterMs(headerMap);
        final fits = problem.retryable &&
            problem.sameKey &&
            waited + retryAfter <= deadlineMs;
        if (fits && attempts.length < maxAttempts) {
          waited += retryAfter;
          continue;
        }
        return _finalize(
          _base(id, 'refused', {
            'error': {'code': problem.code, 'receipt': problem.receipt},
            'waitedMs': waited,
          }),
          prepared,
          attempts,
        );
      }
      if (code >= 200 && code < 300) {
        if (!_jsonObject(text)) {
          return _finalize(
            _base(id, 'refused', {
              'error': {
                'code': ProblemCode.validationInvalid.value,
                'receipt': 'none',
              },
              'waitedMs': waited,
            }),
            prepared,
            attempts,
          );
        }
        return _finalize(
          _base(id, 'accepted', {'waitedMs': waited}),
          prepared,
          attempts,
        );
      }
      return _finalize(
        _base(id, 'refused', {
          'error': {
            'code': ProblemCode.validationInvalid.value,
            'receipt': 'none',
          },
          'waitedMs': waited,
        }),
        prepared,
        attempts,
      );
    }
    return _unresolved(id, prepared, attempts, waited);
  }

  Map<String, Object?> _project(
    Codec codec,
    Map<String, Object?> case_,
    String id,
  ) {
    final text = _text(case_);
    final Map<String, Object?> raw;
    try {
      raw = _object(jsonDecode(text) as Object?);
    } on Object {
      return _failure(id, ProblemCode.validationInvalid);
    }
    final full = codec.digest(raw);
    final unknown = _unknownKeys(raw);
    final whitelist = _whitelistDigest(codec, raw);
    final covers = unknown.isNotEmpty && full != whitelist;
    final meta = <String, Object?>{
      'fullDigest': full,
      'whitelistDigest': whitelist,
      'preservedUnknown': unknown,
      'digestCoversUnknown': covers,
    };
    if (raw.containsKey('digest') && raw['digest'] != full) {
      return _base(id, 'refused', {
        ...meta,
        'error': {
          'code': ProblemCode.validationInvalid.value,
          'receipt': 'none',
        },
      });
    }
    try {
      codec.decode(text);
    } on ProblemException catch (error) {
      return _base(id, 'refused', {
        ...meta,
        'error': {'code': error.problem.value, 'receipt': error.problem.receipt},
      });
    }
    return _base(id, 'accepted', {...meta, 'totals': _totals(raw)});
  }

  Map<String, Object?> _feed(
    Codec codec,
    Map<String, Object?> case_,
    String id,
  ) {
    final Envelope envelope;
    try {
      envelope = codec.decode(_text(case_));
    } on ProblemException catch (error) {
      return _failure(id, error.problem);
    }
    final events = envelope.raw['events'];
    final schemas = <String>[];
    if (events is List) {
      for (final event in events) {
        if (event is Map) {
          final schema = event['schema'];
          if (schema is String) schemas.add(schema);
        }
      }
    }
    final cursor = envelope.raw['nextCursor'];
    return _base(id, 'accepted', {
      'cursor': cursor is String ? cursor : null,
      'persisted': true,
      'eventCount': events is List ? events.length : 0,
      'preservedUnknown': schemas,
    });
  }

  Map<String, Object?> _capabilities(
    Codec codec,
    Map<String, Object?> case_,
    String id,
  ) {
    final PreparedCommand prepared;
    try {
      prepared = _prepare(codec, _text(case_), null, _path(case_));
    } on ProblemException catch (error) {
      return _failure(id, error.problem);
    }
    final replacement = case_['replacement'];
    final schemas = replacement is Map ? replacement['acceptedCommandSchemas'] : null;
    final accepted = schemas is List
        ? [for (final item in schemas) if (item is String) item]
        : <String>[];
    if (!accepted.contains(prepared.schema)) {
      return _finalize(
        _base(id, 'recovery', {
          'error': {
            'code': ProblemCode.schemaUnsupported.value,
            'receipt': ProblemCode.schemaUnsupported.receipt,
          },
        }),
        prepared,
        const [],
      );
    }
    return _finalize(_base(id, 'retained'), prepared, const []);
  }

  Map<String, Object?> _pair(
    Codec codec,
    Map<String, Object?> case_,
    String id,
  ) {
    final leftText = case_['leftText'];
    final rightText = case_['rightText'];
    final PreparedCommand left;
    final PreparedCommand right;
    try {
      left = _prepare(
        codec,
        leftText is String ? leftText : '',
        _seal(case_),
        _path(case_),
      );
      right = _prepare(
        codec,
        rightText is String ? rightText : '',
        _seal(case_),
        _path(case_),
      );
    } on ProblemException catch (error) {
      return _failure(id, error.problem);
    }
    final leftValue = _object(jsonDecode(left.bytes) as Object?);
    final rightValue = _object(jsonDecode(right.bytes) as Object?);
    return _finalize(
      _base(id, 'prepared', {
        'isolated': left.bytes != right.bytes,
        'authorities': [
          leftValue['sourceAuthority'],
          rightValue['sourceAuthority'],
        ],
        'preservedUnknown': [
          leftValue['eventId'] is String ? leftValue['eventId'] : '',
          rightValue['eventId'] is String ? rightValue['eventId'] : '',
        ],
      }),
      left,
      const [],
    );
  }

  PreparedCommand _prepare(Codec codec, String text, String? seal, String path) {
    var wire = text;
    if (seal != null) {
      final raw = _object(jsonDecode(text) as Object?);
      raw.remove(seal);
      raw[seal] = codec.digest(raw, member: seal);
      wire = jsonEncode(raw);
    }
    final envelope = codec.decode(wire);
    final epoch = envelope.raw['routingEpoch'];
    final commandKey = envelope.raw['commandKey'];
    final operationRef = envelope.raw['operationRef'];
    return PreparedCommand(
      bytes: codec.encode(envelope),
      digest: codec.digestOf(envelope),
      commandKey: commandKey is String ? commandKey : null,
      operationRef: operationRef is String ? operationRef : null,
      schema: envelope.schema,
      routingEpoch: epoch is int ? epoch : null,
      path: path,
    );
  }

  int _retryAfterMs(Map<Object?, Object?> headers) {
    final value = headers['retry-after'] ?? headers['Retry-After'] ?? '0';
    if (value is int) return value * 1000;
    if (value is String && RegExp(r'^(0|[1-9][0-9]*)$').hasMatch(value)) {
      return int.parse(value) * 1000;
    }
    return 0;
  }

  _ProblemView _problem(Codec codec, String body) {
    try {
      final problem = codec.decodeProblem(body);
      return _ProblemView(
        problem.code.value,
        problem.code.receipt,
        problem.code.retryable,
        problem.code.sameKey,
      );
    } on ProblemException {
      return _ProblemView(
        ProblemCode.validationInvalid.value,
        'none',
        false,
        false,
      );
    }
  }

  Map<String, Object?> _totals(Map<String, Object?> raw) {
    final result = raw['result'];
    final node = result is Map ? Map<String, Object?>.from(result) : <String, Object?>{};
    final applied = node['applied'];
    final observed = node['observed'];
    final exposure = node['exposure'];
    final hold = node['financialHold'];
    final reconciliation = node['reconciliation'];
    return {
      'applied': applied is Map
          ? {
              'capturedTotal': _amount(applied, 'capturedTotal'),
              'refundedTotal': _amount(applied, 'refundedTotal'),
            }
          : null,
      'observed': observed is Map
          ? {
              'capturedTotal': _amount(observed, 'capturedTotal'),
              'refundedTotal': _amount(observed, 'refundedTotal'),
            }
          : null,
      'exposure': exposure is Map
          ? {
              'currency': exposure['currency'] is String ? exposure['currency'] : null,
              'overCaptured': _amount(exposure, 'overCaptured'),
              'overRefunded': _amount(exposure, 'overRefunded'),
            }
          : null,
      'financialHold': {
        'active': hold is Map && hold['active'] == true,
      },
      'spendable': _amount(node, 'spendable'),
      'projectionStatus':
          node['projectionStatus'] is String ? node['projectionStatus'] : null,
      'pendingAllocationReason': node['pendingAllocationReason'] is String
          ? node['pendingAllocationReason']
          : null,
      'reconciliation': {
        'status': reconciliation is Map && reconciliation['status'] is String
            ? reconciliation['status']
            : null,
      },
    };
  }

  String? _amount(Map<dynamic, dynamic> node, String key) {
    final money = node[key];
    if (money is! Map) return null;
    final amount = money['amount'];
    return amount is String ? amount : null;
  }

  List<String> _unknownKeys(Map<String, Object?> raw) {
    final keys = [
      for (final key in raw.keys)
        if (!_statusKeys.contains(key)) key,
    ]..sort();
    return keys;
  }

  String _whitelistDigest(Codec codec, Map<String, Object?> raw) {
    final copy = Map<String, Object?>.from(raw);
    copy.removeWhere((key, _) => !_statusKeys.contains(key));
    copy.remove('digest');
    return codec.digest(copy);
  }

  Map<String, Object?> _attempt(PreparedCommand prepared, String authorization) {
    final parsed = jsonDecode(prepared.bytes) as Object?;
    final key = parsed is Map && parsed['commandKey'] is String
        ? parsed['commandKey'] as String
        : null;
    return {
      'path': prepared.path,
      'body': prepared.bytes,
      'authorization': authorization,
      'commandKey': key,
    };
  }

  Map<String, Object?> _unresolved(
    String id,
    PreparedCommand prepared,
    List<Map<String, Object?>> attempts,
    int waited,
  ) {
    return _finalize(
      _base(id, 'unresolved', {
        'error': {
          'code': ProblemCode.transportUnavailable.value,
          'receipt': ProblemCode.transportUnavailable.receipt,
        },
        'waitedMs': waited,
        'recovery': {
          'commandKey': prepared.commandKey,
          'operationRef': prepared.operationRef,
          'digest': prepared.digest,
          'selectors': ['commandKey', 'operationRef'],
        },
      }),
      prepared,
      attempts,
    );
  }

  Map<String, Object?> _failure(String id, ProblemCode code) => _base(id, 'refused', {
        'error': {'code': code.value, 'receipt': code.receipt},
      });

  Map<String, Object?> _base(
    String id,
    String outcome, [
    Map<String, Object?> extra = const {},
  ]) {
    return {
      'id': id,
      'outcome': outcome,
      'error': null,
      'cancelInvoked': false,
      'newKey': false,
      'rebuilt': false,
      'sameBytes': true,
      'attempts': <Object?>[],
      'authorizations': <Object?>[],
      'waitedMs': 0,
      'prepared': null,
      'totals': null,
      'cursor': null,
      'persisted': false,
      'eventCount': null,
      'preservedUnknown': <Object?>[],
      'fullDigest': null,
      'whitelistDigest': null,
      'digestCoversUnknown': false,
      'routingEpoch': null,
      'recovery': null,
      'isolated': null,
      'authorities': <Object?>[],
      'evidence_class': 'transport-contract',
      ...extra,
    };
  }

  Map<String, Object?> _finalize(
    Map<String, Object?> result,
    PreparedCommand prepared,
    List<Map<String, Object?>> attempts,
  ) {
    final same = attempts.every(
      (attempt) =>
          attempt['body'] == prepared.bytes && attempt['path'] == prepared.path,
    );
    final cancel = attempts.any(
      (attempt) =>
          (attempt['path'] as String).contains('/cancel') ||
          (attempt['body'] as String).contains('payment.cancel.v1'),
    );
    final newKey = attempts.any(
      (attempt) =>
          prepared.commandKey != null &&
          attempt['commandKey'] != prepared.commandKey,
    );
    return {
      ...result,
      'prepared': prepared.export(),
      'attempts': attempts,
      'authorizations': [
        for (final attempt in attempts) attempt['authorization'],
      ],
      'routingEpoch': prepared.routingEpoch,
      'sameBytes': attempts.isEmpty || same,
      'rebuilt': attempts.isNotEmpty && !same,
      'newKey': newKey,
      'cancelInvoked': cancel,
    };
  }

  String _text(Map<String, Object?> case_) {
    final text = case_['jsonText'];
    if (text is String) return text;
    throw const ProblemException(
      ProblemCode.validationInvalid,
      'jsonText is required',
    );
  }

  String? _seal(Map<String, Object?> case_) {
    final seal = case_['seal'];
    return seal is String ? seal : null;
  }

  String _path(Map<String, Object?> case_) {
    final path = case_['path'];
    return path is String ? path : '/billium/v1/sellers/seller-a/payments/execute';
  }

  bool _jsonObject(String text) {
    try {
      return jsonDecode(text) is Map;
    } on Object {
      return false;
    }
  }

  Map<String, Object?> _object(Object? value) {
    if (value is! Map) {
      throw const ProblemException(
        ProblemCode.validationInvalid,
        'envelope must be an object',
      );
    }
    return Map<String, Object?>.from(value);
  }

  List<Object?> _list(Object? value) => value is List ? value : const [];
}

final class _ProblemView {
  const _ProblemView(this.code, this.receipt, this.retryable, this.sameKey);

  final String code;
  final String receipt;
  final bool retryable;
  final bool sameKey;
}
