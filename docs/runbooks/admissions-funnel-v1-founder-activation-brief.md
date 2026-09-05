# Founder Activation Brief — 新生問班 Admissions Funnel V1

**日期**：2026-09-05
**結論**：Founder GO 已執行；功能已 production activated and verified（`ADMISSIONS_FUNNEL_V1=true`）。完整 staff/public 資料流程仍待指定安全測試身分。

## 已完成（production activated）

| 項目 | 證據 |
|------|------|
| 公開 guided interview UI + API | `#/admissions`；`POST /api/v1/admission-inquiries`（throttle 10/min）；production invalid validation probe → 422 |
| 主任佇列／狀態機 | contact → trial → trial-result → enroll；terminal `lost` |
| 負責與追蹤 | detail 可認領 owner、查看 status／next action、設定 follow-up 日期、瀏覽不含 raw PII 的歷程 |
| PII | encrypted casts；list mask；public generic response；audit 無電話／姓名明文 |
| Trial handoff | 委派 `EnrollmentService`；row lock 冪等（#2459） |
| 報名 | 委派既有 `convertTrial`；inquiry 只存 reference |
| Deploy 控制 | `deploy.yml` input `admissions_funnel_v1=on` 已由 Founder-gated run 33942384695 執行 |
| 測試 | `AdmissionInquiryApiTest` + frontend contract + nav flag-off；production health/version/route activation probe 通過 |

### Production evidence

- Merge/deploy SHA：`5c4fed10facd7cf120e4168c06bf7e3ec03e4755`
- Deploy run：[33942384695](https://github.com/jerry200176-png/AllTrue_System/actions/runs/33942384695)
- `GET /api/v1/health`：`status=ok`
- `GET /deployment.json`：backend/frontend/frontend_build 均為上述 SHA
- Deploy log：migration 成功、admissions flag `mode=on, value=true`

## GO 代表什麼

1. **Migrate**：建立 `admission_inquiries`，並加入 `follow_up_at`（additive；merge ≠ migrate）。
2. **Flag on**：後端 `ADMISSIONS_FUNNEL_V1=true` + 前端 `VITE_ADMISSIONS_FUNNEL_V1=true`（需 rebuild／deploy，建議明確傳 `admissions_funnel_v1=on`）。
3. **Smoke**：主任校區隔離、一筆真實或指定測試家庭走完聯絡→試聽→（報名或 lost）。
4. **Staff update**：本次 activation 已核准，改由 `STAFF_UPDATES.yml` 正式公告。

## GO 不代表什麼

- 不啟用 CRM／行銷自動化／第二套排課收款。
- 不改 role-onboarding 或 CourseManagement payment。
- 不授權 Pi SSH／artisan／phpunit on prod 給 Agent。

## 建議指令（Founder／OPS 執行）

詳見 [`admissions-funnel-v1-activation-execution-package.md`](./admissions-funnel-v1-activation-execution-package.md)。  
回滾：立即 `admissions_funnel_v1=off`。

## 目前剩餘（需 Founder 提供安全測試資料）

- [x] Production migration GO／執行完成（`2026_09_05_020000_add_follow_up_to_admission_inquiries`；主表 migration 已由既有版本完成）
- [x] Flag activation GO／執行完成
- [ ] Retention／PII policy sign-off（見 `REF_PRIVACY_DATA_INVENTORY`）
- [ ] Post-activation staff/public end-to-end smoke sign-off：請指定低峰主任帳號、分校與可用測試家庭；未指定前不建立 production inquiry
