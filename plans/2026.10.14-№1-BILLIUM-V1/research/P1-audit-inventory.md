# P1 — инвентарь аудита Perplexity и заданий DR (snapshot)

Сгенерировано `artifacts/P1-design/build-inventories.py` (D6). Каждый ID обязателен:
- секции §1–§9 закрывает P1.3 в `knowledge/audit-matrix.md` (`## Покрытие аудита`);
- задания §10 закрывает P1.4 в `knowledge/research/DR-0N*.md` (`## Покрытие задания`).

## 0. Источники

| Файл | Байт | sha256[:12] |
|:--|--:|:--|
| `Infrastructure/Research/Reports/Billium-Perplexity-Volume-1-2026-10-12.md` | 85914 | `e333ac2ab08f` |
| `Infrastructure/Research/Reports/Billium-Volume-1-Review-2026-10-12.md` | 21501 | `03f99f72245c` |
| `Infrastructure/Research/Evidence/Billium-Audit-Evidence-2026-10-12.md` | 20932 | `d8359b2f22ff` |
| `Infrastructure/Research/Prompts/Billium-Research.md` | 54793 | `1318f12e8754` |
| `Infrastructure/Research/Prompts/DR-01-Billing-Sources-and-Schemas.md` | 10088 | `d09c50316389` |
| `Infrastructure/Research/Prompts/DR-02-Russian-Payments-and-Fiscalization.md` | 8665 | `cf1afd4fe0da` |
| `Infrastructure/Research/Prompts/DR-03-Rights-Quotas-and-Credits.md` | 8650 | `92f0ae2adde5` |
| `Infrastructure/Research/Prompts/DR-04-Financial-Contracts-and-Authority.md` | 8880 | `20281357ca1f` |

## 1. Разделы отчёта тома 1

