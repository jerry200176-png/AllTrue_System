# Design System Components（`At*`）

全站共用 UI 元件，**只吃 `styles.css` 的 `--ds-*` token**，不含硬編碼色。
規格來源：[`docs/RULE_DESIGN_SYSTEM.md`](../../../../docs/RULE_DESIGN_SYSTEM.md)。
文案規範：[`docs/GUIDE_UI_COPY.md`](../../../../docs/GUIDE_UI_COPY.md)。

> 漸進遷移：新頁面/新區塊一律用這些元件；既有頁面在各自的 page issue（#691–#700）逐步替換，不要求一次全改。

---

## AtButton

```vue
<AtButton variant="primary" @click="save">儲存</AtButton>
<AtButton variant="secondary" icon="download">匯出 Excel</AtButton>
<AtButton variant="ghost" size="sm">取消</AtButton>
<AtButton variant="danger" icon="delete">刪除</AtButton>
```

| prop | 值 | 預設 |
|---|---|---|
| `variant` | `primary` / `secondary` / `ghost` / `danger` | `primary` |
| `size` | `sm` / `md` | `md` |
| `icon` | Material Symbols 名稱 | — |
| `block` | 滿版 | `false` |
| `disabled` | — | `false` |

**規則**：一個區塊只放一顆 `primary`（§2 主色稀有）。

---

## AtCard

```vue
<AtCard title="今日排課">
  <p>內容…</p>
</AtCard>

<AtCard>
  <template #header><h3>自訂標題</h3></template>
  <template #actions><AtButton size="sm" variant="ghost">更多</AtButton></template>
  內容…
</AtCard>

<AtCard variant="inset">淡底嵌入卡</AtCard>
```

| prop | 說明 |
|---|---|
| `variant` | `default`（白底細邊）/ `inset`（淡底） |
| `title` | 快速標題；需要自訂用 `#header` slot |

---

## AtEmpty

```vue
<AtEmpty
  icon="school"
  title="尚無課程記錄"
  description="請至學生管理建立課程後再回來。"
>
  <template #action>
    <AtButton variant="secondary" @click="goStudents">前往學生管理</AtButton>
  </template>
</AtEmpty>
```

| prop | 說明 |
|---|---|
| `icon` | Material Symbols 名稱（**禁止 emoji**） |
| `title` | 情境（必填） |
| `description` | 下一步行動（空狀態公式見 `GUIDE_UI_COPY.md` §2） |

---

## AtMetric

```vue
<AtMetric label="今日待點名" :value="12" accent="var(--ds-warning)" />
<AtMetric label="本月收款" value="NT$ 128,400" delta="+8.2%" delta-tone="positive" />
```

| prop | 說明 |
|---|---|
| `label` | 小寫標籤 |
| `value` | 數字/金額（已套 `tabular-nums`） |
| `delta` | 變化值（可選） |
| `deltaTone` | `positive` / `negative` / `neutral` |
| `accent` | 底部類別色條（傳 token，如 `var(--ds-warning)`） |

---

## 禁止（對齊 `RULE_DESIGN_SYSTEM.md` §7）

- 在元件內寫死 `#hex`（一律 `var(--ds-*)`）
- gradient mesh、純黑 `#000`、裝飾 emoji
- 一張卡堆多層 `box-shadow`
- 金額/堂數不套 `tabular-nums`
