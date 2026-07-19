# Closeout — Leave cascade slot times + longer makeup（2026-07-19）

> **Status:** Engineering closeout complete. Historical batch `--execute` **not** approved.  
> **Related PRs:** #1335 (R77), #1337 (R59 longer makeup), #1338 (evidence + net idempotency), #1339 (fixture TelegramID).  
> **Evidence run:** GitHub Actions `29680051696` (Leave/Makeup Evidence Closeout, success).  
> **Production HEAD at evidence:** `74e197c1`.

---

## Founder decisions (binding)

1. **Do not** run `repair:leave-cascade-slot-times --execute --force` on the current 96 candidates.
2. **Do not** require Founder or engineers to manually triage raw DB / artisan stdout.
3. Historical remaps become a **director-reviewable data repair** (CSV-first; no large UI yet):
   - Show student, course date, current slot, contract slot, reason, leave/makeup/manual-exception evidence.
   - Default unchecked; single or explicit multi-select; preview before apply.
   - Audit: approver, before/after, executed_at, reason.
   - Execute **only** approved `class_session_id`s — never re-scan-then-batch-write.
   - Idempotent + rollback/compensate command required.
4. First quantify high-confidence subset of the 96; if small → CSV / existing director paths before building UI.
5. Open a **standalone GitHub Issue for TD-059** (package pool minutes). No production schema change until impact is confirmed.

---

## Production probes (domain path)

| Probe | Result |
|-------|--------|
| `LEAVE_PROBE_JSON` | `ok=true`, `sat_ok`, `wed_ok`, `undo_ok`, `exception_preserved=true` |
| `MAKEUP_PROBE_JSON` | `ok=true`, `ledger_minutes=180`, retry idempotent, reverse idempotent, normal longer = whole session, `charge_unchanged=true` |
| Health | `GET /api/v1/health` → `status=ok` |
| Fixtures | `__CLOSEOUT_TEST__` rolled back / cleaned (no real-student mutation) |

Teacher Wed17/Sat10 contract located: `sc=2301` campus=4, `leave_rows=0`, current clocks already correct. No live drift row for that pattern in dry-run (`includes_wed17_sat10=false`).

---

## Dry-run quantification (limit=200, production)

| Metric | Value |
|--------|------:|
| courses_scanned | 762 |
| multi_weekday distinct-clock courses | 86 |
| candidates | **96** |
| distinct_courses | 36 |
| distinct_students | 34 |
| includes_wed17_sat10 | false |
| reason | `foreign_weekday_clock_on_target_date` |
| safeguard | current clock equals other weekday contract; skip `IsContractException` |

### Rule-based confidence (offline on evidence stdout; no Founder manual review)

Source: Actions run `29680051696` candidate lines (96).

| Tier | Sessions | Courses | Rule (summary) |
|------|---------:|--------:|----------------|
| **high_confidence** | **19** | 11 | `status=leave` with foreign clock, **or** scheduled sibling on same course that has a leave candidate in the set |
| **medium_pattern** | 57 | 10 | Reciprocal weekday clock-swap pattern across ≥2 rows, but **no leave row** in the candidate set |
| **needs_review** | 20 | 15 | Singleton / weak pattern; cannot assert leave-cascade vs intentional slot |

**Recommendation:** Export **high_confidence (19)** as director review CSV (default `selected=0`). Medium + needs_review stay case-by-case or later director queues — **not** batch `--execute`.

Command (after classify export lands):  
`php artisan repair:leave-cascade-slot-times --dry-run --export-csv=/tmp/leave-slot-review.csv`

---

## Follow-up work (out of this closeout)

| Track | Issue |
|-------|-------|
| Director-reviewable leave-cascade slot repair (CSV-first) | [#1342](https://github.com/jerry200176-png/AllTrue_System/issues/1342) |
| TD-059 package pool minutes | [#1343](https://github.com/jerry200176-png/AllTrue_System/issues/1343) |

Links also recorded in .

## Teacher-facing final reply (approved wording)

老師您好，兩件事都已在正式環境驗證過：

【請假後其他天時段被改掉】  
系統已改為「只順延日期，各星期維持自己的上課時間」。我們也在正式環境用測試資料確認：星期三請假後，星期六仍是 10:00–12:00；取消請假後也正確；另外有人工改過的特殊時段不會被蓋掉。

目前系統裡有一筆「三 17:00＋六 10:00」的課程，現在各天時間是對的，沒有還在錯位的請假紀錄。若您那邊畫面仍不對，請告訴我們學生／請假日期，我們再逐筆幫您對。

【補課加長卻只扣一堂】  
已改為依實際分鐘扣除（例如契約 120 分、補課 180 分就扣 180 分），不會自動產生應收款。重複點名不會多扣；取消後會把分鐘加回去。一般（非補課）課堂就算排比較長，仍依原本規則扣整堂。

歷史若還有少數其他「跨星期時間錯置」的舊資料，我們會先列出清單給主任審核，不會自動整批改寫；需要確認後再處理。
