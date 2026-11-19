# Billium — каталог приёмки

Статус: PLAN1.P1.7 (2026-10-17), корректировки PLAN1.P1.11 и PLAN1.P1.13 по review P1.9.
Источник ID — `research/P1-acceptance-inventory.md` плана `2026.10.14-№1-BILLIUM-V1` в [workspace](planning.md)
(82 сценария: PB 24, PF 14, MG 12, FD 10, SB 10, EC 12); ID не переименованы. Одна строка — один сценарий.
Фаза — провизорная фаза реализации по roadmap (D1); контракт/модель — `docs/contracts/billium-contracts-v1.md`
и `docs/architecture/data-model.md`.

Правила:

- «Сторона» `billium` — Billium выполняет и доказывает денежную часть сценария; `сосед:<имя>` — сценарий
  принадлежит соседу, Billium в нём не пишет (REQ-OUT/REQ-NBR в «Требования»). Смешанный сценарий
  остаётся `billium`, а чужая часть названа в «Примечание» (пример MG-10).
- Прочитанный тест или документация провайдера — не пройденная приёмка; результаты test double и
  реального runtime хранятся раздельно (REQ-ACC-14, REQ-ACC-18). Проваленная или пропущенная проверка
  не зелёная (REQ-ACC-15).
- PB-01 — embedded (Commerz), PB-02/10 — remote, PB-12/13/21 — переходы, остальные PB — в обоих
  профилях там, где Billium участвует (REQ-ACC-01).

## Пилот PB

| ID | Сценарий | Сторона | Фаза | Требования | Контракт | Примечание |
|:--|:--|:--|:--|:--|:--|:--|
| PB-01 | Обычный embedded charge: одна attempt, один исход, проекция заказа один раз | сосед:commerz | — | REQ-NBR-01, REQ-NBR-02, REQ-NBR-03 | — | Исполняет Commerz (embedded-commerz); Billium максимум ведёт allocation по feed (PF-12) |
| PB-02 | Обычный remote charge: Billium execution — единственный writer, Commerz строит проекцию из исхода | billium | P5 | REQ-OWN-08, REQ-PROF-04, REQ-PROF-18, REQ-ACC-01, REQ-ACC-18, REQ-CON-23, INV-01, INV-02, REQ-OWN-16, REQ-OWN-17, REQ-CON-04, REQ-PROF-01, REQ-PROF-21 | payment.execute.v1 / payment.outcome.v1 | Результаты test double и реального runtime хранятся раздельно |
| PB-03 | Повтор execute: та же операция и результат, без нового вызова провайдера | billium | P5 | INV-05, REQ-FAIL-01, REQ-ACC-17, REQ-CON-32 | payment.execute.v1 / payment.outcome.v1; payment.admission.v1 |  |
| PB-04 | Тот же ключ с другим digest — детерминированный conflict | billium | P5 | INV-05, REQ-FAIL-06, REQ-CON-19 | payment.admission.v1; billium.idempotency.conflict |  |
| PB-05 | Потерян ответ accepted: lookup или повтор восстанавливает операцию | billium | P5 | REQ-FAIL-01, REQ-CON-08, REQ-ACC-17, REQ-CON-05 | payment.find.v1 |  |
| PB-06 | Неизвестный исход провайдера: второго charge нет до reconcile | billium | P5 | INV-04, REQ-FAIL-02, REQ-ACC-19, REQ-CON-29 | payment.find.v1; FSM операции |  |
| PB-07 | Дубль callback: один переход состояния | billium | P5 | REQ-FAIL-03, REQ-FAIL-26, REQ-ACC-17, REQ-ACC-19 | inbox_message; payment.outcome.v1 |  |
| PB-08 | Конфликтующий callback: quarantine, предыдущий terminal защищён | billium | P5 | REQ-FAIL-04, INV-06 | inbox_message; discrepancy |  |
| PB-09 | Повторы частичного refund: cumulative сумма точна, дубля refund нет | billium | P5 | INV-06, INV-10, REQ-CON-06 | payment.refund.v1 |  |
| PB-10 | Сбой адаптера и replay: проекция догоняет по сохранённому feed/outcome query | billium | P5 | REQ-CON-08, REQ-CON-18, REQ-ACC-01 | payment.find.v1; outbox_event | Сторона Commerz — применение проекции |
| PB-11 | Восстановление из backup: пробелы inbox/outbox обнаружены и сверены | billium | P5 | REQ-FAIL-15, REQ-ACC-12, REQ-FAIL-30, REQ-PROF-21 | installation_state; ACC-RST-03 |  |
| PB-12 | Cutover embedded→remote: только новые операции идут в remote | billium | P9 | REQ-PROF-05, REQ-ACC-01, REQ-PROF-06 | payment.execute.v1 / payment.outcome.v1; executionProfile, routingEpoch | Маршрутизация — мост commerz-billium (REQ-NBR-01) |
| PB-13 | Fenced старый worker: новая отправка отвергнута, in-flight сверен до замены | billium | P5 | REQ-FAIL-14, INV-02 | attempt (lease/fence) |  |
| PB-14 | Дубль у потребителя allocation: один allocation receipt | billium | P3 | REQ-FAIL-20, REQ-CON-13, INV-08, REQ-OWN-05, REQ-CON-10, REQ-PROF-01, REQ-PROF-02, REQ-PROF-20, REQ-PROF-22 | payment.observation.v1; AllocationReceipt |  |
| PB-15 | Совместимость старого/нового контракта: предыдущий minor-потребитель читает outcome | billium | P2 | REQ-CON-20, REQ-CON-01, REQ-CON-03, REQ-CON-31, REQ-ACC-16, REQ-PROF-03, REQ-OWN-18, REQ-CON-02, REQ-ACC-13, REQ-CON-26, REQ-CON-37, REQ-NBR-12 | compat/pilot fixtures | Pinned producer/consumer версии |
| PB-16 | Два параллельных refund: confirmed + резервы не допускают превышения; unknown держит ёмкость | billium | P5 | INV-10, REQ-FAIL-09 | payment.refund.v1; ACC-CON-03 |  |
| PB-17 | Lookup/команда чужого продавца и поддельный callback: отказ или quarantine без раскрытия | billium | P5 | REQ-PROF-08, INV-14, REQ-FAIL-05, REQ-CON-21, REQ-PROF-09, REQ-NBR-14 | billium.auth.denied; inbox_message | Server-профиль повторно в P8 |
| PB-18 | Откат intent / потерян локальный commit ответа: отправки до commit нет, повтор находит исходное | billium | P5 | INV-03, REQ-FAIL-01, REQ-NBR-02 | payment.execute.v1 / payment.outcome.v1 | Tx A — сторона Commerz |
| PB-19 | Новый ключ для того же намерения / истёкший ключ: исходная binding или conflict/expired | billium | P5 | REQ-FAIL-07, REQ-FAIL-16, REQ-OWN-10 | billium.key.expired; business binding |  |
| PB-20 | Восстановленный клон со старой routing epoch: dispatch выключен до сверки и допуска writer | billium | P5 | REQ-FAIL-15, INV-02 | installation_state; ACC-RST-04 |  |
| PB-21 | Refund после cutover и откат remote→embedded: refund у исполнителя исходного charge | billium | P9 | INV-07, REQ-PROF-05, REQ-ACC-01, REQ-OWN-20 | payment.refund.v1; execution_binding |  |
| PB-22 | Cancel до/после отправки: до — блок; capture в полёте сохраняется; refund отдельно | billium | P5 | REQ-CON-07, REQ-FAIL-11 | payment.cancel.v1 |  |
| PB-23 | Изменение суммы при том же статусе / bootstrap / пробел retention: полная cumulative проекция или recovery-required | billium | P5 | INV-06, REQ-FAIL-22, REQ-CON-09 | payment.outcome.v1; outbox_event |  |
| PB-24 | Поздний внешний эффект сверх локальных границ: discrepancy, без потери и двойной allocation | billium | P5 | REQ-FAIL-10, REQ-OWN-14, REQ-FAIL-18 | discrepancy.recorded.v1 |  |

## Prerequisites PF

