# Parent Binding UX Spec

| Field | Value |
|-------|-------|
| Status | Design review only |
| Date | 2026-07-26 |
| Audience | Founder / Director / Implementation |
| Related | ADR · Target Architecture · Threat Model |

> 家長文案與 internal `reason_code` **分離**。匿名階段不透露學生是否存在、校區、手機是否匹配、已綁家長數、課程狀態。

---

## 1. Design principles

1. **School is the authority** — 對齊 ClassDojo / PowerSchool / Schoology：拿不到碼就聯繫分校。  
2. **Identity first, relationship second** — 先確認 LINE／家長身份，再 consumecredential。  
3. **One job per screen** — 綁定過程不塞繳費／課表。  
4. **Safe failure default** — 寧可多聯繫櫃檯，不可幫攻擊者收窄搜尋空間。  
5. **Staff gets signal, not noise** — Inbox 只放可執行案件。

### 1.1 Recommended unified safe failure（重新評估後）

研究後建議（優於任務草案中的長句）：

**主文案（匿名失敗預設）：**

> 目前無法完成綁定。請向就讀分校確認資料，或請分校協助重新提供綁定方式。

**理由：**

- 不提「姓名／手機是否正確」（避免引導繼續猜）。  
- 不提「系統尚未設定家長資料」（對攻擊者等同確認可能存在）。  
- 把行動導向 **分校**（對齊 Clever/PowerSchool「contact your school」）。  
- 短、可唸、適合 LINE 氣泡。

**次文案（可選第二行，僅已登入家長或已持有碼時）：**

> 若你有綁定碼，請重新向分校索取後再試。

---

## 2. Parent copy catalog

| # | State | Channel | Copy（繁中） | Machine |
|---|-------|---------|--------------|---------|
| 1 | 初次加入 LINE | LINE follow | 您好！歡迎加入{分校}。若要查看孩子的學習與繳費資訊，請向分校取得綁定碼後，回覆「綁定碼 您的代碼」。也可以請櫃檯出示 QR。 | — |
| 2 | 有配對碼（指引） | LINE / Portal | 請輸入分校提供的綁定碼，或開啟分校給你的連結。 | — |
| 3 | 沒有配對碼 | LINE / Portal | 還沒有綁定碼時，請聯繫就讀分校櫃檯協助。不要在公開群組傳送個人資料。 | `MANUAL_REVIEW_REQUIRED` optional |
| 4 | 碼無效 | LINE / Portal | 這個綁定碼無法使用。請向分校確認後再試，或請分校重新提供。 | `CODE_INVALID` |
| 5 | 碼過期 | LINE / Portal | 綁定碼已過期。請向分校索取新的綁定碼。 | `CODE_EXPIRED` |
| 6 | 已使用完畢 | LINE / Portal | 這個綁定碼已達使用上限。請向分校索取新的綁定碼。 | `CODE_CONSUMED` |
| 7 | 已綁定 | LINE / Portal | 「{學生顯示名}」已連結到你的帳號。如需綁定其他孩子，請向分校取得另一組綁定碼。 | `ALREADY_BOUND` |
| 8 | 申請待審 | Portal / LINE | 已收到你的連結申請，分校確認後會完成綁定。如需加快處理，請直接聯繫分校。 | `RELATIONSHIP_PENDING` |
| 9 | 資料尚未完成（**僅主任可見**；家長仍用安全文案） | Staff | 此學生尚未填寫家長聯絡手機。 | `CONTACT_PHONE_MISSING` |
| 10 | 系統暫時不可用 | Any | 系統忙碌中，請稍後再試。若持續發生，請聯繫分校。 | `5xx` / unavailable |
| 11 | Rate limited | Any | 嘗試次數過多，請稍後再試。 | `RATE_LIMITED` |
| 12 | 聯繫分校（快捷） | Any | 請於上班時間聯繫就讀分校櫃檯，並告知孩子姓名與需要「家長綁定碼」。 | — |

### 2.1 Legacy name+phone（Phase 1–2，降級）

成功路徑文案可維持「綁定成功」。  
**所有失敗**改用 §1.1 安全文案（不再說「找不到某某與此手機」）。

已綁定成功後的多子女提示可保留，因已通過驗證。

### 2.2 Portal empty-phone（現行 401）修正方向

現行：「此學生尚未設定聯絡手機…」→ **對匿名／未完成驗證者移除**。  
改為安全文案；內部寫 `CONTACT_PHONE_MISSING`；符合條件才建 Inbox。

