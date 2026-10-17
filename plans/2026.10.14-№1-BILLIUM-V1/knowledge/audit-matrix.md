# Разбор аудита Perplexity тома 1 — покрытие, утверждения, матрица схем

Вход — пакет аудита из `research/P1-sources.md` § 3, прочитанный полностью в порядке перечня: отчёт тома 1,
его разбор, evidence разбора, исходное задание v3. ID — из `research/P1-audit-inventory.md` § 1–§ 9
(snapshot design-этапа, D6; executor его не меняет). Требования — только из `docs/requirements.md` (D7).

Сверка snapshot (2026-10-17): sha256[:12] всех четырёх файлов совпали с инвентарём § 0 (`e333ac2ab08f`,
`03f99f72245c`, `d8359b2f22ff`, `1318f12e8754`); расхождений нет.

Правила документа:

- Аудит Perplexity — не источник требований. Его выводы записаны как утверждения `CL-NN`; требования
  ссылаются на REQ/INV из `docs/requirements.md`. Недостающее требование — «новое требование для P1.6» со
  ссылкой на FS/CL.
- Статус утверждения берётся из разбора (C-ID: столбец «Статус и предел»). Без C-ID статус не выше
  `hypothesis`: `[FACT]` автора отчёта даёт `unverified`, `[INFERENCE]` — `hypothesis`, `[RECOMMENDATION]` и
  поправки разбора — `recommendation`. Наблюдения evidence L/E без C-ID не поднимают статус выше `hypothesis`.
- «Нужна проверка» направляет утверждение в задание P1.4 (DR-01…DR-04); `нет` — утверждение является
  дизайн-советом или уже закрыто каноном/требованием и первичной проверки не требует.
- Диспозиции покрытия: «учтено» — содержание сведено в указанные CL; «вне границ» — относится к чужому
  владельцу (REQ-OUT); «нет утверждений» — заголовок, журнал или задание без собственных утверждений;
  «дубль» — повторяет указанный ID.
- Сводная матрица — компактная оценка, не evidence: каждая ячейка опирается на CL и несёт его метку;
  первичную проверку делает P1.4, выбор — P1.6.

## Покрытие аудита