| ID | Сценарий | Сторона | Фаза | Требования | Контракт | Примечание |
|:--|:--|:--|:--|:--|:--|:--|
| PF-01 | Tx A исполнителя: одно намерение/ключ → операция + frozen binding | billium | P5 | REQ-CON-32, REQ-OWN-10, REQ-OWN-21, REQ-ACC-02 | money_operation; execution_binding | В пилоте — Sales Commerz; для Billium execution — аналог |
| PF-02 | Custody ответа исполнителя до fenced transition | billium | P5 | REQ-FAIL-17, INV-02, REQ-ACC-03, REQ-OWN-09 | provider_evidence; attempt |  |
| PF-03 | Recovery: наблюдаемое отсутствие + запрет поздних отправок | billium | P5 | REQ-FAIL-08, REQ-CON-29 | payment.find.v1 absenceCertificate |  |
| PF-04 | Replay только с явным разрешением, тем же телом, account и в окне | billium | P5 | REQ-CON-29 | replayOriginalWithinWindow |  |
| PF-05 | Custody и identity callback в scope account | billium | P5 | REQ-FAIL-03, REQ-FAIL-05 | inbox_message |  |
| PF-06 | Деньги и terminal evidence провайдера | billium | P5 | REQ-FAIL-18, REQ-NBR-04 | provider_evidence |  |
| PF-07 | Финансовое намерение переживает общий prune | billium | P5 | REQ-FAIL-16, REQ-FAIL-30, REQ-OWN-10 | intent_tombstone |  |
| PF-08 | Restore исключает пропавший внешний эффект и отправки клона | billium | P5 | REQ-FAIL-15 | installation_state |  |
| PF-09 | Закрытый namespace и импорт сохраняют защиту от дублей | billium | P5, P9 | REQ-FAIL-25, REQ-FAIL-28 | intent_tombstone; import port |  |
| PF-10 | Строка, revision, лог и outbox в одной транзакции соединения | billium | P3 | REQ-CON-18, INV-08, REQ-OWN-15 | outbox_event |  |
| PF-11 | Истина discrepancy независима от локальных границ | billium | P5 | REQ-FAIL-10, REQ-OWN-14 | discrepancy |  |
| PF-12 | Inbox и allocation Billium применяются атомарно и идемпотентно | billium | P3 | REQ-CON-13, REQ-FAIL-20, REQ-ACC-02, REQ-OWN-11, REQ-FAIL-12 | payment.observation.v1 |  |
| PF-13 | Согласованный snapshot + сохранённый tail для нового потребителя | billium | P3, P9 | REQ-FAIL-22, REQ-CON-27 | feed_partition | Feed Commerz — REQ-NBR-03; P3 — embedded ledger с test double источника, P9 — с мостом Commerz |
| PF-14 | Committed cursor источника и restore lineage | billium | P3, P9 | REQ-FAIL-23, REQ-CON-27 | feed_partition; payment.observation.v1 | P3 — embedded ledger с test double источника (REQ-PROF-20, REQ-OWN-11); P9 — с мостом Commerz |

## MG, FD, SB, EC

| ID | Сценарий | Сторона | Фаза | Требования | Контракт | Примечание |
|:--|:--|:--|:--|:--|:--|:--|
| MG-01 | Потерян ответ подписки: повторы возвращают исходный денежный receipt, лишних денег нет | billium | P4 | REQ-ACC-04, REQ-ACC-20, REQ-FAIL-01, REQ-PROF-14, REQ-PROF-07 | obligation.prepare.v1; occurrence.ensure.v1 | Grant и entitlement — Memster/Tariffler (REQ-NBR-07) |
| MG-02 | Тот же ключ с иной ценой/периодом: typed conflict до записи | billium | P4 | REQ-ACC-20, INV-05, REQ-FAIL-06, REQ-CON-28 | billium.idempotency.conflict |  |
| MG-03 | Годовое покрытие + 12 слотов: повтор/переупорядочивание funding и ревизий расписания не повторяют obligation | billium | P4 | REQ-ACC-20, INV-11, REQ-ACC-08, REQ-OWN-02, REQ-OWN-03 | billing_occurrence; obligation | Месячные grants — Memster/Tariffler |
| MG-04 | Две ревизии/разные ID одного покрытия: overlap-constraint блокирует дубль; преемник — supersession/adjustment | billium | P4 | INV-11, REQ-ACC-09, INV-12 | EXCLUDE billing_occurrence | Grant-сторона — Memster |
| MG-05 | Параллельные подписка и резервы/consume на лимите | сосед:memster | — | REQ-OUT-10, REQ-NBR-07 | — | Резервы и квоты — Memster/Tariffler; денежная уникальность occurrence — MG-04 |
| MG-06 | Cancel до/после ensure, поздний ответ, старый dispatcher: денежный сбор закрыт generation хоста | billium | P7 | REQ-FAIL-19, REQ-FAIL-27, REQ-NBR-06, REQ-OWN-06 | dunning_case; billing_schedule.cancellation_generation | Реактивация grant — Memster/хост |
| MG-07 | Отмена в конце срока против немедленной, просроченный Active/trial | сосед:memster | — | REQ-OUT-01, REQ-NBR-06 | — | Допуск к сервису решает продукт/хост по принятому cutoff |
| MG-08 | Upgrade/downgrade в середине периода: proration по исходным условиям цены | billium | P4 | REQ-ACC-20, INV-12, REQ-CON-33, REQ-OWN-01 | rated_line; correction child | Квоты при смене — Tariffler; P4.8 V1, V903–V905 |
| MG-09 | Поздняя выдача после конца периода, календарные края: денежный интервал заморожен | billium | P4 | INV-11, REQ-CON-11 | billing_occurrence.interval | Выдача grant — Memster |
| MG-10 | Частичный refund при потреблённых единицах: точная денежная коррекция компонента | billium | P7 | REQ-ACC-20, REQ-OWN-19, INV-10, REQ-ACC-04 | payment.refund.v1; credit_note | Коррекция прав — владелец прав (REQ-OUT-01); фаза P7 — нужны PriceVersion компонента (P4) и refund (P5) |
| MG-11 | Replay импорта legacy: без сгенерированных provider ID и прошлых начислений | billium | P9 | REQ-ACC-20, REQ-NBR-16, REQ-FAIL-28, REQ-NBR-11 | import port |  |
| MG-12 | Резерв60/факт35/settlement, поздний usage | сосед:tariffler | — | REQ-OUT-10, REQ-NBR-08 | — | Резерв и его исходы — Tariffler; итоговый факт Billium — EC-11 |
| FD-01 | Выбранный продавец/режим: без смешения SaaS и гостевых продаж | billium | P6 | REQ-ACC-21, REQ-OUT-12, REQ-NBR-13, REQ-ACC-05 | legal_document | Фискальный профиль продавца — вход (I-16) |
| FD-02 | Тот же ключ/digest — исходный receipt; иной seller/body — conflict | billium | P6 | REQ-ACC-21, INV-05 | DocumentReserved |  |
| FD-03 | Crash после фискального эффекта: точный query или manualRecovery, без повторной отправки | billium | P6 | REQ-CON-24, REQ-FAIL-24, REQ-NBR-05 | fiscal_receipt | Когда Billium — исполнитель |
| FD-04 | Разделённый чек, замена устройства | сосед:airesto | — | REQ-OUT-12 | — | Устройства и чеки зала — AiResto/axioma_fiscal |
| FD-05 | Неверный actor/org/device, права кассира, офлайн-профиль | сосед:airesto | — | REQ-OUT-12, REQ-OUT-13 | — | Права кассира и офлайн-профиль — AiResto и хост |
| FD-06 | Фискал без оплаты и оплата без фискала — независимые исходы | billium | P6 | REQ-ACC-21, REQ-CON-36, REQ-FAIL-24 | fiscal_receipt; FSM операции |  |
| FD-07 | Номер invoice не меняется при смене шаблона и перерендере; выбранный renderer прошёл gate adopt | billium | P6 | REQ-ACC-21, REQ-CON-38, INV-13, REQ-OWN-07, REQ-OWN-04, REQ-ACC-24 | render_record; legal_document |  |
| FD-08 | Печать принята/неизвестна отдельно от бумаги и регистрации | сосед:airesto | — | REQ-OUT-12 | — | Печать и фискальный регистратор зала — AiResto |
| FD-09 | Cancel/correction/refund — каждый со связанным receipt | billium | P6 | REQ-ACC-21, REQ-CON-25, REQ-OWN-04 | credit_note; fiscal_receipt |  |
| FD-10 | Prune/backup/restore/clone не воскрешают удалённое и не повторяют отправку | billium | P6 | REQ-ACC-21, REQ-FAIL-15, REQ-FAIL-30, REQ-NBR-15 | render_record; fiscal_receipt |  |
| SB-01 | Событие → payable → statement → payout → учёт | сосед:settlement | — | REQ-OUT-09 | — | Payables и выплаты — кандидат Settlement (I-15), не строится |
| SB-02 | Неизвестная policy блокирует денежный эффект, нулевая obligation не выдумывается | billium | P4 | REQ-ACC-23, REQ-ACC-06 | billium.policy.unknown |  |
| SB-03 | Дубль/изменённый пользователь, сумма, валюта ключа | сосед:settlement | — | REQ-OUT-09 | — | Идемпотентность событий payable — Settlement |
| SB-04 | Потерянный grant/conversion hold и точный исходный исход | сосед:host | — | REQ-NBR-06, REQ-OUT-11 | — | Grant и provisioning — хост продукта |
| SB-05 | Потерянный payout/cancel/переупорядоченные факты | сосед:settlement | — | REQ-OUT-09 | — | Выплаты — Settlement |
| SB-06 | Payout после refund/удаления контента — reversal без стирания | сосед:settlement | — | REQ-OUT-09 | — | Reversal payout — Settlement; refund Billium — PB-09 |
| SB-07 | Bundle из двух компонентов: точная allocation, налог, округление, lineage | billium | P3 | REQ-ACC-23, REQ-OWN-13, REQ-ACC-06, REQ-CON-30, REQ-PROF-20 | allocation; ledger/conservation |  |
| SB-08 | Частичный fulfilment, поздний grant, cutoff — receipt каждого владельца | сосед:host | — | REQ-NBR-06, REQ-OUT-11 | — | Fulfilment и grant — хост; оплата Billium не меняется |
| SB-09 | Частичная отмена отдельно от потреблённой квоты и позднего usage | billium | P4 | REQ-ACC-23, REQ-OWN-19, REQ-ACC-19 | credit_note; correction child | Квота — Tariffler; P4.8 V1, V903–V905 |
| SB-10 | Retention/restore/clone без повторной выдачи, выплаты и проводки | billium | P6 | REQ-CON-34, REQ-FAIL-15, REQ-ACC-19, REQ-CON-22, REQ-CON-35, REQ-NBR-09 | outbox_event (finbridge) | Выплаты — Settlement |
| EC-01 | Текущая недоступная композиция | сосед:edufy | — | REQ-OUT-05 | — | Credits и композиция прогонов — Edufy |
| EC-02 | 20 одновременных резервов | сосед:edufy | — | REQ-OUT-05, REQ-OUT-10 | — | Резервы credits — Edufy |
| EC-03 | Потеря ответа commit резерва; изменённые сумма/account/actor | сосед:edufy | — | REQ-OUT-05 | — | Резервы credits — Edufy |
| EC-04 | Закрытый voice, затем timeout прогона; replay после завершения | сосед:edufy | — | REQ-OUT-05 | — | Прогоны и credits — Edufy |
| EC-05 | Провайдер выполнил, ответ потерян, рестарт worker | сосед:edufy | — | REQ-OUT-05, REQ-OUT-18 | — | AI-провайдер и credits — Edufy |
| EC-06 | Невалидный JSON после успешного эффекта | сосед:edufy | — | REQ-OUT-05 | — | Результат прогона — Edufy |
| EC-07 | Резерв60 → provisional20 → cumulative final35; дубли | сосед:edufy | — | REQ-OUT-05 | — | Credit ledger Edufy; Billium — только денежная сторона EC-11 |
| EC-08 | Cancel до/во время отправки/после settlement | сосед:edufy | — | REQ-OUT-05 | — | Settlement прогонов — Edufy |
| EC-09 | Подтверждение после TTL, параллельный допуск | сосед:edufy | — | REQ-OUT-05 | — | Допуск прогонов — Edufy |
| EC-10 | Adjustment commit, затем сбой audit/outbox/cache и повтор | сосед:edufy | — | REQ-OUT-05 | — | Adjustments credits — Edufy |
| EC-11 | Факт сверх резерва или после закрытого invoice: исходный период, явная доплата/долг/discrepancy | billium | P4 | REQ-ACC-22, REQ-FAIL-21, REQ-ACC-07, REQ-ACC-09, REQ-CON-17 | usage.fact.v1; correction child | P4.8 V2–V3, V903–V905; original-period fixture |
| EC-12 | Restore и ревизия цены не меняют исходный quote и не повторяют consume | billium | P4 | REQ-ACC-22, INV-12, REQ-ACC-07, REQ-OWN-01 | price_version; obligation |  |

