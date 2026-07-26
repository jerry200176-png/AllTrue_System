# Parent Binding UX Spec

| Field | Value |
|-------|-------|
| Status | **ADR Accepted** (Founder 2026-07-26) — design only |
| Related | ADR · Architecture · Threat |

Parent copy ≠ internal `reason_code`. Anon: never reveal existence, campus match, phone match, guardian count, or course status.

## Principles

School authority · Identity then relationship · One job/screen · Safe fail · Inbox = high-signal only.

**Default anon fail copy:**「目前無法完成綁定。請向就讀分校確認資料，或請分校協助重新提供綁定方式。」  
Optional (logged-in / has code):「若你有綁定碼，請重新向分校索取後再試。」

## Parent copy catalog

| # | State | Copy | Machine |
|---|-------|------|---------|
| 1 | LINE follow | 歡迎加入{分校}。請向分校取得綁定碼後回覆「綁定碼 …」或掃 QR | — |
| 2 | Has code | 輸入綁定碼或開啟分校連結 | — |
| 3 | No code | 聯繫分校櫃檯；勿在公開群傳個人資料 | `MANUAL_REVIEW_REQUIRED`? |
| 4 | Invalid | 綁定碼無法使用，請分校確認或重發 | `CODE_INVALID` |
| 5 | Expired | 已過期，請索取新碼 | `CODE_EXPIRED` |
| 6 | Consumed | 已達使用上限，請索取新碼 | `CODE_CONSUMED` |
| 7 | Bound | 「{名}」已連結；其他孩子另取碼 | `ALREADY_BOUND` |
| 8 | Pending | 已收到申請，分校確認後完成；可直接聯繫分校 | `RELATIONSHIP_PENDING` |
| 9 | Missing phone | **Staff only:** 尚未填寫家長聯絡手機 | `CONTACT_PHONE_MISSING` |
| 10 | Unavailable | 系統忙碌，稍後再試或聯繫分校 | 5xx |
| 11 | RL | 嘗試過多，稍後再試 | `RATE_LIMITED` |
| 12 | Contact | 上班時間聯繫分校，告知孩子姓名與需要「家長綁定碼」 | — |

Legacy Phase 1–2: success copy OK; **all failures** use safe copy. Portal empty-phone 401 existence leak → remove for anon.

## UI states

| Channel | Screens |
|---------|---------|
| Mobile LIFF | Welcome (campus + 有碼/請協助) · Enter code (single field; **no search**) · Success (name after consume) · Pending · Error |
| Portal | LINE login CTA · Children list **姓名·分校** · Add via code · Request (auth+campus+name; always “已收到”) · read_only/suspended labels |
| BindingRequest | LINE auth → campus+name → safe pending → Inbox approve → list shows 學生·分校 |

## Staff

| Area | Spec |
|------|------|
| Panel | Completeness badge; guardians (masked LINE, status, method, revoke); pairing status + 產生/複製/QR/撤銷/重發; pending; last 20 attempts |
| Filters | Missing phone; no GSR; expired unused; pending; failure aggregates |
| Issue（Founder） | TTL **24h/72h/7d** default **7d**; **no permanent**; max_uses=1; ≤4 active unused; plaintext **once**; no extend; 「私訊/紙本，勿群組轉傳」 |
| Revoke | Confirm: 無法再查看；可重發碼 |

## Inbox / LINE phases / a11y

Cases: Architecture inbox types. Fields: dedupe, 7d cooldown, SLA 48h requests, resolve on phone filled / request terminal / GSR active; staff notify **no full phone**.

| Phase | LINE |
|-------|------|
| 1 | Keep「綁定 姓名 手機」; fail→safe copy |
| 2 | Add「綁定碼 …」/ deep link |
| 3 | Legacy flag off |

Tone: short, polite; no internal codes to parents; success may show name; fail must not echo guessed name (Phase 1+).

| Given | Expect |
|-------|--------|
| Wrong anon code | Safe copy; no student name |
| Expired | 已過期索取新碼 |
| Consume OK | Child name + portal CTA |
| Missing phone staff | Badge; can still issue code |
| Repeat missing | One Inbox case |