| ID | Диспозиция | Ссылки | Примечание |
|:--|:--|:--|:--|
| AUD-001 | учтено | CL-01, CL-02, CL-03, CL-04 | Шесть областей, единственный owner, существующий commerce journal, риск двойных engines |
| AUD-002 | учтено | CL-05, CL-07 | Профили A–D: authority, готовая основа, собственный код |
| AUD-003 | учтено | CL-06 | Taxonomy классов решений |
| AUD-004 | нет утверждений | — | Заголовок shortlist; содержание — подразделы AUD-005…AUD-009 |
| AUD-005 | учтено | CL-08, CL-13, CL-15 | Lago: позиционирование, runtime, AGPL |
| AUD-006 | учтено | CL-20, CL-21, CL-96 | Kill Bill: модель платежа, Janitor, Java; doc-утверждения не подтверждены |
| AUD-007 | учтено | CL-23, CL-24, CL-25, CL-28, CL-30 | OpenMeter: метрики, immutable meter, pipeline, beta, edition |
| AUD-008 | учтено | CL-31, CL-32 | PHP embedded path и предел Omnipay/Payum |
| AUD-009 | учтено | CL-36 | Invoice applications как donors |
| AUD-010 | учтено | CL-35, CL-94 | Срез версий: SHA, не stable tags |
| AUD-011 | учтено | CL-97, CL-36, CL-13 | Screening и причины отсева |
| AUD-012 | учтено | CL-81, CL-80 | Ownership-таблица и уточнение agreement/grant schedule |
| AUD-013 | учтено | CL-56 | Billing period ≠ grant period |
| AUD-014 | нет утверждений | — | Заголовок «Схема Lago»; содержание — AUD-015…AUD-018 |
| AUD-015 | учтено | CL-08, CL-10 | Области source tree Lago |
| AUD-016 | учтено | CL-103 | Conceptual ERD Lago, не физическая модель |
| AUD-017 | учтено | CL-14 | Donor patterns Lago |
| AUD-018 | учтено | CL-11, CL-16, CL-59 | Риски: seller partition, merchant provenance, allocations, wallets ≠ rewards |
| AUD-019 | нет утверждений | — | Заголовок «Схема OpenMeter»; содержание — AUD-020…AUD-023 |
| AUD-020 | учтено | CL-29, CL-103 | Области OpenMeter, subject ≠ customer |
| AUD-021 | учтено | CL-103 | Conceptual ERD OpenMeter |
| AUD-022 | учтено | CL-101 | Сильные стороны OpenMeter как donor |
| AUD-023 | учтено | CL-28, CL-102 | Async query, collector drop, exactly-once insert |
| AUD-024 | нет утверждений | — | Заголовок «Схема Kill Bill»; содержание — AUD-025…AUD-028 |
| AUD-025 | учтено | CL-17 | Области storage Kill Bill |
| AUD-026 | учтено | CL-103 | Conceptual ERD Kill Bill |
| AUD-027 | учтено | CL-20 | Payment/transaction/attempt, Janitor, plugins |
| AUD-028 | учтено | CL-18, CL-19, CL-21, CL-22 | Риски: constraints, tenant ≠ seller, cost, UUID |
| AUD-029 | учтено | CL-37 | Минимальная схема для A/B |
| AUD-030 | учтено | CL-37 | Строгие поля сущностей |
| AUD-031 | учтено | CL-38 | Где JSON допустим |
| AUD-032 | учтено | CL-38 | Где JSON недопустим как единственное хранение |
| AUD-033 | учтено | CL-40, CL-41 | ERD invoice-модуля поверх kernel |
| AUD-034 | учтено | CL-40, CL-41 | Формула open amount и разделение сущностей |
| AUD-035 | учтено | CL-43 | Anti-corruption layer server storage |
| AUD-036 | учтено | CL-44 | Последовательность lifecycle DB vs PSP |
| AUD-037 | учтено | CL-44, CL-45 | Десять правил transaction boundaries; нет exactly-once |
| AUD-038 | учтено | CL-46 | Scope, хранимые данные и поведение idempotency |
| AUD-039 | учтено | CL-49 | Capability-модель provider operations |
| AUD-040 | учтено | CL-51, CL-39 | Merchant configuration version |
| AUD-041 | нет утверждений | — | Заголовок «Usage architecture»; содержание — AUD-042…AUD-044 |
| AUD-042 | учтено | CL-52 | Типы метрик и неизменность definition |
| AUD-043 | учтено | CL-53 | Policy поздних событий |
| AUD-044 | учтено | CL-54 | Provenance rated line |
| AUD-045 | учтено | CL-59 | Виды credits и liabilities |
| AUD-046 | учтено | CL-60, CL-61 | Reservation FSM и async AI |
| AUD-047 | учтено | CL-42 | Mutability invoice по состояниям |
| AUD-048 | учтено | CL-62, CL-63 | Поля outbox/inbox и атомарность |
| AUD-049 | нет утверждений | — | Заголовок «Конкуренция»; содержание — AUD-050…AUD-052 |
| AUD-050 | учтено | CL-64 | Partial refund invariant |
| AUD-051 | учтено | CL-65 | Concurrent allocation |
| AUD-052 | учтено | CL-58 | Ключ recurring occurrence; контрпример AC-03 |
| AUD-053 | нет утверждений | — | Заголовок «Расширяемость»; содержание — AUD-054…AUD-056 |
| AUD-054 | учтено | CL-67 | Extension map |
| AUD-055 | учтено | CL-67 | Декларация модуля и plugin migrations |
| AUD-056 | учтено | CL-68 | Disable и uninstall |
| AUD-057 | учтено | CL-70, CL-71 | Expand/contract, enum, rollback |
| AUD-058 | учтено | CL-72 | Query paths и partitioning |
| AUD-059 | нет утверждений | — | Заголовок «PHP и Dart»; содержание — AUD-060…AUD-062 |
| AUD-060 | учтено | CL-73, CL-75 | Общий contract и пример transport |
| AUD-061 | учтено | CL-75 | Правила арифметики |
| AUD-062 | учтено | CL-76, CL-77 | Варианты authority и committed replication |
| AUD-063 | учтено | CL-79 | Purchase-to-ready |
| AUD-064 | учтено | CL-79, CL-111, CL-118 | Compensation cases |
| AUD-065 | учтено | CL-82 | Seller boundary |
| AUD-066 | учтено | CL-83, CL-84 | RU-провайдеры: checklist без primary audit |
| AUD-067 | учтено | CL-85, CL-15 | Лицензионные режимы |
| AUD-068 | нет утверждений | — | Заголовок «Три композиции»; содержание — AUD-069…AUD-072 |
| AUD-069 | учтено | CL-86 | Композиция 1 |
| AUD-070 | учтено | CL-87 | Композиция 2 |
| AUD-071 | учтено | CL-88 | Композиция 3 |
| AUD-072 | учтено | CL-89 | Композиция 4 (рекомендованная) |
| AUD-073 | учтено | CL-92 | Таблица fault stories; разбор по FS — ниже |
| AUD-074 | учтено | CL-90, CL-95 | Reuse verdict по units |
| AUD-075 | учтено | CL-91 | Pilot: назначение |
| AUD-076 | учтено | CL-91 | Pilot: scope |
| AUD-077 | учтено | CL-100 | Обязательные failure injections |
| AUD-078 | учтено | CL-91 | Vendor bake-off |
| AUD-079 | дубль | R-01, R-30 | Backlog отчёта — это строки R-01…R-30 инвентаря |
| AUD-080 | нет утверждений | — | Заголовок «Follow-up prompts»; подразделы — AUD-081…AUD-090 |
| AUD-081 | нет утверждений | — | Задание source-аудита схемы Lago; закрывается через DR01-T02 в P1.4 |
| AUD-082 | нет утверждений | — | Задание по recovery Kill Bill; закрывается через DR01-T04 и DR01-T05 |
| AUD-083 | нет утверждений | — | Задание по credits OpenMeter; граница — DR03-T05 |
| AUD-084 | нет утверждений | — | Задание adapter-спецификации ЮKassa; закрывается через DR02-T01 |
| AUD-085 | нет утверждений | — | Задание по lifecycle CloudPayments; закрывается через DR02-T01 |
| AUD-086 | нет утверждений | — | Задание по Atol и ФНС; закрывается через DR02-T07 и DR02-T08 |
| AUD-087 | нет утверждений | — | Задание сравнения PHP Money; закрывается через DR04-T01 и DR04-T02 |
| AUD-088 | нет утверждений | — | Задание миграции subscription-пакетов; граница — DR03-T07 |
| AUD-089 | нет утверждений | — | Задание лицензионной границы Lago; закрывается через DR01-T10 |
| AUD-090 | нет утверждений | — | Задание restore drill; сценарий — в FS-26 и CL-120 |
| AUD-091 | учтено | CL-98 | Каталог референсов: объём и пределы |
| AUD-092 | учтено | CL-93 | Self-audit отчёта; разбор оценивает покрытие ниже |
| AUD-093 | учтено | CL-99 | Порядок следующих исследований |
| C-01 | учтено | CL-05 | A/B независимы от C |
| C-02 | учтено | CL-03, CL-66 | Recovery существующего commerce |
| C-03 | учтено | CL-02 | Один execution owner PSP effect |
| C-04 | учтено | CL-35 | Девять SHA |
| C-05 | учтено | CL-33, CL-34 | Manifests PHP-кандидатов |
| C-06 | учтено | CL-09 | Лицензии server-кандидатов |
| C-07 | учтено | CL-23 | Release OpenMeter |
| C-08 | учтено | CL-10 | Реальные таблицы Lago |
| C-09 | учтено | CL-11 | Organization как seller isolation |
| C-10 | учтено | CL-12 | Partial payments и Premium gate |
| C-11 | учтено | CL-17 | Kill Bill payment storage и Janitor |
| C-12 | учтено | CL-18 | Защита cross-entity balances |
| C-13 | учтено | CL-24 | Метрики и dedupe OpenMeter |
| C-14 | учтено | CL-25 | Immutable meter |
| C-15 | учтено | CL-26 | Grants/ledger и strict reservations |
| C-16 | учтено | CL-27 | EventOutbox — очередь |
| C-17 | учтено | CL-50 | Подпись webhook не универсальна |
| C-18 | учтено | CL-47 | TTL provider idempotency |
| C-19 | учтено | CL-48 | find(reference) и confirmed absence |
| C-20 | учтено | CL-74 | Money baseline PHP/Dart |
| C-21 | учтено | CL-77 | Committed replica |
| C-22 | учтено | CL-76 | Dart local writer + PHP online billing |
| C-23 | учтено | CL-57 | Subscriptionify baseline |
| C-24 | учтено | CL-62 | After-commit callback и durable event |
| C-25 | учтено | CL-91 | Pilot и bake-off не приняты как задача |
| C-26 | учтено | CL-92, CL-103 | Self-audit «выполнено» завышен |
| V3-01 | учтено | CL-104 | Supplied-факты и рекомендации |
| V3-02 | учтено | CL-105 | Нет vendor-vs-build gap proof |
| V3-03 | учтено | CL-106 | Роли различены концептуально |
| V3-04 | учтено | CL-03, CL-74 | Existing technical facts дополнены evidence L |
| V3-05 | учтено | CL-05 | A–D как proposal |
| V3-06 | учтено | CL-93 | Глубина процедуры ниже обещанной |
| V3-07 | учтено | CL-107 | PHP-путь без source-глубины |
| V3-08 | учтено | CL-93 | Capability matrix недовыполнена |
| V3-09 | учтено | CL-93, CL-103 | Реальные схемы недовыполнены |
| V3-10 | учтено | CL-69 | Extension points недовыполнены |
| V3-11 | учтено | CL-108 | Payment/concurrency — design-уровень |
| V3-12 | учтено | CL-109 | Usage/credits — conceptual |
| V3-13 | учтено | CL-76 | PHP/Dart alternatives |
| V3-14 | учтено | CL-110 | Operations/API/security частично |
| V3-15 | учтено | CL-83 | RU недовыполнен |
| V3-16 | учтено | CL-92 | 26 fault stories без evidence |
| V3-17 | учтено | CL-93 | Required outputs частично |
| V3-18 | учтено | CL-98 | Каталог референсов частично |
| V3-19 | учтено | CL-104 | Стандарт доказательности частично |
| V3-20 | учтено | CL-06 | Словарь ролей приемлем |
| AC-01 | учтено | CL-55 | Три owners: факт, деньги, квота |
| AC-02 | учтено | CL-80 | Agreement открыт |
| AC-03 | учтено | CL-58 | Schedule revision не business uniqueness |
| AC-04 | учтено | CL-60 | Reservation FSM |
| AC-05 | учтено | CL-48 | Unknown ≠ confirmed absent |
| AC-06 | учтено | CL-39 | Merchant config version — proposal |
| AC-07 | учтено | CL-26 | Домены OpenMeter не взаимозаменяемы |
| AC-08 | учтено | CL-12 | Edition до pilot |
| AC-09 | учтено | CL-78 | Книги клиента и продавца ПО |
| AC-10 | учтено | CL-92 | FS → research acceptance criteria |
| G-01 | учтено | CL-112 | Server engine без fork/edition |
| G-02 | учтено | CL-113 | PSP lookup/refund/TTL на Commerz |
| G-03 | учтено | CL-114 | Quota, договор, subscriptionify |
| G-04 | учтено | CL-115 | Money conformance и accounting authority |
| G-05 | вне границ | REQ-OUT-15, REQ-NBR-13 | Юридические продавцы, платность CMS, offline expiry — бизнес-факты и вопросы владельцу |
| G-06 | вне границ | REQ-OUT-07, REQ-OUT-13, REQ-OUT-19 | Tenant/loader, licensing publisher, social/AI seams — соседи; том 1 их не закрывает |
| R-01 | учтено | CL-80 | Владелец commercial agreement |
| R-02 | учтено | CL-112 | Engine проходит suite без fork |
| R-03 | учтено | CL-15 | Интерпретация AGPL |
| R-04 | учтено | CL-11 | Seller partition |
| R-05 | учтено | CL-83 | RU PSP capability audit |
| R-06 | учтено | CL-84 | Fiscal lifecycle Atol/PSP |
| R-07 | учтено | CL-47 | Local retention после provider TTL |
| R-08 | учтено | CL-16 | Concurrency Lago |
| R-09 | учтено | CL-18 | Locks и Janitor Kill Bill |
| R-10 | учтено | CL-30 | Edition OpenMeter |
| R-11 | учтено | CL-102 | Late/corrected events OpenMeter |
| R-12 | учтено | CL-16 | Merchant provenance Lago |
| R-13 | учтено | CL-19 | Tenant Kill Bill vs seller |
| R-14 | учтено | CL-116 | Laravel 13 compatibility |
| R-15 | учтено | CL-117 | MoneyPHP vs Brick Money |
| R-16 | учтено | CL-57 | Migration mapping subscriptionify |
| R-17 | учтено | CL-56 | Annual billing / monthly grant |
| R-18 | вне границ | REQ-OUT-10, REQ-OUT-01 | Offline AI budgets и глобальная квота — Tariffler и коллекторы |
| R-19 | учтено | CL-118 | Отмена компонента bundle |
| R-20 | учтено | CL-119 | Export в учёт |
| R-21 | учтено | CL-120 | Restore/reconciliation |
| R-22 | учтено | CL-68 | Plugin migrations и disable |
| R-23 | учтено | CL-121 | Нумерация и профили документов |
| R-24 | учтено | CL-122 | Chargeback/dispute RU PSP |
| R-25 | вне границ | REQ-OUT-09 | Publisher settlement — кандидат Settlement |
| R-26 | учтено | CL-123 | Archive/retention журналов |
| R-27 | учтено | CL-124 | Reporting replica |
| R-28 | учтено | CL-125 | Portable SQLite |
| R-29 | учтено | CL-126 | Manual repair |
| R-30 | учтено | CL-127 | Замена vendor и экспорт |
| FS-01 | учтено | CL-46 | Duplicate checkout |
| FS-02 | учтено | CL-44 | PSP accepted, process died |
| FS-03 | учтено | CL-48 | Timeout |
| FS-04 | учтено | CL-44 | Expired worker lease |
| FS-05 | учтено | CL-47 | Expired PSP idempotency |
| FS-06 | учтено | CL-64 | Concurrent partial refund |
| FS-07 | учтено | CL-63 | Out-of-order webhook |
| FS-08 | учтено | CL-51 | Credential rotation |
| FS-09 | учтено | CL-82 | Two sellers |
| FS-10 | учтено | CL-54 | Price change |
| FS-11 | учтено | CL-42 | Issued invoice edit |
| FS-12 | учтено | CL-56 | Annual billing / monthly grants |
| FS-13 | учтено | CL-61 | AI reservation |
| FS-14 | учтено | CL-53 | Gauge correction |
| FS-15 | учтено | CL-58 | Duplicate schedule job |
| FS-16 | учтено | CL-79 | Paid / provision failed |
| FS-17 | учтено | CL-79 | Refund before activation |
| FS-18 | учтено | CL-84 | Receipt outage |
| FS-19 | учтено | CL-111 | Offline POS |
| FS-20 | учтено | CL-77 | Journal replication |
| FS-21 | учтено | CL-82, CL-106 | Forged organization ID |
| FS-22 | учтено | CL-59 | Reward/settlement confusion |
| FS-23 | учтено | CL-118 | Bundle cancellation |
| FS-24 | учтено | CL-62 | Commit-before-dispatch crash |
| FS-25 | учтено | CL-70 | Rolling upgrade |
| FS-26 | учтено | CL-120 | Restore/replay |
| L-01 | учтено | CL-48, CL-03 | Commerz PaymentGateway: find null только при confirmed absence |
| L-02 | учтено | CL-03, CL-48 | PaymentWorkflow: Tx A до сети, unknown, null → failed |
| L-03 | учтено | CL-03 | RefundWorkflow: тот же протокол по исходному payment |
| L-04 | учтено | CL-66 | RefundJournal: lock refundable payment |
| L-05 | учтено | CL-74 | Commerz Money: int minor, Brick Math |
| L-06 | учтено | CL-34 | CoreX intl: Brick Money 0.9 или 0.10 |
| L-07 | учтено | CL-74, CL-62 | ERP Money int64; Ledger callback best effort |
| L-08 | учтено | CL-77 | Journal adapter: append без repost |
| L-09 | вне границ | REQ-OUT-07 | Ed25519 verifier ERP — область Licensing |
| L-10 | учтено | CL-57 | Memster subscriptionify ^1.0 |
| L-11 | вне границ | REQ-OUT-05 | Reward writer Memster — продуктовые награды, не денежный wallet |
| L-12 | учтено | CL-57 | Edufy DP-07 draft, независимый grant period |
| L-13 | вне границ | REQ-OUT-19, REQ-OUT-13 | ModuleActivationGate CoreX — seam хоста |
| L-14 | вне границ | REQ-OUT-11 | ProvisioningClaim CoreX — provisioning хоста |
| L-15 | учтено | CL-39 | GatewayPaymentRequest без typed merchant-version |
| E-01 | учтено | CL-35, CL-23 | Revisions и release metadata |
| E-02 | учтено | CL-33 | Manifests PHP-кандидатов |
| E-03 | учтено | CL-09 | LICENSE server-кандидатов |
| E-04 | учтено | CL-10, CL-12 | Lago: structure.sql, Payment, ManualCreateService |
| E-05 | учтено | CL-17, CL-19 | Kill Bill: DDL, Janitor, TestJanitor |
| E-06 | учтено | CL-24, CL-25 | OpenMeter docs creating-meters, Meter schema |
| E-07 | учтено | CL-26, CL-27 | OpenMeter Grant, LedgerEntry, EventOutbox |
| E-08 | учтено | CL-47, CL-50 | ЮKassa и CloudPayments: TTL и проверка уведомлений |
| Q-01 | нет утверждений | — | Журнал: проверка подключения mAInd в Brain; о Billium ничего не утверждает |
| Q-02 | нет утверждений | — | Журнал: Perplexity в сессии разбора не вызывался; разбор — не ответ Perplexity |
| Q-03 | дубль | E-01 | Запросы GitHub REST дали матрицу E-01 |
| Q-04 | нет утверждений | — | Журнал: trees трёх repo для выбора путей, не исчерпывающий review |
| Q-05 | дубль | E-04, E-05, E-06, E-07 | Raw-чтение путей, результаты — в E-04…E-07 |
| Q-06 | дубль | E-06, E-08 | Чтение docs OpenMeter и PSP, результаты — в E-06 и E-08 |
| Q-07 | учтено | CL-96 | Docs Kill Bill не открылись; doc claim не verified |
| Q-08 | учтено | CL-96 | Повтор с .html тоже неудачен |
| Q-09 | дубль | E-08 | Поиск TTL ЮKassa; подтверждён прямым HTML в E-08 |
| Q-10 | дубль | L-01, L-15 | Локальные чтения L-01…L-15 |

