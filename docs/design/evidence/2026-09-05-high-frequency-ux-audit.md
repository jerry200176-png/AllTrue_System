# 高頻核心頁面 UX audit — 2026-09-05

## 結論與範圍

本次切片聚焦主任工作流的問班、科目數、學生與課程查找，以及出缺勤／學習紀錄等高頻入口。以現有 AllTrue design system、頁面內漸進揭露與 inline detail 為基礎；不改 API、資料語意、權限、付款真相或排課規則，也不新增大型 UI framework。

## 證據

- 導覽來源：`frontend/src/lib/navigationRegistry.js`。主任的高頻工作集中在「教學現場」、「學生與課程」、「報表與薪資」；手機入口由同一 registry 衍生。
- 程式活動 proxy：截至本次 audit 的 git history，近期異動較多的頁面為 `CourseManagement.vue`（57）、`TuitionCollectionPage.vue`（26）、`TeacherHomePage.vue`（24）、`StudentsList.vue`（23）、`SmartCalendar.vue`（21）、`DirectorDashboard.vue`（20）、`LearningRecordsPage.vue`（15）。這是維護熱點 proxy，不宣稱是真實 page-view 排名；現有 telemetry 沒有頁面瀏覽量。
- In-app bug queue：最新 dump 有 1 筆未 resolved，#249（`course-mgmt`、high）。決策級 probe 確認共用方案的英文科仍待對帳、數學科已確認入帳；這是 billing-semantic discrepancy，本次只改善頁面內的科目分開說明，不遮蔽或修正資料。
- Production read-only：2026-09-05 16:18（Asia/Taipei）`https://daan.lifenet.com.tw/version.json` 回報 build `f82626a8f04ce1c05e13c9c3ef5ec0df0886ac24`；`/api/v1/health` 回報 `status=ok`。公開 `/admissions` 回 HTTP 200、shell title 為「全真一對一」；登入後資料與權限未以真實帳號讀取。

## 優先頁面與 UX 決策

| 頁面 | 主要成本 | 本次處理 | 預期結果 |
|---|---|---|---|
| 新生問班 | 公開表單的第一步可繞過 required；切步驟後焦點不明；清單選取缺少狀態語意；flag 關閉時 standalone 與 staff surface 會同時出現 | 第一階段原生欄位驗證、標題 focus、`aria-current`／`aria-pressed`、科目／年級改用既有中文 constants；standalone/staff template 條件互斥 | 不會帶著不完整基本資料進第二步；公開入口不再顯示主任 API error；鍵盤與讀屏使用者知道目前步驟與選取項目 |
| 科目數統計 | 計算說明預設展開，首屏被次要內容佔用；英文 eyebrow 與中文工作台不一致 | 計算說明預設收合，保留明確 toggle；改為「每日變化／日明細」 | 首屏先看摘要、趨勢與明細；需要規則時再展開 |
| 課程查找 | 共用方案的不同科目可能同時顯示不同繳費狀態，使用者容易把它理解成全案矛盾 | 只在偵測到共用方案多科狀態不一致時，在既有帳務 tab 顯示科目分列提示與帳務中心入口 | 降低誤讀，不改各科 payment status 或帳務資料 |
| 學生管理 | 常用搜尋提示使用半形省略號，與全站 copy rule 不一致 | 改用全形省略號，保留既有學生／課程 inline detail | 文案與其他 AllTrue 操作頁一致 |
| 出缺勤／學習紀錄 | 近期維護熱點且屬日常教學工作 | 本輪作為後續 audit surface；不做無證據的視覺改動 | 保留範圍，避免為了湊頁數擴大風險 |

## 外部取捨

- Duolingo 官方產品原則與 streak 說明支持「清楚的每日目標、低摩擦的下一步與立即回饋」；AllTrue 只採用工作流清晰度，不導入 XP、連續天數、排行榜或遊戲化狀態：[product principles](https://blog.duolingo.com/product-principles/)、[improving the streak](https://blog.duolingo.com/improving-the-streak/)。
- GitLab Pajamas 的 sidebar 指南支持情境式導覽、短名稱與最多兩層；AllTrue 沿用既有 navigation registry 與頁內 tabs，不新增 router 或外部 runtime：[Navigation Sidebar](https://design.gitlab.com/patterns/navigation-sidebar/)。
- Frappe、Gibbon、Moodle 的維護中原始碼與測試只作結構參照；本次沒有引入 OSS 套件或複製程式碼。既有取捨與版本證據記錄於 `docs/research/2026-08-20-sidebar-ux-research.md` 與 `docs/design/evidence/student-course-summary-ux-2026-08-28.md`。

## 驗證與限制

- 更新 source-contract tests，並以 `git diff --check` 檢查 patch。
- 待執行：`npm run test:unit`、`npm run lint:no-undef`、`npm run build`、PHPUnit approved checks；另需 real Vue responsive smoke evidence。
- 本工作樹未執行 production deploy、flag activation、migration 或資料修補；production evidence 只代表 read-only health/version/public shell。
- 頁面 transitions 的減少來自既有頁內 detail、tabs、inline action；本次沒有新增 route，也沒有宣稱量化了實際 click reduction。