---

## 3. Parent UI states

### 3.1 Mobile（390×844）— LINE / LIFF

| Screen | Content |
|--------|---------|
| Welcome | 分校名 + 一句說明 +「我有綁定碼」／「請分校協助」 |
| Enter code | 單一輸入框 + 提交；無學生搜尋 |
| Success | 孩子姓名（consume 成功後才顯示）+ 進入家長入口 CTA |
| Pending | 沙漏／待審說明 + 聯繫分校 |
| Error | Safe copy + 重試／聯繫 |

**禁止**：學生搜尋 typeahead；顯示「是否存在」；卡片堆疊行銷資訊。

### 3.2 Desktop — Parent Portal

| Screen | Content |
|--------|---------|
| Login hub | LINE 登入主 CTA；次要「我有綁定碼」 |
| Manage children | 已連結孩子列表；「新增孩子」→ 輸入碼 |
| Request form（若開啟） | 選分校 + 孩子姓名 + 送出；成功後 pending |

---

## 4. Staff UX — Students management

### 4.1 Student detail — Parent & binding panel

| Element | Spec |
|---------|------|
| 聯絡完整度 | Badge：完整 / 缺家長手機 / 僅舊電話 |
| Relationships | 列表：masked LINE、status、verified_at、method、撤銷 |
| Pairing | 狀態（無／有效至…／已用完／已撤銷）；按鈕：產生、複製連結、顯示 QR、撤銷、重發 |
| Requests | Pending count + 審核入口 |
| Audit | 最近 20 筆 attempts（reason_code、時間、masked） |

### 4.2 Filters & stats（StudentsList / Director）

- 缺家長聯絡資料  
- 尚無有效家長 relationship  
- 配對碼已過期未使用  
- 待審申請  
- 綁定失敗需處理（聚合）  
- 多次錯誤嘗試  

### 4.3 Issue code interaction

1. 點「產生綁定碼」→ 確認 TTL／可用次數（預設值來自設定）。  
2. 顯示 **一次** plaintext + 複製 + QR。  
3. 關閉後無法再看 plaintext（只能重發）。  
4. 文案提示：「請以私訊或紙本交給監護人，勿貼到班級群。」

### 4.4 Revoke

二次確認：「撤銷後該家長將無法再查看此學生。可重新發碼。」

---

## 5. Action Inbox — binding cases

### 5.1 Allowed case types

見 Target Architecture §6.9。

### 5.2 Case fields

| Field | Rule |
|-------|------|
| dedupe_key | 穩定字串，防重複 |
| cooldown | 預設 7 天 |
| priority | contact_missing=normal；SLA breach=high；reconfirm=high |
| SLA | request：48h（可配） |
| assignee | 可空（校區佇列） |
| resolve condition | 明確資料條件（手機已填／request 終態／relationship active） |
| deep link | `/students?id=` 或 request detail |
| reopen | 條件再次成立且過 cooldown |

### 5.3 Notification text（staff）

> 有一筆家長綁定待處理：{學生姓名}（{分校}）。原因：{中文說明對應 reason}。  
> **禁止**完整手機。

---

## 6. LINE command UX（過渡）

| Phase | Command |
|-------|---------|
| Phase 1 | 仍接受「綁定 姓名 手機」，失敗改安全文案 |
| Phase 2 | 新增「綁定碼 XXXXX」／deep link；follow 訊息改推碼流程 |
| Phase 3 | 姓名+手機僅 feature flag 或關閉 |

---

## 7. Accessibility & tone

- 語氣：客氣、短句、不責備家長。  
- 不使用內部代號（B1、P0、reason code）於家長文案。  
- 主任 UI 可顯示 reason code（技術人員／除錯）。  
- 成功時可用學生姓名；失敗時匿名階段不回顯猜測姓名（現行 LINE 會回顯 — Phase 1 起停止）。

---

## 8. Acceptance examples（UX）

| Given | Expect |
|-------|--------|
| 匿名輸入錯碼 | Safe copy；無學生名 |
| 碼過期 | 「已過期，請索取新碼」 |
| 成功 consume | 顯示孩子名 + 入口 |
| 主任開學生頁缺手機 | Badge + 可產生碼（碼不依賴手機） |
| 同學生重複缺手機 attempt | Inbox 1 筆，不刷屏 |