## Concurrency

N-процессные тесты на PostgreSQL ≥ 16 (REQ-ACC-11): N процессов с отдельными соединениями стартуют после
общего барьера; DB proof — состояние строк и constraints после прогона, не логи приложения. Пары — из
`data-model.md` § Операции и блокировки.

| ID | Сценарий | Сторона | Фаза | Требования | Контракт | Примечание | N | Барьеры | Начальные данные | Допустимые исходы | DB proof |
|:--|:--|:--|:--|:--|:--|:--|:--|:--|:--|:--|:--|
| ACC-CON-01 | Один commandKey, разные digest | billium | P5 | INV-05, REQ-FAIL-06, REQ-ACC-11, REQ-CON-39, REQ-ACC-15 | idempotency_receipt | — | 20 | все стартуют после общего барьера до claim | одна операция без receipt | один accepted; остальные duplicate или conflict по digest | одна строка receipt; одна операция |
| ACC-CON-02 | Одно намерение, разные ключи | billium | P5 | REQ-FAIL-07, REQ-OWN-10, REQ-ACC-11 | money_operation (business binding) | — | 10 | барьер перед Tx A | нет операций | одна операция; остальные получают её operationRef | UNIQUE business binding без seller (D36); count = 1 |
| ACC-CON-03 | Параллельные refund одного charge | billium | P5 | INV-10, REQ-FAIL-09, REQ-ACC-11 | payment.refund.v1 | — | 10 | барьер перед admission | charge captured 1000.00 | Σ confirmed + reserved ≤ captured; лишние — capacity_exceeded | CHECK charge; lock wait в pg_locks |
| ACC-CON-04 | Refund admission и исход charge одновременно | billium | P5 | INV-10, INV-06, REQ-ACC-11 | money_operation | — | 4 | callback и refund стартуют вместе | charge pending | refund отклонён до captured или видит captured | сериализация lock charge |
| ACC-CON-05 | Callback и lookup применяют одну revision | billium | P5 | INV-06, REQ-FAIL-04, REQ-FAIL-03, REQ-ACC-11 | inbox_message; operation_revision | — | 8 | барьер перед apply | операция unknown | одна revision; иной digest — quarantine | UNIQUE (operation, revision) |
| ACC-CON-06 | Finish текущего и устаревшего worker | billium | P5 | INV-02, REQ-FAIL-14, REQ-FAIL-17, REQ-ACC-11 | attempt | — | 3 | lease истекает между вызовом и finish | attempt dispatching | один переход; evidence проигравшего сохранено | CAS lease_token; count переходов = 1; count attempt = 1 (UNIQUE operation_id, D37) |
| ACC-CON-07 | Dispatch и cancel | billium | P5 | REQ-CON-07, REQ-ACC-11 | payment.cancel.v1; attempt | P19R2-F03, D40 | 2 | барьер перед Tx B и cancel | операция admitted | либо cancelled без отправки, либо отправлено и cancel не отменяет | Tx B берёт `money_operation` FOR SHARE, cancel — FOR UPDATE (D40): ровно один исход; attempt.state `dispatching|sent` ⇒ operation не cancelled |
| ACC-CON-08 | Allocation одного платежа на две obligations | billium | P3 | INV-09, REQ-ACC-11, REQ-ACC-10, REQ-OWN-12, REQ-PROF-22 | allocation | — | 10 | барьер перед Tx | payment 100.00, две obligations по 80.00 | Σ allocation ≤ 100.00, каждая ≤ 80.00 | CHECK payment/obligation; порядок locks |
| ACC-CON-09 | Allocation и credit note одной invoice | billium | P3 | INV-09, INV-13, REQ-ACC-11 | allocation; credit_note | — | 4 | барьер | issued invoice 100.00 | open ≥ 0; credit ≤ open + reversal по policy | CHECK obligation |
| ACC-CON-10 | Выпуск invoice одной серии | billium | P6 | REQ-OWN-07, INV-13, REQ-ACC-11 | document_series; legal_document | — | 20 | барьер перед issue | серия с next_number = 1 | 20 уникальных номеров без повторов | UNIQUE номер; lock series |
| ACC-CON-11 | Дубль rating job и supersession расписания | billium | P4 | INV-11, INV-12, REQ-ACC-11 | billing_occurrence; obligation | — | 5 | барьер перед материализацией | расписание rev 1, occurrence planned | одна obligation на интервал; supersede только planned | EXCLUDE; UNIQUE basis; P4.8 V3 |
| ACC-CON-12 | Один usage-факт от нескольких доставщиков | billium | P4 | REQ-CON-15, REQ-CON-16, REQ-ACC-11, REQ-CON-39 | usage.fact.v1 | — | 10 | барьер | пусто | один факт; иной digest — conflict | UNIQUE (seller, source, fact_id, revision) |
| ACC-CON-13 | Шаг collection и allocation одной obligation | billium | P7 | REQ-FAIL-19, INV-09, REQ-ACC-11, REQ-CON-12, REQ-OWN-06 | dunning_case; collection_step | — | 4 | барьер | obligation open 50.00 | после allocation шаг не создаётся; повтор job — UNIQUE | UNIQUE (case, step); lock obligation |
| ACC-CON-14 | Несколько relay одного outbox | billium | P3 | REQ-CON-18, INV-08, REQ-ACC-11 | outbox_event | — | 4 | одновременный старт relay | 100 событий | каждое доставлено ≥ 1 раз; потребитель применяет 1 раз | SKIP LOCKED; dedupe event_id |
| ACC-CON-15 | Dispatch во время перевода инсталляции в quarantine | billium | P5 | REQ-FAIL-15, INV-02, REQ-ACC-11 | installation_state | — | 3 | restore-переключение между Tx B | операции admitted | после quarantine отправок нет | Tx B и quarantine — FOR UPDATE installation_state; CAS `writer_checkpoint` (D34) |
| ACC-CON-16 | Параллельное применение feed одной partition | billium | P3, P9 | REQ-FAIL-13, REQ-FAIL-23, REQ-CON-27, REQ-ACC-11 | feed_partition | — | 4 | барьер | applied_sequence = 10 | sequence монотонна; пропуск — hold | lock partition |
| ACC-CON-17 | Правка metadata и выпуск invoice | billium | P3 | INV-13, REQ-ACC-11 | invoice | — | 2 | барьер | draft invoice | после issue меняется только allow-list | триггер заморозки |
| ACC-CON-18 | Одно намерение от двух продавцов разными ключами | billium | P5 | INV-14, REQ-FAIL-07, INV-05, REQ-ACC-11 | money_operation (business binding); billium.idempotency.conflict | D36 | 4 | барьер перед Tx A | продавцы A и B, операций нет | одна операция продавца A; запросы продавца B — conflict без её operationRef | UNIQUE (source_context, business_intent_ref, kind); count = 1 |
| ACC-CON-19 | Allocation платежа продавца A на obligation продавца B | billium | P3 | INV-14, INV-09, REQ-ACC-11, REQ-PROF-20 | allocation | P19-F09 | 2 | барьер перед Tx allocation A→A и A→B | payment A 100.00; obligations A и B по 100.00 | A→A проходит; A→B отвергнута | составной FK (seller_ref, id); 0 строк allocation A→B |
| ACC-CON-20 | Refund, применение его наблюдения и allocation из того же платежа | billium | P5 | INV-09, INV-10, REQ-FAIL-19, REQ-CON-14, REQ-ACC-11 | payment.refund.v1; payment.observation.v1; allocation.reallocate.v1 | P19-F02, D35 | 4 | барьер: refund admission, observation apply, allocation, refundAuthorization | invoice 100.00 оплачен и распределён; refund 30.00 | admission отклонён — ledger без изменений, obligation open = 0; succeeded — `reallocation_required`, новая allocation из платежа отвергнута до reallocate; refundAuthorization ничего не пишет | CHECK `reallocation_required`; триггер `allocation_guard`; 0 строк allocation до reallocate |
| ACC-CON-21 | Шаг collection и refund одной obligation | billium | P7 | REQ-FAIL-19, INV-09, REQ-ACC-11, REQ-OWN-06 | dunning_case; collection_step; allocation.reallocate.v1 | D35 | 3 | барьер: collection job, исход refund, reallocate | obligation оплачена; refund 30.00 pending | шаг не создаётся ни при pending и отказе refund, ни после reversal с `cause = refund` | lock obligation; count collection_step = 0 |
| ACC-CON-22 | Живой writer и его клон запрашивают допуск dispatch | billium | P5 | INV-02, REQ-FAIL-15, REQ-ACC-11 | WriterAdmission; installation_state | D34; две БД, один authority | 2 | барьер перед запросом ticket | prod и копия, операции admitted в обеих | один держатель lease; после первого dispatch writer копия — quarantine навсегда | authority: один lease; у копии `dispatch_enabled = false`, 0 attempt в `dispatching` |
| ACC-CON-23 | Ротация credentials и callbacks двух merchants с одинаковым external ID | billium | P5 | REQ-OWN-20, REQ-OWN-21, REQ-FAIL-05, INV-07, INV-14, REQ-ACC-03, REQ-ACC-11 | execution_binding; inbox_message | PE-08 | 4 | барьер: ротация credential account A, callback A, callback B с тем же external ID, refund старой операции A | merchants A и B; операции с одинаковым provider external ID | callback применяется только к операции своего (provider, merchant account, environment); без internal ref и совпадения account — quarantine до ACK; refund старой операции A идёт по её binding с новой версией credential того же account | UNIQUE inbox (provider, merchant_account_ref, environment, external_event_id); binding_id операций неизменен; 0 переходов чужой операции |
| ACC-CON-24 | Применение наблюдений одной partition: дубль eventId, пропуск sequence, конфликт revision, чужой seller, несогласованные totals | billium | P3 | REQ-CON-27, REQ-CON-13, REQ-FAIL-13, REQ-FAIL-12, INV-06, INV-14, REQ-ACC-11 | payment.observation.v1; feed_partition; inbox_message | P19-F03; PE-06, PE-09 | 6 | барьер перед apply | applied_sequence = 10; payment captured 30.00 rev 2 | один эффект на eventId; seq 12 до 11 — parked; та же revision с иным digest — conflict; чужой seller — auth.denied без эффекта; rev 3 captured 60.00 — totals присвоены (60.00, не 90.00) | UNIQUE inbox eventId; applied_sequence монотонна; payment.captured_minor = 6000 |
| ACC-CON-25 | Смена маршрута, затем refund старого charge | billium | P5 | INV-07, REQ-OWN-20, REQ-OWN-21, REQ-ACC-11 | payment.refund.v1; execution_binding | P19-F09 | 2 | барьер: смена маршрута по умолчанию и refund admission | charge succeeded на binding 1; маршрут по умолчанию переключён на binding 2 | refund получает binding 1 исходного charge; иной binding отвергнут триггером | refund.binding_id = charge.binding_id; 0 refund на binding 2 |
| ACC-CON-26 | Refund 30.00 из charge 100.00: наблюдение charge (`refundedTotal` 30.00) и наблюдение refund-операции (`capturedTotal` 30.00) в любом порядке | billium | P3 | REQ-CON-27, REQ-ACC-10, INV-06, INV-09, REQ-FAIL-18, REQ-ACC-11 | payment.observation.v1; payment.refund.v1 | P19R2-F02, D41 | 4 | барьер: оба наблюдения и повторная доставка каждого | charge captured 100.00 распределён на invoice; refund 30.00 succeeded у источника | `refunded_minor` = 30.00 при любом порядке и дублях; расхождение Σ refund и `refundedTotal` — discrepancy без изменения ledger | `payment.refunded_minor` = 3000; `reallocation_required` на 30.00; 0 строк conflict; discrepancy при расхождении |