| ID | Строка | Раздел |
|:--|--:|:--|
| AUD-001 | 11 | Главный вывод |
| AUD-002 | 30 | Решения по профилям |
| AUD-003 | 43 | Классы решений |
| AUD-004 | 61 | Shortlist |
| AUD-005 | 63 | · Lago |
| AUD-006 | 79 | · Kill Bill |
| AUD-007 | 97 | · OpenMeter |
| AUD-008 | 115 | · PHP embedded path |
| AUD-009 | 127 | · Invoice applications |
| AUD-010 | 140 | Срез версий |
| AUD-011 | 160 | Screening решений |
| AUD-012 | 182 | Модель ownership |
| AUD-013 | 211 | Границы подписки |
| AUD-014 | 241 | Схема Lago |
| AUD-015 | 243 | · Фактические области |
| AUD-016 | 261 | · Концептуальная ERD |
| AUD-017 | 284 | · Сильные стороны |
| AUD-018 | 291 | · Риски |
| AUD-019 | 301 | Схема OpenMeter |
| AUD-020 | 303 | · Фактические области |
| AUD-021 | 323 | · Концептуальная ERD |
| AUD-022 | 344 | · Сильные стороны |
| AUD-023 | 351 | · Риски |
| AUD-024 | 361 | Схема Kill Bill |
| AUD-025 | 363 | · Фактические области |
| AUD-026 | 383 | · Концептуальная ERD |
| AUD-027 | 403 | · Сильные стороны |
| AUD-028 | 410 | · Риски |
| AUD-029 | 419 | Минимальное payment storage |
| AUD-030 | 436 | · Строгие поля |
| AUD-031 | 451 | · Где допустим JSON |
| AUD-032 | 459 | · Где JSON недопустим как единственное хранение |
| AUD-033 | 475 | Invoice-модуль |
| AUD-034 | 493 | · Ключевой invariant |
| AUD-035 | 517 | Server storage |
| AUD-036 | 547 | Payment lifecycle |
| AUD-037 | 577 | · Transaction boundaries |
| AUD-038 | 594 | Idempotency contract |
| AUD-039 | 628 | Provider operations |
| AUD-040 | 666 | Merchant versioning |
| AUD-041 | 687 | Usage architecture |
| AUD-042 | 689 | · Типы metric |
| AUD-043 | 712 | · Late events |
| AUD-044 | 725 | · Provenance |
| AUD-045 | 740 | Credits и reservations |
| AUD-046 | 753 | · Async AI usage |
| AUD-047 | 781 | Invoice mutability |
| AUD-048 | 797 | Outbox и inbox |
| AUD-049 | 832 | Конкуренция |
| AUD-050 | 834 | · Partial refund |
| AUD-051 | 853 | · Allocation |
| AUD-052 | 864 | · Recurring schedule |
| AUD-053 | 880 | Расширяемость |
| AUD-054 | 882 | · Extension map |
| AUD-055 | 897 | · Plugin migrations |
| AUD-056 | 912 | · Disable и uninstall |
| AUD-057 | 924 | Эволюция схемы |
| AUD-058 | 944 | Query paths |
| AUD-059 | 965 | PHP и Dart |
| AUD-060 | 967 | · Общий contract |
| AUD-061 | 999 | · Arithmetic |
| AUD-062 | 1009 | · Authority варианты |
| AUD-063 | 1035 | Purchase-to-ready |
| AUD-064 | 1062 | · Compensation cases |
| AUD-065 | 1076 | Multi-seller |
| AUD-066 | 1098 | RU-провайдеры |
| AUD-067 | 1119 | Лицензионные границы |
| AUD-068 | 1136 | Три композиции |
| AUD-069 | 1138 | · Композиция 1: PHP-first modular |
| AUD-070 | 1169 | · Композиция 2: Lago-centered server |
| AUD-071 | 1202 | · Композиция 3: Kill Bill payment/billing server |
| AUD-072 | 1234 | · Композиция 4: Hybrid recommended |
| AUD-073 | 1257 | Fault stories |
| AUD-074 | 1290 | Reuse verdict |
| AUD-075 | 1314 | Pilot |
| AUD-076 | 1318 | · Scope |
| AUD-077 | 1333 | · Обязательные failure injections |
| AUD-078 | 1346 | · Vendor bake-off |
| AUD-079 | 1366 | Backlog вопросов |
| AUD-080 | 1403 | Follow-up prompts |
| AUD-081 | 1405 | · Prompt 1: Lago schema |
| AUD-082 | 1409 | · Prompt 2: Kill Bill recovery |
| AUD-083 | 1413 | · Prompt 3: OpenMeter credits |
| AUD-084 | 1417 | · Prompt 4: YooKassa |
| AUD-085 | 1421 | · Prompt 5: CloudPayments |
| AUD-086 | 1425 | · Prompt 6: Atol |
| AUD-087 | 1429 | · Prompt 7: PHP Money |
| AUD-088 | 1433 | · Prompt 8: Subscription migration |
| AUD-089 | 1437 | · Prompt 9: Lago licensing |
| AUD-090 | 1441 | · Prompt 10: Restore drill |
| AUD-091 | 1447 | Лучшие референсы |
| AUD-092 | 1475 | Coverage audit |
| AUD-093 | 1502 | Порядок следующих исследований |

## 2. Утверждения разбора (C)

| ID | Утверждение | DR |
|:--|:--|:--|
| C-01 | A/B независимы от C; «Решения по профилям» | DR-01 |
| C-02 | Existing commerce содержит payment/refund recovery; «Главный вывод» | DR-02 |
| C-03 | Один execution owner PSP effect | DR-01 |
| C-04 | Девять exact revisions; «Срез версий» | DR-01 |
| C-05 | Runtime/licence PHP candidates | DR-01 |
| C-06 | Lago AGPL; Kill Bill/OpenMeter Apache | DR-01 |
| C-07 | OpenMeter latest `v1.0.0-beta.236` | DR-01 |
| C-08 | Lago реальные payment/settlement/idempotency области | DR-01 |
| C-09 | Organization достаточен для seller isolation | DR-01 |
| C-10 | Lago поддерживает нужные partial payments в выбранной edition | DR-01 |
| C-11 | Kill Bill payment/transaction/attempt/history и Janitor | DR-01 |
| C-12 | Kill Bill DDL/source защищает все cross-entity balances | DR-01 |
| C-13 | OpenMeter COUNT/UNIQUE_COUNT/LATEST, id+source dedupe | DR-01 |
| C-14 | Meter definition immutable | DR-01 |
| C-15 | OpenMeter grants/ledger пригодны для strict reservations | DR-03 |
| C-16 | OpenMeter event outbox — queue, не financial event archive | DR-01 |
| C-17 | PSP webhook universally signed; payment sequence | DR-02 |
| C-18 | Provider idempotency не вечна | DR-02 |
| C-19 | Generic find(reference) достаточно | DR-02 |
| C-20 | PHP/Dart shared contracts и точная арифметика | DR-04 |
| C-21 | Committed replica не повторяет posting/tax | DR-04 |
| C-22 | Dart local writer + PHP online billing | DR-04 |
| C-23 | Annual billing/monthly grants, existing subscriptionify | DR-03 |
| C-24 | Durable financial event по after-commit callback | DR-04 |
| C-25 | Pilot и три vendor implementations обязательны сейчас | — |
| C-26 | Storage variants/extensions/fault stories «выполнены» | DR-01 |

