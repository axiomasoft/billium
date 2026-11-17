# Billium — требования и трассировка

Статус: требования уровня A корпуса (PLAN1.P1.1), дописанные строки уровней B и C (PLAN1.P1.2) и строки,
добавленные по заявкам аудита решениями D20–D26 (PLAN1.P1.6);
существующие строки не перенумерованы, новые получили свободные номера в своих разделах. Источник каждой строки — ID `SRC-NNN` из
`research/P1-corpus.md` плана `2026.10.14-№1-BILLIUM-V1` в [workspace](planning.md)
(`BRAIN` = `/home/vostrikov/Vaults/Brain`);
`§` указывает раздел документа. Диспозиция каждого прочитанного документа — в
`knowledge/corpus-ledger.md` того же плана в workspace.

Правила документа:

- ID неизменяемы: `REQ-<AREA>-NN` (AREA ∈ OWN, PROF, CON, FAIL, ACC, NBR, OUT), `INV-NN`,
  `CONFLICT-NN`. Удалённое требование помечается, а не перенумеровывается.
- Требование без источника не пишется (D7). Интерпретация исполнителя помечена словом
  `hypothesis`; бизнес-факты не выбираются, а помечены как вопрос владельцу.
- Аудит Perplexity и задания DR не являются источником требований: их выводы разбирают P1.3 и P1.4.
- Норматив выше паспортов: канон (`SRC-016`, `SRC-002`, `SRC-009`, `SRC-013`, `SRC-041`).
  Расхождение паспорта с каноном фиксируется в разделе «Конфликты», а не исправляется молча.
- Термины контрактов — как в источниках (`operationRef`, `commandKey`, `PriceVersion`, occurrence,
  allocation). Омонимы: «счёт гостя» AiResto ≠ invoice Billium ≠ фискальный чек (`SRC-002` §7);
  правила канона `B-01…B-16` (`SRC-016` §3) ≠ таблица требований `B-01…B-16` в `SRC-014`.

## Владение — state → writer

Таблица «state → writer» по правилу канона B-01. «Billium» означает Billium либо ровно один выбранный
delegated engine внутри Billium; второго writer у сущности нет.

| ID | Требование | Writer / профиль | Источник |
|:--|:--|:--|:--|
| REQ-OWN-01 | Billium владеет SaaS/usage `PriceVersion` и rating: frozen условия + принятые факты → денежное начисление. Розничная цена и промо в rating Billium не входят | Billium; оба профиля | SRC-002 §4; SRC-016 §4.2; SRC-027 (capability «SaaS/usage PriceVersion, rating»); SRC-012 §2 |
| REQ-OWN-02 | Billium владеет денежным `BillingSchedule` и денежными occurrences (billing / collection occurrence). Grant occurrence и `PlanVersion` принадлежат Tariffler | Billium | SRC-002 §4; SRC-012 §2; SRC-015 §3 |
| REQ-OWN-03 | Billium владеет obligations — долгом покупателя перед продавцом ПО. Одна obligation возникает из одного основания начисления, а не из каждого retry или revision | Billium | SRC-002 §4; SRC-003 §Billium; SRC-012 §3 |
| REQ-OWN-04 | Billium владеет invoices и credit notes, включая юридический статус документа. Область действия — продавец (seller), а не tenant или бренд | Billium; software-seller профиль | SRC-002 §4; SRC-024 §Owners; SRC-025 |
| REQ-OWN-05 | Billium владеет allocation платежей на obligations и выдаёт собственный `allocationReceipt`. Он не является payment receipt | Billium | SRC-002 §4; SRC-005 §Identities; SRC-027 (capability «Invoice/credit note/allocation/collection») |
| REQ-OWN-06 | Billium владеет collection и dunning. Решение ограничить доступ принимает rights/product policy, а не collector; driver dunning не ведёт | Billium (опциональный модуль) | SRC-002 §4; SRC-025; SRC-014 §Модульная граница |
| REQ-OWN-07 | Billium владеет юридической нумерацией документов. Номер резервирует seller-scoped legal writer (уникальный scope seller + series + period); renderer номер не выдаёт и статус не меняет | Billium | SRC-002 §4; SRC-024 §Owners, §Proposed shapes |
| REQ-OWN-08 | Billium исполняет собственные платежи и возвраты для своих операций: журнал попыток, unknown-recovery, inbox callbacks, reconcile, исполнение refund. В профилях `remote-billium` и `standalone-billium` Billium execution — единственный исполнитель | Billium execution | SRC-002 §4; SRC-005 §Authority profiles; SRC-027 (capability «Payment/refund attempt, unknown/retry/inbox») |
| REQ-OWN-09 | Provider binding операции (frozen request, provider key, `providerOperationId`, evidence) принадлежит execution owner операции. Driver этим state не владеет | Execution owner операции | SRC-005 §Identities; SRC-037 §2 |
| REQ-OWN-10 | Реестр денежных намерений (`businessIntentRef` → `operationRef`, fingerprint, tombstones) принадлежит execution owner. Это не второй payment journal: статус и баланс остаются у writer операции | Execution owner (Billium execution для своих операций) | SRC-044 §1 |
| REQ-OWN-11 | Когда исполняет Commerz (`embedded-commerz`), Billium ведёт только собственную денежную проекцию и allocation через свой inbox. Таблицы Commerz Billium не пишет и не читает | Billium | SRC-006 §2, §5; SRC-023 §4; SRC-016 §3 |
| REQ-OWN-12 | Billium владеет денежным prepaid-балансом (неприменённый платёж, monetary customer credit) и refundable amount. Немонетарные grants и квоты к ним не относятся | Billium | SRC-033 §Credits; SRC-020 §Does Billium require double-entry; SRC-001 §Worked examples |
| REQ-OWN-13 | В bundle Billium владеет денежной allocation компонентов и credit notes по компонентам. Состав прав остаётся у rights-владельцев, условия bundle — у хоста | Billium | SRC-030 §Owners; SRC-012 §6 |
| REQ-OWN-14 | Discrepancy-факты (подтверждённый внешний эффект сверх локальных границ) по своим операциям фиксирует execution owner. Полученный от Commerz discrepancy Billium хранит как отдельную lineage и не исправляет его новым денежным эффектом | Execution owner / Billium как получатель | SRC-023 §1–§2; SRC-006 §7 |
| REQ-OWN-15 | Idempotency, outbox, inbox и receipts Billium хранит в своих таблицах. Семантика — по Engineering Standards §2; общую библиотеку в замороженные API не вводить | Billium | SRC-009 CD-09; SRC-041 §2; SRC-002 §8 |
| REQ-OWN-16 | Billium пишет только свои таблицы и не читает чужие; это проверяет gate владения таблицами | Billium | SRC-016 §3; SRC-041 §2 |
| REQ-OWN-17 | В каждом профиле поставки на инсталляцию приходится один writer каждой сущности Billium. План Billium ведёт таблицу «state → writer» | Billium | SRC-002 §4; SRC-016 §1 п. 5, §3 |
| REQ-OWN-18 | Dart — клиент контрактов и SDK. Он не пишет денежное state и не повторяет lifecycle исполнения | Dart SDK — только чтение и команды | SRC-025; SRC-016 §1 п. 6 |
| REQ-OWN-19 | Возврат по компоненту соглашения или bundle рассчитывается от исходной оплаты, `PriceVersion` и условий этого компонента, а не долей прав. Сбой provisioning не порождает повторный charge | Billium | SRC-077 §W-03; SRC-091 §W-03 |
| REQ-OWN-20 | Исходный merchant account и authority исполнения операции неизменяемы; ротация credentials допустима только в том же account. Маршрут по умолчанию не переносит старые операции к новому исполнителю | Execution owner операции | SRC-111 §I-17 |
| REQ-OWN-21 | Execution binding операции неизменяем и записывается до первой отправки: seller, merchant account, environment, драйвер и версия его контракта, `bindingRevision`, ссылка на credentials (ротация — новая версия credentials того же account), `routingEpoch`. Refund и lookup используют binding исходной операции; секреты в binding не хранятся | Execution owner операции | SRC-022 (AUD-040 → CL-51; заявка P1.3 CL-39); SRC-111 §I-17; DR02-27; D26 |