P4.8: `fixtures/vectors/rating/corrections/original-period.json` содержит ожидаемые полные суммы,
дельты и partial-cancel пример (D19/D56/D63; owner rating). `ProrationCalculatorTest` проверяет
временные дроби и отказ неподдержанных комбинаций; `CorrectionIntegrationSmokeTest` проверяет
исходную цену и период, issued volume/graduated, release policies, выбор и stale tax policy,
повтор, гонки и rollback участников. V1–V3 и V903–V905 остаются отдельными проверками conductor;
их покрытие COV-199–205, COV-502 и COV-538 фиксируется только после фактического прохождения.

P4.10: [машинный manifest](architecture/p4-acceptance.json) связывает ACC-CON-11/12, MG-08,
SB-02/SB-09, EC-11, COV-AC-520 и calendar/tier/commitment/tax/gauge/distinct/finality/composition
с конкретными fixtures и PHP test paths. [Evidence guide](architecture/p4-acceptance.md) описывает
точные суммы, logical PostgreSQL restore, N-процессные gates и обязательные owner inputs
(D53/D56/D59; owner PLAN1.P4.10). `P4AcceptanceManifestTest` делает отсутствие обязательного
предмета/feature disposition красным; наличие ссылки само по себе не считается пройденной проверкой.
Manifest сохраняет красные результаты `checks.1` V1/V3/V4/V5/V6; исправления ожидают повторной
приёмки conductor. Независимый фазовый gate остаётся pending (D59/D64/D66; owner PLAN1.P4.10).

