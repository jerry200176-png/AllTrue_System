<template>
  <div class="tw:space-y-3">
    <div class="tw:flex tw:items-center tw:gap-2">
      <label class="tw:text-sm" for="sep-term">學期</label>
      <select id="sep-term" v-model="term" class="tw:h-9 tw:rounded-md tw:border tw:bg-background tw:px-2 tw:text-sm" @change="load">
        <option v-for="t in terms" :key="t.key" :value="t.key">{{ t.label }}</option>
      </select>
    </div>
    <p v-if="error" role="alert" class="tw:text-sm tw:text-destructive">{{ error }}</p>
    <p v-if="!loading && !schools.length" class="tw:text-sm tw:text-muted-foreground">還沒有填學校的學生。</p>
    <Card v-for="s in schools" :key="s.school_name" class="sep-card tw:gap-3 tw:py-4">
      <CardHeader class="tw:px-4">
        <CardTitle class="tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:text-base">
          {{ s.school_name }}
          <Badge variant="secondary">{{ s.student_count }} 人</Badge>
        </CardTitle>
        <p v-if="s.variants.length > 1" class="tw:text-xs tw:text-muted-foreground">可能同校：{{ s.variants.join('、') }}</p>
      </CardHeader>
      <CardContent class="tw:space-y-2 tw:px-4">
        <div v-for="slot in slots" :key="slot.seq" class="sep-row tw:grid tw:grid-cols-[4.5rem_1fr_1fr] tw:items-center tw:gap-2">
          <span class="tw:text-sm">{{ slot.short }}</span>
          <input v-model="draft(s, slot.seq).start_date" type="date" :disabled="!isDirector" :aria-label="`${s.school_name} ${slot.short} 開始`" class="tw:h-9 tw:min-w-0 tw:rounded-md tw:border tw:bg-background tw:px-2 tw:text-sm" />
          <input v-model="draft(s, slot.seq).end_date" type="date" :disabled="!isDirector" :aria-label="`${s.school_name} ${slot.short} 結束`" class="tw:h-9 tw:min-w-0 tw:rounded-md tw:border tw:bg-background tw:px-2 tw:text-sm" />
          <div v-if="isDirector" class="tw:col-span-3 tw:flex tw:justify-end tw:gap-2">
            <Button size="sm" :disabled="!dirty(s, slot.seq)" @click="save(s, slot)">儲存</Button>
            <Button v-if="draft(s, slot.seq).id" size="sm" variant="ghost" @click="remove(s, slot)">清除</Button>
          </div>
        </div>
      </CardContent>
    </Card>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Card, CardContent, CardHeader, CardTitle } from './ui/card';
import { currentTerm } from '../lib/schoolExamTerm';
import { supabase } from '../supabase';

const props = defineProps({
  branchId: { type: [Number, String], required: true },
  isDirector: { type: Boolean, default: false },
});

const slots = [
  { seq: 1, label: '第一次段考', short: '段考一' },
  { seq: 2, label: '第二次段考', short: '段考二' },
  { seq: 3, label: '期末考', short: '期末' },
];

const now = currentTerm();
const terms = [0, 1, 2].map((i) => {
  const idx = now.year * 2 + (now.semester - 1) + 1 - i;
  const year = Math.floor(idx / 2);
  const semester = (idx % 2) + 1;
  return { key: `${year}-${semester}`, year, semester, label: `${year} 學年第 ${semester} 學期` };
});
const term = ref(`${now.year}-${now.semester}`);
const termObj = computed(() => terms.find((t) => t.key === term.value));

const loading = ref(false);
const error = ref('');
const schools = ref([]);
const saved = reactive({});
const drafts = reactive({});
const k = (s, seq) => `${s.school_name}|${seq}`;

function draft(s, seq) {
  return (drafts[k(s, seq)] ||= { id: null, start_date: '', end_date: '' });
}
function dirty(s, seq) {
  const d = draft(s, seq);
  const o = saved[k(s, seq)] || { start_date: '', end_date: '' };
  return !!d.start_date && !!d.end_date && (d.start_date !== o.start_date || d.end_date !== o.end_date);
}

async function api(path, options = {}) {
  const { data: { session } } = await supabase.auth.getSession();
  const token = session?.access_token;
  const res = await fetch(`/api/v1${path}`, {
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.message || `HTTP ${res.status}`);
  return body;
}

async function load() {
  loading.value = true;
  error.value = '';
  const { year, semester } = termObj.value;
  try {
    const c = encodeURIComponent(props.branchId);
    const [sc, pe] = await Promise.all([
      api(`/exam-periods/schools?campus_id=${c}`),
      api(`/exam-periods?campus_id=${c}&academic_year=${year}&semester=${semester}`),
    ]);
    schools.value = sc.data || [];
    for (const key of Object.keys(drafts)) delete drafts[key];
    for (const key of Object.keys(saved)) delete saved[key];
    for (const p of pe.data || []) {
      if (p.grade_ids) continue; // grade-scoped rows are edited via API only
      const key = `${p.school_name}|${p.seq}`;
      const row = { id: p.id, start_date: p.start_date, end_date: p.end_date };
      drafts[key] = { ...row };
      saved[key] = { ...row };
    }
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

async function save(s, slot) {
  const d = draft(s, slot.seq);
  error.value = '';
  try {
    const body = { start_date: d.start_date, end_date: d.end_date };
    const res = d.id
      ? await api(`/exam-periods/${d.id}`, { method: 'PATCH', body: JSON.stringify(body) })
      : await api('/exam-periods', {
        method: 'POST',
        body: JSON.stringify({
          ...body, campus_id: Number(props.branchId), school_name: s.school_name, label: slot.label,
          academic_year: termObj.value.year, semester: termObj.value.semester, seq: slot.seq,
        }),
      });
    d.id = res.data.id;
    saved[k(s, slot.seq)] = { start_date: d.start_date, end_date: d.end_date };
  } catch (e) {
    error.value = e.message || '儲存失敗';
  }
}

async function remove(s, slot) {
  const d = draft(s, slot.seq);
  error.value = '';
  try {
    await api(`/exam-periods/${d.id}`, { method: 'DELETE' });
    Object.assign(d, { id: null, start_date: '', end_date: '' });
    delete saved[k(s, slot.seq)];
  } catch (e) {
    error.value = e.message || '清除失敗';
  }
}

onMounted(load);
</script>