## Утверждения

| ID | Тема | Утверждение | Статус | Источник | Нужна проверка |
|:--|:--|:--|:--|:--|:--|
| CL-01 | Архитектура | Billium делится минимум на шесть независимо подключаемых областей: payment contracts, execution journal, obligations/invoicing, scheduling/rating, usage/credits, coordination ports | recommendation | AUD-001 | нет |
| CL-02 | Single writer | У каждой денежной операции один execution owner, у invoice/credit/reservation — один authoritative writer | recommendation | AUD-001; C-03 | нет |
| CL-03 | Existing commerce | Commerz сохраняет attempt до сетевого вызова, переводит прочие исключения в unknown, сверяет по исходному gateway/reference, fenced local result; refund — тем же протоколом | verified-static | C-02; L-01; L-02; L-03 | нет |
| CL-04 | Композиция | Главная опасность — композиция с двумя renewal/proration engines или двумя retry одного PSP effect | hypothesis | AUD-001 | нет |
| CL-05 | Профили | Профили A/B не зависят от C; профиль A — базовое ограничение всей архитектуры | hypothesis | C-01; AUD-002 | DR-01 |
| CL-06 | Taxonomy | Классы решений (adapter library, orchestrator, billing engine, metering, invoicing app, entitlement, accounting, MoR, PSP) владеют разным state; taxonomy входит в публичную терминологию | recommendation | AUD-003; V3-20 | нет |
| CL-07 | Профиль D | В профиле D billing service владеет PSP effects, магазин — проекцией; локальная «подстраховка» повторным PSP-вызовом запрещена | recommendation | AUD-002 | нет |
| CL-08 | Lago | Lago — event-based metering и usage/subscription billing, usage считается в конце периода; self-hosted — API, worker, clock worker, PostgreSQL, Redis, PDF service | unverified | AUD-005; AUD-015 | DR-01 |
| CL-09 | Лицензии | LICENSE на inspected commits: Lago — AGPL-3.0, Kill Bill и OpenMeter — Apache-2.0; юридическая пригодность и edition — отдельно | verified-static | C-06; E-03 | DR-01 |
| CL-10 | Lago | В `structure.sql` есть payments, invoice_settlements, idempotency_records; payments — bigint amount, currency, organization/customer, provider IDs, polymorphic payable | verified-static | C-08; E-04 | DR-01 |
| CL-11 | Lago | Organization достаточен для seller isolation; при этом idempotency organization nullable, UNIQUE только по key | unverified | C-09; AUD-018; R-04 | DR-01 |
| CL-12 | Lago | Manual/partial incoming payments проходят ветку `License.premium?` (кроме advance charges); OSS-путь не обещан | unverified | C-10; E-04; AC-08 | DR-01 |
| CL-13 | Lago | Lago не годится как обязательная embedded PHP-зависимость: это отдельная Ruby/Rails service composition | hypothesis | AUD-005; AUD-011 | нет |
| CL-14 | Lago | Donor patterns: organization_id в financial и idempotency tables, раздельные wallet credit/transaction, charge → subscription → fee, provider customer records | hypothesis | AUD-017 | DR-01 |
| CL-15 | Лицензии | REST-интеграция с AGPL-сервисом не освобождает автоматически; модифицированный fork — высокая вероятность AGPL obligations; Lago — только заменяемый remote service за ACL без копирования моделей | recommendation | AUD-067; R-03 | DR-01 |
| CL-16 | Lago | Concurrency partial allocations/refunds, поздние usage corrections и merchant-config provenance в Lago не доказаны | unverified | AUD-018; R-08; R-12 | DR-01 |
| CL-17 | Kill Bill | Payment DDL содержит payment_attempts, payments, transactions и history; external_key UNIQUE в tenant scope; Janitor и TestJanitor есть; PostgreSQL artifact и lock path не проверены | verified-static | C-11; E-05 | DR-01 |
| CL-18 | Kill Bill | DDL/source Kill Bill защищает все cross-entity balances | unverified | C-12; R-09 | DR-01 |
| CL-19 | Kill Bill | Tenant Kill Bill не совпадает автоматически с legal seller, merchant account и product tenant | unverified | AUD-028; R-13; E-05 | DR-01 |
| CL-20 | Kill Bill | Лучший референс разделения logical payment / provider transaction / retry attempt; Janitor — образец unknown recovery; payment, control и invoice plugins — ясные extension boundaries | recommendation | AUD-006; AUD-027 | DR-01 |
| CL-21 | Kill Bill | Kill Bill — отдельный Java-сервис, встраивать как библиотеку нецелесообразно; history/audit/state machine повышают migration и operational cost | hypothesis | AUD-006; AUD-028; AUD-071 | нет |
| CL-22 | Kill Bill | Internal UUID vendor не экспортируется как единственный business reference; нужен собственный external operation ID | recommendation | AUD-028 | нет |
| CL-23 | OpenMeter | Latest release `v1.0.0-beta.236` (2026-10-09) — beta; без targeted regression suite не принимать | verified-doc | C-07; E-01 | DR-01 |
| CL-24 | OpenMeter | COUNT, UNIQUE_COUNT и LATEST имеют разную семантику; dedupe событий по id + source | verified-doc | C-13; E-06 | DR-01 |
| CL-25 | OpenMeter | Ключевые свойства meter неизменяемы после создания; в Ent immutable event_type и aggregation | verified-doc | C-14; E-06 | DR-01 |
| CL-26 | OpenMeter | Grants/ledger пригодны для strict reservations; grant amount — float в API при numeric в БД, ledger entry — decimal | unverified | C-15; E-07; AC-07 | DR-03 |
| CL-27 | OpenMeter | EventOutbox — очередь публикации, доставленные строки удаляются; это не архив финансовых событий | verified-static | C-16; E-07 | DR-01 |
| CL-28 | OpenMeter | Pipeline CloudEvents → Kafka → ClickHouse, ingestion асинхронен; для A/B избыточен; асинхронный query не годится как строгий quota gate | hypothesis | AUD-007; AUD-023 | DR-01 |
| CL-29 | OpenMeter | Subject (потребитель ресурса) и customer (billable entity) различаются | unverified | AUD-020 | DR-01 |
| CL-30 | OpenMeter | Граница OSS и hosted для billing, invoicing и payments не установлена | unverified | AUD-007; R-10 | DR-01 |
| CL-31 | PHP embedded | Для A/B — PHP-native путь: Money library, PSP SDK или thin adapter, собственный небольшой durable journal, optional invoice/collection/fiscal | recommendation | AUD-008; AUD-069 | нет |
| CL-32 | PHP embedded | Omnipay и Payum не хранят operation owner, request fingerprint, merchant snapshot, inbox, reconciliation и unknown recovery | hypothesis | AUD-008; AUD-011 | DR-01 |
| CL-33 | Лицензии | Manifests на inspected commits: Invoice Ninja — Elastic License, FOSSBilling — Apache-2.0, Omnipay, Payum, MoneyPHP, Brick Money — MIT; resolver и transitive support не проверены | verified-static | C-05; E-02 | DR-01 |
| CL-34 | PHP и Dart | Brick Money 0.15.2 вне диапазона CoreX (0.9 или 0.10); прямой замены нет | verified-static | C-05; L-06 | DR-04 |
| CL-35 | Срез версий | Все девять SHA отчёта существуют, но это не stable tags; чтение кода автором не доказано | verified-static | C-04; E-01 | DR-01 |
| CL-36 | Invoice applications | Invoice Ninja, FOSSBilling, InvoicePlane, SolidInvoice — donors нумерации, document lifecycle, snapshots и UI; application-first, не usage billing и не resilient payment orchestration | recommendation | AUD-009; AUD-011 | нет |
| CL-37 | Payment storage | Для A/B — собственная компактная схема: seller, merchant config version, payment/refund intent, attempt, provider operation, provider event, inbox, outbox, reconciliation | recommendation | AUD-029; AUD-030 | нет |
| CL-38 | Payment storage | Поля authorization, uniqueness, reconciliation и due-job — реляционные и индексируемые; JSON — только sanitized payload и metadata со schema version и лимитом размера | recommendation | AUD-031; AUD-032 | нет |
| CL-39 | Merchant config | Версия merchant config на операции — пока proposal; в Commerz GatewayPaymentRequest typed merchant-version нет | hypothesis | AC-06; L-15; AUD-040 | DR-02 |
| CL-40 | Invoice-модуль | open amount = issued total − valid allocations − credit applications; refund сам не обязательно увеличивает balance | recommendation | AUD-033; AUD-034 | нет |
| CL-41 | Invoice-модуль | Payment, allocation, credit note, refund и wallet credit — разные сущности | recommendation | AUD-034 | нет |
| CL-42 | Invoice mutability | После issuance номер, seller, buyer snapshot, currency, totals, lines и tax не меняются обычным CRUD; коррекция — credit note или void/reissue; metadata — по allow-list | recommendation | AUD-047 | нет |
| CL-43 | Server storage | Vendor DB как authority требует ACL: role IDs ↔ vendor IDs, seller/environment, revision, cutover; продукты не используют vendor IDs и статусы | recommendation | AUD-035 | нет |
| CL-44 | Payment lifecycle | Intent и idempotency — в одной транзакции; PSP-вызов вне транзакции; recoverable запись до вызова; terminal fact и outbox атомарно; timeout → unknown; fencing не отменяет отправленный запрос | recommendation | AUD-036; AUD-037 | нет |
| CL-45 | Payment lifecycle | Exactly-once между PostgreSQL и PSP нет: at-least-once доставка, provider idempotency, durable recovery и однократное применение provider fact | recommendation | AUD-037 | нет |
| CL-46 | Idempotency | Scope: seller + environment + command kind + namespace + key; хранить hash и версию canonicalization; другой hash → conflict; key не заменяет business uniqueness | recommendation | AUD-038 | нет |
| CL-47 | Idempotency | Provider idempotency не вечна: ЮKassa — 24 часа, CloudPayments — час; blind retry create после TTL запрещён | verified-doc | C-18; E-08 | DR-02 |
| CL-48 | Unknown | Unknown ≠ confirmed absent: timeout, пустой lookup и «последняя операция по InvoiceId» не оправдывают null; Commerz переводит find=null в failed, поэтому adapter обязан соблюдать строгий контракт | unverified | C-19; AC-05; L-01; L-02 | DR-02 |
| CL-49 | Provider operations | Нейтральный contract описывает capabilities; неподдержанная capability → typed UnsupportedCapability | recommendation | AUD-039 | нет |
| CL-50 | Webhooks | Универсальная подпись webhook — не факт: ЮKassa предлагает status check и IP, CloudPayments — HMAC; нужен provider-specific verifier | unverified | C-17; E-08 | DR-02 |
| CL-51 | Merchant versioning | Старая merchant config закрыта для новых charge, но доступна для lookup, refund и reconciliation до конца business retention | recommendation | AUD-040 | нет |
| CL-52 | Usage | Типы метрик: additive, gauge, distinct, duration, rate, cost, reservation; metric definition версионируется и не меняется после первого факта | recommendation | AUD-041; AUD-042 | нет |
| CL-53 | Usage | Late event после closure — traceable adjustment или correction document, не silent rerate; policy задаётся на metric | recommendation | AUD-043 | нет |
| CL-54 | Usage | Rated line хранит metric version, price version, window, aggregation result, rounding и tax version, source hash, predecessor | recommendation | AUD-044 | нет |
| CL-55 | Usage ownership | Usage fact, деньги и quota — три owners: коллекторы измеряют, Billium выполняет rating, Tariffler резервирует service units | recommendation | AC-01 | нет |
| CL-56 | Подписка | Billing period ≠ grant period; годовая оплата с месячными credits требует unique grant occurrence на компонент и окно | recommendation | AUD-013 | DR-03 |
| CL-57 | Migration | Memster зависит от `subscriptionify ^1.0`; Edufy draft — независимый grant period; нужен адаптер без второго paid state | verified-static | C-23; L-10; L-12 | DR-03 |
| CL-58 | Schedule | Ключ occurrence со schedule revision не задаёт business uniqueness: две revisions создают разные строки одного периода | hypothesis | AC-03; AUD-052 | нет |
| CL-59 | Credits | Monetary prepayment, usage credits, promo grant, reward, provider cost и publisher payable — разные liabilities и owners | recommendation | AUD-045 | нет |
| CL-60 | Credits | Линейная FSM reservation некорректна: commit, release и expiry — альтернативные исходы; partial commit требует conservation и idempotent transitions | hypothesis | AC-04; AUD-046 | DR-03 |
| CL-61 | Credits | `consume()` entitlement-библиотеки недостаточно для async AI: нужен atomic reserve owner и детерминированная late-result policy | recommendation | AUD-046 | DR-03 |
| CL-62 | Outbox/inbox | Financial commit и outbox insert — одна локальная транзакция; afterCommit без durable outbox не гарантирует доставку; Ledger callback ERP — best effort | hypothesis | C-24; AUD-048; L-07 | DR-04 |
| CL-63 | Outbox/inbox | Outbox: aggregate/version, event type/version, lease/fence, dead-letter; inbox: source, seller, external ID, payload hash, signature result, handler version | recommendation | AUD-048 | нет |
| CL-64 | Конкуренция | Σ successful + Σ reserved refunds ≤ refundable captured; lock или version, reservation, commit и только затем PSP-вызов | recommendation | AUD-050 | нет |
| CL-65 | Конкуренция | Allocation: lock balance aggregate, не больше unallocated payment и open obligation, детерминированный порядок, reversal новой строкой | recommendation | AUD-051 | нет |
| CL-66 | Конкуренция | Commerz RefundJournal блокирует refundable payment; все non-failed refunds удерживают сумму; local result fenced | verified-static | C-02; L-04 | нет |
| CL-67 | Расширяемость | Extension map: PSP — adapter + capability; rating — strategy/version; tax и fiscal — ports со snapshot; модуль владеет своими таблицами и не меняет core без migration contract | recommendation | AUD-054; AUD-055 | нет |
| CL-68 | Расширяемость | Disable прекращает новые операции, история читается, данные не удаляются; purge — отдельная privileged операция | recommendation | AUD-056 | нет |
| CL-69 | Расширяемость | Реальные interfaces и extension points финалистов не исследованы; extension map — generic | unverified | C-26; V3-10 | DR-01 |
| CL-70 | Эволюция схемы | Expand/contract: nullable add, dual read, backfill, `NOT VALID`, validate, switch readers, drop после rollback window | recommendation | AUD-057 | нет |
| CL-71 | Эволюция схемы | Status — constrained text или lookup, не PostgreSQL enum; rollback кода не удаляет business facts, коррекция — бизнес-операцией | recommendation | AUD-057 | нет |
| CL-72 | Query paths | Индексы: due, renewals, unknown, provider lookup, idempotency, refundable, usage, outbox, webhook, fiscal, reconciliation; partitioning — только после измерений | recommendation | AUD-058 | нет |
| CL-73 | PHP и Dart | PHP и Dart делят spec, а не библиотеку: Money serialization, exponent, bounds, rounding names, allocation fixtures, refs, canonicalization, envelopes, signatures | recommendation | AUD-060 | DR-04 |
| CL-74 | PHP и Dart | Baseline: Commerz Money — int minor + Brick Math без молчаливого округления; CoreX — Brick Money; ERP — int64 Money с overflow boundaries | verified-static | C-20; L-05; L-06; L-07 | DR-04 |
| CL-75 | PHP и Dart | `minor` передаётся JSON string; float нет; exponent — из versioned table; overflow тестируется одинаково в PHP и Dart | recommendation | AUD-060; AUD-061 | DR-04 |
| CL-76 | PHP и Dart | Dart local writer — для учёта компании, PHP writer — для online vendor billing; dual writer одной книги не выбирать | hypothesis | C-22; AUD-062 | DR-04 |
| CL-77 | PHP и Dart | Committed replica не повторяет posting, tax и FX; импорт хранит source journal ID, sequence и версии | verified-static | C-21; L-08 | DR-04 |
| CL-78 | Книги | Книга клиента AiFina ≠ книга vendor billing; online PHP writer не определяет writer учёта компании | recommendation | AC-09 | нет |
| CL-79 | Purchase-to-ready | Оплата, grant и provisioning — раздельные состояния; сбой provisioning — retry тем же ключом без второго charge; refund до activation отменяет pending grant/provision | recommendation | AUD-063; AUD-064 | нет |
| CL-80 | Agreement | Владелец commercial agreement открыт; один lifecycle writer; grant schedule — у rights; versioned correlation вместо общего изменяемого subscription row | hypothesis | AC-02; AUD-012; R-01 | DR-03 |
| CL-81 | Ownership | Plan и права — тарифный компонент; price version и monetary schedule — billing; usage fact — коллектор; reservation — один quota owner; company posting — финансовый SDK | recommendation | AUD-012 | нет |
| CL-82 | Multi-seller | Seller boundary (legal ID, merchant config, numbering, tax/fiscal, mandates, liability, secrets) входит в каждый financial aggregate и unique key, а не выводится join-ом | recommendation | AUD-065 | нет |
| CL-83 | RU-провайдеры | Primary-аудит ЮKassa, CloudPayments, СБП, МКБ и Atol не выполнен; checklist — placeholders вне backlog R-01…R-30 | unverified | AUD-066; V3-15; E-08 | DR-02 |
| CL-84 | RU-провайдеры | СБП — семейство методов, не один gateway; Atol — fiscal adapter с отдельным receipt lifecycle; receipt outage не делает paid unpaid | recommendation | AUD-066; R-06 | DR-02 |
| CL-85 | Лицензии | Лицензионный риск зависит от режима: dependency, копирование, AGPL network service, fork, Elastic License, REST, schema donor | recommendation | AUD-067 | DR-01 |
| CL-86 | Композиции | Композиция 1 PHP-first modular: профили A и B, минимум зависимостей, commerce остаётся authority; build gap — durable provider execution invariant | recommendation | AUD-069 | нет |
| CL-87 | Композиции | Композиция 2 Lago-centered: C и частично D; минусы — AGPL, Ruby, seller audit, раздел payment ownership, OSS/Premium; fork trigger — seller semantics в core | recommendation | AUD-070 | DR-01 |
| CL-88 | Композиции | Композиция 3 Kill Bill: C/D; Apache-2.0, Java, сложная модель; с OpenMeter — два центра schedule/rating | recommendation | AUD-071 | DR-01 |
| CL-89 | Композиции | Композиция 4 Hybrid: общие contracts и PHP runtime, server engine заменяем, A/B не зависят от C, OpenMeter — при доказанном масштабе | recommendation | AUD-072 | нет |
| CL-90 | Reuse verdict | Money — dependency после conformance; commerce journal — сохранить; idempotency/outbox/inbox — узкий build; universal ERP — не строить; dual-writer — defer | recommendation | AUD-074 | нет |
| CL-91 | Pilot | Pilot (один seller, RUB, один PSP) и bake-off трёх реализаций обязательны сейчас | recommendation | C-25; AUD-075; AUD-076; AUD-078 | нет |
| CL-92 | Fault stories | 26 fault stories — design coverage; ни одна не доказана для финалиста; сначала research acceptance criteria | recommendation | C-26; AC-10; V3-16 | нет |
| CL-93 | Coverage | Том 1 не выполнил source/schema-проход: нет capability matrix candidate → mechanism → evidence, физических схем финалистов и write paths | hypothesis | V3-06; V3-08; V3-09; V3-17 | DR-01 |
| CL-94 | Срез версий | Revisions — moving targets; для выбора vendor нужны release tags и повтор schema diff | recommendation | AUD-010 | DR-01 |
| CL-95 | Тарифы | Subscriptionify — migration source или entitlement adapter, не второй money owner | recommendation | AUD-074 | DR-03 |
| CL-96 | Kill Bill | Doc-утверждения отчёта о payment guide Kill Bill не подтверждены: страница не открылась, разбор опирался на source Janitor | unverified | Q-07; Q-08; AUD-006 | DR-01 |
| CL-97 | Screening | Hosted-решения (Stripe Billing/Cashier, Paddle, Chargebee, Recurly, Zuora) — сравнение API vocabulary, не canonical storage; MoR ≠ self-hosted kernel; ERP — только patterns | recommendation | AUD-011 | нет |
| CL-98 | Референсы | Каталог отчёта — 21 строка, 33 URL; repo root не exact code location, inspected version у многих строк нет | hypothesis | AUD-091; V3-18 | DR-01 |
| CL-99 | Порядок | Сначала ownership и общий acceptance suite, параллельно лицензия и RU, затем vendor drills; отложить settlements, ERP, global offline quota, partitioning | recommendation | AUD-093 | нет |
| CL-100 | Pilot | Failure injections: crash до и после PSP, duplicate webhook, webhook раньше HTTP-ответа, late refund callback, concurrent refunds, recurring duplicate, provisioning timeout, outbox crash, restore | recommendation | AUD-077 | нет |
| CL-101 | OpenMeter | Лучший donor разделения usage event, meter definition, grant, ledger entry и balance snapshot; смена aggregation → новая metric version | recommendation | AUD-022 | нет |
| CL-102 | OpenMeter | Exactly-once insert, drop в collector после retries и late/corrected events — без fault-injection evidence | unverified | AUD-023; R-11 | DR-01 |
| CL-103 | Схемы | Conceptual ERD Lago, OpenMeter и Kill Bill построены по source tree и docs, это не физические модели; ERD не копировать | unverified | C-26; AUD-016; AUD-021; AUD-026 | DR-01 |
| CL-104 | Evidence | Supplied-факты задания местами переходят в рекомендации без нового evidence; `[FACT]` автора — не проверка | hypothesis | V3-01; V3-19 | нет |
| CL-105 | Build | Для собственного journal и reservation ledger нет полного vendor-vs-build gap proof | unverified | V3-02; AUD-074 | DR-01 |
| CL-106 | Роли | Покупатель, плательщик, actor, seller и tenant различены концептуально; identity mappings не проверены | hypothesis | V3-03; AUD-065 | нет |
| CL-107 | PHP embedded | PHP-путь собран из primitives без сопоставимой source-глубины; прочие кандидаты — только screening | hypothesis | V3-07 | DR-01 |
| CL-108 | Конкуренция | Candidate-specific constraints, locks, unknown recovery и outbox atomicity не доказаны | unverified | V3-11; C-12 | DR-01 |
| CL-109 | Usage | Proration, reserve и late-usage enforcement и migration mapping финалистов не исследованы | unverified | V3-12 | DR-03 |
| CL-110 | Эксплуатация | API auth/scopes/errors, audit repair, backup/reconciliation и измеренный footprint в томе 1 не раскрыты | unverified | V3-14; R-29 | нет |
| CL-111 | POS | Неоплаченная подписка POS — продуктовая grace/read-only policy; гостевые операции универсально не блокируются | recommendation | AUD-064 | нет |
| CL-112 | Gaps | Можно ли взять server engine без fork и без коммерческой edition — не установлено | unverified | G-01; C-10; R-02 | DR-01 |
| CL-113 | Gaps | Как exact PSP lookup/refund/auth/TTL ложатся на Commerz — не установлено | unverified | G-02; C-19 | DR-02 |
| CL-114 | Gaps | Владелец quota и договора и миграция subscriptionify — не установлены | unverified | G-03; C-23 | DR-03 |
| CL-115 | Gaps | Conformance Money и accounting authority — не установлены | unverified | G-04; C-20 | DR-04 |
| CL-116 | PHP embedded | Совместимость выбранных PHP-зависимостей с Laravel 13 не проверена | unverified | R-14; C-05 | DR-01 |
| CL-117 | PHP и Dart | Выбор MoneyPHP или Brick Money на общем fixture corpus с Dart не выполнен | unverified | R-15; C-20 | DR-04 |
| CL-118 | Bundle | Частичная отмена или возврат bundle затрагивает только соответствующий компонент agreement; proration — по его условиям | recommendation | R-19; AUD-064 | нет |
| CL-119 | Учёт | Export billing → accounting — через mapper/outbox consumer, без прямой записи в книгу | recommendation | R-20; AUD-054 | нет |
| CL-120 | Restore | Restore БД → reconcile provider facts, replay inbox/outbox без повторного эффекта; exactly-once между системами не предполагается | recommendation | R-21; FS-26 | нет |
| CL-121 | Документы | Профили нумерации и юридических документов зависят от seller и операции; в томе 1 не определены | unverified | R-23 | DR-02 |
| CL-122 | RU-провайдеры | Представление chargeback/dispute у RU PSP не исследовано | unverified | R-24 | DR-02 |
| CL-123 | Retention | Archive и retention event/provider journals не определены; outbox-очередь удаляет доставленное | unverified | R-26; C-16 | DR-01 |
| CL-124 | Query paths | Reporting replica против прямых финансовых запросов — не решено | hypothesis | R-27; AUD-058 | нет |
| CL-125 | Хранение | Portable SQLite для локальных приложений — отложенный вопрос; PostgreSQL важен для shop/server | hypothesis | R-28 | нет |
| CL-126 | Эксплуатация | Manual repair требует authorization, audit и correction provenance | recommendation | R-29 | нет |
| CL-127 | Vendor | Полнота экспорта при замене vendor не исследована; внутренняя схема должна пережить замену vendor | recommendation | R-30; AUD-011 | нет |