## Профили поставки и потребители

### Профили и оси

| ID | Требование | Источник |
|:--|:--|:--|
| REQ-PROF-01 | Профили поставки: `embedded` (Laravel-пакет внутри хоста) и `server` (отдельный сервис с API для нескольких продуктов) на одном коде. Обязательны только `billium-contracts` и core | SRC-009 CD-14; SRC-002 §4 |
| REQ-PROF-02 | Лёгкий embedded-путь не требует CoreX, Tariffler, центрального сервера или приватного ERP | SRC-014 §Требования; SRC-006 §1; SRC-018 §Interfaces; SRC-016 §1 п. 4 |
| REQ-PROF-03 | Дополнительные модули и профили не расширяют обязательный install footprint потребителя | SRC-025 §Что ещё не определено; SRC-014 §Модульная граница |
| REQ-PROF-04 | Профили authority исполнения — отдельная ось: `embedded-commerz` (исполняет Commerz, Billium максимум ведёт allocation), `remote-billium` (исполняет Billium execution, Commerz хранит проекцию), `standalone-billium` (obligations и collection операции исполняет Billium) | SRC-005 §Authority profiles; SRC-002 §2 |
| REQ-PROF-05 | Профиль исполнения фиксируется в операции с первого принятого execution command и не меняется. Смена профиля действует только для новых операций или через отдельный fenced handover. Появление Billium server старые операции не переключает | SRC-005 §Authority profiles; SRC-016 §3; SRC-006 (рекомендация); SRC-021 §Решения |
| REQ-PROF-06 | Оси не отождествляются: профили A–D аудита (сценарии внедрения: магазин, малое приложение, billing service, remote execution), профили поставки embedded/server, профили authority исполнения и модели дистрибуции D1–D6. Матрица P1.3 использует A–D только как измерение | SRC-022 §Решения по профилям; SRC-001 §I-02; SRC-009 CD-14 |
| REQ-PROF-07 | В standalone-подписке Billium не создаёт фиктивный розничный заказ ради вызова Commerz и не тянет Commerz как зависимость | SRC-006 §2; SRC-018 §Interfaces; SRC-015 §3 |
| REQ-PROF-08 | В server-профиле продавцы изолированы. Два продавца не смешивают балансы, нумерацию, мандаты и секреты. Credentials провайдера не выводятся из tenant или бренда | SRC-014 §Требования; SRC-025; SRC-005 §Admission |
| REQ-PROF-09 | Пакеты tenancy-neutral: в доменных таблицах Billium нет `tenant_id` без решения. Изоляцию даёт хост (соединение, БД, scope) | SRC-016 §3; SRC-041 §1 |
| REQ-PROF-10 | Платность — свойство доступности пакета, а не ветки кода: нет edition-флагов и `pro`-веток. Состав бесплатного и платного — вопрос владельцу (I-02); рекомендация «полезная бесплатная база без paywall-инвариантов» — hypothesis до решения владельца | SRC-016 §1 п. 2, §3; SRC-009 (открытые inputs); SRC-001 §I-02 |
| REQ-PROF-20 | Первый профиль Billium — библиотека obligations/invoice/allocation; remote execution — позже и не заменяет исполнение Commerz. Рекомендация источников (O-06, disposition DEFER), подтверждается в P1.6 | SRC-053 §O-01…O-10; SRC-089 §Billium |
| REQ-PROF-21 | Опциональный runtime Billium execution (standalone/remote) включается только при доказанных operational owner, SLO, backup/restore и migration plan | SRC-053 §Wave 4 |
| REQ-PROF-22 | «Лёгкий» и бесплатный профиль — ограниченный scope, а не ослабленные инварианты: изоляция продавцов, idempotency, recovery после commit, точность валют и целостность экспорта одинаковы во всех комплектациях. Выбор комплектации B-A/B-B/B-C — вопрос владельцу (I-02) | SRC-061 §Минимальная полезная бесплатная часть, §Billium; SRC-088 §Billium |

### Потребители

Профиль и контракт потребителя записаны только там, где их называет источник; иначе — вопрос
владельцу. Первый платный потребитель не выбран (I-18).

| ID | Потребитель и требование | Профиль | Контракт | Источник |
|:--|:--|:--|:--|:--|
| REQ-PROF-11 | Flex / FlexCRM — SaaS-подписки и invoicing продавца ПО | `embedded` во FlexCRM-облаке (канон) или `server`; выбор — вопрос владельцу | obligations, invoices, collection, исполнение (`standalone-billium`) | SRC-002 §4; SRC-004 §Синергии; SRC-001 §Fit |
| REQ-PROF-12 | FlexCMS / AiFina / AiResto — лицензии и подписки через их PHP-облака. Lease выдаёт хост после оплаченного occurrence, не Billium | `embedded` в облаке продукта (AiFina Cloud и т. п.) | obligations, invoices, collection; occurrence для хоста | SRC-002 §4, §6; SRC-013 §Пары без моста |
| REQ-PROF-13 | AiResto MP — тарифы и оплата подписки сети. MP хранит только ссылки на subscription и grant | `embedded` в AiResto MP | obligations, collection; refs для MP | SRC-002 §4, §7; SRC-009 CD-07 |
| REQ-PROF-14 | Memster — денежная часть `billing` переходит в Billium при первом платном сценарии; legacy ID и история сохраняются; награды остаются в продукте | вопрос владельцу (embedded или server) | `prepareObligation`, `ensureBillingOccurrence`, `applyPaymentObservation`, исполнение без заказа Commerz | SRC-002 §4; SRC-009 CD-08; SRC-015 §3–§4, §8 |
| REQ-PROF-15 | Edufy — денежная часть `commerce Billing` переходит в Billium при первом платном сценарии. Credit ledger Edufy остаётся владельцем credits | вопрос владельцу | денежный rating и obligation — только при платном предложении | SRC-002 §4; SRC-009 CD-08; SRC-038 §Owners |
| REQ-PROF-16 | Agelto — доступ, seats, данные, AI и API. Начисления клиенту отделены от расходов на LLM | вопрос владельцу | rating по нормализованным фактам, obligations | SRC-002 §4; SRC-014 §Потребители |
| REQ-PROF-17 | ChatomIo — деньги в Billium, MAU и сообщения приходят как usage-факты по входному контракту Billium | вопрос владельцу | usage-приём, rating, obligations | SRC-002 §4; SRC-003 §ChatomIo |
| REQ-PROF-18 | Commerz — через мост `commerz-billium` (дом — Commerz) в профиле `remote-billium`. Billium публикует для него `billium-contracts` | `remote-billium` | `payment.execute.v1` / `payment.outcome.v1` | SRC-009 CD-02; SRC-005 §Package layout; SRC-013 §Реестр |
| REQ-PROF-19 | Прочие упомянутые кандидаты (FlexStore, Diabox, AxiomaSoft-услуги, RegHub) в каноническом списке потребителей отсутствуют. Розница идёт через Commerz. Не проектировать до подтверждения владельцем | вопрос владельцу | — | SRC-004 §Синергии; SRC-014 §Потребители; SRC-002 §4, §8 |

