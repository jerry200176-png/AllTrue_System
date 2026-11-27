<template>
  <div class="tw:space-y-3">
    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
      <label class="tw:text-sm" for="sep-term">學期</label>
      <select id="sep-term" v-model="term" class="tw:h-9 tw:rounded-md tw:border tw:bg-background tw:px-2 tw:text-sm" @change="load">
        <option v-for="t in terms" :key="t.key" :value="t.key">{{ t.label }}</option>
      </select>
      <template v-if="isDirector && refAvailable">
        <label class="tw:ml-auto tw:text-sm" for="sep-city">參考縣市</label>
        <select id="sep-city" v-model="city" class="tw:h-9 tw:rounded-md tw:border tw:bg-background tw:px-2 tw:text-sm" :disabled="importing" @change="rememberCity">
          <option value="">全部（不自動帶入）</option>
          <option v-for="c in refIndex.cities" :key="c" :value="c">{{ c }}</option>
        </select>
        <Button size="sm" variant="outline" :disabled="importing || !importable.length" @click="importAll">
          帶入參考日期（{{ importable.length }} 格）
        </Button>
      </template>
    </div>
    <p v-if="isDirector && refAvailable" class="tw:text-xs tw:text-muted-foreground">
      參考日期來自 {{ refSource }}（目前只有{{ refIndex.cities.join('、') }}），請以學校公告為準。先選縣市，「帶入」才會把那個縣市、還沒登記、而且只有一個全校適用的參考日期的格子填好並儲存；其他的請自己按參考日期再按儲存。
    </p>
    <p v-if="importNote" role="status" class="tw:text-sm">{{ importNote }}</p>
    <p v-if="error" role="alert" class="tw:text-sm tw:text-destructive">{{ error }}</p>
    <p v-if="!loading && !schools.length" class="tw:text-sm tw:text-muted-foreground">還沒有填學校的學生。</p>
    <Card v-for="s in schools" :key="s.key" class="sep-card tw:gap-3 tw:py-4">
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
          <input v-model="draft(s, slot.seq).start_date" type="date" :disabled="!isDirector || busy(s, slot.seq)" :aria-label="`${s.school_name} ${slot.short} 開始`" class="tw:h-9 tw:min-w-0 tw:rounded-md tw:border tw:bg-background tw:px-2 tw:text-sm" />
          <input v-model="draft(s, slot.seq).end_date" type="date" :disabled="!isDirector || busy(s, slot.seq)" :aria-label="`${s.school_name} ${slot.short} 結束`" class="tw:h-9 tw:min-w-0 tw:rounded-md tw:border tw:bg-background tw:px-2 tw:text-sm" />
          <div v-if="isDirector && refOptions(s, slot.seq).length" class="tw:col-span-3 tw:flex tw:flex-wrap tw:items-center tw:gap-1 tw:text-xs tw:text-muted-foreground">
            參考
            <Button v-for="(o, i) in refOptions(s, slot.seq)" :key="i" size="sm" variant="outline" :disabled="busy(s, slot.seq)" @click="useReference(s, slot.seq, o)">
              {{ o.city }} {{ o.label }} {{ shortDate(o.start_date) }}–{{ shortDate(o.end_date) }}
            </Button>
          </div>
          <div v-if="isDirector" class="tw:col-span-3 tw:flex tw:justify-end tw:gap-2">
            <Button size="sm" :disabled="busy(s, slot.seq) || !dirty(s, slot.seq)" @click="save(s, slot)">儲存</Button>
            <Button v-if="draft(s, slot.seq).id" size="sm" variant="ghost" :disabled="busy(s, slot.seq)" @click="remove(s, slot)">清除</Button>
          </div>
        </div>
      </CardContent>
    </Card>
  </div>
</template>

<script setup>
import { trackedFetch as fetch } from '../lib/loadingBar';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Card, CardContent, CardHeader, CardTitle } from './ui/card';
import { currentTerm } from '../lib/schoolExamTerm';
import { buildReferenceIndex, CITIES, referenceFor } from '../lib/schoolExamReference';
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
const pending = reactive({});
const k = (s, seq) => `${s.key}|${seq}`;
const mutationKey = (s, seq) => JSON.stringify([String(props.branchId), term.value, s.key, seq]);
const busy = (s, seq) => !!pending[mutationKey(s, seq)];

function draft(s, seq) {
  return (drafts[k(s, seq)] ||= { id: null, start_date: '', end_date: '' });
}
function dirty(s, seq) {
  const d = draft(s, seq);
  const o = saved[k(s, seq)] || { start_date: '', end_date: '' };
  return !!d.start_date && !!d.end_date && (d.start_date !== o.start_date || d.end_date !== o.end_date);
}

// Reference dates (a large static table, loaded on demand for directors). They only fill a draft; saving goes through save().
const refIndex = ref(null);
const refSource = ref('');
const importing = ref(false);
const importNote = ref('');
const city = ref('');
const cityKey = () => `sep-ref-city:${props.branchId}`;
function recallCity() {
  try { city.value = CITIES.includes(localStorage.getItem(cityKey())) ? localStorage.getItem(cityKey()) : ''; } catch { /* storage blocked: keep what is chosen now */ }
}
function rememberCity() {
  try { localStorage.setItem(cityKey(), city.value); } catch { /* the choice just lasts for this visit */ }
}
const refBySchool = computed(() => Object.fromEntries(schools.value.map((s) => [
  s.key, referenceFor(refIndex.value, [s.school_name, ...(s.variants || [])], termObj.value.year, termObj.value.semester, city.value),
])));
const refOptions = (s, seq) => refBySchool.value[s.key]?.slots[seq] || [];
// The table covers one term. The city choice stays visible for that term even when the chosen city matches nothing,
// so the director can always change it back.
const refAvailable = computed(() => !!refIndex.value && schools.value.length > 0
  && refIndex.value.year === termObj.value.year && refIndex.value.semester === termObj.value.semester);