## Сводная матрица

16 тем тома 1 × 8 критериев. Профили A–D — сценарии внедрения аудита, а не профили поставки (REQ-PROF-06).
Ячейка: оценка до ~12 слов, метка доказанности, CL.

| Тема | Профили A–D | Embedded без сервера | RU-платежи/54-ФЗ | PHP+Dart | Single writer | Лицензия/edition | Эволюция схемы | Операционная цена |
|:--|:--|:--|:--|:--|:--|:--|:--|:--|
| Lago | C, частично D; не для A/B — hypothesis CL-13, CL-87 | Нет: отдельный Ruby/Rails-сервис — hypothesis CL-13 | RU-адаптеры не проверены, нужен свой — unverified CL-83 | Только через ACL API — hypothesis CL-13, CL-43 | Второй payment journal рядом с Commerz — hypothesis CL-04 | AGPL-3.0 verified-static CL-09; Premium-gate unverified CL-12 | Fork trigger — seller semantics в core — recommendation CL-87 | API, workers, Redis, PostgreSQL, PDF — unverified CL-08 |
| Kill Bill | C/D при приоритете зрелости payment — recommendation CL-88 | Нет: Java-сервис — hypothesis CL-21 | RU PSP через plugins, не проверены — unverified CL-83 | Через facade API, без общего кода — hypothesis CL-21 | Janitor есть verified-static CL-17; locks unverified CL-18 | Apache-2.0 — verified-static CL-09 | History/state machine дорожают миграции — hypothesis CL-21 | Java-сервис и сложная модель — hypothesis CL-21 |
| OpenMeter | Metering для C при доказанном масштабе — recommendation CL-89 | Нет: Kafka и ClickHouse — hypothesis CL-28 | n/a: платёжные возможности edition не установлены — unverified CL-30 | n/a: usage по контракту приёма — recommendation CL-55 | Grants и ledger ≠ одна authority — unverified CL-26 | Apache-2.0 verified-static CL-09; OSS/hosted unverified CL-30 | Immutable meter → новая metric version — verified-doc CL-25 | Kafka, ClickHouse, beta — verified-doc CL-23; hypothesis CL-28 |
| PHP embedded path | A и B, не зависит от C — recommendation CL-31, CL-86 | Да: пакет в хосте — recommendation CL-86 | Свои адаптеры; RU не проверен — unverified CL-83 | PHP Money + общие fixtures — recommendation CL-73; verified-static CL-74 | Commerz — authority в A — verified-static CL-03 | MIT verified-static CL-33; Laravel 13 unverified CL-116 | Собственные миграции expand/contract — recommendation CL-70 | Минимальна, нужен свой recovery suite — recommendation CL-86 |
| Invoice applications | Donor для C, не engine — recommendation CL-36 | Application-first, встраивать нецелесообразно — recommendation CL-36 | n/a: фискал не исследован — unverified CL-83 | PHP-приложения без source-глубины — hypothesis CL-107 | Gateway plugins не доказывают recovery — recommendation CL-36 | Elastic и Apache-2.0 — verified-static CL-33 | n/a: только donor паттернов — recommendation CL-36 | Отдельное приложение с UI — recommendation CL-36 |
| Минимальное payment storage | A/B без Lago и Kill Bill — recommendation CL-37 | Да: таблицы в БД хоста — recommendation CL-37 | Merchant config version под ротацию — hypothesis CL-39 | n/a: Dart денег не пишет — hypothesis CL-76 | Intent/attempt/fence у одного исполнителя — recommendation CL-44 | n/a: собственная схема — recommendation CL-37 | JSON только вне строгих полей — recommendation CL-38 | PostgreSQL хоста и recovery workers — recommendation CL-44 |
| Invoice-модуль | Опционален в A/B, ядро C — recommendation CL-01, CL-41 | Да: поверх payment kernel — recommendation CL-41 | Фискальный документ — отдельная сущность — recommendation CL-84 | n/a: Dart — клиент запросов — hypothesis CL-76 | Allocation, credit note, refund раздельны — recommendation CL-41 | n/a: собственная схема — recommendation CL-41 | Issued меняется только документами коррекции — recommendation CL-42 | Lock balance aggregate на allocation — recommendation CL-65 |
| Server storage | C/D при vendor DB — recommendation CL-43 | n/a: только server-профиль — recommendation CL-43 | RU-адаптеры за ACL не проверены — unverified CL-83 | Dart — клиент versioned API — hypothesis CL-76 | Vendor DB authority, продукты не пишут — recommendation CL-43 | Зависит от engine — verified-static CL-09 | ACL ведёт mapping lifecycle и cutover — recommendation CL-43 | Vendor stack плюс ACL — recommendation CL-43 |
| Idempotency | Одинакова во всех профилях — recommendation CL-46 | Да: таблицы хоста — recommendation CL-46 | TTL ЮKassa 24 ч, CloudPayments час — verified-doc CL-47 | Canonicalization — общая спецификация — recommendation CL-73 | Тот же key, другой hash → conflict — recommendation CL-46 | n/a: узкий собственный build — recommendation CL-90 | Версия canonicalization хранится — recommendation CL-46 | Ключи живут дольше provider TTL — verified-doc CL-47 |
| Usage | Приём фактов в любом профиле — recommendation CL-55 | Да, без OpenMeter — hypothesis CL-28 | n/a: не платёжная область — recommendation CL-55 | n/a: факты приходят по контракту — recommendation CL-55 | Факт, деньги, квота — три owners — recommendation CL-55 | n/a: собственный контракт приёма — recommendation CL-52 | Metric version неизменна — recommendation CL-52 | Late event — adjustment, не rerate — recommendation CL-53 |
| Credits | Денежный prepaid отделён от credits — recommendation CL-59 | Денежный prepaid в БД хоста — recommendation CL-59 | n/a: не платёжная область — recommendation CL-59 | n/a: reward — не деньги — recommendation CL-59 | Один reserve owner; линейная FSM ошибочна — hypothesis CL-60 | Ledger OpenMeter: fit не доказан — unverified CL-26 | n/a: reservation вне Billium — hypothesis CL-60 | Atomic reserve/commit/release — recommendation CL-61 |
| Outbox/inbox | Обязательны во всех профилях — hypothesis CL-62 | Да: таблицы хоста без брокера — recommendation CL-63 | Inbox хранит результат проверки — recommendation CL-63; unverified CL-50 | Event envelopes — общая спецификация — recommendation CL-73 | Commit и outbox атомарно — hypothesis CL-62 | n/a: узкий собственный build — recommendation CL-90 | Event type/version в outbox — recommendation CL-63 | Очередь удаляет доставленное — verified-static CL-27; unverified CL-123 |
| Конкуренция | Одинаковые locks во всех профилях — recommendation CL-64, CL-65 | Row lock PostgreSQL хоста — recommendation CL-64 | n/a: не зависит от PSP — recommendation CL-64 | n/a: Dart не пишет — hypothesis CL-76 | RefundJournal блокирует refundable payment — verified-static CL-66 | n/a: собственная реализация — recommendation CL-90 | Reversal новой строкой — recommendation CL-65 | Vendor locks не доказаны — unverified CL-108, CL-18 |
| Расширяемость | Модули опциональны по профилям — recommendation CL-67 | Модули со своими таблицами — recommendation CL-67 | Новый PSP — adapter + capability — recommendation CL-49, CL-67 | n/a: модули PHP — recommendation CL-67 | Модули не меняют core tables — recommendation CL-67 | Extension points финалистов не изучены — unverified CL-69 | Disable без purge — recommendation CL-68 | Fork trigger при правке core — recommendation CL-87, CL-88 |
| Эволюция схемы | Одна дисциплина для всех — recommendation CL-70 | Миграции пакета в хосте — recommendation CL-70 | n/a: не зависит от PSP — recommendation CL-70 | schemaVersion в transport — recommendation CL-73 | Dual-write только на cutover — recommendation CL-70 | n/a: собственные миграции — recommendation CL-70 | Expand/contract, `NOT VALID`, rollback window — recommendation CL-70 | Constrained text вместо enum — recommendation CL-71 |
| PHP и Dart | Dart — клиент online-биллинга — hypothesis CL-76 | PHP Money в хосте — verified-static CL-74 | n/a: не зависит от PSP — hypothesis CL-76 | Общая spec и fixtures, не библиотека — recommendation CL-73 | Dual writer не выбирать — hypothesis CL-76 | Money-библиотеки MIT — verified-static CL-33 | Версия exponent-таблицы и schemaVersion — recommendation CL-75 | Brick 0.15 вне диапазона CoreX — verified-static CL-34 |