## Контракты

| ID | Требование | Источник |
|:--|:--|:--|
| REQ-CON-01 | `billium-contracts` — лёгкий пакет для мостов. Он зависит только от контрактов; мост Commerz живёт в репозитории Commerz | SRC-009 CD-02; SRC-013 §Правила моста п. 1, п. 3; SRC-002 §2 |
| REQ-CON-02 | Деньги на межпакетной границе — MoneyWire: decimal string + ISO 4217 + scale валюты; float запрещён. У каждого рантайма свой VO; общий PHP money-пакет не создаётся. Точная форма строки — CONFLICT-03 | SRC-016 §3; SRC-009 CD-05; SRC-002 §8 |
| REQ-CON-03 | Общее между PHP и Dart — wire-спецификация и conformance fixtures, а не общий код. Результаты PHP и Dart совпадают по суммам, округлению и ошибкам до принятия общего wire | SRC-016 §1 п. 6; SRC-041 §6; SRC-019 §Приёмка; SRC-018 §Interfaces |
| REQ-CON-04 | Команда `payment.execute.v1` (ExecutePayment) несёт `commandKey`, `operationRef`, `operationKind`, source (`context`, `orderRef`, `intentRef`), `installationRef`, `environment`, `executionProfile`, `routingEpoch`, `sellerRef`, `merchantAccountRef`, money, `bindingRevision`, `returnContextRef`, `requestedAt`. Ответ — `accepted`, `known-terminal` или `conflict` с operationRef, revision, digest и outcome query ref | SRC-005 §Command envelope |
| REQ-CON-05 | Ошибки `invalid`, `denied`, `expired`, `recovery-required` не являются исходами провайдера и не запускают автоматический retry новым ключом. Timeout или transport error не означают, что команда не принята | SRC-005 §ExecutePayment |
| REQ-CON-06 | `RequestRefund` получает собственный `operationRef`, ссылается на `originalOperationRef`, несёт точную сумму и неизменяемые reason/category. Повтор запроса из UI/API использует тот же `commandKey` | SRC-005 §RequestRefund; SRC-006 §2 |
| REQ-CON-07 | `CancelOperation` запрещает новые отправки и replay после отзыва product authorization, но уже отправленный запрос не отменяет. `cancelled` допустим только после доказанного отсутствия эффекта; capture после cancellation сохраняется как факт | SRC-005 §CancelOperation |
| REQ-CON-08 | `FindOperation` возвращает `accepted-unknown`, `pending`, `succeeded`, `failed-terminal` или `cancelled` с revision, digest и cumulative totals. `absent` — отдельный результат с гарантией полноты lookup в исходном namespace. Stale replica, отказ доступа, пробел retention или restore дают typed `unavailable` / `recovery-required`, не `absent` | SRC-005 §FindOperation; SRC-006 §6 |
| REQ-CON-09 | Исход `payment.outcome.v1` несёт `operationRef`, монотонную `revision`, `status`, money, `capturedTotal`, `refundedTotal`, `bindingRevision`, `sellerRef`, `merchantAccountRef`, `providerEvidenceRef`, `occurredAt`, `digest`. Totals cumulative; refund outcome несёт свой `operationRef` и `originalOperationRef` | SRC-005 §Outcome envelope |
| REQ-CON-10 | Receipts различаются: `CommandAccepted`, `ProviderOutcomeRecorded`, `ProjectionApplied`, `AllocationReceipt`, `FulfilmentReceipt`. Ни один не подменяет другой | SRC-005 §Receipt boundaries |
| REQ-CON-11 | `prepareObligation(agreementRef, revision, component, billingOccurrenceRef, priceVersion, snapshotDigest)` воспроизводит денежные условия, валюту, округление и ссылку на налоговый snapshot. Receipt связан с единственным основанием начисления | SRC-012 §3; SRC-018 §Interfaces; SRC-003 §Billium |
| REQ-CON-12 | `authorizeCollection(obligationRef, businessPaymentIntent, executorBinding)` — отдельное бизнес-разрешение на сбор. Уже оплаченное намерение повторно не собирается | SRC-012 §3; SRC-003 §Billium |
| REQ-CON-13 | `applyPaymentObservation(operationRef, authoritativeRevision, values, evidence)` применяет денежное наблюдение идемпотентно. Refund и correction связаны с исходной операцией | SRC-012 §3; SRC-018 §Interfaces |
| REQ-CON-14 | Запросы восстановления только читают и аутентифицированы: `paymentStatus`, `obligation`, `allocationByOperation`, `refundAuthorization`, receipt-запросы, `readRender`. Нерешённый результат не выдаётся за успех | SRC-003 §Billium; SRC-018 §Interfaces; SRC-012 §3 |
| REQ-CON-15 | Usage и overage Billium принимает по своему входному контракту, не завися от пакета-источника. Факт несёт стабильный `(source, factId)`, `metricVersion`, unit, subject, interval, provenance, event time и received time, identity коррекции и digest | SRC-009 CD-15; SRC-034 §Late usage; SRC-018 §Interfaces |
| REQ-CON-16 | Семантика метрики во входном факте явная: additive, gauge (заменяется более поздней revision, не суммируется) или distinct (множество, не сумма частичных счётчиков) | SRC-034 §Late usage; SRC-021 §Решения |
| REQ-CON-17 | Существующий `OverageEvent` v1 не меняется и не расширяется молча. Новое основание начисления — отдельная версионированная форма с compatibility map | SRC-033 §Credits; SRC-018 §Interfaces |
| REQ-CON-18 | События Billium публикуются через свой outbox в той же транзакции, что и state. У события стабильный `eventId`, версия схемы, revision и digest; повтор доставки сохраняет identity и содержимое | SRC-041 §2; SRC-023 §2; SRC-045 §Общая квалификация |
| REQ-CON-19 | Ошибки — реестр problem codes (retryable, нужен ли receipt) и problem+json на HTTP | SRC-041 §2 |
| REQ-CON-20 | Схемы и идентичности замораживаются до 1.0, далее меняются только аддитивно (schema snapshot). Неизвестная обязательная схема или major-версия отвергаются до эффекта; неизвестные необязательные поля допустимы только вне semantic hash и authorization | SRC-041 §2; SRC-005 §Acceptance tests; SRC-012 §3; SRC-045 §Общая квалификация |
| REQ-CON-21 | Общий конверт команды: owner namespace, profile, incarnation, бизнес-ключ, canonicalization и schema version, digest, causation и correlation. ID доставки — не бизнес-ключ; ID в payload — селекторы, а не права доступа | SRC-045 §Общая квалификация; SRC-005 §Admission |
| REQ-CON-22 | Экспорт в учёт: Billium как источник FinBridge публикует invoice, captured payment, allocation, refund и credit note отдельными фактами со стабильным `sourceFactRef` на каждый экономический эффект. Коррекция — новый связанный факт. Мост `billium-finbridge` живёт в Billium (статус proposed) | SRC-029 §B; SRC-013 §Реестр; SRC-002 §9; SRC-045 FC-02 |
| REQ-CON-23 | Billium execution зависит только от `payment-drivers/contracts` и выбранного драйвера. Продукты драйверы напрямую не вызывают | SRC-002 §3; SRC-013 §Реестр |
| REQ-CON-24 | Фискализацию запускает исполнитель оплаты после подтверждённого исхода charge/refund: через receipt capability драйвера PSP или fiscal driver. Статусы платежа и чека независимы; unknown чека разрешается lookup, а не повторной отправкой; содержимое чека задаёт общая спецификация с fixtures | SRC-002 §5; SRC-009 CD-04; SRC-024 §Success |
| REQ-CON-25 | Порты документов: `reserveLegalDocument` (seller + series + period, economicRef, тип, numberingPolicy, ключ), `renderDocument`, `readRender`, `deliverOutput` и связанные `correctDocument` — независимые операции. Credit note, фискальная коррекция и refund PSP — разные действия | SRC-024 §Proposed shapes; SRC-045 FC-05; SRC-006 §2 |
| REQ-CON-26 | Dart SDK — тонкий клиент server API и контрактов; lifecycle исполнения он не повторяет | SRC-025 |
| REQ-CON-27 | Приём денежного feed от Commerz: конверт наблюдения содержит `schemaVersion`, `eventId`, `contentDigest`, `sourceAuthority`, `sourceLineage`, `partitionRef`, `sourceSequence`, `operationRef`/kind, `originalPaymentRef`, `businessIntentRef`, `operationRevision`, state, currency, cumulative totals, `providerAccountRef`, evidence и отдельную lineage discrepancy | SRC-023 §2; SRC-006 §4 |
| REQ-CON-28 | Ссылка строки покупки неизменяема: `AgreementRef + CommercialRevision + componentRef + subjectRef + OfferSnapshotRef + TermsRef + PriceVersionRef + PlanVersionRef + executorBindingRef`. Tenant, user или org не подставляются вместо seller или subject | SRC-012 §2; SRC-015 §3 |
| REQ-CON-29 | Порт восстановления исполнения различает `queryOriginal` (без эффекта), `replayOriginalWithinWindow` (тот же key/body/account по отдельному разрешению), `recoverOutcome` и `inspectTerminal`. Результаты: `found`, `confirmedAbsent(certificate)`, `uncertain(reason, nextCheckAt)`, `unsupported` | SRC-037 §3; SRC-006 §6; SRC-018 §Interfaces |
| REQ-CON-30 | Внутренние деньги: целые minor units + валюта, точная арифметика, явное округление, largest-remainder allocation | SRC-041 §2; SRC-002 §8 |
| REQ-CON-31 | Публичная поверхность пакетов — только `Contracts`, `Data`, `Enums`, `Events`, `Exceptions`; маркеры `#[Api]` и `#[Spi]`; остальное `Internal/`. Eloquent не появляется в публичных сигнатурах | SRC-041 §1 |
| REQ-CON-32 | Idempotency-ключ claim-ится в корневой транзакции мутации с digest payload. Повтор возвращает сохранённый receipt, другой payload даёт conflict, replay проверяет текущую авторизацию | SRC-041 §2; SRC-044 §1 |
| REQ-CON-33 | Начисление не выводится из изменяемого поля текущего плана. Изменение плана или цены проходит preview/quote → фиксацию условий в revision соглашения хоста → явный pending/commit; вторая таблица subscriptions с независимым коммерческим lifecycle не вводится | SRC-049 §Владение подпиской и ценой |
| REQ-CON-34 | Экспорт в учёт: несколько статусов или allocations одного capture не порождают нескольких фактов выручки; allocation Billium — коммерческая детализация, а не второй cash effect, и не повторное взыскание розничного платежа | SRC-070 §Detailed W-01; SRC-104 §Payment feed import requirements; SRC-111 §I-26 |
| REQ-CON-35 | Экспортный факт для FinBridge (`finbridge.fact/1`, proposal) несёт sourceBook, sourceFact, sourceOperation, sourceRevision, eventKind, бизнес-дату, `recordedAt` UTC, hash, точные деньги, seller и ссылки на исходный факт. Mapping version — сохранённая metadata, а не ключ импорта; новый status, revision или transport ID не разрешает повторную проводку | SRC-104 §Detailed W-01 |
| REQ-CON-36 | Invoice, платёж, фискальный документ, grant и готовность ресурса — независимые машины состояний; общая кнопка «оплатить» их не объединяет. Состояния invoice из источника (Draft, Issued, PartiallyPaid, Paid, Uncollectible) — иллюстрация и вход FSM P1.6, не контракт | SRC-109 §Связь финансовых состояний, §FlexCRM |
| REQ-CON-37 | Dart SDK — online-клиент server API: offline-запись денежного state он не обещает. Денежная арифметика в Dart — только в профилях, где её допускает `axioma_contracts` (dart2js запрещён) | SRC-129 §Runtime map, §Четыре способа |
| REQ-CON-38 | Рендер юридического документа фиксирует frozen вход, версию шаблона, шрифты с лицензией, версию renderer и digest вывода; повторный рендер или смена шаблона не меняет юридический номер. Renderer пока не выбран | SRC-144 §FD; SRC-208 §FD-G04 |
| REQ-CON-39 | Поля, охраняющие инварианты, — типизированные колонки с constraints: деньги и валюта, seller, provider reference, state, ключ идемпотентности и его scope, ссылки авторизации. JSON допустим только для санитизированного payload и metadata со schema version и лимитом размера | SRC-022 (AUD-031, AUD-032 → CL-38); DR01-13; DR01-14; D22 |

