# [PLAN/ARCH] 工作音樂小彩蛋

日期：2026-04-26  
狀態：DEV/TEST 完成，PR 中  
目標角色：主任、老師、super_admin

## 1. 背景與目標

主任與老師長時間使用後台處理評量、出勤、排課與行政工作。希望加入低干擾的「輕鬆工作音」小彩蛋，但不可引入流行歌、串流服務或未授權音檔造成版權/公播風險。

目標：
- 使用者手動開啟，不自動播放。
- 提供 3 種低干擾音景：雨聲、咖啡廳、棕噪音。
- 不使用第三方音檔或外部串流，改用 Web Audio 本地合成。
- 不新增 DB/API，不保存聆聽紀錄。

## 2. 範圍

In Scope：
- Staff shell 新增工作音樂播放器。
- 播放/暫停、音景切換、音量控制。
- localStorage 保存音量與音景偏好，但刷新後仍不自動播放。
- 文件記錄音源策略與日後新增音檔規則。

Out of Scope：
- Spotify、YouTube、SoundCloud、廣播或商業串流。
- 流行歌、歌詞歌曲、翻奏旋律、KTV/公播音樂。
- 後端偏好同步、播放紀錄、跨裝置同步。

## 3. RACI / Dependencies

| 項目 | 內容 |
|---|---|
| R/A | AI Agent |
| 人類 | I |
| 前置 PR | 無 |
| 外部服務 | 無 |
| DB/API | 無新增 |
| 音源授權 | v1 無第三方音檔；詳 `docs/AMBIENT_AUDIO_LICENSES.md` |

## 4. User Stories / AC

- 老師可在填評量時手動播放柔和背景音；AC：必須點擊播放才出聲。
- 主任可切換不同音景；AC：3 種音景切換後立即生效。
- 使用者不被突然出聲干擾；AC：登入、刷新、切頁皆不得 autoplay。
- 經營者避免版權風險；AC：無第三方音檔，文件明列禁止事項。

## 5. UI/UX

| 面向 | 規格 |
|---|---|
| 位置 | `App.vue` staff topbar，帳號選單左側 |
| 預設 | 收合、靜音狀態、不自動播放 |
| 控制 | Play/Pause、音景切換、音量 slider |
| 手機 | 按鈕收斂成 icon，面板避開底部 nav |
| 無障礙 | 按鈕/slider 有 aria-label，鍵盤可操作 |
| 錯誤 | 瀏覽器不支援 Web Audio 時顯示 inline error，不阻塞頁面 |

## 6. 功能與非功能需求

- FR-001：僅主任/老師/super_admin staff shell 顯示。
- FR-002：音訊只能由使用者 gesture 啟動。
- FR-003：音量預設 25%，最高 60%。
- FR-004：偏好只存 localStorage。
- NFR-001：不得新增後端、DB、migration。
- NFR-002：不得使用外部音檔、iframe 或串流。
- NFR-003：音訊錯誤不得造成頁面空白。

## 7. 技術設計

| 檔案 | 職責 |
|---|---|
| `frontend/src/components/AmbientMusicPlayer.vue` | Web Audio 合成音景、播放控制、偏好保存 |
| `frontend/src/App.vue` | 掛載播放器，條件為 staff + feature flag |
| `frontend/src/lib/perfFlags.js` | `AMBIENT_MUSIC_ENABLED` 快速關閉 |
| `docs/AMBIENT_AUDIO_LICENSES.md` | 音源策略與日後真實音檔授權規則 |

設計決策：
- 選 Web Audio 本地合成，不選第三方音檔：降低版權/公開傳輸風險。
- 選 topbar，不選右下 FAB：避免和問題回報、導覽 FAB 競爭。
- 選 localStorage，不選後端：符合資料最小化。

## 8. 資安 / 法遵 / 多校區

- 無新 auth flow，無 PII，無播放紀錄。
- 無後端 query，因此無 `CampusID` / `branch_id` 隔離風險。
- 家長入口不顯示，避免外部使用場景擴大。
- 日後若加入真實音檔，必須先在授權文件記錄來源、授權、下載日期、可商用確認與 attribution。

## 9. QA / DoD

已驗證：
- `cd frontend && npm run build` 通過。
- 無 `backend/`、migration、`frontend/dist_build/` diff。
- 靜態審查確認無 autoplay path：`AudioContext` 只在播放按鈕流程建立/resume。

仍需人工實機驗收：
- 實際聽感是否舒適。
- 手機尺寸 topbar 面板是否符合現場操作習慣。

DoD：
- [x] 前端 build 通過。
- [x] 無後端/DB 變更。
- [x] 音源策略文件完成。
- [x] CHANGELOG 完成。
- [ ] PR CI 全綠。