## Restore, clone, lost response

Drills до effect-capable remote-профиля и при смене runtime, retention или custody (REQ-ACC-12). Каждый
проходит цепочку инвариант → граница → инъекция сбоя → durable receipt/query → исход (REQ-ACC-14).

| ID | Сценарий | Сторона | Фаза | Требования | Контракт | Примечание | Checkpoint | Потерян effect/reply | Recovery barrier | Условие возобновления |
|:--|:--|:--|:--|:--|:--|:--|:--|:--|:--|:--|
| ACC-RST-01 | Потерян ответ accepted после Tx A | billium | P5 | REQ-FAIL-01, REQ-ACC-12, REQ-ACC-14, REQ-ACC-15 | payment.execute.v1 / payment.outcome.v1 | — | commit Tx A | ответ клиенту | повтор тем же ключом | исходная операция возвращена |
| ACC-RST-02 | Crash после принятия провайдером до Tx C | billium | P5 | REQ-FAIL-02, INV-04, REQ-ACC-12, REQ-ACC-14 | attempt; payment.find.v1 | — | запрос отправлен | ответ провайдера | lookup до любой новой отправки; второй attempt нет (D37) | исход применён по lookup/callback |
| ACC-RST-03 | Restore без свежего inbox | billium | P5 | REQ-FAIL-15, REQ-ACC-12, REQ-ACC-14 | installation_state | — | backup до callback | inbox-записи | quarantine incarnation; сверка с провайдером | допуск writer извне |
| ACC-RST-04 | Клон со старой epoch и сырой клон без процедуры restore: в чужом deployment, рядом с живым writer, workers стартуют до сверки | billium | P5 | REQ-FAIL-15, INV-02, REQ-ACC-12, REQ-FAIL-19 | installation_state; WriterAdmission; billium.installation.quarantined | P19-F01, D34: копия несёт `dispatch_enabled = true`, ту же incarnation и epoch | копия БД без процедуры (`dispatch_enabled = true`) | fence (epoch, incarnation, флаг скопированы) | ticket `WriterAdmission` не выдан (нет учётных данных, lease занят, checkpoint устарел) → quarantine; 0 отправок и 0 collection step | активация новой incarnation от authority после сверки inventory провайдера |
| ACC-RST-05 | Потерян ответ allocation | billium | P3 | REQ-FAIL-20, REQ-ACC-12, REQ-ACC-14, REQ-CON-14 | AllocationReceipt | — | commit allocation | ответ | query по receipt | тот же receipt, без PSP |
| ACC-RST-06 | Новый потребитель после prune outbox | billium | P3 | REQ-FAIL-22, REQ-FAIL-30, REQ-ACC-12 | outbox_event | — | prune выше floor | ранние события | snapshot + committed cursor | tail без пропусков |
| ACC-RST-07 | Источник feed восстановлен ниже применённой revision | billium | P3, P9 | REQ-FAIL-23, REQ-ACC-12 | feed_partition | — | applied > source | хвост источника | partition recovery_required | rebootstrap |
| ACC-RST-08 | Replay старого ключа после prune tombstone | billium | P5 | REQ-FAIL-25, REQ-FAIL-16, REQ-ACC-12 | intent_tombstone | — | горизонт истёк | запись намерения | namespace закрыт | `billium.key.expired`, эффекта нет |
| ACC-RST-09 | Потерян ответ регистрации чека | billium | P6 | REQ-FAIL-24, REQ-CON-24, REQ-ACC-12 | fiscal_receipt | — | external_id сохранён до сети | uuid/ответ | query исходного, без повторной отправки | чек найден или manualRecovery |
| ACC-RST-10 | Rolling upgrade посреди операции | billium | P3 | REQ-FAIL-29, REQ-ACC-12 | все таблицы | — | expand-миграция | старые workers | оба поколения workers | contract-миграция после окна отката |
| ACC-RST-11 | Потерян ответ выпуска invoice | billium | P6 | REQ-OWN-07, INV-13, REQ-ACC-12, REQ-ACC-14 | DocumentReserved | — | commit номера | ответ | повтор тем же ключом | тот же номер |
| ACC-RST-12 | Refund failed, replacement refund, затем поздний success первого | billium | P5 | INV-10, INV-06, REQ-FAIL-10, REQ-FAIL-18, REQ-ACC-03, REQ-ACC-12 | payment.refund.v1; discrepancy | PE-04 | refund 1 failed-terminal с certificate, refund 2 succeeded | поздний success refund 1 у провайдера | `failed_terminal → succeeded` по факту + discrepancy сверх ёмкости; авто-возврат и повторное взыскание запрещены | discrepancy разрешена оператором; evidence провайдера сохранено |
| ACC-RST-13 | Cancel или allocation после снятия копии, без dispatch, затем копия берёт lease | billium | P5 | REQ-FAIL-15, REQ-FAIL-19, REQ-FAIL-25, INV-02, REQ-ACC-12 | WriterAdmission; money_commit_log; billium.installation.quarantined | P19R2-F01, D39: backup T0; затем cancel O и оплата B из `customer_credit` без dispatch | `commit_no` копии ниже `released_commit_no`, либо lease захвачен без release | штатная остановка: `release(commit_no)`; нештатная: lease истёк без release | quarantine при `commit_no` ниже released; recovery без release: 0 отправок и 0 collection step, lookup и применение исходов разрешены | активация оператором с audit и выбор БД как baseline (REQ-FAIL-10) |

## Покрытие требований

Каждый REQ/INV из `docs/requirements.md`: «учтено» — сценарии каталога выше; «вне границ» — владелец
сосед, Billium в приёмке не пишет; «вопрос владельцу» — приёмка зависит от решения владельца.