## Инварианты

INV-01…INV-08 — дословно из `plan.md` §2 (брифа M1 §2), порядок сохранён; основания — канон и
паспорта. INV-09…INV-12 — инварианты паспортов уровня A, которые P1.6 обязана закрепить constraint
или lock. INV-13, INV-14 — добавлены P1.6 по заявкам аудита (D20, D23); enforcement — в
`docs/architecture/data-model.md`.

| ID | Инвариант | Источник |
|:--|:--|:--|
| INV-01 | Один writer на сущность | SRC-016 §1 п. 5, §3; SRC-002 §4 |
| INV-02 | Один исполнитель на денежную операцию на всю её жизнь | SRC-016 §3; SRC-005 §Authority profiles; SRC-002 §2 |
| INV-03 | Сетевой вызов вне транзакции | SRC-016 §3; SRC-041 §2; SRC-005 §Admission |
| INV-04 | `unknown` разрешается lookup-ом, а не новым ключом | SRC-016 §3; SRC-005 §Invariants; SRC-006 §6 |
| INV-05 | Idempotency key + digest, тот же ключ с другим payload = conflict | SRC-016 §3; SRC-041 §2; SRC-005 §Admission |
| INV-06 | Cumulative totals не складываются как дельты | SRC-005 §Outcome envelope; SRC-023 §2; SRC-008 §Payment receiver requirements |
| INV-07 | Refund идёт к исполнителю исходного charge | SRC-002 §2; SRC-005 §Authority profiles |
| INV-08 | Транспортный ACK — не бизнес-receipt | SRC-016 §1 п. 8; SRC-005 §Receipt boundaries |
| INV-09 | Сохранение денег в subledger: open = due − allocated; allocations не превышают чистую доступную сумму платежа (captured − refunded); credit note и refund меняют allocation по policy, а не автоматически | SRC-020 §Does Billium require double-entry; SRC-001 §Worked examples |
| INV-10 | Допуск refund не превышает captured − confirmed refunds − reserved/pending/unknown refunds; конкурирующие запросы сериализуются по исходному charge; unknown-резерв не освобождается по таймеру | SRC-005 §RequestRefund, §Invariants; SRC-006 §7 |
| INV-11 | Уникальность денежного occurrence задаётся бизнес-идентичностью (seller + component + purpose + неизменяемый интервал), а не revision расписания | SRC-012 §4; SRC-015 §4; SRC-021 §Решения |
| INV-12 | Денежные условия неизменяемы: зафиксированная obligation или invoice не пересчитывается по новой `PriceVersion`; изменение — связанный adjustment | SRC-012 §4; SRC-014 §Требования; SRC-034 §Late usage |
| INV-13 | Invoice после выпуска неизменяем: номер, seller, снимок покупателя, валюта, строки, итоги и налоговый снимок не меняются; коррекция — credit note или void с перевыпуском; metadata — только по allow-list | SRC-022 (AUD-047 → CL-42, FS-11); SRC-208 §FD-G04; D20 |
| INV-14 | Seller входит в каждый денежный агрегат, каждый бизнес-ключ уникальности и scope идемпотентности; ссылка между сущностями разных продавцов невозможна | SRC-022 (AUD-065 → CL-82, FS-09); SRC-014 §Требования; DR01-11; DR01-14; D23 |