## 3. Блоки coverage audit задания v3

| ID | Блок | Вердикт разбора |
|:--|:--|:--|
| V3-01 | Zero context / классы inputs | Частично |
| V3-02 | Ready foundations vs build | Частично |
| V3-03 | Consumers и роли | В основном conceptual |
| V3-04 | Existing technical facts | Partial supplied |
| V3-05 | A–D | Полезный proposal |
| V3-06 | Deep procedure | Частично |
| V3-07 | Candidates | Частично |
| V3-08 | Functional capability matrix | Недовыполнено |
| V3-09 | Real schemas | Недовыполнено |
| V3-10 | Real extension points | Недовыполнено |
| V3-11 | Payment/concurrency/events | Design covered |
| V3-12 | Usage/subscription/credits | Conceptual + отдельные docs facts |
| V3-13 | PHP/Dart/local/cloud | Полезные alternatives |
| V3-14 | Operations/API/security | Частично |
| V3-15 | RU/fiscal/licences | RU недовыполнен |
| V3-16 | 26 fault stories | Все темы упомянуты |
| V3-17 | Required outputs | Частично |
| V3-18 | Annotated references/backlog | Частично |
| V3-19 | Evidence standard | Частично |
| V3-20 | Internal role glossary | Приемлемо |

## 4. Поправки разбора (AC)

| ID | Поправка |
|:--|:--|
| AC-01 | Usage facts / money / quota — три owners. |
| AC-02 | Commercial agreement открыт. |
| AC-03 | Schedule revision не business uniqueness сама по себе. |
| AC-04 | Reservation diagram — не корректная законченная FSM. |
| AC-05 | Unknown не confirmed absent. |
| AC-06 | Merchant config version — пока proposal. |
| AC-07 | OpenMeter schema domains не взаимозаменяемы. |
| AC-08 | Vendor edition проверяется до pilot. |
| AC-09 | AiFina customer book ≠ vendor billing book. |
| AC-10 | Failure stories превращаются сначала в research acceptance criteria. |

## 5. Gaps разбора (G)

| ID | Решение, зависящее от evidence |
|:--|:--|
| G-01 | Можно ли брать server engine без fork и нужной коммерческой edition |
| G-02 | Как exact PSP lookup/refund/auth/TTL ложатся на Commerz |
| G-03 | Кто владеет quota и договором; migration subscriptionify |
| G-04 | Existing Money conformance и accounting authority |
| G-05 | Owners legal sellers, CMS base/premium, offline expiry policy |
| G-06 | Extraction Tenant/loader, licensing publisher, social/AI seams |

## 6. Backlog вопросов отчёта (R)

