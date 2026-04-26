# [PLAN] Ambient Work Music Easter Egg

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 工作音樂小彩蛋 |
| 日期 | 2026-04-26 |
| 版本 | v1 |
| 狀態 | Draft |
| 目標角色 | 主任、老師、super_admin |

## 2. 目標與業務背景

主任與老師長時間使用 AllTrue 處理評量、出勤、排課與行政工作。加入低干擾的工作環境音可讓系統更有溫度，但音樂功能有版權、瀏覽器自動播放、公共場所播放與使用者干擾風險。

目標：
- 提供一個「可手動開啟、可立即關閉」的輕量音樂小彩蛋。
- 使用可商用、低風險授權的短環境音，不使用流行歌、Spotify、YouTube 或未授權串流。
- 不影響核心功能效能與上課/行政流程。

KPI：
- 使用者必須 1 次點擊才能播放音訊，避免 autoplay。
- 預設音量 ≤ 30%，並記住個人偏好。
- 初版最多 3 種音景，避免選項膨脹。
- 音檔總量目標 ≤ 3 MB。

## 3. 範圍

In Scope：
- 前端新增「工作音樂」迷你控制器。
- 初版提供 3 種音景：雨聲、咖啡廳、白/棕噪音或柔和環境音。
- 音訊由使用者手動播放/暫停，含音量控制與記憶偏好。
- 音源只採 CC0 或明確允許商業網頁使用的免權利金素材，並保留授權來源文件。

Out of Scope：
- 不接 Spotify、YouTube、SoundCloud、廣播串流。
- 不播放流行歌曲、歌詞歌曲、KTV/商業公播音樂。
- 不做跨裝置同步、不做個人播放歷史。
- 不做自動排程播放。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（前端功能） | `[FEATURE]` Agent | R |
| AI Agent（UX） | `[FEATURE]` Agent | R |
| AI Agent（測試） | `[TEST]` Agent | R |
| AI Agent（資安/法遵審查） | `[REVIEW]` Agent | R |
| AI Agent（文件） | `[DOCS]` Agent | R |
| AI Agent（部署） | `[OPS]` Agent | R |
| 人類 | 使用者 | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR | 無 | 已確認 |
| 外部服務 | 無；v1 不接第三方串流 | 已確認 |
| 素材授權 | v1 改採本地合成音景，不使用第三方音檔；仍需保存音源策略文件 | 已確認 |
| 環境 | 前端可載入靜態 assets；localStorage 可保存偏好 | 已存在 |

## 5. User Stories + AC

US-001：As a 老師，我想在填評量時開啟柔和背景音 so that 長時間工作更輕鬆。
AC：老師點擊「工作音樂」後才開始播放，且可立即暫停。

US-002：As a 主任，我想選擇不同音景 so that 行政工作時可以依心情切換。
AC：使用者可在最多 3 種音景間切換，切換後立即生效。

US-003：As a 使用者，我不想系統突然出聲 so that 不會干擾教室或辦公室。
AC：頁面載入、登入後、切頁時不得自動播放有聲音訊。

US-004：As a 經營者，我想避免版權風險 so that 系統不會使用未授權音樂。
AC：每個音檔需有來源、授權類型、下載日期與可商用說明。

## 5b. UI/UX 精緻化

| 面向 | 規格 |
|---|---|
| 版面層次 | 在全站 header 或右下角加入小型「工作音樂」按鈕；展開後才顯示播放器。 |
| 色彩一致性 | 使用柔和中性色，不用警示紅；播放中可用藍/綠小點表示。 |
| 互動回饋 | Play/Pause 狀態明確；切換音景時顯示目前名稱；失敗時顯示「音訊載入失敗」。 |
| 空狀態 | 若音訊資源不可用，顯示可關閉提示，不阻塞頁面。 |
| 載入狀態 | 音檔 lazy-load；播放前不下載全部音檔。 |
| 防呆設計 | 預設關閉；預設音量低；提供一鍵靜音。 |
| 響應式 | 手機上收合成單一音符/喇叭按鈕，展開面板不得水平 overflow。 |
| 無障礙 | Play/Pause 使用 `aria-label`；鍵盤可操作；音量 slider 有 label。 |

