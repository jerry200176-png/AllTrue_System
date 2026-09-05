# REP — Admissions Funnel V1 activation（dark launch → Founder GO）

> **狀態**：Activated and production-verified on 2026-09-05
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
| Feature flags default | Code default remains **off**；production activation was explicitly dispatched with `admissions_funnel_v1=on` |

### Production activation record

| 項目 | 證據 |
|------|------|
| Merge | PR #2472 squash-merged to `main` at `5c4fed10facd7cf120e4168c06bf7e3ec03e4755` |
| Migration | `2026_09_05_020000_add_follow_up_to_admission_inquiries` migrated successfully by deploy workflow; pre-migration backup completed |
| Flag | Founder-gated deploy run `33942384695` dispatched with `admissions_funnel_v1=on`; log reports `mode=on, value=true` |
| Deploy | [Deploy to Pi run 33942384695](https://github.com/jerry200176-png/AllTrue_System/actions/runs/33942384695) completed successfully; exact-main gate passed |
| Runtime | `GET /api/v1/health` → `status=ok`; `GET /deployment.json` reports backend/frontend/frontend_build SHA `5c4fed10facd7cf120e4168c06bf7e3ec03e4755` |
| Activation probe | `POST /api/v1/admission-inquiries` with `{}` → HTTP 422 validation (active route; no production inquiry created); `GET /api/v1/branches` → HTTP 200 |

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

## 2.4 Validation（pre-activation dark launch）

- PHPUnit：`AdmissionInquiryApiTest`（flag-off 404、dedupe、mask、campus deny、state 422、trial lock idempotency、enroll same Student、lost）。
- Ownership：主任可認領 inquiry；detail 顯示 owner、status、next action、follow-up 與不含 raw PII 的歷程。
- Frontend：`AdmissionInquiriesPageContract.test.js`、`navigationRegistry` flag-off hide。
- Deploy：workflow 日誌含 `admissions funnel flag prepared ... value=false`（或 production 現值仍為 false）。
- 唯讀：`GET /api/v1/health`；公開 `POST /api/v1/admission-inquiries` 在 flag off 應 **404**。

---

## 2.5 Founder GO checklist（executed）

```
[x] Founder 核准 production migrate 與 `admissions_funnel_v1=on`
[x] Deploy run 33942384695：migration、前後端 rebuild、health/smoke 通過
[x] Runtime SHA 與 activation probe 對齊 merge SHA
[ ] 核准 retention／PII 政策（見 REF_PRIVACY_DATA_INVENTORY）
[ ] Staff-only end-to-end smoke：需 Founder 指定低峰測試帳號／分校
[ ] Bounded public inquiry：需 Founder 指定可用測試家庭；本次不建立真實 production inquiry
[ ] 異常則立即 `admissions_funnel_v1=off`，不刪資料
```

---

## 2.6 Success criteria after GO

- Flag on 後 public submit 202 + generic message；duplicate 不新增 active row（尚待指定測試家庭做完整資料流程驗證）。
- Director 僅見本校園；list 遮罩、detail 可 click-to-call。
- Director 可在同一 detail 認領負責、看到下一步、設定追蹤日期並查看處理歷程。
- Trial handoff 只建一個 Student；enroll 沿用 convertTrial，Student count 不增加。
- Audit：`admission_inquiry.submit` / `admission_inquiry.state_transition` 無 raw PII。
