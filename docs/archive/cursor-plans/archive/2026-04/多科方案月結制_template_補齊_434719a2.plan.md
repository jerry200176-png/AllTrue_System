---
name: 多科方案月結制 template 補齊
overview: UniversalClassScheduler.vue 的 script 段已具備月結制邏輯（payment_type、setPkgPaymentType、pkgRateError 等），但 template 的「多科共用方案」區塊仍是舊版純堂數制佈局，需補入計費方式切換 UI 及對應欄位。
todos:
  - id: template-billing-switch
    content: 在「方案基本資料」section 加入 pkg-billing-switch + 條件欄位（堂數制：total_sessions+rate；月結制：rate+settlement_day）
    status: completed
  - id: template-summary
    content: 更新「方案摘要」卡片：月結制顯示費率/結算日/科目數及正確說明文字
    status: completed
  - id: template-submit-btn
    content: 送出按鈕加月結制 < 2 科時 disabled + tooltip
    status: completed
  - id: deploy
    content: npm run deploy
    status: completed
isProject: false
---

# 多科方案月結制 template 補齊

## 問題根因

[`frontend/src/components/UniversalClassScheduler.vue`](frontend/src/components/UniversalClassScheduler.vue) 分兩段出問題：

- **Script（已修）**：`pkgForm.payment_type`、`pkgForm.settlement_day`、`setPkgPaymentType()`、`pkgRateError`、`pkgSettlementDayError` 都已就位
- **Template（尚未修）**：`<div v-if="packageMode">` 區塊仍是舊版，只有固定的 `total_sessions` + `rate` 欄位，沒有計費切換開關

## 需要改的 template 位置

### 1. 「方案基本資料」section（約 line 35~70）

**現在**：直接顯示 `total_sessions`、`rate`，無計費切換

**改後**：在 `<h4>方案基本資料</h4>` 正下方插入 `pkg-billing-switch`，表單欄位改為條件顯示：

```html
<!-- 計費方式切換開關 -->
<div class="pkg-billing-switch" role="tablist">
  <button :class="['pkg-billing-option', { active: pkgForm.payment_type === 'session' }]"
          @click="setPkgPaymentType('session')">
    <span class="pkg-billing-title">堂數制</span>
    <span class="pkg-billing-tag badge-gray">一次購入 N 堂共用池</span>
  </button>
  <button :class="['pkg-billing-option', { active: pkgForm.payment_type === 'monthly' }]"
          @click="setPkgPaymentType('monthly')">
    <span class="pkg-billing-title">月結制</span>
    <span class="pkg-billing-tag badge-blue">上幾堂收幾堂</span>
  </button>
</div>

<!-- 堂數制專屬欄位 -->
<transition name="pkg-fade-slide">
  <div v-if="pkgForm.payment_type === 'session'" class="form-group">
    <label>總堂數 *</label>
    <input v-model.number="pkgForm.total_sessions" ... />
  </div>
</transition>

<!-- 月結制專屬欄位 -->
<transition name="pkg-fade-slide">
  <div v-if="pkgForm.payment_type === 'monthly'" class="form-group">
    <label>每堂費率（元） *</label>
    <input v-model.number="pkgForm.rate" ... />
    <p v-if="pkgRateError" class="field-note warning-text">...</p>
    <p v-else class="field-note">月費 = 當月實際出席堂數 × 本費率（不分科目）</p>
  </div>
</transition>
<transition name="pkg-fade-slide">
  <div v-if="pkgForm.payment_type === 'monthly'" class="form-group">
    <label>每月結算日 *</label>
    <select v-model.number="pkgForm.settlement_day" ...>...</select>
    <p v-if="pkgSettlementDayError" ...>...</p>
  </div>
</transition>

<!-- 堂數制的 rate 欄位也保留 -->
<transition name="pkg-fade-slide">
  <div v-if="pkgForm.payment_type === 'session'" class="form-group">
    <label>每堂費率 *</label>
    <input v-model.number="pkgForm.rate" ... />
  </div>
</transition>
```

### 2. 「方案摘要」卡片（約 line 168~190）

**現在**：硬寫 `total_sessions`、已補登、剩餘（全是堂數制資訊）

**改後**：依 `pkgForm.payment_type` 顯示不同摘要：
- 堂數制：總堂數 / 已補登 / 剩餘（現行）
- 月結制：每堂費率 / 結算日 / 科目數；說明文字改為「當月實際出席 × 費率，不分科目」

### 3. 送出按鈕（約 line 480）

**現在**：只判斷 `submitting`

**改後**：月結制且科目數 < 2 時 `disabled`，加 `title` tooltip

## 改動範圍

- 只改 `frontend/src/components/UniversalClassScheduler.vue` 的 template 段（CSS 與 script 都已就位）
- 改完後 `npm run deploy`