## 6. 功能需求 FR

- FR-001：系統應提供全站可見但低干擾的工作音樂入口。
- FR-002：音訊只能由使用者點擊後播放。
- FR-003：系統應支援播放/暫停、音量、音景切換。
- FR-004：系統應保存個人偏好到 localStorage：音量、最後選擇音景、是否收合。
- FR-005：系統不得保存任何聆聽紀錄到後端。
- FR-006：系統應在音訊載入失敗時保持核心頁面可用。
- FR-007：所有音源必須有授權清單文件。

## 7. 非功能需求 NFR

- NFR-001：音樂控制器初始 JS/CSS 增量應保持小於 20 KB gzip。
- NFR-002：初版音檔總量目標小於 3 MB；音檔 lazy-load。
- NFR-003：不得新增後端 API 或 DB migration。
- NFR-004：音訊播放失敗不得拋出未捕捉錯誤造成頁面空白。

## 8. 技術方向

- 前端：新增一個全站迷你音訊控制元件，掛在 `App.vue` header/全站 layout。
- 音源：優先採本地靜態檔案，放在前端 assets 或 public 子目錄。
- 授權：新增一份素材來源清單，記錄每個音檔的授權與來源連結。
- 狀態：使用 localStorage，不進後端，不新增資料表。

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-26 | 使用本地 CC0/可商用音檔 | Spotify/YouTube/SoundCloud embed | 外部串流與商業使用授權不明，且可能有廣告/追蹤/公播風險。 |
| 2026-04-26 | 手動點擊才播放 | 登入後自動播放 | 瀏覽器 autoplay 會擋，且突發聲音會干擾教室。 |
| 2026-04-26 | 初版 3 個音景 | 大型音樂庫 | 低風險、低干擾、易驗收。 |

## 9. 資安與存取控制

- Role：功能可對登入 staff 顯示；家長端 v1 不顯示。
- PII：不收集任何個人聆聽資料。
- STRIDE：
  - Spoofing：無新身份流程。
  - Tampering：音源固定白名單，不允許使用者輸入 URL。
  - Repudiation：非關鍵操作，不需 audit log。
  - Information Disclosure：不傳送播放紀錄到第三方。
  - Denial of Service：音檔 lazy-load，總量限制。
  - Elevation of Privilege：無權限提升。
- 法遵/版權：不得使用未授權商業音樂；如未能確認授權，該音檔不得進 PR。

## 10. QA 驗收

Happy Path：
- 登入主任/老師後看到「工作音樂」入口。
- 點擊播放後有聲音，按暫停立即停止。
- 切換音景成功，音量可調。
- 重新整理後保留音量與音景偏好，但不自動播放。

Edge：
- 音檔 404 時 UI 顯示錯誤但頁面不崩潰。
- 手機版不遮住主要操作。
- 快速切頁時音樂不中斷或可預期停止，需在 ARCH 決定。

UI/UX 驗收：
- 無 autoplay。
- 一鍵關閉明顯。
- 鍵盤可操作。

## 11. 上線與維運

部署步驟：
- feature branch → PR → CI 全綠 → merge → deploy workflow。
- 無 migration。
- 前端改動需由 deploy workflow 上線並驗證 `version.json`。

Feature Flag：
- 建議使用前端常數或 `perfFlags` 類似 flag：`ambient_music_enabled`，預設開給 staff；若出現投訴可快速關閉。

Observability：

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 前端 build | `npm run build` | fail | `[OPS]` |
| 音檔大小 | `du -sh frontend/public/audio` | > 3 MB | `[REVIEW]` |
| Health check | `/api/v1/health` | 非 200 | `[OPS]` |

回滾：
- `git revert` 本 PR；無 DB rollback。

## 12. 里程碑與優先級

- P0 `[LEGAL]/[REVIEW]`：確認音源授權，未確認不得實作。
- P1 `[UX]`：確定入口位置與互動。
- P1 `[FEATURE]`：實作迷你播放器。
- P1 `[TEST]`：前端 build + 手動播放驗收。
- P2 `[DOCS]`：CHANGELOG + 音源授權清單。
- P2 `[OPS]`：CI 綠後 merge/deploy/health check。

