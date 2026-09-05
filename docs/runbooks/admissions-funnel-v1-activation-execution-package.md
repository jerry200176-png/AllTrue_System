# REP — Admissions Funnel V1 activation（dark launch → Founder GO）

> **狀態**：Ready for Founder review — **禁止**在本文件外自行開啟 flag 或 production migrate  
> **PCR / REP ID**：REP-2026-09-04-ADMISSIONS-FUNNEL-V1  
> **權威模板**：[`GUIDE_RELEASE_EXECUTION_PACKAGE.md`](../GUIDE_RELEASE_EXECUTION_PACKAGE.md)  
> **契約**：[`architecture/RFC_ADMISSIONS_FUNNEL_V1.md`](../architecture/RFC_ADMISSIONS_FUNNEL_V1.md)

---

## 2.1 Scope

| 項目 | 內容 |
|------|------|
| In scope | `admission_inquiries` additive migration；後端 `ADMISSIONS_FUNNEL_V1`；前端 `VITE_ADMISSIONS_FUNNEL_V1`；公開 `#/admissions` 與主任「新生問班」nav |
| Out of scope | CRM、第二套排課／收款、role-onboarding、CourseManagement payment、legacy parent_phone cutover |
| Dark-launch code | 已在 main（#2444 / #2449 / #2452 / #2459 + finish PR）；本次補上 owner／follow-up／history slice（待本 PR） |
| Feature flags default | **off**；deploy input `admissions_funnel_v1` 預設 `unchanged` |

---

## 2.2 Risk Assessment

| 維度 | 評級 | 說明 |
|------|------|------|
| 資料完整性 | MED | 新表 + PII encryption；試聽 handoff 寫入既有 Student／Enrollment |
| 可用性 | LOW | Flag off 時公開與主任入口隱藏；既有 login／Student／排課不變 |
| 回滾難度 | MED | Flag 可立即關閉；migration rollback 依 snapshot／REP，禁止猜修 production data |
| 多校區隔離 | PASS | director campus scope；public submit 僅寫入選定 campus |

---

## 2.3 Rollback Plan

1. Deploy with `admissions_funnel_v1=off`（或手動將 prod `.env` 與 frontend build 的 `VITE_ADMISSIONS_FUNNEL_V1` 設為 false）。
2. 程式回退：`git revert` 對應 admissions PR（不 force-push）。
3. 若 migration 已執行：依 backup snapshot 與 `migrate:rollback` 路徑；**禁止**手刪 `admission_inquiries`／Student。

---

## 2.4 Validation（flag off / dark launch）

- PHPUnit：`AdmissionInquiryApiTest`（flag-off 404、dedupe、mask、campus deny、state 422、trial lock idempotency、enroll same Student、lost）。
- Ownership：主任可認領 inquiry；detail 顯示 owner、status、next action、follow-up 與不含 raw PII 的歷程。
- Frontend：`AdmissionInquiriesPageContract.test.js`、`navigationRegistry` flag-off hide。
- Deploy：workflow 日誌含 `admissions funnel flag prepared ... value=false`（或 production 現值仍為 false）。
- 唯讀：`GET /api/v1/health`；公開 `POST /api/v1/admission-inquiries` 在 flag off 應 **404**。

---

## 2.5 Founder GO checklist（activation — 本任務不執行）

```
[ ] 核准 production migrate：`2026_09_04_060000_create_admission_inquiries_table` + `2026_09_05_020000_add_follow_up_to_admission_inquiries`
[ ] 核准 deploy admissions_funnel_v1=on（後端 + 前端 rebuild）
[ ] 核准 retention／PII 政策（見 REF_PRIVACY_DATA_INVENTORY）
[ ] Staff-only smoke：主任登入 → 新生問班 → 認領／聯絡／試聽／設定追蹤（低峰）
[ ] Bounded public：#/admissions 送出一筆 → queue 可見 → 結案或 lost
[ ] 異常則立即 admissions_funnel_v1=off，不刪資料
```

---

## 2.6 Success criteria after GO

- Flag on 後 public submit 202 + generic message；duplicate 不新增 active row。
- Director 僅見本校園；list 遮罩、detail 可 click-to-call。
- Director 可在同一 detail 認領負責、看到下一步、設定追蹤日期並查看處理歷程。
- Trial handoff 只建一個 Student；enroll 沿用 convertTrial，Student count 不增加。
- Audit：`admission_inquiry.submit` / `admission_inquiry.state_transition` 無 raw PII。
