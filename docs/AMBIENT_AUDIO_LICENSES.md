# 工作音樂音源授權清單

更新日期：2026-04-26

## v1 音源策略

本功能 v1 不使用任何第三方音檔、串流、歌手作品、流行歌曲或外部音樂服務。

所有音景由 `frontend/src/components/AmbientMusicPlayer.vue` 在使用者點擊播放後，透過瀏覽器 Web Audio API 即時合成：

| 音景 | 來源 | 授權 / 風險說明 |
|---|---|---|
| 雨聲 | 程式即時產生 filtered noise | 無第三方素材；不需額外音檔授權 |
| 咖啡廳 | 程式即時產生 filtered noise / low murmur texture | 無第三方素材；不含真實人聲錄音 |
| 棕噪音 | 程式即時產生 brown noise | 無第三方素材；不需額外音檔授權 |

## 禁止事項

- 不得加入未確認授權的 mp3、wav、ogg。
- 不得嵌入 Spotify、YouTube、SoundCloud、廣播或個人串流服務。
- 不得加入流行歌、翻奏旋律、歌詞歌曲、KTV 或商業公播音樂。

## 日後新增音檔規則

若未來要加入真實音檔，必須在 PR 內更新本文件，逐一記錄：

| 欄位 | 必填內容 |
|---|---|
| 檔名 | repo 內實際路徑 |
| 來源 URL | 原始下載頁 |
| 授權 | CC0 / public domain / 可商用免權利金授權 |
| 下載日期 | YYYY-MM-DD |
| Attribution | 是否需要署名與署名文字 |
| 可商用確認 | 授權文字中明確允許 commercial use |

未能確認授權時，不得合併。