| ID | Диспозиция | Сценарии | Основание |
|:--|:--|:--|:--|
| REQ-OWN-01 | учтено | MG-08, EC-12 | REQ-OWN-01 |
| REQ-OWN-02 | учтено | MG-03 | REQ-OWN-02 |
| REQ-OWN-03 | учтено | MG-03 | REQ-OWN-03 |
| REQ-OWN-04 | учтено | FD-07, FD-09 | REQ-OWN-04 |
| REQ-OWN-05 | учтено | PB-14 | REQ-OWN-05 |
| REQ-OWN-06 | учтено | MG-06, ACC-CON-13, ACC-CON-21 | REQ-OWN-06 |
| REQ-OWN-07 | учтено | FD-07, ACC-CON-10, ACC-RST-11 | REQ-OWN-07 |
| REQ-OWN-08 | учтено | PB-02 | REQ-OWN-08 |
| REQ-OWN-09 | учтено | PF-02 | REQ-OWN-09 |
| REQ-OWN-10 | учтено | PB-19, PF-01, PF-07, ACC-CON-02 | REQ-OWN-10 |
| REQ-OWN-11 | учтено | PF-12 | REQ-OWN-11 |
| REQ-OWN-12 | учтено | ACC-CON-08 | REQ-OWN-12 |
| REQ-OWN-13 | учтено | SB-07 | REQ-OWN-13 |
| REQ-OWN-14 | учтено | PB-24, PF-11 | REQ-OWN-14 |
| REQ-OWN-15 | учтено | PF-10 | REQ-OWN-15 |
| REQ-OWN-16 | учтено | PB-02 | REQ-OWN-16 |
| REQ-OWN-17 | учтено | PB-02 | REQ-OWN-17 |
| REQ-OWN-18 | учтено | PB-15 | REQ-OWN-18 |
| REQ-OWN-19 | учтено | MG-10, SB-09 | REQ-OWN-19 |
| REQ-OWN-20 | учтено | PB-21, ACC-CON-23, ACC-CON-25 | REQ-OWN-20 |
| REQ-OWN-21 | учтено | PF-01, ACC-CON-23, ACC-CON-25 | REQ-OWN-21 |
| REQ-PROF-01 | учтено | PB-02, PB-14 | REQ-PROF-01 |
| REQ-PROF-02 | учтено | PB-14 | REQ-PROF-02 |
| REQ-PROF-03 | учтено | PB-15 | REQ-PROF-03 |
| REQ-PROF-04 | учтено | PB-02 | REQ-PROF-04 |
| REQ-PROF-05 | учтено | PB-12, PB-21 | REQ-PROF-05 |
| REQ-PROF-06 | учтено | PB-12 | REQ-PROF-06 |
| REQ-PROF-07 | учтено | MG-01 | REQ-PROF-07 |
| REQ-PROF-08 | учтено | PB-17 | REQ-PROF-08 |
| REQ-PROF-09 | учтено | PB-17 | REQ-PROF-09 |
| REQ-PROF-10 | вопрос владельцу | — | REQ-PROF-10, D18; состав бесплатного и платного (I-02) |
| REQ-PROF-20 | учтено | PB-14, SB-07, ACC-CON-19 | REQ-PROF-20 |
| REQ-PROF-21 | учтено | PB-02, PB-11 | REQ-PROF-21 |
| REQ-PROF-22 | учтено | PB-14, ACC-CON-08 | REQ-PROF-22 |
| REQ-PROF-11 | вопрос владельцу | — | REQ-PROF-11, D18; профиль и первый платный потребитель не выбраны (I-18) |
| REQ-PROF-12 | вопрос владельцу | — | REQ-PROF-12, D18; профиль и первый платный потребитель не выбраны (I-18) |
| REQ-PROF-13 | вопрос владельцу | — | REQ-PROF-13, D18; профиль и первый платный потребитель не выбраны (I-18) |
| REQ-PROF-14 | учтено | MG-01 | REQ-PROF-14 |
| REQ-PROF-15 | вопрос владельцу | — | REQ-PROF-15, D18; профиль и первый платный потребитель не выбраны (I-18) |
| REQ-PROF-16 | вопрос владельцу | — | REQ-PROF-16, D18; профиль и первый платный потребитель не выбраны (I-18) |
| REQ-PROF-17 | вопрос владельцу | — | REQ-PROF-17, D18; профиль и первый платный потребитель не выбраны (I-18) |
| REQ-PROF-18 | учтено | PB-02 | REQ-PROF-18 |
| REQ-PROF-19 | вопрос владельцу | — | REQ-PROF-19, D18; профиль и первый платный потребитель не выбраны (I-18) |
| REQ-CON-01 | учтено | PB-15 | REQ-CON-01 |
| REQ-CON-02 | учтено | PB-15 | REQ-CON-02 |
| REQ-CON-03 | учтено | PB-15 | REQ-CON-03 |
| REQ-CON-04 | учтено | PB-02 | REQ-CON-04 |
| REQ-CON-05 | учтено | PB-05 | REQ-CON-05 |
| REQ-CON-06 | учтено | PB-09 | REQ-CON-06 |
| REQ-CON-07 | учтено | PB-22, ACC-CON-07 | REQ-CON-07 |
| REQ-CON-08 | учтено | PB-05, PB-10 | REQ-CON-08 |
| REQ-CON-09 | учтено | PB-23 | REQ-CON-09 |
| REQ-CON-10 | учтено | PB-14 | REQ-CON-10 |
| REQ-CON-11 | учтено | MG-09 | REQ-CON-11 |
| REQ-CON-12 | учтено | ACC-CON-13 | REQ-CON-12 |
| REQ-CON-13 | учтено | PB-14, PF-12, ACC-CON-24 | REQ-CON-13 |
| REQ-CON-14 | учтено | ACC-RST-05, ACC-CON-20 | REQ-CON-14 |
| REQ-CON-15 | учтено | ACC-CON-12 | REQ-CON-15 |
| REQ-CON-16 | учтено | ACC-CON-12 | REQ-CON-16 |
| REQ-CON-17 | учтено | EC-11 | REQ-CON-17 |
| REQ-CON-18 | учтено | PB-10, PF-10, ACC-CON-14 | REQ-CON-18 |
| REQ-CON-19 | учтено | PB-04 | REQ-CON-19 |
| REQ-CON-20 | учтено | PB-15 | REQ-CON-20 |
| REQ-CON-21 | учтено | PB-17 | REQ-CON-21 |
| REQ-CON-22 | учтено | SB-10 | REQ-CON-22 |
| REQ-CON-23 | учтено | PB-02 | REQ-CON-23 |
| REQ-CON-24 | учтено | FD-03, ACC-RST-09 | REQ-CON-24 |
| REQ-CON-25 | учтено | FD-09 | REQ-CON-25 |
| REQ-CON-26 | учтено | PB-15 | REQ-CON-26 |
| REQ-CON-27 | учтено | PF-13, PF-14, ACC-CON-16, ACC-CON-24, ACC-CON-26 | REQ-CON-27 |
| REQ-CON-28 | учтено | MG-02 | REQ-CON-28 |
| REQ-CON-29 | учтено | PB-06, PF-03, PF-04 | REQ-CON-29 |
| REQ-CON-30 | учтено | SB-07 | REQ-CON-30 |
| REQ-CON-31 | учтено | PB-15 | REQ-CON-31 |
| REQ-CON-32 | учтено | PB-03, PF-01 | REQ-CON-32 |
| REQ-CON-33 | учтено | MG-08 | REQ-CON-33 |
| REQ-CON-34 | учтено | SB-10 | REQ-CON-34 |
| REQ-CON-35 | учтено | SB-10 | REQ-CON-35 |
| REQ-CON-36 | учтено | FD-06 | REQ-CON-36 |
| REQ-CON-37 | учтено | PB-15 | REQ-CON-37 |
| REQ-CON-38 | учтено | FD-07 | REQ-CON-38 |
| REQ-CON-39 | учтено | ACC-CON-01, ACC-CON-12 | REQ-CON-39 |
| INV-01 | учтено | PB-02 | INV-01 |
| INV-02 | учтено | PB-02, PB-13, PB-20, PF-02, ACC-CON-06, ACC-CON-15, ACC-RST-04, ACC-CON-22, ACC-RST-13 | INV-02 |
| INV-03 | учтено | PB-18 | INV-03 |
| INV-04 | учтено | PB-06, ACC-RST-02, ACC-CON-06 | INV-04 |
| INV-05 | учтено | PB-03, PB-04, MG-02, FD-02, ACC-CON-01, ACC-CON-18 | INV-05 |
| INV-06 | учтено | PB-08, PB-09, PB-23, ACC-CON-04, ACC-CON-05, ACC-CON-24, ACC-RST-12, ACC-CON-26 | INV-06 |
| INV-07 | учтено | PB-21, ACC-CON-23, ACC-CON-25 | INV-07 |
| INV-08 | учтено | PB-14, PF-10, ACC-CON-14 | INV-08 |
| INV-09 | учтено | ACC-CON-08, ACC-CON-09, ACC-CON-13, ACC-CON-19, ACC-CON-20, ACC-CON-21, ACC-CON-26 | INV-09 |
| INV-10 | учтено | PB-09, PB-16, MG-10, ACC-CON-03, ACC-CON-04, ACC-CON-20, ACC-RST-12 | INV-10 |
| INV-11 | учтено | MG-03, MG-04, MG-09, ACC-CON-11 | INV-11 |
| INV-12 | учтено | MG-04, MG-08, EC-12, ACC-CON-11 | INV-12 |
| INV-13 | учтено | FD-07, ACC-CON-09, ACC-CON-10, ACC-CON-17, ACC-RST-11 | INV-13 |
| INV-14 | учтено | PB-17, ACC-CON-18, ACC-CON-19, ACC-CON-23, ACC-CON-24 | INV-14 |
| REQ-FAIL-01 | учтено | PB-03, PB-05, PB-18, MG-01, ACC-RST-01 | REQ-FAIL-01 |
| REQ-FAIL-02 | учтено | PB-06, ACC-RST-02 | REQ-FAIL-02 |
| REQ-FAIL-03 | учтено | PB-07, PF-05, ACC-CON-05 | REQ-FAIL-03 |
| REQ-FAIL-04 | учтено | PB-08, ACC-CON-05 | REQ-FAIL-04 |
| REQ-FAIL-05 | учтено | PB-17, PF-05, ACC-CON-23 | REQ-FAIL-05 |
| REQ-FAIL-06 | учтено | PB-04, MG-02, ACC-CON-01 | REQ-FAIL-06 |
| REQ-FAIL-07 | учтено | PB-19, ACC-CON-02, ACC-CON-18 | REQ-FAIL-07 |
| REQ-FAIL-08 | учтено | PF-03 | REQ-FAIL-08 |
| REQ-FAIL-09 | учтено | PB-16, ACC-CON-03 | REQ-FAIL-09 |
| REQ-FAIL-10 | учтено | PB-24, PF-11, ACC-RST-12 | REQ-FAIL-10 |
| REQ-FAIL-11 | учтено | PB-22 | REQ-FAIL-11 |
| REQ-FAIL-12 | учтено | PF-12, ACC-CON-24 | REQ-FAIL-12 |
| REQ-FAIL-13 | учтено | ACC-CON-16, ACC-CON-24 | REQ-FAIL-13 |
| REQ-FAIL-14 | учтено | PB-13, ACC-CON-06 | REQ-FAIL-14 |
| REQ-FAIL-15 | учтено | PB-11, PB-20, PF-08, FD-10, SB-10, ACC-CON-15, ACC-RST-03, ACC-RST-04, ACC-CON-22, ACC-RST-13 | REQ-FAIL-15 |
| REQ-FAIL-16 | учтено | PB-19, PF-07, ACC-RST-08 | REQ-FAIL-16 |
| REQ-FAIL-17 | учтено | PF-02, ACC-CON-06 | REQ-FAIL-17 |
| REQ-FAIL-18 | учтено | PB-24, PF-06, ACC-RST-12, ACC-CON-26 | REQ-FAIL-18 |
| REQ-FAIL-19 | учтено | MG-06, ACC-CON-13, ACC-RST-04, ACC-CON-20, ACC-CON-21, ACC-RST-13 | REQ-FAIL-19 |
| REQ-FAIL-20 | учтено | PB-14, PF-12, ACC-RST-05 | REQ-FAIL-20 |
| REQ-FAIL-21 | учтено | EC-11 | REQ-FAIL-21 |
| REQ-FAIL-22 | учтено | PB-23, PF-13, ACC-RST-06 | REQ-FAIL-22 |
| REQ-FAIL-23 | учтено | PF-14, ACC-CON-16, ACC-RST-07 | REQ-FAIL-23 |
| REQ-FAIL-24 | учтено | FD-03, FD-06, ACC-RST-09 | REQ-FAIL-24 |
| REQ-FAIL-25 | учтено | PF-09, ACC-RST-08, ACC-RST-13 | REQ-FAIL-25 |
| REQ-FAIL-26 | учтено | PB-07 | REQ-FAIL-26 |
| REQ-FAIL-27 | учтено | MG-06 | REQ-FAIL-27 |
| REQ-FAIL-28 | учтено | PF-09, MG-11 | REQ-FAIL-28 |
| REQ-FAIL-29 | учтено | ACC-RST-10 | REQ-FAIL-29 |
| REQ-FAIL-30 | учтено | PB-11, PF-07, FD-10, ACC-RST-06 | REQ-FAIL-30 |
| REQ-ACC-01 | учтено | PB-02, PB-10, PB-12, PB-21 | REQ-ACC-01 |
| REQ-ACC-02 | учтено | PF-01, PF-12 | REQ-ACC-02 |
| REQ-ACC-03 | учтено | PF-02, ACC-CON-01, PB-03, PB-04, ACC-RST-01, ACC-RST-02, ACC-CON-06, PB-05, PF-03, PB-06, PB-17, ACC-CON-03, PB-16, ACC-RST-12, PB-22, ACC-CON-02, PB-23, PB-14, ACC-CON-05, ACC-CON-24, ACC-RST-08, PF-07, PB-19, ACC-RST-03, ACC-CON-23, PB-08, PF-06 | REQ-ACC-03 |
| REQ-ACC-04 | учтено | MG-01, MG-10 | REQ-ACC-04 |
| REQ-ACC-05 | учтено | FD-01 | REQ-ACC-05 |
| REQ-ACC-06 | учтено | SB-02, SB-07 | REQ-ACC-06 |
| REQ-ACC-07 | учтено | EC-11, EC-12 | REQ-ACC-07 |
| REQ-ACC-08 | учтено | MG-03 | REQ-ACC-08 |
| REQ-ACC-09 | учтено | MG-04, EC-11 | REQ-ACC-09 |
| REQ-ACC-10 | учтено | ACC-CON-08, ACC-CON-26 | REQ-ACC-10 |
| REQ-ACC-11 | учтено | ACC-CON-01, ACC-CON-02, ACC-CON-03, ACC-CON-04, ACC-CON-05, ACC-CON-06, ACC-CON-07, ACC-CON-08, ACC-CON-09, ACC-CON-10, ACC-CON-11, ACC-CON-12, ACC-CON-13, ACC-CON-14, ACC-CON-15, ACC-CON-16, ACC-CON-17, ACC-CON-18, ACC-CON-19, ACC-CON-20, ACC-CON-21, ACC-CON-22, ACC-CON-23, ACC-CON-24, ACC-CON-25, ACC-CON-26 | REQ-ACC-11 |
| REQ-ACC-12 | учтено | PB-11, ACC-RST-01, ACC-RST-02, ACC-RST-03, ACC-RST-04, ACC-RST-05, ACC-RST-06, ACC-RST-07, ACC-RST-08, ACC-RST-09, ACC-RST-10, ACC-RST-11, ACC-RST-12, ACC-RST-13 | REQ-ACC-12 |
| REQ-ACC-13 | учтено | PB-15 | REQ-ACC-13 |
| REQ-ACC-14 | учтено | ACC-RST-01, ACC-RST-02, ACC-RST-03, ACC-RST-05, ACC-RST-11 | REQ-ACC-14 |
| REQ-ACC-15 | учтено | ACC-CON-01, ACC-RST-01 | REQ-ACC-15 |
| REQ-ACC-16 | учтено | PB-15 | REQ-ACC-16 |
| REQ-ACC-17 | учтено | PB-03, PB-05, PB-07 | REQ-ACC-17 |
| REQ-ACC-18 | учтено | PB-02 | REQ-ACC-18 |
| REQ-ACC-19 | учтено | PB-06, PB-07, SB-09, SB-10 | REQ-ACC-19 |
| REQ-ACC-20 | учтено | MG-01, MG-02, MG-03, MG-08, MG-10, MG-11 | REQ-ACC-20 |
| REQ-ACC-21 | учтено | FD-01, FD-02, FD-06, FD-07, FD-09, FD-10 | REQ-ACC-21 |
| REQ-ACC-22 | учтено | EC-11, EC-12 | REQ-ACC-22 |
| REQ-ACC-23 | учтено | SB-02, SB-07, SB-09 | REQ-ACC-23 |
| REQ-ACC-24 | учтено | FD-07 | REQ-ACC-24 |
| REQ-NBR-01 | вне границ | PB-01 | REQ-NBR-01, D17 |
| REQ-NBR-02 | учтено | PB-18 | REQ-NBR-02 |
| REQ-NBR-03 | вне границ | PB-01 | REQ-NBR-03, D17 |
| REQ-NBR-04 | учтено | PF-06 | REQ-NBR-04 |
| REQ-NBR-05 | учтено | FD-03 | REQ-NBR-05 |
| REQ-NBR-06 | учтено | MG-06 | REQ-NBR-06 |
| REQ-NBR-07 | вне границ | MG-05 | REQ-NBR-07, D17 |
| REQ-NBR-08 | вне границ | MG-12 | REQ-NBR-08, D17 |
| REQ-NBR-09 | учтено | SB-10 | REQ-NBR-09 |
| REQ-NBR-10 | вне границ | — | REQ-NBR-10, D17 |
| REQ-NBR-11 | учтено | MG-11 | REQ-NBR-11 |
| REQ-NBR-12 | учтено | PB-15 | REQ-NBR-12 |
| REQ-NBR-13 | учтено | FD-01 | REQ-NBR-13 |
| REQ-NBR-14 | учтено | PB-17 | REQ-NBR-14 |
| REQ-NBR-15 | учтено | FD-10 | REQ-NBR-15 |
| REQ-NBR-16 | учтено | MG-11 | REQ-NBR-16 |
| REQ-OUT-01 | вне границ | MG-07 | REQ-OUT-01, D17 |
| REQ-OUT-02 | вне границ | — | REQ-OUT-02, D17 |
| REQ-OUT-03 | вне границ | — | REQ-OUT-03, D17 |
| REQ-OUT-04 | вне границ | — | REQ-OUT-04, D17 |
| REQ-OUT-05 | вне границ | EC-01, EC-02, EC-03, EC-04, EC-05, EC-06, EC-07, EC-08, EC-09, EC-10 | REQ-OUT-05, D17 |
| REQ-OUT-06 | вне границ | — | REQ-OUT-06, D17 |
| REQ-OUT-07 | вне границ | — | REQ-OUT-07, D17 |
| REQ-OUT-08 | вне границ | — | REQ-OUT-08, D17 |
| REQ-OUT-09 | вне границ | SB-01, SB-03, SB-05, SB-06 | REQ-OUT-09, D17 |
| REQ-OUT-10 | вне границ | MG-05, MG-12, EC-02 | REQ-OUT-10, D17 |
| REQ-OUT-11 | вне границ | SB-04, SB-08 | REQ-OUT-11, D17 |
| REQ-OUT-12 | вне границ | FD-04, FD-05, FD-08, FD-01 | REQ-OUT-12, D17 |
| REQ-OUT-13 | вне границ | FD-05 | REQ-OUT-13, D17 |
| REQ-OUT-14 | вне границ | — | REQ-OUT-14, D17 |
| REQ-OUT-15 | вопрос владельцу | — | REQ-OUT-15; бизнес-факты — вопросы владельцу |
| REQ-OUT-16 | вне границ | — | REQ-OUT-16, D17 |
| REQ-OUT-17 | вне границ | — | REQ-OUT-17, D17 |
| REQ-OUT-18 | вне границ | EC-05 | REQ-OUT-18, D17 |
| REQ-OUT-19 | вне границ | — | REQ-OUT-19, D17 |