## Поправки и gaps

| ID | Суть | Как учтено | Требования |
|:--|:--|:--|:--|
| AC-01 | Usage facts, деньги и quota — три owners | CL-55; Billium только rating и obligations по своему входному контракту, квоты не резервирует | REQ-CON-15, REQ-OUT-10, REQ-NBR-08, REQ-OWN-01 |
| AC-02 | Commercial agreement открыт | CL-80; канон закрывает вопрос: сага соглашения — у хоста, Billium — денежное расписание и occurrences | REQ-OUT-02, REQ-NBR-06, REQ-OWN-02, REQ-CON-28 |
| AC-03 | Schedule revision не business uniqueness | CL-58; уникальность occurrence — бизнес-идентичность без revision; supersession — задача P1.6 | INV-11, REQ-ACC-09 |
| AC-04 | Reservation FSM некорректна | CL-60; reservation — у Tariffler; Billium получает только принятые факты | REQ-OUT-10, REQ-NBR-07, REQ-CON-15 |
| AC-05 | Unknown ≠ confirmed absent | CL-48; строгий lookup с certificate, иначе unknown | INV-04, REQ-FAIL-08, REQ-CON-08, REQ-CON-29 |
| AC-06 | Merchant config version — proposal | CL-39, CL-51; immutable account операции, ротация только в том же account; форму версии выбирает P1.6 | REQ-OWN-20, REQ-OWN-09 |
| AC-07 | Домены OpenMeter не взаимозаменяемы | CL-26; OpenMeter не принимается как credit authority Billium | REQ-OUT-10, REQ-OUT-14 |
| AC-08 | Vendor edition проверяется до pilot | CL-12; adopt-кандидат проходит edition-проверку в P1.4 до любого invariant spike; новое требование для P1.6 — ниже в «Кандидаты» | REQ-PROF-01, REQ-PROF-02, CONFLICT-10 |
| AC-09 | Книга клиента AiFina ≠ книга vendor billing | CL-78; GL в Billium нет, факты идут через FinBridge | REQ-OUT-04, REQ-NBR-09, REQ-CON-22 |
| AC-10 | Fault stories сначала — research acceptance criteria | CL-92; FS сведены к REQ ниже; каталог приёмки строит P1.7 | REQ-ACC-14, REQ-ACC-18, REQ-ACC-11 |
| G-01 | Server engine без fork и без коммерческой edition | CL-112; DR-01 в P1.4; до ответа adopt server engine не выбирается | REQ-PROF-01, REQ-PROF-21, INV-01 |
| G-02 | PSP lookup/refund/auth/TTL на Commerz | CL-113; DR-02 в P1.4; драйверы — PaymentDrivers | REQ-NBR-04, REQ-OUT-06, REQ-CON-29, REQ-FAIL-16 |
| G-03 | Владелец quota и договора; миграция subscriptionify | CL-114; quota — Tariffler, сага — хост, миграция — адаптеры Billium без угадывания | REQ-OUT-01, REQ-OUT-02, REQ-NBR-16, REQ-PROF-14 |
| G-04 | Money conformance и accounting authority | CL-115; DR-04 в P1.4; GL в Billium нет; форма MoneyWire — CONFLICT-03 | REQ-CON-02, REQ-CON-03, REQ-NBR-12, REQ-OUT-04, CONFLICT-03 |
| G-05 | Юридические продавцы, платность CMS, offline expiry | Вопросы владельцу (I-01, I-02); не выдумываются | REQ-OUT-15, REQ-NBR-13, REQ-PROF-10 |
| G-06 | Tenant/loader, licensing publisher, social/AI seams | Вне границ Billium; том 1 их не закрывает | REQ-OUT-07, REQ-OUT-13, REQ-OUT-19 |

