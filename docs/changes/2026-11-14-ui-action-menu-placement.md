## 2026-11-14 — fix(ui): 「更多」選單放不下時改到空間較大的一邊 (issue 4989)
<!-- release-notes: silent_ship=silent-2026-11-14-ui-action-menu-placement -->
<!-- silent-reason: Presentation-only placement fix of the shared 更多 menu found during the 課程查找 template migration; a menu that already fit is unchanged, a taller one now opens on the side with more room and scrolls inside itself; no item, label, request or permission change. -->
- 平板（約 768 寬）上，課程列等處的「更多」選單原本會超出畫面下緣、最後幾項點不到；現在放不下時會改到上方或下方空間較大的一邊，內容太長時選單內部可捲動，用鍵盤上下移動時選到的項目也會捲進畫面。原本放得下的選單位置不變，選項與文字都不變。