| ID | Приоритет | Вопрос |
|:--|:--|:--|
| R-01 | P0 | Кто владеет commercial agreement: billing или product coordinator? |
| R-02 | P0 | Какой engine проходит duplicate/unknown/refund acceptance suite без fork? |
| R-03 | P0 | Legal interpretation AGPL для planned Lago deployment/modification |
| R-04 | P0 | Seller partition: organization/tenant или отдельная billing entity? |
| R-05 | P0 | RU PSP create/find/refund/webhook capability audit |
| R-06 | P0 | Fiscal lifecycle и retry для Atol/PSP receipts |
| R-07 | P0 | Local idempotency retention после provider TTL |
| R-08 | P0 | Allocation/refund concurrency в Lago |
| R-09 | P0 | Invoice/payment locks и Janitor recovery в Kill Bill PostgreSQL |
| R-10 | P0 | OpenMeter OSS/payment/billing edition boundary |
| R-11 | P1 | OpenMeter late/corrected event semantics |
| R-12 | P1 | Lago seller/merchant config version provenance |
| R-13 | P1 | Kill Bill tenant vs legal seller isolation |
| R-14 | P1 | Laravel 13 compatibility выбранных PHP dependencies |
| R-15 | P1 | MoneyPHP против Brick Money на Dart fixture corpus |
| R-16 | P1 | Subscriptionify migration mapping и двойной paid state |
| R-17 | P1 | Annual billing/monthly grant ownership |
| R-18 | P1 | Offline AI budgets и global quota trade-off |
| R-19 | P1 | Bundle component cancellation/proration |
| R-20 | P1 | Billing-to-accounting export contract |
| R-21 | P1 | Restore/reconciliation procedure |
| R-22 | P1 | Plugin migration ownership и disable semantics |
| R-23 | P2 | Invoice numbering/legal document profiles |
| R-24 | P2 | Chargeback/dispute representation у RU PSP |
| R-25 | P2 | Publisher settlement boundary |
| R-26 | P2 | Archive/retention of event/provider journals |
| R-27 | P2 | Reporting replica versus direct financial queries |
| R-28 | P2 | Portable SQLite profile для локальных приложений |
| R-29 | P2 | Admin manual repair authorization/audit |
| R-30 | P2 | Vendor replacement/export completeness |

## 7. Fault stories отчёта (FS)

| ID | История | Механизм |
|:--|:--|:--|
| FS-01 | Duplicate checkout | Business uniqueness + idempotency hash |
| FS-02 | PSP accepted, process died | Persist-before-call + lookup |
| FS-03 | Timeout | `unknown`, delayed lookup |
| FS-04 | Expired worker lease | Fence local write + provider lookup |
| FS-05 | Expired PSP idempotency | Durable business reference |
| FS-06 | Concurrent partial refund | Refund reservation + row/version lock |
| FS-07 | Out-of-order webhook | State transition guard + inbox |
| FS-08 | Credential rotation | Merchant config version on operation |
| FS-09 | Two sellers | Seller in every key/config |
| FS-10 | Price change | Immutable terms/rate snapshot |
| FS-11 | Issued invoice edit | State-specific mutation policy |
| FS-12 | Annual billing/monthly grants | Unique grant occurrence |
| FS-13 | AI reservation | Reserve/commit/release |
| FS-14 | Gauge correction | Metric-version correction policy |
| FS-15 | Duplicate schedule job | Unique occurrence |
| FS-16 | Paid/provision failed | Idempotent provisioning key |
| FS-17 | Refund before activation | Agreement generation/state check |
| FS-18 | Receipt outage | Independent receipt state |
| FS-19 | Offline POS | Local product policy |
| FS-20 | Journal replication | Source journal dedupe |
| FS-21 | Forged organization ID | Host-authenticated context |
| FS-22 | Reward/settlement confusion | Separate ledgers |
| FS-23 | Bundle cancellation | Agreement components |
| FS-24 | Commit-before-dispatch crash | Transactional outbox |
| FS-25 | Rolling upgrade | Expand/contract migration |
| FS-26 | Restore/replay | Reconciliation checkpoints |

## 8. Evidence разбора (E, L)

