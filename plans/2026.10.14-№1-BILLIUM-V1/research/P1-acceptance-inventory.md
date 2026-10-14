# P1 — инвентарь приёмочных сценариев (D8)

Snapshot исходных PB/PF/MG/FD/SB/EC. Это перечень для диспозиции в P1.7,
не результаты тестов и не передача Billium чужих обязанностей. Для каждого ID P1.7
выделяет сторону Billium или даёт обоснованную диспозицию `сосед:<имя>` / `n/a`.
При изменении источника исходный ID сохраняется; расхождение документируется,
новые ID добавляются только через design-delta. SB развёрнут из нумерованного абзаца.

## Источники

| Набор | SRC | Путь относительно BRAIN | sha256 |
|:--|:--|:--|:--|
| PB | SRC-005 | `Infrastructure/Workspace/Pilot-Commerz-Billium-Payment-Boundary.md` | `a8f53984ee6dff7b2fca0f13cefae7b05fdac09408e4fc3ca862a72db1ff70e9` |
| PF | SRC-018 | `Infrastructure/Workspace/Domains/Billing/Payment-Implementation-Prerequisites.md` | `45357a9c63a452e72fe23bd5ef17ebb9d92d2944b94bf62fb2b710dd222c1db7` |
| MG | SRC-165 | `Infrastructure/Workspace/Projects/Memster/Memster-Implementation-Prerequisites.md` | `8ee1ddea61310747f1f09d8e8883c074f3af235fa2cc2947bb8fecb1cc269627` |
| FD | SRC-208 | `Infrastructure/Workspace/Fiscal-Offline-Documents-Implementation-Prerequisites.md` | `91aad1a645d47ff336fefb61acf6a4d61385794092eacdb4e19f86864851ad99` |
| SB | SRC-221 | `Infrastructure/Workspace/Settlements-Bundles-Implementation-Prerequisites.md` | `88d7b960179ebfb4af3e04b8bf2f3f170527f55083f4420e2e28df8e9ea293ac` |
| EC | SRC-215 | `Infrastructure/Workspace/Projects/Edufy/Edufy-Implementation-Prerequisites.md` | `d4b7b990a6a185e87169bf2ba32c2f52b3f546d9b02064a3189a08d968fdebfb` |

## Сценарии

