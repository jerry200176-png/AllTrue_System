# Course Continuity — PII-free cohort results

> **Status**: Awaiting production **read-only** execution  
> **SQL**: [`docs/sql/course-continuity-cohort-discovery.sql`](../sql/course-continuity-cohort-discovery.sql)  
> **Issue**: #1382  
> **Rule**: 本檔只准寫 aggregate counts。禁止貼姓名、電話、Memo、請假原因、學號以外可識別資訊。

## Execution log

| 欄位 | 值 |
|------|-----|
| Ran at (UTC+8) | _pending_ |
| Runner | _pending（Pi readonly / Founder）_ |
| DB | AllTrue |
| Git SHA of SQL | _pending_ |

## Metrics

| # | Cohort | group_count | student_count | notes |
|---|--------|------------:|--------------:|-------|
| 0 | Baseline active contracts / students | | | |
| 1 | Adjacent renewal (same subj+teacher, ≤14d gap) | | | high-confidence seed |
| 2 | Overlapping dates same subj+teacher | | | needs human / Continuity decision |
| 3 | Same subj different teacher | | | |
| 4 | Future cross-subject same-slot pairs | | | session pairs |
| 5 | Trial + formal | | | |
| 6 | Different class types | | | |
| 7 | Mixed rate_unit / mode / package | | | do **not** auto-link |
| 8 | Different duration or Rate | | | |
| 9 | Both have Invoice/Payment | | | billing divergence risk |
| 10 | Both have attended/LR | | | history keep-both |
| 11 | Stopped SC with future scheduled | | | materialization leak |
| 12 | Campus joinable contracts | | | sanity |
| 13 | 3+ active same subject chains | | | |

## Derived (fill after queries)

| Metric | Value |
|--------|------:|
| active_contract_count | |
| future_overlapping_session_count | |
| attended_duplicate_count | _(use #1130 audit tooling; do not paste PII)_ |
| share_with_payment_or_invoice | |
| share_with_LR_or_attended | |
| high_confidence_renewal_candidate_count | ≈ #1 − unsafe |
| must_human_decide_count | ≈ #2 + #3 + #7 + #8 |
| unsafe_to_auto_link_count | ≈ #7 + #9 + #10 |

## Notes

- Cloud agent environment **無** Pi SSH；本輪無法填入實數。  
- Founder／ops 執行 SQL 後更新本表即可解鎖 schema PR 的 suggest 門檻校準。
