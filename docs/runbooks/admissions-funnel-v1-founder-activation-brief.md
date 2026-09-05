# Founder Activation Brief — 新生問班 Admissions Funnel V1

**日期**：2026-09-05
**結論**：功能已 dark-launch 完成（flag **OFF**）。**請勿自行開啟**；僅在本 brief 與 REP 核准後由 Founder GO 啟用。

## 已完成（flag off）

| 項目 | 證據 |
|------|------|
| 公開 guided interview UI + API | `#/admissions`；`POST /api/v1/admission-inquiries`（throttle 10/min）；flag off → 404／「準備中」 |
| 主任佇列／狀態機 | contact → trial → trial-result → enroll；terminal `lost` |
| 負責與追蹤 | detail 可認領 owner、查看 status／next action、設定 follow-up 日期、瀏覽不含 raw PII 的歷程 |
| PII | encrypted casts；list mask；public generic response；audit 無電話／姓名明文 |
| Trial handoff | 委派 `EnrollmentService`；row lock 冪等（#2459） |
| 報名 | 委派既有 `convertTrial`；inquiry 只存 reference |
| Deploy 控制 | `deploy.yml` input `admissions_funnel_v1`：`unchanged`／`on`／`off`（預設 unchanged，不推開） |
| 測試 | `AdmissionInquiryApiTest` + frontend contract + nav flag-off |

## GO 代表什麼

1. **Migrate**：建立 `admission_inquiries`，並加入 `follow_up_at`（additive；merge ≠ migrate）。
2. **Flag on**：後端 `ADMISSIONS_FUNNEL_V1=true` + 前端 `VITE_ADMISSIONS_FUNNEL_V1=true`（需 rebuild／deploy，建議明確傳 `admissions_funnel_v1=on`）。
3. **Smoke**：主任校區隔離、一筆真實或指定測試家庭走完聯絡→試聽→（報名或 lost）。
4. **Staff update**：啟用後再發 `STAFF_UPDATES`（目前 silent_ship）。

## GO 不代表什麼

- 不啟用 CRM／行銷自動化／第二套排課收款。
- 不改 role-onboarding 或 CourseManagement payment。
- 不授權 Pi SSH／artisan／phpunit on prod 給 Agent。

## 建議指令（Founder／OPS 執行）

詳見 [`admissions-funnel-v1-activation-execution-package.md`](./admissions-funnel-v1-activation-execution-package.md)。  
回滾：立即 `admissions_funnel_v1=off`。

## 目前剩餘（僅 Founder）

- [ ] Production migration GO（`2026_09_04_060000_create_admission_inquiries_table` + `2026_09_05_020000_add_follow_up_to_admission_inquiries`）
- [ ] Flag activation GO  
- [ ] Post-activation staff/public smoke sign-off  
