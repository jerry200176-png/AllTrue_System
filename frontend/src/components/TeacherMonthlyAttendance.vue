<template>
  <section class="tma" aria-labelledby="tma-title">
    <div class="tma-toolbar">
      <h3 id="tma-title" class="tma-title">{{ title }}</h3>
      <input v-model="month" type="month" class="att-date-input" aria-label="選擇月份" @change="load" />
      <select v-if="teachers.length > 1" v-model="teacherId" class="att-date-input" aria-label="選擇老師">
        <option v-for="t in teachers" :key="t.teacher_id" :value="t.teacher_id">
          {{ t.teacher_name }}{{ t.totals.anomaly_days ? `（${t.totals.anomaly_days} 天只刷一次）` : '' }}
        </option>
      </select>
      <label class="tma-check"><input v-model="onlyAnomaly" type="checkbox" /> 只看只刷一次</label>
      <button type="button" class="ghost small" @click="print">列印</button>
    </div>

    <div v-if="loading" class="att-empty enterprise-empty enterprise-loading" role="status" aria-live="polite">載入中…</div>
    <div v-else-if="error" class="att-empty enterprise-empty" role="alert">{{ error }}</div>
    <div v-else-if="!current" class="att-empty enterprise-empty" role="status">這個月沒有刷卡紀錄</div>
    <template v-else>
      <p class="tma-totals">
        {{ current.teacher_name }}：出勤 {{ current.totals.days_present }} 天、總工時 {{ hours(current.totals.minutes) }} 小時、只刷一次 {{ current.totals.anomaly_days }} 天、修正 {{ current.totals.corrected_days }} 天
      </p>
      <div class="att-table-scroll">
        <table>
          <thead>
            <tr><th>日期</th><th>跑校</th><th>上班</th><th>下班</th><th>工時(時)</th><th>註記</th></tr>
          </thead>
          <tbody>
            <tr v-for="d in rows" :key="d.date" :class="{ 'tma-anomaly': d.anomaly, 'tma-empty': !d.sign_in && !d.anomaly }">
              <td>{{ d.label }}</td>
              <td>{{ d.run_school ? '是' : '' }}</td>
              <td>{{ d.sign_in ?? '' }}</td>
              <td>{{ d.sign_out ?? '' }}</td>
              <td>{{ d.minutes != null ? hours(d.minutes) : '' }}</td>
              <td>{{ d.note }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { supabase } from '../supabase';

const props = defineProps({
  campusId: { type: [Number, String], default: null },
  title: { type: String, default: '月出勤表' },
});

const now = new Date();
const month = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
const teachers = ref([]);
const teacherId = ref(null);
const onlyAnomaly = ref(false);
const loading = ref(false);
const error = ref('');

const current = computed(() => teachers.value.find(t => t.teacher_id === teacherId.value) ?? null);
const rows = computed(() => (current.value?.days ?? []).filter(d => !onlyAnomaly.value || d.anomaly));
const hours = m => Math.round(m / 60 * 100) / 100;

let seq = 0;
async function load() {
  const my = ++seq;
  loading.value = true;
  error.value = '';
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const campus = props.campusId ? `&campus_id=${props.campusId}` : '';
    const res = await fetch(`/api/v1/teacher-attendance/monthly?year_month=${month.value}${campus}`, {
      headers: { Authorization: `Bearer ${session?.access_token}`, Accept: 'application/json' },
    });
    if (!res.ok) throw new Error(String(res.status));
    const data = await res.json();
    if (my !== seq) return;
    teachers.value = data.teachers ?? [];
    if (!current.value) teacherId.value = teachers.value[0]?.teacher_id ?? null;
  } catch {
    if (my !== seq) return;
    teachers.value = [];
    error.value = '讀取失敗，請稍後再試';
  } finally {
    if (my === seq) loading.value = false;
  }
}

async function print() {
  onlyAnomaly.value = false; // 列印一定是整月完整紀錄（§30 副本）
  await nextTick();
  window.print();
}

watch(() => props.campusId, load);
onMounted(load);
</script>

<style scoped>
.tma-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
.tma-title { margin: 0 auto 0 0; font-size: 1rem; }
.tma-check { display: inline-flex; gap: 4px; align-items: center; font-size: 0.875rem; }
.tma-totals { margin: 0 0 8px; font-weight: 600; }
.tma-anomaly td { color: var(--ds-warning, #b45309); }
.tma-empty td { color: var(--ds-text-muted, #9ca3af); }
@media print {
  .tma-toolbar select, .tma-toolbar input, .tma-toolbar button, .tma-check { display: none; }
}
</style>
