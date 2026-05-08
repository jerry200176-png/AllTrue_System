# Security Policy

## 通報漏洞（負責揭露）

若你發現**尚未公開**的安全性問題，請**不要**先開公開 Issue 揭露細節。

建議依序：

1. **GitHub**：[Repository security · Report a vulnerability](https://github.com/jerry200176-png/AllTrue_System/security/advisories/new)（若你介面上有「Report a vulnerability」請用私人通報）。
2. 若無法使用上述管道，請聯絡 **repository owner**（[`@jerry200176-png`](https://github.com/jerry200176-png)），主旨注明 `Security: AllTrue`。

我們會在合理時間內回覆；在此之前請勿發布可利用的 PoC 到公開頻道。

## 範圍內（我們關心的）

- 認證與 session 繞過、越權存取（含多校區隔離）
- 個資／PII 外洩、未授權讀寫生產資料
- RFID／LINE Webhook／公開端點可被濫用
- 部署與 CI/CD 憑證外洩、供應鏈風險

## 範圍外（通常不發漏洞獎金／可能直接關閉）

- 僅理論、無實際影響的掃描報告
- 需要實體接觸裝置／已遭入侵帳號的攻擊鏈（仍歡迎通報，但可能轉維運處置）

## 更完整的內部決策與歷史

見 [`docs/SECURITY.md`](docs/SECURITY.md)。