## Отказы и восстановление

| ID | Требование | Источник |
|:--|:--|:--|
| REQ-FAIL-01 | Потерян ответ `accepted` → повтор с тем же `commandKey` возвращает существующую операцию | SRC-005 §Failure matrix |
| REQ-FAIL-02 | Timeout провайдера после возможного списания → unknown/pending; query или reconcile до любого повтора; новый ключ запрещён | SRC-005 §Failure matrix; SRC-006 §6 |
| REQ-FAIL-03 | Повторный callback дедуплицируется в inbox в scope провайдер + merchant account + environment и не создаёт новой revision | SRC-005 §Identities, §Failure matrix; SRC-025 |
| REQ-FAIL-04 | Конфликтующий callback или та же revision с другим digest → quarantine и путь reconcile/оператора, а не last-write-wins | SRC-005 §Outcome envelope, §Failure matrix |
| REQ-FAIL-05 | Callback без найденной операции или без проверки подлинности → durable quarantine до ACK; автоматического charge или refund нет. Аккаунт определяется доверенным route; проверяются операция, сумма и валюта | SRC-005 §Admission; SRC-037 §5 |
| REQ-FAIL-06 | Тот же ключ с изменённой суммой → conflict; исходная команда не обновляется | SRC-005 §Failure matrix; SRC-044 §1 |
| REQ-FAIL-07 | Новый транспортный ключ для того же бизнес-намерения возвращает исходную операцию (уникальная business binding). Ключ после горизонта → `expired` / `recovery-required`, нового эффекта нет | SRC-005 §Admission; SRC-044 §1 |
| REQ-FAIL-08 | Пустой lookup, 404, timeout, отказ доступа или истёкший TTL — не доказанное отсутствие. `confirmedAbsent` допустим только с certificate, включая доказательство невозможности поздней отправки | SRC-006 §6; SRC-037 §3; SRC-018 §PF |
| REQ-FAIL-09 | Конкурирующие refunds с разными ключами проходят одно атомарное резервирование под lock исходного charge (INV-10) | SRC-005 §Failure matrix; SRC-006 §7 |
| REQ-FAIL-10 | Поздний подтверждённый внешний эффект сверх локальных границ (over-refund) → durable discrepancy с evidence и repair receipt; не отбрасывается и не «исправляется» новым refund. Billium блокирует автоматическое повторное взыскание или возврат и передаёт решение оператору или учёту | SRC-006 §7; SRC-023 §2; SRC-005 §Invariants |
| REQ-FAIL-11 | Поздний capture после cancel или failed принимается как факт; возврат требует отдельной авторизации и команды | SRC-005 §CancelOperation; SRC-006 §7 |
| REQ-FAIL-12 | Refund пришёл раньше платежа или отсутствует основание allocation → наблюдение паркуется, зависимость запрашивается; grant не выводится, PSP не вызывается | SRC-023 §4; SRC-018 §PF |
| REQ-FAIL-13 | Revision пришла не по порядку или ниже применённой → hold или запрос пропуска; проекция не откатывается | SRC-005 §Failure matrix; SRC-023 §4 |
| REQ-FAIL-14 | Старый исполнитель проснулся после cutover → dispatch admission проверяет действующий fence; уже отправленный запрос сверяется до любой замены | SRC-005 §Failure matrix |
| REQ-FAIL-15 | Восстановление из backup или клон → профиль quarantine: новые отправки, replay и замены выключены до сверки inventory провайдера и внешнего допуска активного writer. Скопированная epoch — не fence; недоказуемая полнота → постоянный `recoveryRequired` | SRC-005 §Rollback gates; SRC-044 §3; SRC-018 §PF |
| REQ-FAIL-16 | Ключи, tombstones, frozen bindings и lookup хранятся на горизонте retry, refund, backup и позднего callback; затем namespace закрывается (`expired` / `recovery-required`). Числовые горизонты не выбраны — вопрос владельцу и deployment | SRC-005 §Admission; SRC-044 §2 |
| REQ-FAIL-17 | Устаревшая попытка не уничтожает полученное внешнее evidence: custody evidence раньше fenced transition | SRC-037 §5; SRC-023 §1 |
| REQ-FAIL-18 | Несовпадение суммы или валюты у провайдера, отсутствующая сумма при partial capture → typed discrepancy или quarantine, а не успех на всю сумму | SRC-006 §3; SRC-037 §5; SRC-018 §PF |
| REQ-FAIL-19 | Повтор оплаты не запускает collection снова; cancellation generation или tombstone хоста закрывает будущий сбор по компоненту | SRC-003 §Billium; SRC-012 §6 |
| REQ-FAIL-20 | Потерян ответ allocation Billium → восстановление по receipt или query, без PSP-списания | SRC-006 §5 |
| REQ-FAIL-21 | Поздний usage-факт после выставленного или закрытого периода → прослеживаемый adjustment по policy; исходный invoice сохраняется, тихого перерасчёта нет | SRC-034 §Late usage; SRC-021 §Решения |
| REQ-FAIL-22 | Новый потребитель feed после prune outbox или нарушение retention floor → bootstrap (snapshot + committed cursor + tail) или явный rebootstrap. Историческая allocation компонентов не восстанавливается из текущих цен | SRC-023 §3–§4; SRC-005 §Outcome envelope |
| REQ-FAIL-23 | Источник восстановлен ниже применённой получателем revision → partition `recoveryRequired`, дублирующего экономического эффекта нет | SRC-023 §4; SRC-018 §PF |
| REQ-FAIL-24 | Сбой фискализации или provisioning не стирает факт оплаты; unknown фискального результата → запрос исходного; ручная сверка вместо автоматической повторной отправки | SRC-024 §Success; SRC-014 §Требования; SRC-025 |
| REQ-FAIL-25 | Отсутствие записи после restore — не доказательство свежего намерения: старый ключ с неизвестным namespace → `recoveryRequired` / `expiredNamespace`, отправки нет | SRC-044 §1 |
| REQ-FAIL-26 | Повторный callback или повторная доставка исхода не порождает повторный fulfilment: исход несёт `operationRef` + version, получатель дедуплицирует; успешная доставка — не доказательство выданного grant | SRC-054 §Контракты между контекстами; SRC-099 §S-14 |
| REQ-FAIL-27 | Отмена соглашения не откатывает завершённую денежную операцию, refund не равен revoke прав; сбой provisioning не переписывает исход оплаты, компенсация или refund — отдельная команда | SRC-080 §Коммерческая покупка; SRC-099 §S-02, §S-04 |
| REQ-FAIL-28 | Смена writer при миграции legacy-биллинга: явный cutoff и authority binding, остановка старых admissions, drain/reconcile незавершённых операций, snapshot + post-cutoff факты. Независимые dual writes запрещены; неудачный импорт не перезапускает старый writer без обратного handover | SRC-165 §Import и смена writer |
| REQ-FAIL-29 | Схема меняется по expand/contract: старые и новые workers работают одновременно без потери journal; constraints вводятся `NOT VALID` с последующей проверкой; статусы — text с CHECK, не enum БД; down-миграции для денежных фактов не обещаются, откат кода не удаляет бизнес-факты | SRC-022 (AUD-057 → CL-70, CL-71, FS-25); D21 |
| REQ-FAIL-30 | Очередь outbox отделена от журнала фактов: доставленное удаляется из очереди только выше retention floor, а revisions операций, provider evidence, inbox и receipts хранятся append-only до горизонта REQ-FAIL-16 | SRC-022 (R-26 → CL-123); DR01-28; DR04-23; D25 |

