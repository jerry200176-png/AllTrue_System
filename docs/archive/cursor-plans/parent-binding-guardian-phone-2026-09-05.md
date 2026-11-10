# Parent binding guardian phone regression — 2026-09-05

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1：家長可能無法完成 LINE／Portal 綁定，但未造成權限擴張。 |
| 根因類型 | 邏輯錯誤／權限邊界。 |
| 根因摘要 | `ParentBindingClassifier` 以 `StudentContactPhone::normalizedDigits()`／`forStudent()` 判斷「是否有手機」與比對；該 projection 只代表主要監護人／legacy 欄位，漏掉合法的 active／read_only 非主要監護人。 |
| 錯誤行為 | 多監護人開啟時，非主要監護人的有效手機可能被判為 `CONTACT_PHONE_MISSING` 或 `PHONE_MISMATCH`。 |
| 預期行為 | 綁定與登入以任一 active／read_only guardian phone 作為有效匹配；只有 revoked／suspended／pending 或無 legacy phone 才視為無可用聯絡電話。 |
| 影響範圍 | LINE 綁定與 Parent Portal classifier；涵蓋姓名與 Student ID 路徑，跨校資料仍由既有 campus boundary 控制。 |
| **歷史比對** | B0：closed issue 未找到相同「非主要 guardian classifier」案件；屬 R10／R10b 復發家族延伸。既有 R10 是 legacy `parent_phone` projection 漏接，R10b 已要求多監護人 auth 使用 active／read_only canonical SLB。 |
| **根因層級** | 架構設計缺口：5 Whys 結論是 display projection 被重用為 auth existence predicate，未將「顯示主要聯絡人」與「可授權的聯絡人集合」分成兩個明確邊界。 |
| **大廠參考** | [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html) 將 authentication 的 authenticator 與 digital identity 分開；本修復保留 canonical access predicate，避免以單一顯示欄位代替整個身份／授權集合。[Keycloak Server Administration Guide](https://www.keycloak.org/docs/latest/server_admin/) 也以 authentication flow 中的多個 alternative authenticator 建模；AllTrue 對應為同一學生下多個已授權 guardian phone 的集合比對。 |
| B1 偵查來源 | 生產 read-only `parent_binding` case dump、current code／schema／tests、R10/R10b 文件與 B0 issue／MemPalace 查詢。 |

## 1. 文件資訊

- 功能名稱：Parent binding guardian phone matching
- 版本：2026-09-05
- 狀態：Implementation / CI evidence pending
- 嚴重度：P1
- 目標角色：家長（LINE、Parent Portal）
- 關聯 Bug：木柵「王泓曄」回報；目前生產 probe 顯示其現行資料與主要手機綁定成功，無法證明歷史失敗由現行缺陷造成。

## 2. 業務背景與影響

多監護人資料已經把 primary contact phone 作為顯示 projection，但授權匹配允許任一 active／read_only guardian phone。classifier 在兩者之間使用了錯誤的 existence／comparison predicate，造成合法家長可能被拒絕。修復後，現行 UI 顯示規則不變，LINE／Portal auth matching 與 `StudentContactPhone::matchesNormalizedInput` 保持一致。

## 3. 範圍

### In Scope

- 統一 LINE／Portal `ParentBindingClassifier` 的存在判斷與電話比對。
- 覆蓋 primary、non-primary active/read_only、legacy、revoked、missing cases。
- 保留既有 revoked／suspended／pending 不得授權與 legacy fallback 的安全邊界。
- 加入 regression lesson、changelog 與 read-only production evidence probe。

### Out of Scope

- 不修改 production data、guardian links、Student phone、LINE IDs 或 sessions。
- 不執行 migration、schema cutover、feature-flag activation 或 deployment activation。
- 不改前端顯示 projection、Staff guardian CRUD、SLB repair、RFID、其他登入方式或訊息文案。

## 4. RACI

| 角色 | 負責 |
|---|---|
| R（Responsible） | AI Agent：實作、測試、證據包與 PR。 |
| A（Accountable） | AI Agent：依 repository governance 完成 required checks；Founder 僅在 T3 protected production activation／repair 邊界被通知。 |
| C（Consulted） | 無；既有 R10/R10b 與 schema contract 為依據。 |
| I（Informed） | Founder／維運：PR、CI、production probe 結果。 |

## 4b. Dependencies

- 依賴既有 `guardians`／`student_guardians` tables 與 `PERF_MULTI_GUARDIAN` flag；本 PR 不新增 migration。
- 依賴 `StudentGuardian::activeAccess()` 與 `StudentContactPhone::matchesNormalizedInput()` 的既有 canonical semantics。
- 無前置 PR；production activation、data repair、migration／cutover 均不在本 PR 自動執行。

## 5. Acceptance Criteria

### AC-001：合法 guardian phone

- AC-001-a：primary active guardian phone 可通過 LINE Student ID classifier。
- AC-001-b：non-primary active／read_only guardian phone，在 primary 無手機且 legacy 無手機時，LINE Student ID 與 Portal name classifier 均成功。

### AC-002：legacy 相容

- AC-002-a：multi-guardian flag 關閉時，legacy `parent_phone` 可成功通過 classifier。

### AC-003：撤銷與缺漏安全邊界

- AC-003-a：revoked guardian phone 不得通過，且回傳 `CONTACT_PHONE_MISSING`（無 legacy fallback 時）。
- AC-003-b：沒有任何可用 guardian／legacy phone 的學生回傳 `CONTACT_PHONE_MISSING`。

### AC-004：production evidence

- AC-004-a：read-only probe 輸出 Wang 的 data-source／auth parity 與 aggregate impact counts，不輸出 raw PII。

## 6. 功能需求 FR

- FR-001：系統應以 active／read_only guardian phone 集合判斷是否存在可用聯絡電話。
- FR-002：系統應以 canonical matching predicate 比對 LINE 與 Portal 的電話輸入。
- FR-003：系統應拒絕 revoked／suspended／pending guardian phone，並依既有規則處理 legacy fallback。
- FR-004：系統應維持 campus mismatch、student-not-found、already-bound 與 ambiguity 的既有 reason codes。

## 7. 非功能需求 NFR

不適用：本次是 auth classification correctness bug，不是效能 bug；新增查詢沿用既有 indexed student/status 與 guardian phone predicates，完整 query profiling 留在後續容量工作。

## 8. 技術方向

- `backend/app/Support/StudentContactPhone.php`：新增明確的 usable-contact predicate，將 display projection 與 auth existence 分離。
- `backend/app/Services/ParentBinding/ParentBindingClassifier.php`：LINE／Portal 的 Student ID 與 name paths 改用 usable predicate + canonical match。
- `backend/tests/Feature/ParentBindingObservabilityTest.php`：加入五類 lifecycle／source regression matrix。
- `.github/workflows/production-case-dump.yml`：保留 bounded SELECT-only Wang probe 與 aggregate impact evidence。
- 取捨：不改 `forStudent()`，避免影響 UI／通知等 display consumers；只修 auth classifier 的 boundary。

## 8b. Decision Log

| 日期 | 替代方案 | 選擇理由 |
|---|---|---|
| 2026-09-05 | 讓 `forStudent()` 回傳所有 guardian phones | 拒絕；會破壞其 primary display contract，並讓消費者無法區分 projection 與 auth set。 |
| 2026-09-05 | 在每個 controller 各自補 guardian query | 拒絕；會重複 query、容易造成 LINE／Portal parity drift。 |
| 2026-09-05 | 在 shared contact support 建立 usable predicate，classifier 統一呼叫既有 match | 採用；集中 auth boundary、保持 revoke-proof 與 legacy rollback 語意。 |

## 9. 資安與存取控制

- 這是 T3 auth／PII／LINE webhook 變更；新增可接受的輸入僅限既有 `active`／`read_only` links。
- `pending`、`suspended`、`revoked` 不得授權；若存在 active guardian phone，不得以 stale legacy phone 繞過 canonical rule。
- production probe 僅輸出 existence、來源、status／primary flags、counts 與 reason/outcome metadata；不輸出 raw name、phone、LINE ID。
- 不新增權限、token、session、資料修復或 production mutation。

## 10. QA 驗收

- Happy path：primary、non-primary active/read_only、legacy；LINE／Portal classifier assertions。
- Edge：primary guardian 無手機、secondary 有手機；多校區 student mismatch；legacy fallback。
- Error：revoked、suspended、pending、missing、mismatch；reason code parity。
- Revert-proof 驗證：在保留 test fixture 的前提下，以 base implementation 重跑新增 regression test，non-primary case 至少一個 failure；恢復修復後再跑完整 targeted suite。

## 11. 上線與維運

- 部署：PR required checks 綠後依既有 main deploy workflow；本 PR 無 migration。
- Production activation：不開／不改 flag；不執行 production artisan、phpunit、repair 或 data mutation。
- Observability：現有 `parent_binding_attempts` reason codes；本 probe 在 observability flag 關閉時不改變 production writes。
- 回滾：revert PR code／workflow commit，重新跑 required CI；預估 10 分鐘內可回到原 classifier，無 schema rollback。

## 12. 優先級

- P1 / T3：家長登入／綁定 correctness 與 auth boundary。
- 執行 Agent：AI Agent；SEC／OPS evidence 由同一 Agent 產出並附於 PR。

## 13. 風險／假設／開放問題

- 假設：`StudentGuardian::activeAccess()` 的 active／read_only 定義是現行 canonical access contract；probe 與現有 PB-04 文件一致。
- 風險：部分舊資料可能只有 legacy phone；flag-off fallback 必須保留，已由 regression test 鎖定。
- 風險：目前 production Wang row 沒有歷史 attempt（observability disabled），因此不能把本次 systemic defect 當成該個案歷史 root cause。
- 外部參考已查：OWASP authentication guidance、Keycloak alternative authentication flows；本 PR 採用「identity projection 與 authenticator/access predicate 分離」的共同原則，不引入其產品或資料模型。
- 開放問題：若要修補歷史失敗個案或啟用新的 guardian cutover，須另立 Founder-approved production repair／activation task。

## 14. Definition of Done

- [ ] FR-001～FR-004：`bash scripts/phpunit-isolated.sh backend/tests/Feature/ParentBindingObservabilityTest.php` 回傳成功。
- [ ] Revert-proof：base implementation 下新增 non-primary regression case 至少 1 failure；修復後 targeted suite 全綠。
- [ ] Docs：`git diff --check` 回傳空白錯誤；CHANGELOG 與 R lesson 各含 2026-09-05 條目。
- [ ] Production evidence：workflow run 成功，artifact 明確標示 `read_only=true`、Wang auth parity 與 impact counts。
- [ ] CI：PR required checks 全部成功，且 deploy／activation 未由本任務直接觸發。
