<template>
  <section class="sbl" aria-label="學生帳務清單">
    <AtInlineAlert v-if="error" tone="danger" title="帳務資料載入失敗" data-testid="sbl-error">
      {{ error }}。未載入前不顯示金額，避免誤以為都已繳清。
      <template #action>
        <AtButton variant="secondary" size="sm" shape="rect" :loading="loading" data-testid="sbl-retry" @click="$emit('retry')">重試</AtButton>
      </template>
    </AtInlineAlert>
    <div v-else-if="pending" class="sbl__loading" data-testid="sbl-loading" role="status" aria-label="載入帳務資料中">
      <AtSkeleton :rows="6" height="28px" />
    </div>
    <template v-if="!pending && !(error && !alertRows.length)">
    <div class="sbl__summary" data-testid="sbl-summary">
      <span>到今天未收 <strong>{{ money(totalNow) }}</strong></span>
      <span>未繳學生 <strong>{{ owingCount }}</strong> 位</span>
    </div>
    <div class="sbl__tools">
      <input v-model="query" type="search" placeholder="搜尋學生" aria-label="搜尋學生" data-testid="sbl-search" />
      <div class="sbl__chips" role="group" aria-label="篩選">
        <button
          v-for="(f, key) in STUDENT_FILTERS"
          :key="key"
          type="button"
          :class="['sbl__chip', { 'is-on': filter === key }]"
          :aria-pressed="filter === key"
          :data-testid="`sbl-filter-${key}`"
          @click="filter = key"
        >{{ f.label }}</button>
      </div>
    </div>
    <p v-if="!visible.length" class="sbl__empty">沒有符合的學生</p>
    <ul v-else class="sbl__list">
      <li v-for="r in visible" :key="r.student_id">
        <button type="button" class="sbl__row" :data-testid="`sbl-row-${r.student_id}`" @click="$emit('open', r)">
          <span class="sbl__name">{{ r.student_name }}</span>
          <span class="sbl__now" :class="{ due: r.owed_now > 0 }">
            {{ r.owed_now > 0 ? `未繳 ${money(r.owed_now)}` : '目前沒有未繳' }}
          </span>
          <span v-if="r.overdue_days > 0" class="sbl__tag tag-danger">逾期 {{ r.overdue_days }} 天</span>
          <span v-if="r.waiting_confirm > 0" class="sbl__tag tag-warn">家長說繳了，等你確認</span>
          <span v-if="r.owed_later > 0" class="sbl__muted">之後還會到期 {{ money(r.owed_later) }}</span>
          <span v-if="r.open_contracts > 1" class="sbl__muted">{{ r.open_contracts }} 筆合約未繳</span>
          <span v-if="r.last_paid_at" class="sbl__muted">最近繳費 {{ r.last_paid_at.replaceAll('-', '/') }}</span>
        </button>
      </li>
    </ul>
    </template>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import AtButton from '../design-system/AtButton.vue';
import AtInlineAlert from '../design-system/AtInlineAlert.vue';
import AtSkeleton from '../design-system/AtSkeleton.vue';
import { buildStudentBillingRows, STUDENT_FILTERS } from '../../lib/studentBillingRows.js';

const props = defineProps({
  alertRows: { type: Array, default: () => [] },
  students: { type: Array, default: () => [] },
  today: { type: String, default: undefined },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['open', 'retry']);
// No alert data yet: zeros would read as "all paid".
const pending = computed(() => props.loading && !props.alertRows.length && !props.error);

const query = ref('');
const filter = ref('all');
const rows = computed(() => buildStudentBillingRows(props.alertRows, props.students, props.today));
const totalNow = computed(() => rows.value.reduce((s, r) => s + r.owed_now, 0));
const owingCount = computed(() => rows.value.filter((r) => r.owed_now > 0).length);
const visible = computed(() => rows.value.filter((r) => STUDENT_FILTERS[filter.value].test(r)
  && (!query.value.trim() || r.student_name.includes(query.value.trim()))));
const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
</script>

<style scoped>
.sbl{display:grid;gap:10px}
.sbl__summary{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:14px}
.sbl__summary strong{font-size:18px}
.sbl__tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.sbl__tools input{flex:1 1 180px;min-height:36px;padding:6px 10px;font:inherit}
.sbl__chips{display:flex;flex-wrap:wrap;gap:6px}
.sbl__chip{border:1px solid var(--ds-border);background:var(--ds-canvas);border-radius:999px;padding:4px 12px;font-size:13px;cursor:pointer;min-height:32px}
.sbl__chip.is-on{background:var(--ds-primary-wash,var(--ds-canvas-soft));border-color:var(--ds-primary,var(--ds-ink-mute));font-weight:700}
.sbl__list{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.sbl__row{width:100%;display:flex;flex-wrap:wrap;align-items:center;gap:4px 12px;text-align:left;border:1px solid var(--ds-border);border-radius:10px;background:var(--surface,var(--ds-canvas));padding:10px 12px;cursor:pointer;font:inherit;color:inherit}
.sbl__row:hover{border-color:var(--ds-ink-mute)}
.sbl__name{font-weight:700;min-width:6em}
.sbl__now{font-variant-numeric:tabular-nums}
.sbl__now.due{color:var(--ds-danger);font-weight:700}
.sbl__tag{border-radius:6px;padding:1px 7px;font-size:12px;font-weight:700}
.tag-danger{background:var(--ds-danger-wash);color:var(--ds-danger)}
.tag-warn{background:var(--ds-warning-wash);color:var(--ds-warning)}
.sbl__muted{font-size:12px;color:var(--ds-ink-mute)}
.sbl__empty{color:var(--ds-ink-mute)}
</style>