## Приёмочные сценарии

ID сценариев — из источников; полный перечень с применимостью строит P1.7
(`docs/acceptance-catalog.md`). Здесь — обязательство Billium по каждому семейству.

| ID | Требование | Источник |
|:--|:--|:--|
| REQ-ACC-01 | PB-01…PB-24 пилота: сторона Billium — профиль `remote-billium` (PB-02, PB-10), переходы (PB-12, PB-13, PB-21) и сценарии обоих профилей; PB-01 (embedded) исполняет Commerz | SRC-005 §Acceptance tests |
| REQ-ACC-02 | PF-01…PF-14: PF-12 (атомарный идемпотентный inbox и allocation Billium), PF-13 (snapshot + tail), PF-14 (committed cursor и lineage restore) — сторона получателя; PF-01…PF-11 применяются к Billium execution как исполнителю | SRC-018 §PF |
| REQ-ACC-03 | PE-01…PE-09 паспорта исполнения применяются к Billium execution в профилях, где он исполнитель | SRC-006 §9 |
| REQ-ACC-04 | MG-01…MG-12 (Memster): денежная сторона — billing occurrence, funding, денежная коррекция, частичный refund после потребления | SRC-015 §8; SRC-018 §Memster prerequisites |
| REQ-ACC-05 | FD-01…FD-10 и FD-G01…FD-G07: сторона Billium — юридический номер и статус, рендер и доставка, фискализация через исполнителя | SRC-024 §Success |
| REQ-ACC-06 | SB-01…SB-10 и SB-G01…SB-G06: сторона Billium — денежная allocation компонентов bundle и credit notes; payables вне Billium | SRC-030 §Bundle lineage |
| REQ-ACC-07 | EC-01…EC-12 (Edufy): Billium участвует только денежной стороной платного предложения; credits — сосед | SRC-038 §Compatibility |
| REQ-ACC-08 | Сценарии соглашения: годовая оплата + 12 месячных grant occurrences, повтор или переупорядочивание funding, две revisions одного occurrence, refund компонента с точным округлением, чужие ссылки seller, restore tombstone | SRC-012 §8 |
| REQ-ACC-09 | Usage-сценарии со стороной Billium: поздний факт после выставленного invoice; тот же период в двух revisions расписания | SRC-034 §Acceptance frontier |
| REQ-ACC-10 | Примеры сохранения денег — fixtures: invoice с частичными оплатами, refund после расчёта, prepaid-баланс | SRC-020 §Does Billium require double-entry; SRC-001 §Worked examples |
| REQ-ACC-11 | Конкурентность доказывается N-процессными тестами на PostgreSQL | SRC-041 §3 |
| REQ-ACC-12 | До effect-capable remote-профиля проходят drills restore, clone и lost response; повтор при смене runtime, retention или custody | SRC-005 §Migration gates |
| REQ-ACC-13 | Денежные fixtures: точность валют (RUB 2, JPY 0, KWD 3 по выбранному реестру), границы int64, round trip > 2^53, распределение одной minor unit на трёх получателей со стабильным остатком, отказ на неизвестной схеме | SRC-029 §A; SRC-045 FC-01 |
| REQ-ACC-14 | Цепочка трассировки приёмки: инвариант → граница команды и хранения → инъекция сбоя → durable receipt или query → наблюдаемый исход. Прочитанный тест или документация провайдера — не пройденная приёмка | SRC-018 §Минимальные specifications |
| REQ-ACC-15 | Гоняются только затронутые тесты; мутации и нагрузка — отдельными задачами; проваленная или пропущенная проверка не зелёная | SRC-041 §3 |
| REQ-ACC-16 | Перед релизом — consumer install smoke из артефактов | SRC-041 §3 |
| REQ-ACC-17 | Приёмка моста: conformance fixtures обеих сторон плюс сценарии duplicate, lost response и out-of-order | SRC-013 §Правила моста п. 8 |
| REQ-ACC-18 | Результаты test double хранятся отдельно от реального runtime; conformance double не доказывает готовность реального Billium или PSP | SRC-005 §Acceptance tests |
| REQ-ACC-19 | Аналитические сценарии границ со стороной Billium входят в каталог P1.7: S-01 (timeout PSP и двойной поздний webhook без второго charge), S-02 (оплата при сбое provisioning), S-04 (частичная отмена bundle без переоценки прошлого invoice), S-05 (однократный импорт в учёт покупателя), S-14 (replay webhook оплаченного invoice) | SRC-099 §Boundary scenarios |
| REQ-ACC-20 | MG со стороны Billium: MG-01 (потерян ответ — исходный receipt, без лишних денег), MG-02 (тот же ключ с иной ценой/периодом — conflict до записи), MG-03 (годовое покрытие + 12 слотов — funding replay не повторяет obligation), MG-08 (proration по исходным условиям цены), MG-10 (частичный refund — точная денежная коррекция компонента, без процента), MG-11 (replay импорта legacy без сгенерированных provider ID) | SRC-165 §Acceptance |
| REQ-ACC-21 | FD со стороны Billium: FD-01 (без смешения SaaS и гостевых продаж), FD-02 (тот же ключ/digest — исходный receipt, иной seller/body — conflict), FD-06 (независимые исходы оплаты и фискала), FD-07 (номер invoice не меняется при смене шаблона и повторном рендере), FD-09 (cancel/correction/refund со связанным receipt), FD-10 (restore/clone не воскрешает удалённое и не повторяет отправку) | SRC-208 §Fixtures |
| REQ-ACC-22 | EC со стороны Billium: EC-11 (usage сверх резерва или после закрытого invoice — факт в исходном периоде, явная доплата/долг/discrepancy, без переноса в новый период), EC-12 (restore и ревизия цены не меняют исходный quote и не повторяют consume) | SRC-215 §Acceptance fixtures |
| REQ-ACC-23 | SB со стороны Billium: SB-02 (неизвестная policy блокирует денежный эффект, нулевое обязательство не выдумывается), SB-07 (bundle из двух компонентов — точная allocation, налог, округление, lineage), SB-09 (частичная отмена отдельна от потреблённой квоты и позднего usage) | SRC-221 §Acceptance |
| REQ-ACC-24 | Любой adopt- или hybrid-кандидат на внешнем компоненте проходит записанную проверку до spike: лицензия и edition-gates на release tag, embedded без сервера (или его ненужность), single writer; без неё adopt не выбирается | SRC-017 (AC-08); SRC-022 (AUD-010 → CL-94, CL-12); DR01-01; DR01-08; D24 |

