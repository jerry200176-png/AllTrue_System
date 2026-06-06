# AllTrue Design QA Smoke 手冊

> 每個 `frontend/**` PR merge 後必跑本清單。不靠「我看過了」。  
> 帳號見 `.cursor/.local/test-credentials.md`

---

## 通用檢查（所有前端 PR）

- [ ] `npm run build` 本機通過
- [ ] `npm run lint:design`（#689 上線後）：無新增 raw hex 警告
- [ ] CI 7 項 required checks 全綠（含 Vite Frontend Build）
- [ ] Primary CTA 每區塊 ≤ 1 顆實心橘色
- [ ] 金額/堂數欄位 `tabular-nums`（目視數字不跳動）
- [ ] 空狀態有「下一步」說明（非只寫「無資料」）
- [ ] 無裝飾性 emoji 殘留在 UI 文案

---

## 角色路徑 Smoke

### 主任（`admin` / `director`）

| 步驟 | 通過條件 |
|---|---|
| 登入 → 儀表板 | 繳費提醒 / 待審評量載入、無橫向捲軸 |
| 儀表板 → 學生管理 | 列表渲染、搜尋可用 |
| 學生管理 → 新增學生 | 表單可填、儲存不 500 |
| 主選單 → 課程管理 | 頁面載入、可展開課程 |
| 課程 → 請假/調課 modal | Modal 開關正常、primary CTA 一顆 |
| 主選單 → 繳費管理 | 金額欄右對齊 + tabular |
| 主選單 → 學習評量 | 上一堂摘要卡顯示（有資料時） |

### 老師（`teacher`）

| 步驟 | 通過條件 |
|---|---|
| 登入 → 教學工作台 | 今日待辦、課表卡渲染 |
| 工作台 → 出缺勤 | 本日課程可點名 |
| 出缺勤 → 學習評量 | 可開單填寫、可存草稿 |

### 家長（`?parent=1`）

| 步驟 | 通過條件 |
|---|---|
| `?parent=1` 登入 | 手機 390px 寬度可讀、無橫向捲軸 |
| 家長入口 → 公告 | 版本公告顯示（限 audience: parent） |
| 家長入口 → 繳費狀態 | 金額顯示正確 |

---

## 高風險頁加測

| 頁面 | 加測步驟 |
|---|---|
| **SmartCalendar** | `npm run test:calendar` 全綠；手動週檢視 2 分校、代課課程正確顯示 |
| **LearningRecords** | 上一堂摘要仍顯示（#685 回歸）；代課標示可見 |
| **TuitionCollection** | 一筆金額欄對齊、千分位正確 |
| **CourseManagement** | 請假/調課/補課三 modal 開關正常 |

---

## 頁面設計對齊快速目視

並排任兩頁確認：

- [ ] CTA 按鈕顏色相同（`--ds-primary`）
- [ ] 卡片邊框風格一致（hairline 1px，非厚邊）
- [ ] 空狀態 icon 為 Material icon，非 emoji
- [ ] 錯誤狀態顯示白話訊息，非 stack trace

---

## 上線後 OPS（前端有 deployable diff 時）

```bash
# 1. 確認 deploy workflow 成功
gh run list --workflow=deploy.yml --limit 1

# 2. Health check
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
# 必須回傳 {"status":"ok",...}

# 3. 前端版本確認（前端有改才做）
ssh admin@pi.lifenet.com.tw "cat /home/admin/backend/public/version.json"
# 時間戳應為剛才的時間
```

---

## 參考

- Epic #687 子 issue 清單
- `docs/RULE_DESIGN_SYSTEM.md` §7 禁止清單
- `docs/UI_COPY_GUIDE.md` 空狀態公式
- `.cursor/rules/auto-frontend-deploy.mdc` deploy SOP
