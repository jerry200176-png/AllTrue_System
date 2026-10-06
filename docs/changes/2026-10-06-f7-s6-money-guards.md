## 2026-10-06 — fix(billing): F7 S6 payment guards use the resolver — a partly paid course can take the remainder (#3536 part 1)
<!-- release-notes: silent_ship=silent-2026-10-06-f7-s6 -->
<!-- silent-reason: 上線並在正式站驗證後，與 S7 一起發教職員卡 -->
- 重複入帳與月結延長的防護改以帳務解析器判斷：部分繳費的課程可以補登尾款，已繳清、免費或解析失敗時一律擋下。