### PE → каталог

PE-01…PE-09 — предложенные сценарии паспорта исполнения (SRC-006 § 9), применяемые к Billium execution
(REQ-ACC-03). Суть — по тексту источника; часть, принадлежащая соседу, названа явно.

| PE | Суть по SRC-006 §9 | Строки каталога | Покрытие |
|:--|:--|:--|:--|
| PE-01 | Тот же ключ и payload параллельно → одна операция и один initiate; изменённые сумма/meta/платёж → conflict | ACC-CON-01, PB-03, PB-04 | полное; null-default суммы Commerz неприменим — `money` обязателен в `payment.execute.v1` |
| PE-02 | Kill после Tx A / принятия PSP / Tx B, потеря ответа → recovery той же операции и ключа; stale worker не понижает state | PF-02, ACC-RST-01, ACC-RST-02, ACC-CON-06, PB-05 | полное |
| PE-03 | Пустой lookup при живом запросе, истёкшем TTL или неверном merchant → uncertain, баланс удержан; absence только с evidence | PF-03, PB-06, ACC-RST-02, PB-17 | полное |
| PE-04 | 100 captured: 70 unknown + параллельные 40 (отказ) и 30 (допуск); failed → поздний success после replacement refund → отдельная discrepancy | ACC-CON-03, PB-16, ACC-RST-12 | полное |
| PE-05 | Cancel заказа, затем подтверждённый capture: деньги сохранены, одно compensation-намерение, grant не активируется | PB-22, ACC-CON-02, PB-24 | частичное: статус заказа — Commerz (REQ-NBR-02), grant — хост (REQ-OUT-11) |
| PE-06 | 30 → 60 из 100 при том же статусе: наблюдение или query-repair, allocation 60; дубли и перестановки totals не суммируются | PB-23, PB-14, ACC-CON-05, ACC-CON-24 | полное |
| PE-07 | Тот же ключ после 30-дневного prune receipts и restore: прежний charge/refund не исполняется снова | ACC-RST-08, PF-07, PB-19, ACC-RST-03 | полное |
| PE-08 | Два merchants с одинаковым external ID, ротация credentials, callback без internal ref: изоляция и исходный binding; unmatched callback — только с durable custody | ACC-CON-23 | полное |
| PE-09 | Несовпадение суммы/валюты refund, нет суммы при partial capture, отозванный actor, decline после success: несогласованное наблюдение отвергнуто или в quarantine | PB-08, PB-17, PF-06, ACC-CON-24 | полное |