## Fault stories → требования

| ID | История | Механизм (CL) | Требования |
|:--|:--|:--|:--|
| FS-01 | Duplicate checkout | Business uniqueness + idempotency hash (CL-46) | REQ-FAIL-06, REQ-FAIL-07, REQ-CON-32, INV-05 |
| FS-02 | PSP accepted, process died | Persist-before-call + lookup (CL-44) | REQ-FAIL-02, INV-03, INV-04 |
| FS-03 | Timeout | Unknown и отложенный lookup (CL-48) | REQ-FAIL-02, REQ-FAIL-08, REQ-CON-05, REQ-CON-08, INV-04 |
| FS-04 | Expired worker lease | Fence local write + provider lookup (CL-44) | REQ-FAIL-14, REQ-FAIL-17, INV-02 |
| FS-05 | Expired PSP idempotency | Durable business reference (CL-47) | REQ-FAIL-07, REQ-FAIL-16, INV-04 |
| FS-06 | Concurrent partial refund | Refund reservation + row/version lock (CL-64) | INV-10, REQ-FAIL-09 |
| FS-07 | Out-of-order webhook | Transition guard + inbox (CL-63) | REQ-FAIL-03, REQ-FAIL-04, REQ-FAIL-13, INV-06 |
| FS-08 | Credential rotation | Merchant config version на операции (CL-51) | REQ-OWN-20, REQ-OWN-09 |
| FS-09 | Two sellers | Seller в каждом ключе и config (CL-82) | REQ-PROF-08, REQ-CON-28 |
| FS-10 | Price change | Immutable terms и rate snapshot (CL-54) | INV-12, REQ-CON-33 |
| FS-11 | Issued invoice edit | State-specific mutation policy (CL-42) | INV-12, REQ-CON-36, REQ-CON-38; новое требование для P1.6: политика изменений invoice по состояниям с allow-list metadata после issuance (CL-42) |
| FS-12 | Annual billing / monthly grants | Unique occurrence (CL-56) | INV-11, REQ-OWN-02, REQ-ACC-08, REQ-NBR-07 |
| FS-13 | AI reservation | Reserve/commit/release — у quota owner (CL-61) | REQ-OUT-10, REQ-NBR-08 |
| FS-14 | Gauge correction | Correction policy по версии метрики (CL-53) | REQ-CON-16, REQ-FAIL-21 |
| FS-15 | Duplicate schedule job | Unique occurrence (CL-58) | INV-11, REQ-OWN-03 |
| FS-16 | Paid / provision failed | Idempotent provisioning key у хоста (CL-79) | REQ-OWN-19, REQ-FAIL-24, REQ-FAIL-27, REQ-OUT-11 |
| FS-17 | Refund before activation | Generation/state check соглашения (CL-79) | REQ-FAIL-27, REQ-FAIL-19, REQ-NBR-06 |
| FS-18 | Receipt outage | Независимое состояние чека (CL-84) | REQ-CON-24, REQ-FAIL-24 |
| FS-19 | Offline POS | Локальная продуктовая policy (CL-111) | REQ-OUT-12, REQ-CON-37 |
| FS-20 | Journal replication | Dedupe по source journal (CL-77) | REQ-CON-35, REQ-CON-34, REQ-OUT-04 |
| FS-21 | Forged organization ID | Host-authenticated context (CL-82, CL-106) | REQ-CON-21, REQ-NBR-14, REQ-OUT-13 |
| FS-22 | Reward/settlement confusion | Раздельные книги (CL-59) | REQ-OUT-05, REQ-OUT-09, REQ-OWN-12 |
| FS-23 | Bundle cancellation | Компоненты соглашения (CL-118) | REQ-OWN-13, REQ-OWN-19, REQ-ACC-23 |
| FS-24 | Commit-before-dispatch crash | Transactional outbox (CL-62) | REQ-CON-18, INV-08 |
| FS-25 | Rolling upgrade | Expand/contract migration (CL-70) | REQ-CON-20; новое требование для P1.6: expand/contract миграции и одновременная работа старых и новых workers без потери journal (CL-70, CL-71) |
| FS-26 | Restore/replay | Reconciliation checkpoints (CL-120) | REQ-FAIL-15, REQ-FAIL-23, REQ-FAIL-25, REQ-ACC-12 |