## Требования к соседям

| ID | Требование к соседу | Источник |
|:--|:--|:--|
| REQ-NBR-01 | Commerz: мост `commerz-billium` в монорепо Commerz реализует `PaymentExecution` поверх `billium-contracts`; у моста нет бизнес-таблиц, provider SDK, retry scheduler и refund state machine | SRC-002 §2; SRC-005 §Package layout; SRC-009 CD-02 |
| REQ-NBR-02 | Commerz: Tx A сохраняет intent, `operationRef`/ключ, frozen payload, профиль и epoch маршрутизации, outbox до remote send; проекция применяет revision идемпотентно | SRC-005 §Admission |
| REQ-NBR-03 | Commerz (`embedded-commerz`): атомарная публикация наблюдений операции и discrepancy на каждое материальное изменение денег, committed cursor, запросы bootstrap (`operationSnapshot`, `readObservations`, `beginBootstrap`, `readBootstrap`, `receiverProgress`) — основа allocation Billium | SRC-023 §1, §3; SRC-018 §Interfaces; SRC-006 §5 |
| REQ-NBR-04 | PaymentDrivers: stateless contracts (`initiate`, `find`, `refund`, `findRefund`, `verifyCallback`, `capabilities`, MoneyWire, `ReceiptPayload`), recovery capability с typed результатами, conformance fixtures (success, decline, timeout→unknown, duplicate callback, partial refund). Секреты остаются на сервере; несовместимый драйвер отвергается до первой отправки | SRC-002 §3; SRC-037 §1, §3; SRC-018 §Interfaces |
| REQ-NBR-05 | PaymentDrivers: `fiscal-contracts` (`registerReceipt`, `findReceipt`) для облачных касс | SRC-002 §3; SRC-009 CD-04 |
| REQ-NBR-06 | Хост (ProductCoordinator): неизменяемая принятая revision соглашения и компоненты, cancellation generation и tombstones, оркестрация grant и provisioning; seller, environment, installation и права actor определяются до команды | SRC-012 §2, §5; SRC-006 §2; SRC-005 §Admission |
| REQ-NBR-07 | Tariffler: `PlanVersion` и grant occurrences без импорта Billium; право выдаёт хост по оплаченному occurrence | SRC-002 §4; SRC-009 CD-15; SRC-013 §Пары без моста |
| REQ-NBR-08 | Источники usage (ControLLM, UsageMeter, доменные коллекторы): нормализованные факты со стабильными ID, семантикой метрики и связанными коррекциями | SRC-034 §Late usage; SRC-018 §Interfaces; SRC-013 §Пары без моста |
| REQ-NBR-09 | AiFina / FinBridge: целевой маппинг и проводка — у владельца книги; факты Billium идут через маппинг FinBridge, без прямой записи | SRC-002 §6; SRC-029 §B; SRC-019 |
| REQ-NBR-10 | Licensing: lease выдаёт хост после оплаченного occurrence; Billium в этом не участвует | SRC-013 §Пары без моста; SRC-002 §9 |
| REQ-NBR-11 | Memster и Edufy: адаптеры миграции с сохранением legacy ID и истории; credits и rewards остаются в продуктах | SRC-009 CD-08; SRC-002 §4; SRC-015 §8; SRC-038 §Compatibility |
| REQ-NBR-12 | Money VO по рантаймам: Dart `axioma_contracts`, PHP — точная арифметика; общие fixtures MoneyWire | SRC-002 §8; SRC-041 §2, §6; SRC-019 |
| REQ-NBR-13 | Юридический и учётный владелец продавца: профиль продавца, налоговый режим, фискальный профиль (I-01, I-16) — входы, Billium их не выбирает | SRC-024 §Owners; SRC-009 (открытые inputs) |
| REQ-NBR-14 | Авторизация: Billium принимает verdict через свой порт; AzGuard подключается мостом хоста | SRC-016 §3 |
| REQ-NBR-15 | Хранение выведенных документов — Vaulter или хранилище хоста (опционально); renderer не управляет GC | SRC-024 §Owners |
| REQ-NBR-16 | Адаптеры миграции Memster и Edufy не выводят денежную цену, валюту, funding и occurrences из изменяемого плана (в subscriptionify 1.0.0 их нет), не создают оплаченную `PriceVersion` из документированной цены, не угадывают покрытие, оплату или provider ID и не начисляют исторические строки повторно; неполная история помечается как legacy-unanchored | SRC-110 §I-03; SRC-140 §Практические выводы; SRC-165 §Import и смена writer; SRC-215 §Compatibility |

## Вне границ

| ID | Требование | Источник |
|:--|:--|:--|
| REQ-OUT-01 | Права, квоты и план-как-право принадлежат Tariffler; Billium их не реализует | SRC-002 §4; SRC-016 §4.2 |
| REQ-OUT-02 | Agreement-сага покупки принадлежит хосту (ProductCoordinator) | SRC-002 §4; SRC-012 §2 |
| REQ-OUT-03 | Розничный заказ, цены и промо розницы, корзина — Commerz; Billium не переоценивает snapshot заказа | SRC-002 §4; SRC-025 |
| REQ-OUT-04 | Книга компании и двойная запись — AiFina / `axioma_finance`. GL внутри Billium нет до названного потребителя (I-20): только subledger obligations и allocations | SRC-002 §4, §8; SRC-009 CD-14; SRC-020 |
| REQ-OUT-05 | Credits и награды продуктов (Edufy credits, Memster rewards) — продукты; общего wallet нет | SRC-002 §1, §4; SRC-009 CD-08; SRC-034 |
| REQ-OUT-06 | Интеграции PSP и касс — PaymentDrivers; кода драйверов и адаптеров в Billium нет | SRC-002 §3; SRC-009 CD-03 |
| REQ-OUT-07 | Лицензии — Licensing | SRC-002 §4; SRC-013 §Пары без моста |
| REQ-OUT-08 | Код моста `commerz-billium` — в репозитории Commerz | SRC-009 CD-02; SRC-013 §Правила моста п. 1 |
| REQ-OUT-09 | Выплаты правообладателям, payables и royalties — кандидат Settlement (I-15), не строится | SRC-002 §1; SRC-030 §Подтверждённое |
| REQ-OUT-10 | Измерение usage и резервирование квот — коллекторы и Tariffler; Billium не резервирует единицы и не начисляет внутри consume | SRC-034 §Responsibility; SRC-025 |
| REQ-OUT-11 | Provisioning и fulfilment — хост продукта; сбой ресурса не меняет завершённую оплату | SRC-025; SRC-012 §5 |
| REQ-OUT-12 | Фискал в зале и гостевые деньги ресторана — AiResto / `axioma_fiscal`; «счёт гостя» не invoice Billium | SRC-002 §5, §7 |
| REQ-OUT-13 | Tenancy и изоляция, аутентификация, хранилище и очереди приходят от хоста | SRC-016 §1 п. 7, §3 |
| REQ-OUT-14 | Универсальные PaymentKernel, Wallet, Meter, FinancialKernel и общий money-пакет не создаются | SRC-006 §8; SRC-020; SRC-009 CD-05 |
| REQ-OUT-15 | Бизнес-факты не выдумываются: продавцы и реквизиты (I-01), платность пакетов (I-02), налоговые и фискальные профили (I-16), первый платный потребитель (I-18), PSP первого запуска, бандл AiFina+AiResto (I-28), валюты, цены — вопросы владельцу | SRC-016 (режим принятия); SRC-002 §10; SRC-009 (открытые inputs); SRC-031 |
| REQ-OUT-16 | Billium не является глобальным налоговым движком: налоговый результат приходит snapshot-ом с policy/version ref от владельца правил по seller/product | SRC-057 §Реестр возможностей №5 |
| REQ-OUT-17 | Деньги покупателей магазина владельца сайта (Commerz в FlexCMS) не входят в Billium продавца ПО: у продаж разные продавцы, PSP credentials, основания и документы | SRC-109 §FlexCMS + Commerz |
| REQ-OUT-18 | Себестоимость провайдера (float cost telemetry), внутренние eval-нагрузки и отдельные provider-попытки не являются денежными фактами Billium: rating — только по принятым billable фактам и frozen `PriceVersion` | SRC-143 §Usage / attribution; SRC-146 §Owner map; SRC-219 §Минимальный target seam |
| REQ-OUT-19 | Billium не управляет жизненным циклом ресурсов хоста: не регистрирует providers модулей, не запускает их миграции, не создаёт БД; платёжный или долговой статус сам не выбирает удаление ресурса — это отдельное право и policy хоста | SRC-203 §Сохраняемые гарантии; SRC-205 §Владельцы и authority; SRC-054 §W-04 |