## 13. 風險 / 假設 / 開放問題

業界參考：
- Flowful / FocusNoise / Foci / ZenFocus 等 productivity app 提供 ambient music、rain、café、white/brown noise 作為專注工具。
- Chrome / MDN autoplay guidance：有聲音訊應由使用者 gesture 啟動，並提供 play/pause/volume controls。
- MÜST / HiNet 放心播 / BGMRadio 類公播資訊：營業場所或網站公開傳輸/公開演出音樂需確認授權；個人 Spotify 類服務通常不等於商業/公播授權。

| 風險 | 等級 | 業界標準解法 | 本專案採行方式 |
|---|---|---|---|
| 版權/公播風險 | 高 | 使用 business-licensed music 或 royalty-free/CC0 素材並保存授權 | v1 只用 CC0/可商用素材；不接流行歌串流。 |
| 突然出聲干擾教室 | 中 | 手動播放、低音量、明顯關閉 | 禁止 autoplay，預設關閉。 |
| 外部串流帶來追蹤/廣告 | 中 | 自託管或合規商業音樂服務 | v1 自託管小音檔。 |
| 音檔增加載入負擔 | 中 | lazy-load、限制檔案大小 | 播放前才載入，總量目標 ≤ 3 MB。 |

假設：
- 使用者想要的是「輕鬆氣氛」而非完整音樂平台；若假設不成立，另開 v2 評估合法商業公播服務。

開放問題：
- `[AI-RESOLVABLE]` DEV 前選定 3 個音源並整理授權清單。
- `[BLOCKED: 使用者偏好]` 初版音景偏好：雨聲/咖啡廳/白噪音是否符合 AllTrue 風格。

## 14. Definition of Done

- [ ] 無 autoplay：驗證方式：刷新頁面後 DevTools/手動檢查音訊未播放。
- [ ] 可播放/暫停：驗證方式：手動點擊控制器可聽到/停止音訊。
- [ ] 音源授權清單：驗證方式：repo 中包含每個音檔來源、授權、下載日期。
- [ ] 前端 build：驗證方式：`cd frontend && npm run build` 成功。
- [ ] 無後端/DB：驗證方式：`git diff --name-only` 不包含 `backend/` 與 migration。
- [ ] Health check：驗證方式：deploy 後 `/api/v1/health` 回傳 `status: ok`。

---

# [ARCH] 技術設計

## A1. 現有系統觀察

- `frontend/src/App.vue` 是全站 staff shell，已集中處理 sidebar、topbar、mobile nav、role 判斷與全站浮動元件。
- 家長入口透過 `isStandaloneParent` 與 `ParentPortal` 獨立顯示，不應載入工作音樂。
- v1 改採 Web Audio 本地合成音景，不新增 `frontend/public/audio/` 靜態音檔。
- `frontend/src/lib/perfFlags.js` 已有 compile-time flag pattern，可新增 `AMBIENT_MUSIC_ENABLED` 供快速關閉。

## A2. DB 異動清單

無 DB 異動。

理由：
- 音樂偏好只屬於單一瀏覽器體驗，用 `localStorage` 即可。
- 不需要跨裝置同步。
- 不收集聆聽紀錄，降低隱私與法遵風險。

## A3. API 合約

無新增 API。

理由：
- v1 音源為瀏覽器本地合成。
- 授權清單以 repo 文件保存，不由 API 回傳。
- 無 role/campus 查詢，不產生多校區隔離風險。

## A4. 前端元件規劃

| 元件 / 檔案 | 職責 |
|---|---|
| `frontend/src/components/AmbientMusicPlayer.vue` | 播放器 UI、音訊狀態、play/pause、音景切換、音量、localStorage 偏好。 |
| `frontend/src/App.vue` | 在 staff shell 中掛載播放器；條件為 `session && !isStandaloneParent && (isDirector || isTeacher) && perfFlags.AMBIENT_MUSIC_ENABLED`。 |
| `docs/AMBIENT_AUDIO_LICENSES.md` | 記錄 v1 不使用第三方音檔，並規範日後新增音檔的授權欄位。 |
| `frontend/src/lib/perfFlags.js` | 新增 `AMBIENT_MUSIC_ENABLED: true`，必要時可改 false 快速隱藏入口。 |

