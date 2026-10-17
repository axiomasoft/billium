import 'generated/problem_codes.g.dart';
import 'generated/currencies.g.dart';
import 'money.dart';
import 'problem_exception.dart';

const _moneyDocument = 'common/money-wire.json';
const _totalDefinition = 'common/defs.json#/\$defs/operationTotal';
const _ignored = {r'$id', r'$defs', 'then', 'else'};

/// Validates decoded JSON against the schemas of the revision, using the keyword subset they use; any
/// other keyword throws [UnsupportedError], never a silent pass.
///
/// Money nodes and operation totals do not fail the field stage: their violation is deferred so that decode
/// reports it after the other fields (BC-ENV-03, BC-MW-07).
final class SchemaValidator {
  /// Creates a validator over [documents], the schemas by `$id`.
  SchemaValidator(this._documents);

  final Map<String, Object?> _documents;
  ProblemCode? _deferred;

  /// The code decode must raise for [instance], or null when it is valid.
  ProblemCode? check(String documentId, Object? instance) {
    _deferred = null;
    final document = _documents[documentId];
    if (document == null) throw ArgumentError('unknown schema $documentId');
    if (!_valid(instance, _schema(document), documentId, null)) {
      return ProblemCode.validationInvalid;
    }
    return _deferred;
  }

  bool _valid(
    Object? instance,
    Map<String, Object?> schema,
    String document,
    Map<String, Object?>? parent,
  ) {
    for (final MapEntry(key: keyword, value: argument) in schema.entries) {
      if (_ignored.contains(keyword)) continue;
      final ok = switch (keyword) {
        r'$ref' => _reference(instance, argument as String, document, parent),
        'type' => _type(instance, argument),
        'const' => instance == argument,
        'enum' => _list(argument).contains(instance),
        'pattern' =>
          instance is! String || RegExp(argument as String).hasMatch(instance),
        'minLength' =>
          instance is! String || instance.runes.length >= (argument as int),
        'minimum' => instance is! num || instance >= (argument as num),
        'maximum' => instance is! num || instance <= (argument as num),
        'minItems' => instance is! List || instance.length >= (argument as int),
        'items' =>
          instance is! List ||
              instance.every(
                (item) => _valid(item, _schema(argument), document, null),
              ),
        'required' =>
          !_isObject(instance) ||
              _list(argument)
                  .every((name) => (instance as Map).containsKey(name)),
        'properties' =>
          !_isObject(instance) ||
              _properties(
                instance as Map<String, Object?>,
                _schema(argument),
                document,
              ),
        'additionalProperties' =>
          !_isObject(instance) ||
              _additional(
                instance as Map<String, Object?>,
                argument,
                schema,
                document,
              ),
        'minProperties' =>
          !_isObject(instance) || (instance as Map).length >= (argument as int),
        'allOf' => _list(
          argument,
        ).every((item) => _valid(instance, _schema(item), document, parent)),
        'anyOf' => _list(
          argument,
        ).any((item) => _branch(instance, _schema(item), document, parent)),
        'oneOf' => _one(instance, _list(argument), document, parent),
        'not' => !_isolated(instance, _schema(argument), document, parent),
        'if' => _conditional(instance, schema, document, parent),
        _ => throw UnsupportedError('unsupported schema keyword $keyword'),
      };
      if (!ok) return false;
    }
    return true;
  }

  bool _reference(
    Object? instance,
    String reference,
    String document,
    Map<String, Object?>? parent,
  ) {
    final hash = reference.indexOf('#');
    final target = hash < 0 ? reference : reference.substring(0, hash);
    final pointer = hash < 0 ? '' : reference.substring(hash + 1);
    final targetDocument = target.isEmpty ? document : target;
    Object? node = _documents[targetDocument];
    if (node == null) throw ArgumentError('unknown schema $targetDocument');
    for (final part in pointer.split('/').where((part) => part.isNotEmpty)) {
      node = node is Map && node.containsKey(part)
          ? node[part]
          : throw ArgumentError('bad pointer $reference');
    }
    final schema = _schema(node);

    final entersMoney =
        targetDocument.endsWith(_moneyDocument) &&
        !document.endsWith(_moneyDocument);
    if (entersMoney) return _money(instance, schema, targetDocument, parent);
    if ('$targetDocument#$pointer'.endsWith(_totalDefinition)) {
      return _total(instance, parent);
    }
    return _valid(instance, schema, targetDocument, parent);
  }