## Кандидаты

Это отбор входов для P1.6, не выбор схемы. Канон уже сужает пространство: обязательны только contracts +
core в одном коде для `embedded` и `server` (REQ-PROF-01), лёгкий путь без центрального сервера
(REQ-PROF-02), драйверы — PaymentDrivers (REQ-OUT-06), GL — AiFina (REQ-OUT-04), квоты — Tariffler
(REQ-OUT-10). Профили A–D аудита используются только как измерение (REQ-PROF-06).

### Проходят в P1.6

| Кандидат | Роль в P1.6 | Основания | Что ещё блокирует |
|:--|:--|:--|:--|
| K1. PHP-first modular core (композиция 1) | Базовая альтернатива build для всех семи областей; единственная, совместимая с `embedded` без сервера | CL-31, CL-37, CL-86, CL-90; REQ-PROF-01, REQ-PROF-02 | Build gap — durable execution invariant (CL-86); vendor-vs-build proof отсутствует (CL-105) |
| K2. Hybrid: свои contracts и PHP core, server engine за ACL (композиция 4) | Альтернатива для `server`-профиля: заменяемый engine за anti-corruption layer | CL-43, CL-89, CL-127; REQ-CON-01, REQ-PROF-21 | Engine без fork/edition не установлен (CL-112, G-01) |
| K3. Lago как внешний engine rating/usage/invoicing в `server` | Вторая альтернатива областей rating/schedule и obligations/invoicing; только remote за ACL | CL-10, CL-14, CL-87 | AGPL (CL-09, CL-15, CONFLICT-10), Premium-gate (CL-12), seller isolation (CL-11), concurrency (CL-16) — DR-01 |
| K4. Kill Bill как внешний engine execution/collection в `server` | Вторая альтернатива областей execution journal и collection | CL-17, CL-20, CL-88 | Locks/balances (CL-18), tenant ≠ seller (CL-19), RU-плагины (CL-83), doc claims (CL-96) — DR-01, DR-02 |