## A5. 音訊行為設計

- 使用 Web Audio API 在使用者點擊後本地合成音景，不載入第三方音檔。
- 不在頁面載入時建立播放節點；使用者點擊播放後才建立或 resume `AudioContext`。
- 預設音量 `0.25`。
- 重新整理後保留 `volume` 與 `trackId`，但不保留 `playing=true`，避免刷新自動播放。
- 切換音景時：
  - 若原本播放中，切換後在同一次使用者操作內播放新音景。
  - 若原本暫停，只切換選擇，不自動播放。
- 音訊啟動或瀏覽器不支援時顯示 inline error，不 throw 到全站。

## A6. UI 放置設計

建議放在 `main-topbar` 帳號選單左側：
- 桌面：一顆「音樂」膠囊按鈕，播放中顯示柔和狀態點；展開後是小 popover。
- 手機：同一元件收斂成喇叭 icon，popover 固定右上或底部 sheet，避開 `mobile-bottom-nav`。

不建議右下浮動：
- 右下已有問題回報 FAB 與導覽 `?` FAB，第三顆浮動按鈕會互相競爭。

## A7. 音源選擇規格

v1 不選用外部音檔，改用程式合成：
- 雨聲：filtered noise。
- 咖啡廳：filtered noise + low murmur texture，不含真實人聲錄音。
- 棕噪音：brown noise。

若日後改用真實音檔，才啟用以下規格：
- 授權頁面明確允許 commercial use 或 CC0/public domain。
- 不含歌詞、不含流行旋律翻奏、不含疑似第三方商標聲音。
- 單檔建議 ≤ 1 MB；必要時使用短 loop。
- 文件保留來源 URL、授權名稱、下載日期、檔名、是否需 attribution。

## A8. 多校區 / 權限隔離

- 無後端查詢，因此不涉及 `CampusID` / `branch_id`。
- 顯示範圍：主任、老師、super_admin staff shell。
- 家長入口不顯示，避免親子端誤解為教學內容或增加外部授權場景。

## A9. 測試策略

- Build 驗證：`cd frontend && npm run build`。
- 靜態檢查：確認 `backend/`、`database/migrations/` 無 diff。
- 手動 QA：
  - 刷新後不自動播放。
  - 點擊播放/暫停有效。
  - 調整音量後刷新，音量保留但仍不自動播放。
  - 切換主任/老師頁面時控制器存在；家長入口不存在。
  - 瀏覽器不支援 Web Audio 時頁面不空白，並顯示可理解錯誤。

## A10. 設計問題 Q&A

Q：要不要接 Spotify / YouTube？
A：v1 不接。個人串流服務通常不等於商業系統、公播或公開傳輸授權，且會增加外部追蹤與 iframe/autoplay 問題。

Q：要不要用後端記住使用者音樂偏好？
A：v1 不做。音樂是小彩蛋，localStorage 足夠，且不保存聆聽行為較符合隱私最小化。

Q：音樂是否要跨頁不中斷？
A：AllTrue 無 Vue Router、由 `active` ref 切頁，元件掛在 `App.vue` shell 後自然可跨頁持續；登出或進家長入口時卸載並停止。

Q：如果日後想使用真正商業音樂？
A：另開 v2，需走 LEGAL/SEC，選擇具商業/公播授權的 BGM 服務，並確認授權涵蓋補習班營業場域與網頁公開傳輸。

## A11. [ARCH] Exit Checklist

- [x] DB 異動清單：無。
- [x] API 合約：無新增 API。
- [x] 前端元件規劃：新增獨立播放器元件，掛載於 `App.vue` staff shell。
- [x] 多校區隔離：無後端查詢；僅 staff 顯示；家長入口不顯示。
- [x] 高風險版權議題：v1 採本地合成音景，並建立音源策略/授權清單。
- [x] 設計問題 Q&A：已列出。
