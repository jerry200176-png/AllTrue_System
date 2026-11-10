# PRD／規劃 — 評量「已核准＋正文未填」可見性 + 使用者軍階（ROC）

> **流程**：對照 `docs/INDEX.md` → 本檔為 Phase 1 規劃 artifact；實作拆 **GitHub Issues**（見文末連結）。  
> **精神**：內部文檔（`AI_REGRESSION` 評量模組）+ **業界參考**（例：GitHub **Achievements** 為 opt-in、可隱藏單一徽章 — [GitHub Blog](https://github.blog/news-insights/product-news/introducing-achievements-recognizing-the-many-stages-of-a-developers-coding-journey/)）；職場 gamification 強調 **不綁考績、可關閉**（延續 #314／`teacher_engagement_streak` PRD 原則）。

---

## A. 主任端：已核准，但「正文未填」要更好找

### A1. 現況（根因）

- `LearningRecordsPage.vue` 的 **「只看未填」**（`onlyUnfilled`）僅在 `reviewTab ∈ { pending, changes_requested, all }` 顯示（約 L113–115）。
- 切到 **「已核准」** 時無法同一套篩選，但資料面已存在 **`hasBody`／`fillLabel`**（`fill-missing` 列樣式），且群組有 `unfilled_body_count` — 代表 **已核准但 Progress（授課進度）空白** 的異常／待關注筆，主任要手動掃描。

### A2. 目標

- 在 **已核准**（必要時 **全部**）分頁，主任可 **一鍵只看「正文未填」**，並可選 **KPI 數字**（已核准且未填筆數）與待審分頁行為一致、好理解。

### A3. 範圍（Issue §322）

- **In**：主任／具評量審核權限角色；僅前端篩選與文案（若 API 需 `has_body` query 再開子任務）。
- **Out**：不改核准邏輯、不自動退回（除非另開產品決策）。

### A4. 風險

- 低（UI／篩選）；須確認 `hasLearningRecordBody` 與主任認知「未填」一致。

---

## B. 使用者軍階（中華民國軍階）+ 升級機制

### B1. 願景

- **老師**、**主任**（director／admin）、**super_admin** 有可選的「軍階」展示與升級感。
- **super_admin**：**固定顯示「五星上將」**，不參與 XP 計算（職務身分展示，避免與遊戲化混淆考績）。
- **老師／主任**：依 **可審計的系統事件** 獲得 XP，對應軍階晉升（兩條線可 **共用階級名稱**、**不同 XP 曲線**，或 **同一表不同 `role_track`** — 實作階段決策）。

### B2. 階級表（提案 — 產品可調整階數）

採 **陸軍義務役／志願役與尉校將** 常見稱呼（公開資料可核對國防部軍階說明）；遊戲化可 **合併部分士官階** 以控制等級數。

| 階級帶 | 提案序列（由低至高） |
|--------|----------------------|
| 士兵 | 二兵 → 一兵 → 上等兵 |
| 士官 | 下士 → 中士 → 上士 → 士官長（可細分或合併） |
| 尉官 | 少尉 → 中尉 → 上尉 |
| 校官 | 少校 → 中校 → 上校 |
| 將官 | 少將 → 中將 → 上將 → 一級上將（是否開放給非超管，產品決策） |
| 超管專用 | **五星上將**（固定，不升不降） |

### B3. XP 來源（草案 — 需 CEO／產品確認後定稿）

| 事件 | 可能對象 | 備註 |
|------|----------|------|
| 送出／核准通過的評量（有實質正文） | 老師／主任 | 防刷：同堂次去重、作廢不計 |
| 主任 **審核**（核准／需修改處理） | 主任 | 權重低於「灌水」風險動作 |
| 連續使用天數（已本機 #314） | 可選是否同步「雲端 XP」 | 預設不同步以減少隱私爭議 |
| 其他 | TBD | 對齊 #317～#320 任務／徽章後續 |

**硬規則**：軍階／XP **不影響** `DIRECTOR_PAYMENT_ALERT_RULES`、薪資、權限；僅展示與激勵。

### B4. 技術方向（Phase 拆開 — Issues #324～#326）

1. **Phase 1 — 資料模型 + 讀取 API**：例如 `user_engagement` 表（`user_id`, `role_track`, `xp_total`, `rank_key`, `updated_at`）或擴充 `User`（須 DBA 評估）；**super_admin** 可不存 rank，API 直接回傳 `五星上將`。
2. **Phase 2 — XP 寫入**：在 `LearningRecordController` 核准／建立等 **單一出口** 送事件（避免分散），加 idempotency key；補 PHPUnit。
3. **Phase 3 — UI**：側欄／個資小徽章、**設定內關閉顯示**（opt-out）；動效須尊重 `prefers-reduced-motion`（與 #319 對齊）。

### B5. 資安／合規

- 不收集與教學無關敏感行為；**公開顯示預設關** 或 **預設低調**（產品二選一）。
- 不將軍階寫入對外家長 API。

### B6. 參考（開源／產品模式，非照搬）

- **GitHub Achievements**：里程碑、可關閉顯示。
- **OSS RPG／level**：常見 `xp` + `level_thresholds[]` 表驅動；避免硬編碼 magic number 散落（單一 `RankTable` 設定檔或 DB seed）。

---

## C. DoD（本規劃檔）

- [x] 問題 A／B 分離、可獨立排程
- [x] 對應 GitHub Issues 已建立（#322、#323 史詩、#324–#326）
- [ ] 各 Issue merge 後補 `docs/CHANGELOG.md` 與（若涉高風險）`AI_REGRESSION_LESSONS.md`

---

## D. Issue 對照（已建立）

| Issue | 內容 |
|-------|------|
| [#322](https://github.com/jerry200176-png/AllTrue_System/issues/322) | §A：已核准分頁「只看未填」+ KPI |
| [#323](https://github.com/jerry200176-png/AllTrue_System/issues/323) | §B Epic：軍階總覽與分階段 |
| [#324](https://github.com/jerry200176-png/AllTrue_System/issues/324) | §B Phase 1：DB／API |
| [#325](https://github.com/jerry200176-png/AllTrue_System/issues/325) | §B Phase 2：XP 事件 |
| [#326](https://github.com/jerry200176-png/AllTrue_System/issues/326) | §B Phase 3：UI + opt-out |
