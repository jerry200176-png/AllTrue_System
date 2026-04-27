# Daily Checklist（日常營運檢查表）

本表給主任、櫃台、值班老師每日使用。  
建議印出或固定在開班 SOP 文件中，逐項打勾。

## A. 開店前（上課前 30-60 分鐘）

- [ ] 確認今天使用校區（branch）正確
- [ ] 開啟 `DirectorDashboard` 檢查：
  - [ ] 今日課程清單是否正常顯示
  - [ ] 待審評量數量
  - [ ] 繳費提醒是否有異常暴增
- [ ] 開啟 `SmartCalendar` 檢查：
  - [ ] 今日是否有衝堂警示
  - [ ] 教室與老師安排是否完整
- [ ] 開啟 `AttendancePage`：
  - [ ] RFID 是否有即時刷卡資料
  - [ ] 系統無明顯載入錯誤
- [ ] 若今天有新生/新課程：
  - [ ] 在 `StudentsList` 確認課程已建立且堂數正確

## B. 上課中（每節課前後）

- [ ] 每節課開始前，確認到課名單
- [ ] 課程中若請假/調課，立即在系統更新
- [ ] 每節課後確認出缺勤紀錄已寫入
- [ ] 若有補課：
  - [ ] 確認補課已掛到正確學生與課程
  - [ ] 確認堂數扣除邏輯正確
- [ ] 若有未識別刷卡：
  - [ ] 於 pending swipe 流程人工認列
  - [ ] 無法判定時先標記並回報主任

## C. 關店前（收班）

- [ ] 在 `AttendancePage` 檢查今日未完成/異常記錄
- [ ] 在 `LearningRecordsPage` 檢查：
  - [ ] 老師評量是否都已送出
  - [ ] 被退回評量是否有處理
- [ ] 在財務/提醒頁檢查新增應收是否合理
- [ ] 今日有改排課者，確認明日課表同步正常
- [ ] 記錄今日異常（若有）到內部交接文件

## D. 異常處理快速流程（先救服務）

### D1. 網站顯示舊版或前端怪異

1. 確認目前分支與最新 commit
2. 確認最近一次 `deploy.yml` 成功，且 `backend/public/version.json` 為預期版本
3. 請現場重整頁面並驗證關鍵頁面
4. 若 deploy workflow 掛掉，才依 `docs/DEPLOYMENT.md` 的緊急手動部署流程處理

### D2. API 無法使用 / 500 錯誤

1. 先看最近一次 `deploy.yml` / `pi-health.yml` / Sentry 是否同時告警
2. 測 API 健康檢查；若全站 500，依 `docs/DANGEROUS_OPERATIONS.md` + `docs/OPERATIONS_RUNBOOK.md §H` 處理
3. 不為 debug 直接跑 `php artisan config:clear`、`php artisan test`、`vendor/bin/phpunit`
4. 需要 cache / config / route 操作時，先取得使用者批准並走事故 SOP

### D3. GitHub 同步異常

1. 確認改動在 feature branch，不在 `main`
2. 確認沒有 force push，且 PR CI 已跑完
3. 不要把備份分支直接 merge 到主協作分支
4. 必要時先關閉異常 PR，改用乾淨 feature branch 重送

## E. 每週固定檢查（建議每週五）

- [ ] 抽查 5 筆課程：堂數/出缺勤/繳費是否一致
- [ ] 抽查 5 筆評量：堂次與學生是否對得上
- [ ] 檢查是否有跨校區資料誤入
- [ ] 檢查 Google Drive `AllTrue-Backups` 是否有最新 nightly / sixhour / manifest
- [ ] 檢查 GitHub `main` branch protection 仍啟用（required checks + admin enforcement + no force push/delete）
- [ ] 檢查文件是否需要更新（流程有改就更新）

## E2. 每月 / 每季固定檢查

- [ ] 每月確認 `backup-restore-test.yml` 或 Pi restore drill 成功，並抽查 `Student`、`StudentClass`、`ClassSession` row count 合理
- [ ] 每月下載最新 Drive manifest，確認 manifest 日期與備份日期一致
- [ ] 每季做一次 offsite restore drill：從 Google Drive 取備份還原到 drill DB，不觸碰 production `AllTrue`
- [ ] 每半年做一次 full server tabletop：假設 Pi 壞掉，列出從 GitHub + Drive + secrets 重建服務所需步驟與缺口

## F. 文件參考

- `docs/ROLE_PLAYBOOK.md`
- `docs/OPERATIONS_RUNBOOK.md`
- `docs/INDEX.md`
- `docs/AI_REGRESSION_LESSONS.md`