Schema donors без статуса engine (берутся идеи, не код и не миграции, CL-85):

- Commerz payment/refund journal — образец исполнения и refund lock (CL-03, CL-66); код Commerz Billium не
  читает и не пишет в runtime (REQ-OWN-11, REQ-OWN-16).
- Kill Bill — logical payment / transaction / attempt и Janitor (CL-20).
- OpenMeter — неизменяемая семантика метрики и разделение usage event / ledger entry (CL-24, CL-25, CL-101).
- Lago — seller/organization scoping в financial и idempotency tables (CL-14).
- Invoice applications — нумерация и document lifecycle (CL-36).

Подсказка P1.6 по областям (минимум две альтернативы на решение):

| Область | Альтернативы из аудита | Основания |
|:--|:--|:--|
| contracts | Собственная spec + PHP/Dart fixtures (K1/K2) против vendor API как публичного контракта | CL-73, CL-43, CL-22 |
| execution journal | Узкий PHP build по образцу Commerz (K1) против Kill Bill за ACL (K4) | CL-37, CL-44, CL-90; CL-17, CL-20 |
| obligations/invoicing | PHP-модуль поверх core (K1) против Lago или Kill Bill invoicing в `server` (K3/K4) | CL-40, CL-41, CL-42; CL-10, CL-16 |
| rating/schedule | Собственный rating с provenance (K1) против Lago charges/fees (K3) | CL-54, CL-58; CL-14 |
| usage-приём | Собственный входной контракт (K1) против OpenMeter как внешнего источника фактов, не owner | CL-52, CL-55; CL-24, CL-28 |
| документы | Собственные ports и нумерация (K1) против invoice application как donor | CL-42, CL-121; CL-36 |
| collection | Опциональный PHP-модуль (K1) против retry/collection Kill Bill (K4) | CL-90; CL-20 |

### Отсеяны

| Кандидат | Причина | Основания |
|:--|:--|:--|
| Любой server engine как обязательная зависимость embedded | Нарушает лёгкий путь без сервера | REQ-PROF-02; CL-13, CL-21, CL-28 |
| OpenMeter как payment owner или credit authority | Edition платежей не установлена; grant и ledger — разные механизмы; квоты — Tariffler | REQ-OUT-10; CL-26, CL-30 |
| Omnipay/Payum как orchestrator или слой драйверов Billium | Не durable orchestrator; драйверы — PaymentDrivers | REQ-OUT-06; CL-32 |
| Invoice applications как billing engine | Application-first, без usage billing и recovery | CL-36 |
| Hosted SaaS и MoR (Stripe, Paddle, Chargebee, Recurly, Zuora) | Не self-hosted основа, provider lock-in | CL-97 |
| ERPNext/Tryton, GL внутри Billium | Книга компании — AiFina | REQ-OUT-04; CL-78 |
| Dual writer PHP/Dart | Dart — клиент, не writer | REQ-OWN-18; CL-76 |
| Subscriptionify как money owner | Только источник миграции | REQ-NBR-16; CL-95 |
| Pilot и bake-off трёх реализаций в P1 | Production-кода в P1 нет; допустимы только минимальные spikes | D5; CL-91 |

### Заявки на новые требования для P1.6

Аудит выявил нормы, которых нет в `docs/requirements.md`. P1.6 принимает их D# с альтернативами и
дописывает REQ с SRC + DR + D# либо фиксирует отказ:

1. Политика изменений invoice по состояниям, allow-list metadata после issuance (FS-11, CL-42).
2. Expand/contract миграции, совместимость старых и новых workers, status как constrained text (FS-25, CL-70, CL-71).
3. Строгие реляционные поля денег, seller, provider reference, state и idempotency scope; JSON — только bounded metadata (CL-38).
4. Seller в каждом денежном агрегате и уникальном ключе, не только изоляция на уровне профиля (CL-82; дополняет REQ-PROF-08).
5. Edition- и release-tag-проверка любого adopt-кандидата до spike (CL-12, CL-94; AC-08).
6. Retention и архив provider/event journals отдельно от очереди outbox (CL-123; дополняет REQ-FAIL-16).
7. Форма неизменяемого merchant binding операции (CL-39, CL-51; дополняет REQ-OWN-20).