const shortDate = (d) => `${Number(d.slice(5, 7))}/${Number(d.slice(8, 10))}`;
function useReference(s, seq, o) {
  Object.assign(draft(s, seq), { start_date: o.start_date, end_date: o.end_date });
}
// Safe to fill without a look: the school is pinned to one city, the slot has exactly one reference date, that date
// is for every grade, and the slot is blank (nothing saved, nothing typed).
const blankWithOneChoice = (s, seq) => !!refBySchool.value[s.key]?.sure && refOptions(s, seq).length === 1 && refOptions(s, seq)[0].whole
  && !saved[k(s, seq)] && !draft(s, seq).start_date && !draft(s, seq).end_date;
const importable = computed(() => schools.value.flatMap((s) => slots.filter((slot) => blankWithOneChoice(s, slot.seq)).map((slot) => ({ s, slot }))));
async function importAll() {
  if (importing.value) return;
  importing.value = true;
  importNote.value = '';
  const todo = importable.value;
  const generation = loadSeq; // a campus or term switch reloads the page: stop, never touch what it shows now
  let done = 0;
  try {
    for (const { s, slot } of todo) {
      if (generation !== loadSeq) break;
      if (!blankWithOneChoice(s, slot.seq)) continue; // saved or typed into since the click
      useReference(s, slot.seq, refOptions(s, slot.seq)[0]);
      await save(s, slot);
      if (generation === loadSeq && saved[k(s, slot.seq)]) done += 1;
    }
  } finally {
    importing.value = false;
    if (generation === loadSeq) importNote.value = `已帶入 ${done} 格${done < todo.length ? `，${todo.length - done} 格沒有帶入` : ''}。`;
  }
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

let loadSeq = 0;
async function load() {
  const my = ++loadSeq;
  loading.value = true;
  error.value = '';
  importNote.value = '';
  recallCity();
  schools.value = [];
  for (const key of Object.keys(drafts)) delete drafts[key];
  for (const key of Object.keys(saved)) delete saved[key];
  const { year, semester } = termObj.value;
  try {
    const c = encodeURIComponent(props.branchId);
    const [sc, pe] = await Promise.all([
      api(`/exam-periods/schools?campus_id=${c}`),
      api(`/exam-periods?campus_id=${c}&academic_year=${year}&semester=${semester}`),
    ]);
    if (my !== loadSeq) return; // a newer campus/term load superseded this one
    schools.value = sc.data || [];
    for (const key of Object.keys(drafts)) delete drafts[key];
    for (const key of Object.keys(saved)) delete saved[key];
    for (const p of pe.data || []) {
      if (p.grade_ids) continue; // grade-scoped rows are edited via API only
      const key = `${p.school_key}|${p.seq}`;
      const row = { id: p.id, start_date: p.start_date, end_date: p.end_date };
      drafts[key] = { ...row };
      saved[key] = { ...row };
    }
  } catch (e) {
    if (my === loadSeq) error.value = e.message || '載入失敗';
  } finally {
    if (my === loadSeq) loading.value = false;
  }
}

let mutationSeq = 0;
function beginMutation(s, slot) {
  const key = mutationKey(s, slot.seq);
  if (pending[key]) return null;
  const id = ++mutationSeq;
  const generation = loadSeq;
  pending[key] = id;
  return {
    current: () => generation === loadSeq && key === mutationKey(s, slot.seq) && pending[key] === id,
    finish: () => { if (pending[key] === id) delete pending[key]; },
  };
}

async function save(s, slot) {
  const request = beginMutation(s, slot);
  if (!request) return;
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
    if (!request.current()) return;
    d.id = res.data.id;
    saved[k(s, slot.seq)] = { start_date: body.start_date, end_date: body.end_date };
  } catch (e) {
    if (request.current()) error.value = e.message || '儲存失敗';
  } finally {
    request.finish();
  }
}

async function remove(s, slot) {
  const request = beginMutation(s, slot);
  if (!request) return;
  const d = draft(s, slot.seq);
  error.value = '';
  try {
    await api(`/exam-periods/${d.id}`, { method: 'DELETE' });
    if (!request.current()) return;
    Object.assign(d, { id: null, start_date: '', end_date: '' });
    delete saved[k(s, slot.seq)];
  } catch (e) {
    if (request.current()) error.value = e.message || '清除失敗';
  } finally {
    request.finish();
  }
}

onMounted(load);
onMounted(async () => {
  if (!props.isDirector) return; // teachers cannot save, so they never download the table
  try {
    const data = (await import('../lib/schoolExamReferenceData.generated.js')).default;
    refSource.value = data.source;
    refIndex.value = buildReferenceIndex(data);
  } catch {
    refIndex.value = null; // the page works without reference dates
  }
});
watch(() => props.branchId, load);
</script>