### P6 — Documents, fiscal boundary и accounting facts

| ID | Сценарий | Тест / проверка | Покрытие |
|:--|:--|:--|:--|
| P6.1 | `DocumentProfile` (неполный профиль запрещает issue), `draft -> finalizing -> snapshot_ready` с CAS `input_digest`, external `TaxSnapshotPort` вне Tx, timeout/retry, late/stale snapshot ignored, currency/amount conflict, recheck под lock блокирует changed lines, zero-float tax arithmetic (`tax-arithmetic.json`), disabled mode не блокирует ledger | `DocumentProfileTest` (`--filter=DocumentProfile`) | P6.1 |
| P6.2 | Атомарная выдача под single root `OperationRunner` (`Obligation -> Invoice -> DocumentSeries`), 20 concurrent issue same basis -> 1 номер, 20 different basis -> 20 номеров без пропусков/дублей, rollback после ledger issue, forged `TransactionContext` reject, cross-seller series reject, lost-reply replay, void без переиспользования номера, DB immutability triggers, allow-listed metadata | `LegalIssueTest` (`--filter=LegalIssue`) | P6.2 |
| P6.3 | Асинхронный `render_request` + CAS attach без повторной выдачи, storage timeout-after-upload возвращает прежний `blobRef`, stale revision -> `orphaned` (не перезаписывает новую), `readRender` + `billium.query.v1`, seller/actor auth, TTL expiry, asset allow-list, redacted logs, history read при disabled renderer, retention-pinned orphan GC | `DocumentRenderTest` (`--filter=DocumentRender`) | P6.3 |
| P6.4 | Разделение `credit_note` / positive `document_correction` / `void_reissue` / `write_off` без автозапуска PSP refund, immutable `CreditNoteLine` lineage и DB constraint trigger, two-line bundle + 2 sequential partial credits (exact final remainder), `open_only` vs `reverse_allocations`, optional legal numbering, host `taxCorrectionPolicy` | `DocumentCorrectionTest` (`--filter=DocumentCorrection`) | P6.4 |