| ID | Источник | Строка | Название в источнике |
|:--|:--|--:|:--|
| PB-01 | SRC-005 | 209 | Normal embedded charge |
| PB-02 | SRC-005 | 210 | Normal remote charge |
| PB-03 | SRC-005 | 211 | Duplicate execute |
| PB-04 | SRC-005 | 212 | Same key different digest |
| PB-05 | SRC-005 | 213 | Lost accepted response |
| PB-06 | SRC-005 | 214 | Provider unknown |
| PB-07 | SRC-005 | 215 | Duplicate callback |
| PB-08 | SRC-005 | 216 | Callback conflict |
| PB-09 | SRC-005 | 217 | Partial refund retries |
| PB-10 | SRC-005 | 218 | Adapter outage/replay |
| PB-11 | SRC-005 | 219 | Backup restore |
| PB-12 | SRC-005 | 220 | Embedded→remote cutover |
| PB-13 | SRC-005 | 221 | Fenced old worker |
| PB-14 | SRC-005 | 222 | Allocation consumer duplicate |
| PB-15 | SRC-005 | 223 | Old/new contract compatibility |
| PB-16 | SRC-005 | 224 | Two concurrent refunds |
| PB-17 | SRC-005 | 225 | Cross-seller lookup/command and forged callback |
| PB-18 | SRC-005 | 226 | Intent rollback / lost local response commit |
| PB-19 | SRC-005 | 227 | New key for same business intent / expired key |
| PB-20 | SRC-005 | 228 | Restored clone and stale routing epoch |
| PB-21 | SRC-005 | 229 | Post-cutover refund and remote→embedded rollback |
| PB-22 | SRC-005 | 230 | Cancel before/after send |
| PB-23 | SRC-005 | 231 | Same-status amount change / bootstrap / retention gap |
| PB-24 | SRC-005 | 232 | Late external effect exceeds local bounds |
| PF-01 | SRC-018 | 18 | Sales Tx A: one semantic intent/key→operation + frozen binding |
| PF-02 | SRC-018 | 19 | Executor response custody → fenced transition |
| PF-03 | SRC-018 | 20 | Recovery observational absence + no late sends |
| PF-04 | SRC-018 | 21 | Replay explicit authorization/body/account/window |
| PF-05 | SRC-018 | 22 | Account-qualified callback custody/identity |
| PF-06 | SRC-018 | 23 | Provider money/terminal evidence |
| PF-07 | SRC-018 | 24 | Sales financial intent survives generic prune |
| PF-08 | SRC-018 | 25 | Restore excludes missing external effect и clone sends |
| PF-09 | SRC-018 | 26 | Closed namespace/import preserves duplicate protection |
| PF-10 | SRC-018 | 27 | Sales row/revision/log/outbox same connection Tx |
| PF-11 | SRC-018 | 28 | Discrepancy truth independent local bounds |
| PF-12 | SRC-018 | 29 | Billium inbox/allocation apply atomic and idempotent |
| PF-13 | SRC-018 | 30 | Consistent snapshot + retained tail/new consumer |
| PF-14 | SRC-018 | 31 | Committed source cursor and restore lineage |
| MG-01 | SRC-165 | 54 | Subscribe/ensure committed then lost reply;100 retries return original subscription/grant receipt/ID, no extra entitlement or money |
| MG-02 | SRC-165 | 55 | Same key changed subject/quantity/plan/price/period; typed conflict before write; original receipt remains queryable only to authorized subject |
| MG-03 | SRC-165 | 56 | Proposed annual coverage +12 monthly slots; repeated/reordered funding and scheduler revisions never repeat annual obligation/monthly grant; payment replay independent of grant time |
| MG-04 | SRC-165 | 57 | Two revisions/different minted IDs for same coverage; alias/overlap constraint blocks duplicate effect. Real successor interval has explicit supersession/adjustment lineage |
| MG-05 | SRC-165 | 58 | Concurrent subscribe and20 reserves/consumes at cap; single active profile/occurrence, in-lock check, no overspend/lost update including first-use row creation. PostgreSQL proof required, SQLite/read probe недостаточен |
| MG-06 | SRC-165 | 59 | Cancel-before-ensure and ensure-before-cancel, late reply, restored old dispatcher; tombstone/compensation receipt and pending barrier; no reactivation from delayed renew/grant |
| MG-07 | SRC-165 | 60 | End-of-term cancel versus immediate, expired Active/trial without worker, stale object cache; admitted service follows accepted effective cutoff, not incidental vendor status/cache |
| MG-08 | SRC-165 | 61 | Mid-period upgrade/downgrade used8/cap10→100/5; explicit delta/effective date, consumption retained, no full grant reset; money proration uses original price terms |
| MG-09 | SRC-165 | 62 | Late issue after original period end, Jan31/leap/DST/trial dates; frozen calendar policy, expired-unissued receipt or explicit compensation; no moved period from now |
| MG-10 | SRC-165 | 63 | Partial refund while units consumed/held; exact component monetary correction, owner-local rights correction and unresolved reservation retained; no percentage shortcut |
| MG-11 | SRC-165 | 64 | Legacy import replay/changed snapshot, ID lookup/read compatibility, raw counter and reward reconciliation, one-writer cutoff/rollback; no generated past consumption or provider IDs |
| MG-12 | SRC-165 | 65 | Generation reserve60/actual35/final settlement+release25, duplicate/changed fact, deadline after send then late usage, actual>reserve; effect/usage provenance and quota conservation |
| FD-01 | SRC-208 | 24 | Selected seller/mode/consent → correct tax/fiscal/payment order, no SaaS/guest mixing |
| FD-02 | SRC-208 | 25 | Same key/digest original receipt; changed seller/body/config conflict |
| FD-03 | SRC-208 | 26 | Crash after fiscal effect, lost response → exact query or manualRecovery, no resend |
| FD-04 | SRC-208 | 27 | Partial split receipt, device replacement/revision mismatch preserves original parts |
| FD-05 | SRC-208 | 28 | Wrong actor/org/device; expired cashier rights/offline profile blocks unauthorized send |
| FD-06 | SRC-208 | 29 | Fiscal success/payment absent and payment success/fiscal absent independent outcomes |
| FD-07 | SRC-208 | 30 | Invoice legal number unchanged by template update/re-render; output ACL/hold/current revision |
| FD-08 | SRC-208 | 31 | Print accepted/uncertain distinct from paper/fiscal registration; explicit duplicate copy |
| FD-09 | SRC-208 | 32 | Cancel/correction/refund each has original linked receipt and current policy |
| FD-10 | SRC-208 | 33 | Prune/backup/restore/clone/reconnect cannot resurrect deleted body or resend old fiscal effect |
| SB-01 | SRC-221 | 21 | qualified event→one payable→statement→confirmed payout→accounting |
| SB-02 | SRC-221 | 21 | unknown policy blocks money without inventing zero obligation |
| SB-03 | SRC-221 | 21 | duplicate/changed user/amount/currency key |
| SB-04 | SRC-221 | 21 | lost grant/conversion hold and exact original outcome |
| SB-05 | SRC-221 | 21 | lost payout/cancel/reordered facts preserve in-flight and no double payout |
| SB-06 | SRC-221 | 21 | payout followed refund/content deletion append reversal, no erased history |
| SB-07 | SRC-221 | 21 | two-component bundle exact allocations/tax/rounding/lineage |
| SB-08 | SRC-221 | 21 | partial fulfilment/late grant/cutoff each owner receipt |
| SB-09 | SRC-221 | 21 | partial cancel/consumed quota/late provider usage separate |
| SB-10 | SRC-221 | 21 | retention/restore/clone no repeated grant/payout/accounting |
| EC-01 | SRC-215 | 43 | Current unavailable composition |
| EC-02 | SRC-215 | 44 | 20 simultaneous reserves, including same dictation intent |
| EC-03 | SRC-215 | 45 | Reserve commit/reply loss; changed amount/account/actor |
| EC-04 | SRC-215 | 46 | Voice closed then run timeout; replay after completed |
| EC-05 | SRC-215 | 47 | Provider sent, response lost, worker restart |
| EC-06 | SRC-215 | 48 | Invalid JSON after successful effect |
| EC-07 | SRC-215 | 49 | reserve60→provisional20→cumulative final35; duplicate/reorder |
| EC-08 | SRC-215 | 50 | Cancel before send vs during send vs after settlement |
| EC-09 | SRC-215 | 51 | Confirmation after TTL, concurrent new admission |
| EC-10 | SRC-215 | 52 | Adjustment commit then audit/outbox/cache failure and repeat |
| EC-11 | SRC-215 | 53 | actual>reserve/late after renewal or closed invoice |
| EC-12 | SRC-215 | 54 | Restore, receipt pruning, scheduler/model/price revision |

Итого: 82 (PB 24, PF 14, MG 12, FD 10, SB 10, EC 12).
