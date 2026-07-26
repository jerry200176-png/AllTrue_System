# RFC: Platform Optimization from Starred Reference Repos（規劃 Only）

> **Status**: Draft（產品／架構規劃包；**本 PR 不含業務程式碼改動**）  
> **Date**: 2026-07-24  
> **Scope**: AllTrue System（主）＋ Sunrise Cafe（附錄）  
> **Companion**: [#1382 Course Continuity](https://github.com/jerry200176-png/AllTrue_System/issues/1382)、`docs/MODULE_PRODUCT_ENGINEERING_MATURITY_ROADMAP.md`、`docs/POLICY_AI_NATIVE_ROADMAP.md`、`docs/RULE_DESIGN_SYSTEM.md`

---

## 0. How to read 「參考」欄

每一項優化都用同一格式：

| 欄位 | 意思 |
|------|------|
| **參考 repo** | 我們 star 清單裡的開源專案 |
| **要學什麼** | 具體模式／流程／資訊架構（可落地到 AllTrue／Sunrise） |
| **不要學什麼** | 避免整包搬進現有棧（例如換框架、換 ORM、換整站 UI 庫） |
| **落地位置** | AllTrue／Sunrise 的模組或既有 RFC |

**原則**：參考＝借「決策與交互模式」；不是 fork 對方 codebase。AllTrue 仍維持 Laravel + Vue + MySQL；Sunrise 仍維持既有 Next／Supabase／LINE 棧（除非未來另開遷移案並完成 owner 風險評審）。

---

## 1. Problem / North Star

### 1.1 現況痛點（已從營運與近期工程暴露）

1. **系列 vs 單堂**語意不穩：調課、代課、續約後課表／超排／幽靈堂（見 `fix/schedule-occurrence-stability`）。
2. **合約延續**無統一視圖：加購／續報／平行課像無關課程（[#1382](https://github.com/jerry200176-png/AllTrue_System/issues/1382)）。
3. **帳務信任**：提前結清、作廢、收據流水與課程狀態需可稽核（early-settle 等）。
4. **主任後台密度高但噪音多**：狀態靠顏色、確認不完整、內部 bookkeeping 外洩到 UI。
5. **家長／LINE／通知**通道多，缺少統一「通知編排」與客服工作台視角。
6. Sunrise：包廂訂位、訂金、候補與 LINE 工作流可對齊業界訂位／票務模式。

### 1.2 North Star

> 主任／老師／家長各角色「打開就知道下一步」；課表與帳務可解釋、可稽核；AI agent 從 docs＋GitHub 接手，不靠聊天記憶。

---

## 2. Reference map（依領域）

### 2.1 UX／Design System（後台掃讀，非行銷 landing）

| 參考 repo | 要學什麼 | 不要學什麼 | 落地位置 |
|-----------|----------|------------|----------|
| [Leonxlnx/taste-skill](https://github.com/Leonxlnx/taste-skill)（AllTrue localize：`.cursor/skills/design-taste-frontend`） | Brief inference；後台 dials **VARIANCE=2 / MOTION=2 / DENSITY=8**；禁 emoji 當狀態；anti-slop 檢查清單 | Landing／Awwwards 實驗排版、換字體蓋掉 Inter+Noto | 課程管理、繳費、行事曆 UI 變更的 skill 閘門 |
| [nextlevelbuilder/ui-ux-pro-max-skill](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) | **Forms & Feedback**：確認 dialog、錯誤靠欄位、loading 防雙送、狀態不只靠顏色、progressive disclosure | 用它的 design-system generator 產生整站新色盤蓋過 DS | 暫停／結案／調課／續約 modal；chip 狀態文案 |
| [pbakaus/impeccable](https://github.com/pbakaus/impeccable) | AI harness「設計語言」：間距／層級／少裝飾 | 另開一套與 `RULE_DESIGN_SYSTEM` 衝突的 token | Agent 產出 UI 時的第二檢查 |
| [pacifio/ui](https://github.com/pacifio/ui) | **Dense ops UI**：多表面、資訊密度、dashboard／chat 工作台語彙 | 強制 AMOLED 黑主題、整站換成 atlas CSS | 主任儀表板、課程列、行事曆側欄密度 |
| [VoltAgent/awesome-design-md](https://github.com/VoltAgent/awesome-design-md) | Stripe 等品牌 DESIGN.md 的「意圖＋token＋禁令」寫法 | 複製 Stripe 行銷 gradient mesh 進後台 | 已反映於 `RULE_DESIGN_SYSTEM.md`；持續對齊 |
| [alexpate/awesome-design-systems](https://github.com/alexpate/awesome-design-systems) | 選 DS 時的分類地圖（企業／資料密集／工具） | 同時引入多套官方 DS | 決策時查表，不直接依賴 |
| [shadcn-ui/ui](https://github.com/shadcn-ui/ui) + [birobirobiro/awesome-shadcn-ui](https://github.com/birobirobiro/awesome-shadcn-ui) | Dialog／Select／Toast **交互契約**（focus trap、aria、受控開關） | 把 AllTrue Vue 棧改成 React+shadcn | 對照現有 modal／dropdown 行為缺口 |
| [radix-ui/primitives](https://github.com/radix-ui/primitives) | 無障礙原語：Dialog、Menu、Focus scope | 引入 Radix 到 Vue 專案 | a11y 驗收清單 |
| [primer/css](https://github.com/primer/css) | 工具型產品：表格密度、狀態 label、subtle border | Primer 整套主題 | 列表／chip／table |
| [microsoft/fluentui](https://github.com/microsoft/fluentui) | 企業後台：命令列、篩選條、空狀態 | Fluent React 元件庫 | 主任「今日待辦」資訊架構 |
| [carbon-design-system/carbon](https://github.com/carbon-design-system/carbon) | 資料密集：data table、批次操作、危險確認 | Carbon React／IBM 視覺 | 繳費名單、批次核帳 |
| [argyleink/open-props](https://github.com/argyleink/open-props) | 純 CSS token 分層（spacing／radius／shadow）思維 | 替換既有 `--ds-*` | 僅當擴充 token 時對照 |
| [vbenjs/vue-vben-admin](https://github.com/vbenjs/vue-vben-admin) | Vue3 後台：layout、權限路由、表格頁模式 | 遷移整站到 Vben 模板 | 新後台頁的資訊架構參考 |

### 2.2 Scheduling／系列 vs 單堂（AllTrue 核心）

| 參考 repo | 要學什麼 | 不要學什麼 | 落地位置 |
|-----------|----------|------------|----------|
| [calcom/cal.diy](https://github.com/calcom/cal.com)（star 顯示 cal.diy） | **Recurring series + this occurrence**：改單堂不改系列；取消／改期有明確 scope | 換成 Cal.com 作為排課引擎 | `StudentClass` 契約 vs `ClassSession` 例外；續約只 clone 契約 |
| [fullcalendar/fullcalendar](https://github.com/fullcalendar/fullcalendar) | 拖放、資源視圖、事件「移到」的 UX 預期；event 與 background／overlap 顯示 | 強制替換 SmartCalendar 實作 | 行事曆交互對照；overlap／超排顯示 |
| [alextselegidis/easyappointments](https://github.com/alextselegidis/easyappointments) | 自架預約：服務／提供者／客戶三角；取消政策 UI | PHP 舊式架構整包 | 試聽／補課預約流程文案與狀態機 |
| [GibbonEdu/core](https://github.com/GibbonEdu/core) | 學校系統：學生／家長／課表／出勤的角色邊界 | Gibbon 資料模型或 PHP 模板 | 家長入口、出勤、課務角色對照 |
| [alfio-event/alf.io](https://github.com/alfio-event/alf.io) | **名額／候補／釋放座位**狀態機 | Java 票務棧 | Sunrise 包廂候補；AllTrue 1v2 名額（超排語意） |

**已鎖定產品決策（延續近期 Founder 對齊）**

- 月結續約：**契約固定時段**，不帶單堂調課結果。  
- 調課＝this occurrence；必須 `IsContractException`，禁止回寫 `week/time`。  
- 主畫面預設隱藏內部 cancelled placeholder／幽靈取消 chip。

### 2.3 Billing／財務信任

| 參考 repo | 要學什麼 | 不要學什麼 | 落地位置 |
|-----------|----------|------------|----------|
| [invoiceninja/invoiceninja](https://github.com/invoiceninja/invoiceninja) | Invoice 生命週期：draft→sent→partial→paid→void；報價／專案分帳思路 | 遷移到 Invoice Ninja SaaS | `Invoice`／`Payment`／收據流水狀態機對齊 |
| [crater-invoice-inc/crater](https://github.com/crater-invoice-inc/crater) | 中小企業開票 UI：客戶、稅、PDF、付款記錄並列 | Crater 前端棧 | 繳費單／收據頁資訊架構 |
| [akaunting/akaunting](https://github.com/akaunting/akaunting) | 複式／分類帳視角；期間關帳 | 換成 Akaunting | 會計期間、AR aging（既有 finance API） |
| [frappe/erpnext](https://github.com/frappe/erpnext) | ERP：Sales Invoice vs Payment Entry 分離；審計欄位 | Frappe／ERPNext 整站 | 「應收」與「收款」分離的產品語言 |
| [kimai/kimai](https://github.com/kimai/kimai) | 工時→發票：費率×時數、專案／活動 | 用工時系統取代堂數制 | 分鐘制扣堂（#613）報表對照 |
| [firefly-iii/firefly-iii](https://github.com/firefly-iii/firefly-iii) | 對帳、規則分類、預算警戒（個人財務但 UX 清楚） | 個人記帳產品形態 | 對帳／例外作廢的「規則＋理由」 |

### 2.4 通訊／通知／客服

| 參考 repo | 要學什麼 | 不要學什麼 | 落地位置 |
|-----------|----------|------------|----------|
| [line/line-bot-sdk-php](https://github.com/line/line-bot-sdk-php) | 官方 Messaging API 契約、簽章驗證、回覆型別 | 自幹非官方協定 | AllTrue 家長推播／LIFF |
| [line/line-bot-sdk-nodejs](https://github.com/line/line-bot-sdk-nodejs) | 同上（Node） | — | Sunrise LINE |
| [irazasyed/telegram-bot-sdk](https://github.com/irazasyed/telegram-bot-sdk) + [laravel-notification-channels/telegram](https://github.com/laravel-notification-channels/telegram) | Bot 指令、通知 channel 抽象 | 把業務邏輯塞進 SDK | 主任 Telegram 告警 |
| [novuhq/novu](https://github.com/novuhq/novu) | **多通道通知編排**：偏好、去重、digest、模板 | 強制自架 Novu 當唯一通知中樞（可先學模式） | 家長／主任通知偏好統一 |
| [chatwoot/chatwoot](https://github.com/chatwoot/chatwoot) | Omni-channel inbox：對話歸戶、指派、SLA | 立刻換成 Chatwoot 當家長入口 | 家長客服／留言工作台願景 |

### 2.5 工程平台／安全／權限

| 參考 repo | 要學什麼 | 不要學什麼 | 落地位置 |
|-----------|----------|------------|----------|
| [spatie/laravel-permission](https://github.com/spatie/laravel-permission) | Role／Permission 模型與快取模式 | 無評估地重寫現有 `role:` middleware | 若擴權限矩陣時對照 |
| [filamentphp/filament](https://github.com/filamentphp/filament) | Admin 資源 CRUD、表單驗證、批次 action UX | 改用 Livewire 重寫 Vue 前端 | 後台「資源＋動作」分頁模式 |
| [getsentry/sentry-laravel](https://github.com/getsentry/sentry-laravel) | 發行／環境／使用者上下文、效能交易 | 重複造輪 | 既有 Sentry；補齊 release／PII scrub |
| [cloudflare/security-audit-skill](https://github.com/cloudflare/security-audit-skill) | 分階段安全稽核、可機器讀 findings | 當滲透工具亂打 production | 發佈前 audit skill |
| [anthropics/skills](https://github.com/anthropics/skills) + [VoltAgent/awesome-agent-skills](https://github.com/VoltAgent/awesome-agent-skills) | Skill 包裝、可發現性、官方範式 | 無篩選整包安裝 | `docs/GUIDE_AGENT_SKILLS.md` 擴充 |
| [supabase/supabase](https://github.com/supabase/supabase) | Auth／RLS／Realtime 模式 | AllTrue 遷到 Supabase | Sunrise；AllTrue 僅對照 RLS 思維 |

---

## 3. Epics（分階段；依風險閘門開工）

### Epic A — Schedule Occurrence Integrity（系列／單堂）

- **目標**：調課後無幽靈堂；契約不被單堂污染；續約可預期。  
- **參考**：Cal.com（series/occurrence）、FullCalendar（事件移動預期）、alf.io（名額）。  
- **已開工／相關**：`fix/schedule-occurrence-stability`（#1402）、R56 placeholder、ADR_004 atomic reschedule。  
- **下一刀**：行事曆與課程管理同一套「有效堂次」過濾；1v2 超排與衝堂 dialog 內錯誤（ui-ux-pro-max Forms）。

### Epic B — Course Continuity（合約群組視圖）

- **目標**：續報／加購／試聽／平行課可群組檢視，不合併財務實體。  
- **參考**：Gibbon（學校合約關係）、ERPNext（文件關聯不亂 merge）。  
- **權威**：[RFC_COURSE_CONTINUITY](./RFC_COURSE_CONTINUITY.md)（epic [#1382](https://github.com/jerry200176-png/AllTrue_System/issues/1382)）方案 A。  
- **不參考**：物理 merge StudentClass。

### Epic C — Billing Trust & Settlement

- **目標**：應收／收款／作廢／提前結清可解釋；收據流水與課程狀態一致。  
- **參考**：Invoice Ninja（狀態機）、Crater（開票 UI）、Akaunting／Firefly（對帳與理由）、Kimai（費率×用量）。  
- **相關**：early-settle（#1399）、tuition alerts、exception void。  
- **下一刀**：結清／作廢／核帳一律確認 modal + 必填理由（對照 ui-ux-pro-max P35）。

### Epic D — Director Ops UI Density

- **目標**：後台掃讀快、噪音低、狀態有文字。  
- **參考**：pacifio/ui（dense）、Carbon／Fluent／Primer（表格與狀態）、taste 附錄 dials、Vben（Vue 後台 IA）。  
- **權威**：`RULE_DESIGN_SYSTEM.md` 優先於任何上游美學。  
- **不做**：整站換 shadcn／Filament Livewire。

### Epic E — Notifications & Parent Comms

- **目標**：LINE／Telegram／站內通知可編排、可偏好、可稽核。  
- **參考**：LINE SDKs、Novu（digest／偏好）、Chatwoot（inbox 願景）、Telegram channels。  
- **下一刀**：通知偏好資料模型對齊 Novu 概念；實作仍走現有 channel。

### Epic F — Sunrise Cafe（附錄）

- **目標**：包廂訂位、訂金、候補、LINE 工作流對齊業界。  
- **參考**：alf.io（候補／釋放）、Cal.com（可用性）、LINE Node SDK、Supabase（RLS）、Chatwoot（客服）。  
- **不參考**：把 AllTrue 課表模型硬套進餐廳。

### Epic G — AI-native ops（既有路線延續）

- **目標**：Business digest → 異常 → 挽留建議（見 `POLICY_AI_NATIVE_ROADMAP.md`）。  
- **參考**：anthropics/skills、awesome-agent-skills（skill 品質）、security-audit-skill（發佈閘）。  
- **不參考**：未篩選的 vibe／demo agent OS。

---

## 4. Non-goals（本規劃明確不做）

| 不做 | 原因 |
|------|------|
| 遷移 AllTrue → Next／Supabase／Filament Livewire | 成本高、風險高；無 Founder GO |
| 用 Cal.com／Easy!Appointments 取代 SmartCalendar | 補習班契約／堂數／代課模型不同 |
| 用 Invoice Ninja 取代現有 Invoice／Payment | 已有校區／收據語意 |
| 用 Chatwoot／Novu 立刻當 production 中樞 | 先學模式；導入需獨立資安與營運評估 |
| 行銷 landing 級視覺大翻新後台 | taste 附錄禁止；DS 優先 |
| 為參考而 star 回來的教學／vibe repo 當架構依據 | 已清理；不以教學文當 SSOT |

---

## 5. Sequencing（建議）

```text
Phase 0 (now)     本 RFC 評審 + 參考表定稿；不改業務碼
Phase 1 (P0)      Epic A 收尾（課表穩定）+ Epic D 噪音／確認 UX
Phase 2           Epic B Course Continuity MVP（#1382）
Phase 3           Epic C Billing trust 對齊狀態機＋結清／作廢 UX
Phase 4           Epic E 通知編排（模式先、中樞後）
Phase 5           Epic F Sunrise 訂位／候補（獨立 repo／PR）
Phase 6           Epic G AI-native（digest → anomaly → retention）
```

每 Phase 開工前：完成風險分級、建立可回滾的小型 PR，並對照本 RFC「要學／不要學」表；只有需要新權限、外部付費或不可逆產品決策時才升級請 owner 介入。

---

## 6. Success metrics（規劃層）

| 指標 | 目標方向 |
|------|----------|
| 調課後幽靈取消／假超排工單 | 趨近 0 |
| 續約後「時段跑掉」回報 | 趨近 0（契約固定時段預期被理解） |
| 主任完成「暫停／結清／作廢」需補理由比例 | 100% 危險動作有確認＋理由 |
| 家長通知「收到但不理解／重複」 | 下降（digest／偏好） |
| Agent 開工需問 Founder 的架構問題 | 下降（本 RFC + ADR 可回答） |

---

## 7. Delegated-owner execution defaults（2026-07-24）

以下是 owner 授權代理持續處理後採用的可逆執行預設，**不是逐項由 Founder 親自表態的決策**；涉及付費、自架、資料遷移或跨產品共用個資時，仍須另案評審。

| # | 問題 | 執行預設 | 對 Epic 的含義 |
|---|------|------|----------------|
| 1 | Novu／Chatwoot | **12 個月內可評估自架**（非永遠只學模式） | Epic E：先落地「偏好／digest／去重」資料模型與現有 LINE／Telegram channel；另開評估單（資安、營運、成本）再決定是否自架 Novu／Chatwoot |
| 2 | 主任後台密度 | **願意逐步 denser table**（Carbon／Fluent 風） | Epic D：可導入更密表格／篩選條／批次列；仍受 `RULE_DESIGN_SYSTEM` 與 taste 後台 dials 約束，禁止行銷大翻新 |
| 3 | AllTrue ↔ Sunrise 通知偏好 | **兩個品牌完全分開** | Epic E／F：通知偏好、文案、通道設定分產品；可共用工程經驗，不可共用家長／客人同一套產品語言或設定 UI |

舊版「Open questions」改由上述預設推進；若 owner 覆寫預設，在本 Issue／PR 留言並更新本表日期。

---

## 8. Document control

- **Authors**: Agent（Composer）依 Founder star 清單與既有 RFC／roadmap 彙整  
- **Review**: delegated owner workflow；§7 為可覆寫執行預設
- **Promotion**: 本檔保持 Draft 直到對應 Epic 開工；變更參考表或 §7 需更新日期  

**本 PR／Issue 僅規劃，不含應用程式行為變更。**