## Конфликты

| ID | Противоречие | Источник | Статус |
|:--|:--|:--|:--|
| CONFLICT-01 | Tower: решение «ControLLM → Tariffler → Billium» с `OverageEvent` и `uses: [Tariffler]` во frontmatter. Канон CD-15: Billium и Tariffler не импортируют друг друга, usage Billium принимает по своему контракту | SRC-004 §Ключевые решения; SRC-009 CD-15 | Действует канон (REQ-CON-15). Устаревший frontmatter — в brain-updates P1.8 |
| CONFLICT-02 | Слой provider adapter внутри Billium: tower (bounded context Payment Adapter), Billium-Boundaries («Billium driver adapter»), рекомендация дистрибуции («Billium driver/contract» в Commerz Light). Канон CD-03: драйверы — отдельное семейство PaymentDrivers | SRC-004 §Ключевые решения; SRC-014 §Commerz; SRC-001 §Fit; SRC-009 CD-03 | Действует канон (REQ-OUT-06) |
| CONFLICT-03 | Форма MoneyWire: envelopes пилота несут `"amount": "1490.00"` (decimal в major units), а Finance-Wire и FC-01 предлагают целое minor в строке + `scale` + revision реестра. Канон B-16 («decimal string + ISO 4217, scale валюты») выбор не делает | SRC-005 §Command envelope; SRC-029 §A; SRC-045 FC-01; SRC-016 §3 | Открыт; решает D# P1.7 вместе с fixtures, затем при необходимости предложение CD |
| CONFLICT-04 | Словарь профилей: Light / Embedded / Server / Remote в Features, Billium-Boundaries и паспорте исполнения против канонических `embedded` / `server` (CD-14) и профилей authority исполнения пилота | SRC-025; SRC-014; SRC-006 §2; SRC-009 CD-14; SRC-005 §Authority profiles | Действует канон: CD-14 прямо заменяет Light/Embedded/Server; Light читается как `embedded-commerz` (REQ-PROF-04, REQ-PROF-06) |
| CONFLICT-05 | Омоним ID: в Billium-Boundaries требования `B-01…B-16`, в каноне правила `B-01…B-16` с другим смыслом | SRC-014 §Требования; SRC-016 §3 | Разведено: в артефактах Billium `B-NN` — только правила канона |
| CONFLICT-06 | Состояние репозитория: канон и досье — «repo нет», «`repo:` пуст», «без коммитов»; рабочее дерево Brain содержит незакоммиченное `repo: ~/projects/packages/billium` в tower (sha256 `dc22ac6bd5d4` против snapshot `e2c40fe7ddec`) и неотслеживаемый каталог `docs/` | SRC-004; SRC-016 §4.2; SRC-026; SRC-008 §W-02/03 | Открыт; brain sync — внешний эффект, решает владелец (вопрос P1.8) |
| CONFLICT-07 | Tower: фискализация 54-ФЗ — bounded context внутри Billium. Канон CD-04: фискализацию запускает исполнитель оплаты через fiscal driver или receipt capability PaymentDrivers | SRC-004 §Ключевые решения; SRC-009 CD-04; SRC-002 §5 | Действует канон (REQ-CON-24) |
| CONFLICT-08 | Рекомендация дистрибуции говорит о «pro extensions». Канон: нет `pro`-префиксов и edition-веток, платность — доступность пакета | SRC-001 §B-A / B-B / B-C; SRC-016 §1 п. 2, §3 | Действует канон (REQ-PROF-10); модель дистрибуции — вопрос I-02 |
| CONFLICT-09 | Package-Boundaries: «старые frozen схемы Billium остаются существующим контрактом v1», зависимость старого Billium от `corex/payments`, модуль Fiscal integration внутри Billium; Purchase-and-Activation ссылается на «frozen Billium v1». Канон: драйверы — PaymentDrivers (CD-03), фискал — исполнитель (CD-04); контракты v1 проектирует P1.7, старые схемы лежат в исключённом `Development/` | SRC-049 §Лёгкое ядро, §Что сохраняем; SRC-109 §Связь финансовых состояний; SRC-009 CD-03, CD-04 | Действует канон; старые схемы не читаются. Опубликованы ли они потребителям — вопрос владельцу в P1.8 |
| CONFLICT-10 | Tower: arm's-length вызов Lago по REST «не заражает» проприетарный код AGPL. Distribution options: это не универсальный факт, нужен разбор лицензии и edition | SRC-004 §Ключевые решения; SRC-061 §Licence premises | Открыт; проверяет P1.4 (DR-01, лицензии), до этого Lago по REST не считается безопасным по умолчанию |
| CONFLICT-11 | Towers FlexCRM и FlexStore (вердикт P3.1): тарифный биллинг аккаунта «остаётся в `corex/billing`», FlexStore — «разовые и подписочные платежи e-commerce» через Billium. Канон: `corex/billing` вынесен в standalone Billium, розница — Commerz | SRC-178 §Синергии; SRC-179 §Синергии; SRC-181 §Состав ядра; SRC-002 §4 | Действует канон (REQ-OUT-03, REQ-PROF-19); строки towers — в brain-updates P1.8 |
| CONFLICT-12 | Tower Memster: Billium — «монетизация авторов». Требования: выплаты правообладателям и payables — кандидат Settlement, не строится | SRC-180 §Синергии; SRC-002 §1; SRC-030 §Подтверждённое | Действует REQ-OUT-09; I-15 — вопрос владельцу |
| CONFLICT-13 | Package-Boundary-Policy называет адаптер `commerz-billium-adapter`; канон — мост `commerz-billium` в репозитории Commerz | SRC-211 §Adapter policy; SRC-009 CD-02; SRC-013 §Реестр | Действует канон (REQ-CON-01, REQ-OUT-08) |
