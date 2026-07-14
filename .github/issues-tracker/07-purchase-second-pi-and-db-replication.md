# [DevOps] 採購第二台 Raspberry Pi（NT$3,600）+ 設定 DB replication

## 要做什麼
1. **採購**：購買第二台 Raspberry Pi 4/5（預算約 NT$3,600，含 SD 卡與電源）
2. **設定**：設定 PostgreSQL 主從複寫（Primary-Replica replication），讓第二台 Pi 作為 DB 備援

## 為什麼 sandbox 做不到
需要實際購買硬體、寄送、實體安裝、網路設定。本 AI agent 無法做實體採購。

## 誰來做
**CEO**（負責採購決策與下單）

## 背景
- 目前 AllTrue 生產環境只有「單一台 Pi」— 這是重大單點故障風險
- Pi 主機的 SD 卡有寫入壽命限制，隨時可能故障
- 備援方案文件已產出（技術文件資料夾）
- 第二台 Pi 預估：NT$2,000（Pi 4/5）+ NT$600（SD 卡）+ NT$1,000（電源+外殼）= ~NT$3,600

## 優先級
🟠 Medium — 非緊急但長期風險，建議本月內完成
