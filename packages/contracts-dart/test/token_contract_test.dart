import 'package:billium_contracts/billium_contracts.dart';
import 'package:test/test.dart';

void main() {
  final codec202612 = Codec.forRevision('2026-12');
  final codec202610 = Codec.forRevision('2026-10');

  Map<String, Object?> attribution() => {
    'customerRef': 'cust-1',
    'billingAccountRef': 'ba-1',
    'subjectRef': 'sub-1',
    'componentRef': 'comp-1',
    'agreementRef': 'agr-1',
    'agreementRevision': 1,
    'attributionVersion': 'host_snapshot/1',
    'effectiveAt': '2026-12-01T00:00:00Z',
  };

  Map<String, Object?> stream() => {
    'mode': 'normalized_net_billable',
    'policy': 'exclusive_commercial_basis/1',
    'commercialBasisRef': 'cb-1',
    'normalizationRef': 'norm-1',
  };

  Map<String, Object?> period() => {
    'schema': 'period.commercial.v1',
    'periodRef': 'per-1',
    'sellerRef': 'sel-1',
    'componentRef': 'comp-1',
    'agreementRef': 'agr-1',
    'agreementRevision': 1,
    'scheduleRevision': 1,
    'interval': {
      'start': '2026-12-01T00:00:00Z',
      'end': '2027-01-01T00:00:00Z',
    },
    'localStart': '2026-12-01T00:00:00',
    'localEnd': '2027-01-01T00:00:00',
    'timezone': 'UTC',
    'tzdbVersion': '2025a',
    'frequency': 'monthly',
    'anchorDay': 1,
    'shortMonthPolicy': 'clamp_preserve_anchor',
    'dstGapPolicy': 'shift_forward',
    'dstFoldPolicy': 'earlier_offset',
    'chargeTiming': 'arrears',
    'digest': 'sha256:${'a' * 64}',
  };

  String withDigest(Codec codec, Map<String, Object?> data) {
    final copy = Map<String, Object?>.of(data)..remove('digest');
    copy['digest'] = codec.digest(copy);
    return codec.canonicalize(copy);
  }

  Map<String, Object?> usageFactV2({
    required String unit,
    required String quantity,
  }) => {
    'schema': 'usage.fact.v2',
    'sellerRef': 'sel-1',
    'source': 'src-1',
    'factId': 'fact-1',
    'revision': 1,
    'metricRef': 'tokens_used',
    'metricVersion': 'v1',
    'metricDigest': 'sha256:${'b' * 64}',
    'unit': unit,
    'attribution': attribution(),
    'interval': {
      'start': '2026-12-01T00:00:00Z',
      'end': '2026-12-01T01:00:00Z',
    },
    'eventAt': '2026-12-01T00:30:00Z',
    'receivedAt': '2026-12-01T00:30:01Z',
    'value': {'kind': 'decimal', 'quantity': quantity},
    'grouping': <Object?>[],
    'billableStream': stream(),
    'provenance': {
      'collector': 'col-1',
      'version': '1',
      'sourceRef': 'sref-1',
      'sourceDigest': 'sha256:${'c' * 64}',
    },
  };

  Map<String, Object?> ratingPricePerUnitV2({
    required String numerator,
    required String denominator,
    required int quantityScale,
  }) => {
    'schema': 'rating.price.v1',
    'sellerRef': 'sel-1',
    'priceVersionRef': 'pv-1',
    'componentRef': 'comp-1',
    'effectiveInterval': {
      'start': '2026-12-01T00:00:00Z',
      'end': '2027-01-01T00:00:00Z',
    },
    'unit': 'token',
    'quantityPrecision': 'decimal_24_9/1',
    'amountPrecision': 'decimal_24_12/1',
    'currency': 'USD',
    'currencyExponentVersion': 'ISO4217-2026-10',
    'strategy': {
      'id': 'per_unit',
      'version': 2,
      'unitPrice': '0.002',
      'quantityConversion': {
        'numerator': numerator,
        'denominator': denominator,
        'quantityScale': quantityScale,
      },
    },
    'commitment': {
      'policy': 'same_component_period/1',
      'scope': 'seller_customer_component_period',
      'minimumMinor': '0',
      'maximumMinor': '9223372036854775807',
    },
    'modifierOrder': 'strategy_bounds_tax_round/1',
    'rounding': {
      'policy': 'named_boundaries/1',
      'mode': 'HALF_EVEN',
      'boundaries': 'subtotal_then_bounds_then_tax',
    },
    'taxSnapshot': {
      'snapshotRef': 'host-tax-1',
      'snapshotDigest': 'sha256:${'0' * 64}',
      'policyVersion': 'host-approved-tax/1',
      'correctionPolicyVersion': 'host-approved-correction/1',
      'mode': 'exclusive',
      'rate': '0.20',
    },
    'chargeTiming': 'arrears',
    'quantityBasis': 'aggregate',
    'billableStream': stream(),
    'allowancePolicy': 'none/1',
    'commercialPolicyRef': 'cp-1',
  };

  group('Multi-revision Codec & supportedUnits', () {
    test('exposes 2026-10 and 2026-12 units accurately', () {
      expect(const Codec().specRevision, '2026-12');
      expect(codec202610.supportedUnits(), [
        'call',
        'seat',
        'byte',
        'member',
        'second',
        'event',
      ]);
      expect(codec202612.supportedUnits(), [
        'call',
        'seat',
        'byte',
        'member',
        'second',
        'event',
        'token',
      ]);
    });

    test('refuses unknown spec revision with schemaUnsupported', () {
      final unknown = Codec.forRevision('2099-01');
      expect(
        () => unknown.supportedUnits(),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.schemaUnsupported,
          ),
        ),
      );
    });
  });

  group('BC-ENV-45: token integer quantity rule', () {
    test(
      'accepts integer token quantity in 2026-12 and refuses in 2026-10',
      () {
        final factJson = withDigest(
          codec202612,
          usageFactV2(unit: 'token', quantity: '1250'),
        );
        expect(codec202612.decode(factJson).schema, 'usage.fact.v2');
        expect(
          () => codec202610.decode(factJson),
          throwsA(
            isA<ProblemException>().having(
              (e) => e.problem,
              'code',
              ProblemCode.validationInvalid,
            ),
          ),
        );

        final trailingZeroJson = withDigest(
          codec202612,
          usageFactV2(unit: 'token', quantity: '1250.000'),
        );
        expect(codec202612.decode(trailingZeroJson).schema, 'usage.fact.v2');
      },
    );

    test('rejects fractional token quantity on usage.fact.v2, usage.quantity.v1, usage.aggregate.v1, rating.basis.v1', () {
      final factFrac = withDigest(
        codec202612,
        usageFactV2(unit: 'token', quantity: '12.5'),
      );
      expect(
        () => codec202612.decode(factFrac),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.validationInvalid,
          ),
        ),
      );

      final qtyFrac = withDigest(codec202612, {
        'schema': 'usage.quantity.v1',
        'snapshotRef': 'snap-1',
        'sellerRef': 'sel-1',
        'attribution': attribution(),
        'unit': 'token',
        'quantity': '1.25',
        'effectiveRevision': 1,
        'effectiveInterval': {
          'start': '2026-12-01T00:00:00Z',
          'end': '2027-01-01T00:00:00Z',
        },
        'approvedBy': 'host-1',
        'approvalDigest': 'sha256:${'d' * 64}',
        'sourceDigest': 'sha256:${'e' * 64}',
        'prorationPolicy': 'whole_interval/1',
      });
      expect(
        () => codec202612.decode(qtyFrac),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.validationInvalid,
          ),
        ),
      );

      final aggFrac = withDigest(codec202612, {
        'schema': 'usage.aggregate.v1',
        'snapshotRef': 'snap-1',
        'sellerRef': 'sel-1',
        'metricRef': 'tokens_used',
        'metricVersion': 'v1',
        'metricDigest': 'sha256:${'b' * 64}',
        'unit': 'token',
        'attribution': attribution(),
        'grouping': <Object?>[],
        'window': {
          'start': '2026-12-01T00:00:00Z',
          'end': '2027-01-01T00:00:00Z',
        },
        'effectiveRevision': 1,
        'sourceFrontier': [
          {
            'source': 'src-1',
            'lastFactId': 'fact-1',
            'lastRevision': 1,
            'lastReceivedAt': '2026-12-01T00:30:01Z',
          },
        ],
        'completeness': {
          'status': 'final',
          'watermark': '2027-01-01T00:00:00Z',
          'latePolicy': 'recompute_via_linked_correction/1',
        },
        'sourceDigest': 'sha256:${'e' * 64}',
        'model': {'id': 'additive_sum', 'version': 1, 'reducer': 'sum'},
        'quantity': '0.5',
        'billableStream': stream(),
      });
      expect(
        () => codec202612.decode(aggFrac),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.validationInvalid,
          ),
        ),
      );

      final basisFrac = withDigest(codec202612, {
        'schema': 'rating.basis.v1',
        'ratedBasisRef': 'rb-1',
        'sellerRef': 'sel-1',
        'attribution': attribution(),
        'billingOccurrenceRef': 'occ-1',
        'period': period(),
        'priceVersionRef': 'pv-1',
        'priceDigest': 'sha256:${'f' * 64}',
        'quantityEvidence': {
          'kind': 'aggregate',
          'snapshotRef': 'snap-1',
          'snapshotDigest': 'sha256:${'1' * 64}',
          'effectiveRevision': 1,
          'sourceDigest': 'sha256:${'2' * 64}',
        },
        'quantity': '99.9',
        'unit': 'token',
        'billableStream': stream(),
        'commitmentScopeKey': 'csk-1',
        'taxSnapshot': {
          'snapshotRef': 'host-tax-1',
          'snapshotDigest': 'sha256:${'0' * 64}',
          'policyVersion': 'host-approved-tax/1',
          'correctionPolicyVersion': 'host-approved-correction/1',
          'mode': 'exclusive',
          'rate': '0.20',
        },
        'currencyExponentVersion': 'ISO4217-2026-10',
        'rounding': {
          'policy': 'named_boundaries/1',
          'mode': 'HALF_EVEN',
          'boundaries': 'subtotal_then_bounds_then_tax',
        },
        'amount': {'amount': '1.00', 'currency': 'USD'},
      });
      expect(
        () => codec202612.decode(basisFrac),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.validationInvalid,
          ),
        ),
      );
    });

    test(
      'legacy usage.fact.v1 preserves 2026-10 semantics in both revisions',
      () {
        final legacyV1 = withDigest(codec202610, {
          'schema': 'usage.fact.v1',
          'source': 'src-1',
          'factId': 'fact-1',
          'revision': 1,
          'sellerRef': 'sel-1',
          'subjectRef': 'sub-1',
          'metricRef': 'api_calls',
          'metricVersion': 'v1',
          'semantics': 'additive',
          'unit': 'call',
          'quantity': '1.5',
          'interval': {
            'start': '2026-10-01T00:00:00Z',
            'end': '2026-10-01T01:00:00Z',
          },
          'eventAt': '2026-10-01T00:30:00Z',
          'observedAt': '2026-10-01T00:30:01Z',
          'provenance': {
            'collector': 'col-1',
            'version': '1',
            'sourceRef': 'sref-1',
          },
        });
        expect(codec202610.decode(legacyV1).schema, 'usage.fact.v1');
        expect(codec202612.decode(legacyV1).schema, 'usage.fact.v1');
      },
    );
  });

  group('BC-ENV-46: per_unit@2 exact quantityConversion', () {
    test('accepts exact conversion in 2026-12 and refuses in 2026-10', () {
      final priceJson = withDigest(
        codec202612,
        ratingPricePerUnitV2(
          numerator: '1',
          denominator: '1000',
          quantityScale: 3,
        ),
      );
      expect(codec202612.decode(priceJson).schema, 'rating.price.v1');
      expect(
        () => codec202610.decode(priceJson),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.validationInvalid,
          ),
        ),
      );
    });

    test('refuses inexact conversion in 2026-12 with validationInvalid', () {
      final inexactJson = withDigest(
        codec202612,
        ratingPricePerUnitV2(
          numerator: '1',
          denominator: '3',
          quantityScale: 3,
        ),
      );
      expect(
        () => codec202612.decode(inexactJson),
        throwsA(
          isA<ProblemException>().having(
            (e) => e.problem,
            'code',
            ProblemCode.validationInvalid,
          ),
        ),
      );
    });
  });

  group('CapabilitiesNegotiator', () {
    const negotiator = CapabilitiesNegotiator();

    Map<String, Object?> validCapabilitiesV2() => {
      'schema': 'billium.capabilities.v2',
      'supportedSpecRevisions': [
        {'revision': '2026-10', 'specHash': specRevisionHashes['2026-10']},
        {'revision': '2026-12', 'specHash': specRevisionHashes['2026-12']},
      ],
      'supportedUnits': ['call', 'token'],
      'usageIngressSchemas': ['usage.fact.v1', 'usage.fact.v2'],
      'ratingStrategyVersions': ['per_unit@1', 'per_unit@2'],
      'enabledModules': [
        {'module': 'usage', 'apiVersion': '1'},
        {'module': 'rating', 'apiVersion': '1'},
        {'module': 'schedule', 'apiVersion': '1'},
        {'module': 'ledger', 'apiVersion': '1'},
      ],
    };

    test('passes when all token overage requirements are met', () {
      final result = negotiator.checkTokenOverage(
        capabilitiesPayload: validCapabilitiesV2(),
        expectedSpecHash: specRevisionHashes['2026-12'],
      );
      expect(result.compatible, isTrue);
      expect(result.reasons, isEmpty);
    });

    test('fails closed on v1 capabilities or missing requirements', () {
      final v1Result = negotiator.checkTokenOverage(
        capabilitiesPayload: {'schema': 'billium.capabilities.v1'},
      );
      expect(v1Result.compatible, isFalse);
      expect(
        v1Result.reasons,
        contains('server_capabilities_v2_unavailable:billium.capabilities.v1'),
      );

      final missingModules = validCapabilitiesV2()
        ..['supportedUnits'] = ['call']
        ..['usageIngressSchemas'] = ['usage.fact.v1']
        ..['ratingStrategyVersions'] = ['per_unit@1']
        ..['enabledModules'] = [
          {'module': 'usage', 'apiVersion': '1'},
          {'module': 'rating', 'apiVersion': '1'},
        ];
      final res = negotiator.checkTokenOverage(
        capabilitiesPayload: missingModules,
        expectedSpecHash: 'sha256:${'0' * 64}',
      );
      expect(res.compatible, isFalse);
      expect(
        res.reasons,
        containsAll([
          'spec_revision_unsupported:2026-12',
          'unit_unsupported:token',
          'usage_ingress_unsupported:usage.fact.v2',
          'rating_strategy_unsupported:per_unit@2',
          'module_disabled:schedule',
          'module_disabled:ledger',
        ]),
      );
    });
  });
}