  bool _money(
    Object? instance,
    Map<String, Object?> schema,
    String document,
    Map<String, Object?>? parent,
  ) {
    try {
      Money.fromWire(instance);
    } on ProblemException catch (error) {
      _deferred ??= error.problem;
      return true;
    }
    return _valid(instance, schema, document, parent);
  }

  /// A cumulative total of payment.outcome: a decimal string with the scale of the sibling `money` currency.
  bool _total(Object? instance, Map<String, Object?>? parent) {
    int? scale;
    final money = parent?['money'];
    if (money is Map && money['currency'] is String) {
      scale = currencyScales[money['currency']];
    }
    final fraction = scale == null
        ? r'(\.[0-9]+)?'
        : (scale == 0 ? '' : '\\.[0-9]{$scale}');
    if (instance is! String ||
        !RegExp('^(0|[1-9][0-9]*)$fraction\$').hasMatch(instance)) {
      _deferred ??= ProblemCode.moneyScaleInvalid;
    }
    return true;
  }

  bool _type(Object? instance, Object? type) {
    for (final name in type is List ? type : [type]) {
      final matches = switch (name) {
        'object' => _isObject(instance),
        'array' => instance is List,
        'string' => instance is String,
        'integer' => instance is int,
        'number' => instance is num,
        'boolean' => instance is bool,
        'null' => instance == null,
        _ => throw UnsupportedError('unsupported schema type'),
      };
      if (matches) return true;
    }
    return false;
  }

  bool _properties(
    Map<String, Object?> instance,
    Map<String, Object?> properties,
    String document,
  ) {
    for (final MapEntry(key: name, value: schema) in properties.entries) {
      if (instance.containsKey(name) &&
          !_valid(instance[name], _schema(schema), document, instance)) {
        return false;
      }
    }
    return true;
  }

  bool _additional(
    Map<String, Object?> instance,
    Object? rule,
    Map<String, Object?> schema,
    String document,
  ) {
    final declared = schema['properties'] is Map
        ? _schema(schema['properties']).keys.toSet()
        : <String>{};
    for (final name in instance.keys) {
      if (declared.contains(name)) continue;
      if (rule == false ||
          rule is Map &&
              !_valid(instance[name], _schema(rule), document, null)) {
        return false;
      }
    }
    return true;
  }

  bool _one(
    Object? instance,
    List<Object?> schemas,
    String document,
    Map<String, Object?>? parent,
  ) {
    var valid = 0;
    final kept = _deferred;
    ProblemCode? deferred;
    for (final schema in schemas) {
      _deferred = kept;
      if (_branch(instance, _schema(schema), document, parent)) {
        valid++;
        deferred = _deferred;
      }
    }
    _deferred = valid == 1 ? deferred : kept;
    return valid == 1;
  }

  bool _conditional(
    Object? instance,
    Map<String, Object?> schema,
    String document,
    Map<String, Object?>? parent,
  ) {
    final branch = _isolated(instance, _schema(schema['if']), document, parent)
        ? 'then'
        : 'else';
    if (!schema.containsKey(branch)) return true;
    return _valid(instance, _schema(schema[branch]), document, parent);
  }

  /// A branch whose deferred money violation survives only when the branch is valid.
  bool _branch(
    Object? instance,
    Map<String, Object?> schema,
    String document,
    Map<String, Object?>? parent,
  ) {
    final kept = _deferred;
    final ok = _valid(instance, schema, document, parent);
    if (!ok) _deferred = kept;
    return ok;
  }

  /// A condition evaluated without leaving a deferred violation behind.
  bool _isolated(
    Object? instance,
    Map<String, Object?> schema,
    String document,
    Map<String, Object?>? parent,
  ) {
    final kept = _deferred;
    final ok = _valid(instance, schema, document, parent);
    _deferred = kept;
    return ok;
  }

  bool _isObject(Object? value) => value is Map;

  Map<String, Object?> _schema(Object? node) {
    if (node is! Map) throw ArgumentError('a schema must be an object');
    return node.cast();
  }

  List<Object?> _list(Object? node) {
    if (node is! List) throw ArgumentError('a schema keyword expects a list');
    return node;
  }
}