| ID | Что проверено |
|:--|:--|
| L-01 | Commerz `packages/sales/src/Gateways/Contracts/PaymentGateway.php:17` — initiate/find/refund/findRefund; refe… |
| L-02 | Commerz `packages/sales/src/Payments/Internal/PaymentWorkflow.php:53,63,98,112,144,152` — Tx A до network; ot… |
| L-03 | Commerz `packages/sales/src/Refunds/Internal/RefundWorkflow.php:52,61,105,118,129,161,169` — Аналогичный refu… |
| L-04 | Commerz `packages/sales/src/Refunds/Internal/RefundJournal.php:77,87,94,119,211` и `Payments/Internal/Payment… |
| L-05 | Commerz `packages/core/src/Money/Money.php:15,23,57,81` — int minor; parsing/rounding через Brick Math; extra… |
| L-06 | CoreX `packages/intl/composer.json:9,11,12` — PHP ^8.4, Brick Math, Brick Money ^0.9 или ^0.10 |
| L-07 | ERP `contracts/axioma_contracts/lib/src/values/money.dart:5,16,27,53,143`; `packages/axioma_finance/lib/src/a… |
| L-08 | ERP `packages/axioma_finance/lib/src/sync_adapters/journal_adapter.dart:5,49,61,114,138,142` — Immutable comm… |
| L-09 | ERP `packages/axioma_core/lib/src/licensing/license_verifier.dart:17,31` — Existing Ed25519 verifier с signat… |
| L-10 | Memster `composer.json:14`; `app/Models/User/User.php:18,36` — `revoltify/subscriptionify:^1.0`, User имеет e… |
| L-11 | Memster `app/Actions/Billing/RecordTokenTransactionAction.php:37,45,50,63,72` — User row lock до dedupe и bal… |
| L-12 | Edufy `docs/03_Dev/design/domains/DP-07_billing-credits.md:20,25,30,40` — Documented subscriptionify baseline… |
| L-13 | CoreX `packages/core/src/Modules/Contracts/ModuleActivationGate.php:12,18,20` — check(TenantContext,module,ed… |
| L-14 | CoreX `packages/tenancy/src/Contracts/ProvisioningClaim.php:13`; `Provisioning/Data/ClaimRequest.php:12` — cl… |
| L-15 | Commerz `packages/sales/src/Gateways/Data/GatewayPaymentRequest.php:24` — Typed reference/order/Money/custome… |
| E-01 | exact revisions и release metadata |
| E-02 | manifests и licence text |
| E-03 | manifests и licence text |
| E-04 | Lago: конкретная проверка |
| E-05 | Kill Bill: конкретная проверка |
| E-06 | OpenMeter: конкретная проверка |
| E-07 | OpenMeter: конкретная проверка |
| E-08 | ограниченная проверка PSP API |

## 9. Retrieval journal разбора (Q)

| ID | Запрос |
|:--|:--|
| Q-01 | `maind health --cwd` в Brain |
| Q-02 | `ALL_TOOLS` names с regexp `perplexity` |
| Q-03 | GitHub REST `/commits/{SHA}` и `/releases/latest` для девяти E-01 repos |
| Q-04 | GitHub `/git/trees/{SHA}?recursive=1` для Lago/Kill Bill/OpenMeter |
| Q-05 | Raw GitHub точные E-02…07 paths |
| Q-06 | web.open creating-meters / Yoo interaction / CloudPayments docs |
| Q-07 | web.open `https://docs.killbill.io/latest/userguide_payment` |
| Q-08 | web.open `https://docs.killbill.io/latest/userguide_payment.html` |
| Q-09 | web.find Yoo interaction по `24` / `Идемпотентность обеспечивается`, затем direct URL GET |
| Q-10 | Scoped local reads L-01…15, revision/status и ERP digests |

## 10. Задания DR

| ID | Пункт задания |
|:--|:--|
| DR01-T01 | Brief screening дополнительных OSS/self-hosted кандидатов; затем source глубина для трёх server finalists и 1… |
| DR01-T02 | Для finalists реконструируй физическую persistence модель по DDL/migrations/ORM: seller/billing entity/custom… |
| DR01-T03 | По существенной таблице: actual fields/types, PK/FK/UNIQUE/CHECK/indexes, nullable semantics, deletion, parti… |
| DR01-T04 | Дай trace **invariant → exact source path/line → transaction/lock/version → provider boundary/recovery → actu… |
| DR01-T05 | Обязательные traces: process dies после PSP acceptance; timeout/ambiguous lookup; expired lease и stale worke… |
| DR01-T06 | Реальные interfaces/hooks для PSP, tax/fiscal, rating, metric, invoice, custom attributes, host auth/context.… |
| DR01-T07 | Edition matrix: capability × release × OSS/Premium/hosted → actual mechanism/evidence/gap. Manual receipt reg… |
| DR01-T08 | Query/index/retention/upgrade analysis: due work, unknown attempts, provider refs, remaining refundable, invo… |
| DR01-T09 | Сравни A–D compositions и seam cost. Для каждого build-new сначала отметь, что existing commerce или vendor у… |
| DR01-T10 | Licence text отдельно от юридической интерпретации: dependency/copy/fork/modified network service/appliance/A… |
| DR02-T01 | Capability × provider/API edition matrix: create, lookup by PSP ID/business reference, auth/capture/partial c… |
| DR02-T02 | Idempotency: header, scope, request fingerprint behaviour, retention, повтор с другим payload, late retry aft… |
| DR02-T03 | Lifecycle: provider statuses и terminal meanings; create timeout, callback before response, duplicated/out-of… |
| DR02-T04 | Notification authenticity: body/header canonicalization, raw request bytes, server-side object fetch, merchan… |
| DR02-T05 | Merchant config/history: credential rotation того же account vs переход к другому merchant. Кто может lookup/… |
| DR02-T06 | Recurring: фактический consent/mandate flow, saved method namespaces, payer changes, cancellation/revocation,… |
| DR02-T07 | Atol/PSP receipts: API version, register/external reference, status/callback/auth, duplicate request/timeout/… |
| DR02-T08 | Contextual RU obligations: B2B/B2C, advance/full settlement/refund, продажа доступа/credits/license/goods, аг… |
| DR02-T09 | Reconciliation: incoming/outgoing facts, partial balances, receipts, provider daily reports, restoration посл… |
| DR02-T10 | Официальные PHP SDK и existing driver screening: actual package/version/licence/PHP constraints, methods и ga… |
| DR03-T01 | Actual migrations/models/services/contracts/tests для plans/features/subscriptions/grants/quotas/resets/consu… |
| DR03-T02 | Candidate/version/edition→capability→source mechanism→gap matrix. Boolean features, consumable units, gauges,… |
| DR03-T03 | Monetary vs rights boundary: у каких libraries цены/proration/renewal встроены, можно ли выключить monetary l… |
| DR03-T04 | Annual payment/monthly grants: semantic occurrence key, schedule revision supersession, retries upgrade/renew… |
| DR03-T05 | **Strict reservation**: atomic reserve→commit или release/expiry; quantity conservation, partial commit + rem… |
| DR03-T06 | Grant float/decimal/whole-unit precision, rounding и reset semantics; service credits не маскировать currency… |
| DR03-T07 | Migration baseline subscriptionify: exact version resolution, holder IDs, string/decimal casts, history, plan… |
| DR03-T08 | Host integration: versioned context/subject/reference contracts, PDP+entitlement verdict composition, resourc… |
| DR03-T09 | Reuse verdict по units: ready dependency/existing baseline/adapter/schema donor/narrow missing invariant/defe… |
| DR04-T01 | По Money/decimal/arithmetic primitives: storage/ranges, exact decimal parsing, currency exponent source/versi… |
| DR04-T02 | Language-neutral fixture specification для PHP/Dart: RUB/JPY/KWD и currency mismatch; ±int64 boundaries; huge… |
| DR04-T03 | Existing wire/reference/error/event primitives reuse: какой spec общий, какие adapters per runtime, semantic … |
| DR04-T04 | Сравни authority alternatives: Dart local writer + PHP committed replica/handover; PHP writer в cloud и локал… |
| DR04-T05 | Writer transfer одной книги: active authority/epoch/fencing, enrollment/device trust, sequence/identity, repl… |
| DR04-T06 | **Committed replication ≠ operational event accounting mapping.** Replica сохраняет source committed entries … |
| DR04-T07 | Atomic financial commit+durable enqueue: actual готовые outbox/sync patterns и tests; crash до/после commit/d… |
| DR04-T08 | Double-entry requirement для vendor billing: obligations/payments/allocations subledger vs full company chart… |
| DR04-T09 | Licence/offline bridge: use/update/cloud entitlements, Ed25519 issuer interoperability/rotation/revocation/gr… |
