# 06 — Conformance

Vectors, golden, runner и адаптеры PHP и Dart. Формат вектора — `schema/conformance/vector.json`, протокол
адаптера — `schema/conformance/adapter-message.json`.

## Ревизия

[BC-CNF-01] Общее между PHP и Dart — эта ревизия и fixtures, а не общий код. (REQ-CON-03, D9)

[BC-CNF-02] `rules.json` и `revision.lock.json` генерируются tooling; ручная правка запрещена, свежесть проверяет слой `derived`. (M-02, M-03)

[BC-CNF-03] Замороженная ревизия неизменяема; несовместимое изменение выходит новой датированной ревизией. (M-01, REQ-CON-20)

## Vectors и golden

[BC-CNF-04] Вектор валиден по `schema/conformance/vector.json`, и его `id` равен пути файла под `fixtures/vectors/` без `.json`. (M-06)

[BC-CNF-05] Каждый маркер `spec_refs` вектора разрешим: `BC-*` — в `rules.json`, REQ/INV — в `docs/requirements.md`, D# — в решениях плана. (M-10)

[BC-CNF-06] Каждое правило `rules.json` упомянуто в `spec_refs` хотя бы одного вектора или перечислено в `fixtures/coverage.json` с причиной; not testable не засчитывается как pass. (M-10)

[BC-CNF-07] `expect` — массив длины `script`; `ok` сравнивается частично: ключи — подмножество вывода, значения равны точно, массивы сравниваются целиком. (M-06)

[BC-CNF-08] Golden — UTF-8 без BOM с ровно одним завершающим LF; создаётся оракулом и никогда не регенерируется выводом SDK. (M-07, M-15)

[BC-CNF-09] Вектор с `control` = `must-fail` падает в каждом адаптере; его pass делает прогон красным. (M-16)

[BC-CNF-10] Поведенческие векторы денежных операций входят только в профиль `php`; профиль `dart` — срез codec и контрактов. (REQ-OWN-18, M-09, M-19)

## Runner и адаптеры

[BC-CNF-11] Протокол адаптера — JSON Lines по stdin и stdout, каждое сообщение валидно по `schema/conformance/adapter-message.json`; stderr — только диагностика. (M-09)

[BC-CNF-12] Статусы отчёта закрыты: `pass`, `fail`, `error`; статуса `skip` нет; таймаут — 10 с на вектор. (M-09, M-33)

[BC-CNF-13] Прогон зелёный, только если множество не-pass векторов равно множеству записей baseline; запись, чей вектор прошёл или исчез, — ошибка. (M-11)

[BC-CNF-14] `revision.lock.json.revision`, `spec_rev` пакетов PHP и Dart и `ready.spec_rev` адаптера совпадают; иначе прогон красный. (M-12)

[BC-CNF-15] Отчёт conformance не заявляет exactly-once и не смешивается с паритетом PHP и Dart. (M-14)
