# Billium — вопросы владельцу (PLAN1.P1.8)

Вопросы не решены, только рекомендованы (D7, M1 §7). «Блокирует» — конкретный scope, который ждёт ответа;
всё остальное идёт по roadmap-proposal. Ответ владельца записывается D#-записью следующей design-сессии.

Порядок строк: бизнес-вводные (I-*, PSP, валюты, горизонты, gates пакетов, BRAIN-REPO); открытые
исследования DR/CL — решение build по всем областям (D10–D15) снимает зависимость от открытых
vendor-фактов, они нужны только при пересмотре adopt через gate REQ-ACC-24; затем конфликты документов
CONFLICT-01…13.

| ID | Вопрос | Варианты | Рекомендация | Блокирует |
|:--|:--|:--|:--|:--|
| I-01 | Юридические продавцы и реквизиты | AxiomaSoft; Flex1.ru; несколько юрлиц по продуктам | Назвать продавца первого платного сценария; модель уже seller-scoped (INV-14, D23), реквизиты — вход (REQ-NBR-13) | P6 (нумерация, документы), запуск реальных платежей P5 |
| I-02 | Какие пакеты бесплатные, платные, приватные | всё открыто; contracts+core открыты, server/collection платные; всё приватно | Полезная бесплатная база без paywall-инвариантов, платность — доступность пакета, не ветка кода (REQ-PROF-10, REQ-PROF-22, D17) | Публикацию пакетов (P10), `publish_to` Dart (P2) |
| I-16 | Налоговые режимы и фискальные профили продавцов | УСН/ОСНО/НПД; чеки ЮKassa или своя касса | Billium принимает налоговый snapshot от владельца правил (REQ-OUT-16); фискал — через исполнителя (REQ-CON-24); B2B без ЭСП ККТ не требует (DR02-18) | P6 fiscal port в production |
| I-18 | Первый платный потребитель: Memster или FlexCRM | Memster (embedded); FlexCRM (embedded или server) | Memster: денежная часть billing уже существует и мигрирует по CD-08 (REQ-PROF-14), даёт приёмку MG-01…MG-11; FlexCRM — следующим (REQ-PROF-11) | Порядок P4/P9, выбор профиля поставки первого внедрения |
| PSP-01 | PSP первого запуска | ЮKassa; CloudPayments; другой | ЮKassa: официальный PHP SDK (DR02-23), idempotency 24 ч (DR02-02) против 1 ч у CloudPayments (DR02-08); драйвер — PaymentDrivers (REQ-OUT-06) | P5 на реальном runtime, PB на квалифицированном профиле |
| I-28 | Бандл AiFina+AiResto | один бандл; раздельные подписки | Billium моделирует бандл компонентами с allocation по компонентам (REQ-OWN-13, D11); выбор состава — продукт | Цены бандла в P4 только для этих продуктов |
| CUR-01 | Валюты и мультиюрисдикция | только RUB; RUB + USD/EUR; мультиюрисдикция | Старт — одна валюта RUB и одна юрисдикция; MoneyWire и таблица ISO 4217 уже поддерживают остальные (D29, DR04-11); FX не в Billium | Налоговые snapshots и документы P6 для второй валюты/страны |
| RET-01 | Числовые горизонты retention ключей, tombstones и журналов | по провайдеру; по сроку refund/backup; юридический срок хранения | Горизонт ≥ max(окно refund, backup, поздний callback) и не короче provider TTL (REQ-FAIL-16, REQ-FAIL-30, DR02-02) | Prune в P3/P5, restore drills |
| OPS-01 | Operational owner, SLO, backup/restore для execution runtime | владелец продукта; отдельная команда; не включать execution | Не включать execution, пока нет владельца и drills (REQ-PROF-21, D18) | Включение execution в P5 за пределами test profiles |
| GATE-01 | Dart-потребитель server API (gate `billium_sdk`) | FlexCRM-приложение; AiFina; нет | Не создавать SDK до названного потребителя (D17, REQ-CON-26) | `billium_sdk` в P8 |
| GATE-02 | FinBridge/AiFina как потребитель фактов Billium | принять `finbridge.fact/1`; отложить | Создавать `billium-finbridge` только после подтверждения приёма (D17, REQ-CON-22) | Мост в P6 |
| I-20 | GL внутри Billium | нет GL; GL при названном потребителе | Нет GL, только subledger (REQ-OUT-04, DR04-18) | Ничего до названного потребителя |
| I-15 | Выплаты авторам и издателям | Settlement отдельно; не строить | Не строить в Billium (REQ-OUT-09) | Сценарии SB-01, SB-03, SB-05, SB-06 у соседа |
| BRAIN-REPO | Заполнить `repo:` в карточке Billium (включает brain sync) | заполнить сейчас; после P1.9; не заполнять | Заполнить после GREEN verdict P1.9 и подтверждения эффекта sync владельцем; до этого frontmatter не трогать (D4, CONFLICT-06) | Brain sync карточки Billium |
| DR01-T02 | Остаток схем Lago/Kill Bill (plans, usage, invoice DDL) | доисследовать; закрыть | Закрыть: build выбран (D11, D12) | Ничего |
| DR01-T05 | Fault traces финалистов | доисследовать; закрыть | Закрыть: собственные ACC-CON/ACC-RST (D27, DR01-23) | Ничего |
| DR01-T08 | Retention/upgrade/запросы финалистов | доисследовать; закрыть | Закрыть; собственная политика D25, D21 | Ничего |
| DR01-T09 | Сравнение композиций A–D | закрыто P1.6 | Закрыто решениями D9–D18 (CL-86, CL-89) | Ничего |
| CL-18 | Защищает ли Kill Bill все cross-entity balances | доисследовать; закрыть | Закрыть: Kill Bill не основа (D10, DR01-24) | Ничего |
| CL-19 | Tenant Kill Bill против legal seller | доисследовать; закрыть | Закрыть (D10, D23) | Ничего |
| CL-96 | Payment guide Kill Bill не прочитан | доисследовать; закрыть | Закрыть (D10) | Ничего |
| CL-102 | Exactly-once ingestion OpenMeter | доисследовать; закрыть | Закрыть: свой intake (D13, DR01-30) | Ничего |
| DR01-LEGAL | Юридическая интерпретация AGPL/ELv2 | юрист; не требуется | Не требуется при build; нужна только при adopt Lago/Invoice Ninja (DR01-38, DR01-37, REQ-ACC-24) | Любой будущий adopt этих кандидатов |
| DR02-T05 | Ротация credentials у провайдера | тестовый кабинет; документация PaymentDrivers | Проверить в P5 на тестовом кабинете выбранного PSP; binding фиксирует account (REQ-OWN-21, D26) | Ротация в production P5 |
| DR02-T09 | Сверка по реестрам ЮKassa/CloudPayments | P5; позже | Исследовать в P5 вместе с выбором PSP (REQ-FAIL-18, DR02-28) | Reconcile-отчёты P5 |
| DR02-T03 | Полные timelines callback раньше ответа, out-of-order | P5; позже | Покрыто PB-07, PB-08, ACC-CON-05; таймлайны провайдера — в драйвере (REQ-NBR-04) | Ничего в Billium |
| DR02-T06 | Mandate/consent CloudPayments, смена плательщика | P7; позже | Исследовать в P7 при выборе PSP (DR02-21, D15) | Recurring P7 |
| DR02-T08 | Применимость 54-ФЗ к доступу, лицензиям, credits; ЭДО | юрист; позже | Вопрос юристу вместе с I-16 (DR02-29, REQ-NBR-13) | Фискал P6 в production |
| CL-121 | Профили нумерации документов продавца | по продавцу; единая | Seller + series + period, policy от продавца (REQ-OWN-07, D14) вместе с I-01 | Нумерация P6 |
| DR02-25 | Контракт МКБ (эквайринг, СБП) | исследовать; не нужен | Только если МКБ выбран PSP (DR02-25, REQ-OUT-06) | Драйвер МКБ |
| DR02-24 | Официальный PHP SDK CloudPayments | исследовать; тонкий HTTP-адаптер | Решение PaymentDrivers (DR02-24, REQ-OUT-06) | Драйвер CloudPayments |
| DR02-ATOL | Возвращает ли ошибка 33 АТОЛ `uuid` исходного документа | тестовый кабинет; политика | Хранить `uuid` до сети, иначе manualRecovery (DR02-17, REQ-FAIL-24) | Фискал через АТОЛ |
| DR03-T07 | Версия subscriptionify в Memster, маппинг legacy ID | прочитать lock-файл; позже | Проверить в P9 по репо Memster (DR03-09, REQ-NBR-16) | Импорт Memster P9 |
| DR03-T04 | Окна месячных grants по часовому поясу | P4; Tariffler | Денежные интервалы — calendar policy Billium в P4; grant-окна — Tariffler (D12, DR03-13) | Proration P4 |
| CL-117 | MoneyPHP или Brick Money | прогон fixtures P2 | Выбрать в P2 по `money/*` fixtures (DR04-24, D16) | P2 |
| DR04-T03 | Wire/error примитивы AgenTools | перечитать; закрыть | Перечитать клон при P2 вместе с методами (DR04-22, M-06) | Ничего |
| DR04-AXC | `axioma_contracts` Money не перепроверен | P2 conformance | Сверить в P2 на общем корпусе (DR04-15, REQ-NBR-12) | Dart conformance P2 |
| DR04-NEG | `allocate` MoneyPHP для отрицательных сумм | P2 прогон | Вектор отрицательной allocation в P2 (DR04-03, REQ-CON-30) | P2 |
| CONFLICT-01 | Tower: «ControLLM → Tariffler → Billium», `uses: [Tariffler]` | исправить tower; оставить | Исправить текст tower по CD-15 (REQ-CON-15); правка вне трёх разрешённых (D4) — с согласия владельца | Только согласованность Brain |
| CONFLICT-02 | Provider adapter внутри Billium в tower/Billium-Boundaries | исправить; оставить | Исправить по CD-03 (REQ-OUT-06) | Только согласованность Brain |
| CONFLICT-03 | Форма MoneyWire | decimal major; minor + scale | Принять D29 и предложенную CD-запись (REQ-CON-02) | Ревизия спеки P2 |
| CONFLICT-04 | Словарь профилей Light/Embedded/Server | канон CD-14 | Действует канон (REQ-PROF-06); обновить Features-Billium | Ничего |
| CONFLICT-05 | Омоним B-NN | разведено | Ничего не делать (REQ-PROF-06) | Ничего |
| CONFLICT-06 | Состояние repo Billium в Brain | заполнить `repo:`; нет | См. BRAIN-REPO (D4) | Brain sync |
| CONFLICT-07 | Фискализация внутри Billium в tower | исправить tower | Исправить по CD-04 (REQ-CON-24) | Только согласованность Brain |
| CONFLICT-08 | «pro extensions» в рекомендации дистрибуции | канон | Канон (REQ-PROF-10); ответ — I-02 | Публикация пакетов |
| CONFLICT-09 | Опубликованы ли старые frozen схемы Billium v1 потребителям | да; нет | Если да — отдельная compatibility map в P2; если нет — считать v1 новым (REQ-CON-20, D9) | Ревизия спеки P2 |
| CONFLICT-10 | AGPL Lago по REST | юр. разбор; не нужен | Не нужен: Lago не используется (D11, DR01-38) | Ничего |
| CONFLICT-11 | Towers FlexCRM/FlexStore про `corex/billing` и розницу через Billium | исправить towers | Исправить по канону (REQ-OUT-03, REQ-PROF-19) | Только согласованность Brain |
| CONFLICT-12 | Billium как «монетизация авторов» в tower Memster | исправить; Settlement | Исправить по REQ-OUT-09; ответ — I-15 | Ничего в Billium |
| CONFLICT-13 | Имя `commerz-billium-adapter` | исправить policy | Исправить на `commerz-billium` (REQ-OUT-08, REQ-CON-01) | Ничего |
