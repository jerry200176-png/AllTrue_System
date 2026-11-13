<template>
  <div class="ctp tw:space-y-3">
    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
      <select v-model="filters.subject" aria-label="科目" class="ctp-select" @change="load">
        <option value="">全部科目</option>
        <option v-for="s in SUBJECTS" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>
      <select v-model="filters.grade" aria-label="年級" class="ctp-select" @change="load">
        <option value="">全部年級</option>
        <option v-for="g in GRADES" :key="g.value" :value="g.value">{{ g.label }}</option>
      </select>
      <select v-model="filters.status" aria-label="狀態" class="ctp-select" @change="load">
        <option value="">草稿與使用中</option>
        <option value="draft">草稿</option>
        <option value="active">使用中</option>
        <option value="archived">已封存</option>
      </select>
      <Button v-if="isDirector" size="sm" class="tw:ml-auto" @click="startCreate">新增範本</Button>
    </div>
    <p v-if="error" role="alert" class="tw:text-sm tw:text-destructive">{{ error }}</p>

    <Card v-if="editor" class="ctp-editor tw:gap-3 tw:py-4">
      <CardHeader class="tw:px-4">
        <CardTitle class="tw:text-base">{{ editor.id ? (isDirector ? '編輯範本' : '範本內容') : '新增範本' }}</CardTitle>
      </CardHeader>
      <CardContent class="tw:space-y-3 tw:px-4">
        <div class="tw:grid tw:grid-cols-1 tw:gap-2 sm:tw:grid-cols-2">
          <label class="ctp-field">範本名稱
            <input v-model.trim="editor.title" maxlength="120" :disabled="!isDirector" class="ctp-input" placeholder="例如：七年級上學期數學" />
          </label>
          <label class="ctp-field">出版社（選填）
            <input v-model.trim="editor.publisher" maxlength="40" :disabled="!isDirector" class="ctp-input" />
          </label>
          <label class="ctp-field">科目
            <select v-model="editor.subject" :disabled="!isDirector || !!editor.id" class="ctp-input">
              <option v-for="s in SUBJECTS" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </label>
          <label class="ctp-field">年級
            <select v-model="editor.grade" :disabled="!isDirector || !!editor.id" class="ctp-input">
              <option v-for="g in GRADES" :key="g.value" :value="g.value">{{ g.label }}</option>
            </select>
          </label>
          <label class="ctp-field">狀態
            <select v-model="editor.status" :disabled="!isDirector" class="ctp-input">
              <option value="draft">草稿</option>
              <option value="active">使用中</option>
              <option value="archived">已封存</option>
            </select>
          </label>
        </div>

        <div class="tw:space-y-2">
          <p class="tw:text-sm tw:font-medium">單元（由上到下就是上課順序）</p>
          <p v-if="!editor.units.length" class="tw:text-sm tw:text-muted-foreground">還沒有單元。</p>
          <div v-for="(u, i) in editor.units" :key="u.uid" class="ctp-unit tw:grid tw:grid-cols-[1.5rem_1fr] tw:items-center tw:gap-2">
            <span class="tw:text-sm tw:text-muted-foreground">{{ i + 1 }}</span>
            <input v-model.trim="u.title" maxlength="120" :disabled="!isDirector" :aria-label="`單元 ${i + 1} 名稱`" class="ctp-input" placeholder="單元名稱" />
            <span />
            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
              <select v-model="u.exam_sequence" :disabled="!isDirector" :aria-label="`單元 ${i + 1} 對應段考`" class="ctp-select">
                <option :value="null">不對應段考</option>
                <option v-for="e in EXAMS" :key="e.seq" :value="e.seq">{{ e.label }}</option>
              </select>
              <template v-if="isDirector">
                <Button size="sm" variant="outline" :disabled="i === 0" :aria-label="`單元 ${i + 1} 上移`" @click="move(i, -1)">上移</Button>
                <Button size="sm" variant="outline" :disabled="i === editor.units.length - 1" :aria-label="`單元 ${i + 1} 下移`" @click="move(i, 1)">下移</Button>
                <Button size="sm" variant="ghost" :aria-label="`移除單元 ${i + 1}`" @click="editor.units.splice(i, 1)">移除</Button>
              </template>
            </div>
          </div>
          <Button v-if="isDirector" size="sm" variant="outline" @click="addUnit">加單元</Button>
        </div>

        <p v-if="formError" role="alert" class="tw:text-sm tw:text-destructive">{{ formError }}</p>
        <div class="tw:flex tw:justify-end tw:gap-2">
          <Button size="sm" variant="ghost" @click="editor = null">{{ isDirector ? '取消' : '關閉' }}</Button>
          <Button v-if="isDirector" size="sm" :disabled="saving" @click="save">儲存</Button>
        </div>
      </CardContent>
    </Card>

    <div v-if="loading" class="tw:space-y-2" aria-busy="true">
      <div v-for="n in 2" :key="n" class="tw:h-16 tw:animate-pulse tw:rounded-md tw:bg-muted" />
    </div>
    <p v-else-if="!error && !templates.length" class="tw:text-sm tw:text-muted-foreground">
      還沒有教材範本。{{ isDirector ? '按「新增範本」建立第一份。' : '' }}
    </p>
    <Card v-for="t in templates" :key="t.id" class="ctp-card tw:gap-2 tw:py-4">
      <CardHeader class="tw:px-4">
        <CardTitle class="tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:text-base">
          {{ t.title }}
          <Badge variant="secondary">{{ subjectLabel(t.subject) }}</Badge>
          <Badge variant="secondary">{{ gradeLabel(t.grade) }}</Badge>
          <Badge :variant="t.status === 'active' ? 'default' : 'outline'">{{ STATUS_LABELS[t.status] }}</Badge>
        </CardTitle>
        <p class="tw:text-xs tw:text-muted-foreground">
          {{ t.publisher || '未填出版社' }}・{{ t.units_count }} 個單元
        </p>
      </CardHeader>
      <CardContent class="tw:flex tw:justify-end tw:gap-2 tw:px-4">
        <Button size="sm" variant="outline" @click="openEditor(t)">{{ isDirector ? '編輯' : '查看' }}</Button>
        <Button v-if="isDirector && t.status !== 'archived'" size="sm" variant="ghost" @click="archive(t)">封存</Button>
      </CardContent>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Card, CardContent, CardHeader, CardTitle } from './ui/card';
import { GRADES, SUBJECTS } from '../lib/constants';
import { supabase } from '../supabase';

const props = defineProps({
  branchId: { type: [Number, String], required: true },
  isDirector: { type: Boolean, default: false },
});

const EXAMS = [
  { seq: 1, label: '段考一' },
  { seq: 2, label: '段考二' },
  { seq: 3, label: '期末' },
];
const STATUS_LABELS = { draft: '草稿', active: '使用中', archived: '已封存' };
const subjectLabel = (v) => SUBJECTS.find((s) => s.value === v)?.label || v;
const gradeLabel = (v) => GRADES.find((g) => g.value === v)?.label.replace(/\s*\(.*\)$/, '') || v;

const filters = reactive({ subject: '', grade: '', status: '' });
const loading = ref(false);
const error = ref('');
const templates = ref([]);
const editor = ref(null);
const formError = ref('');
const saving = ref(false);
let uid = 0;
const newUnit = (u = {}) => ({ uid: ++uid, title: u.title || '', exam_sequence: u.exam_sequence ?? null, note: u.note || '' });

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
  const qs = new URLSearchParams({ campus_id: String(props.branchId) });
  for (const [k, v] of Object.entries(filters)) if (v) qs.set(k, v);
  try {
    const res = await api(`/curriculum-templates?${qs}`);
    if (my === loadSeq) templates.value = res.data || [];
  } catch (e) {
    if (my === loadSeq) {
      templates.value = [];
      error.value = e.message || '載入失敗';
    }
  } finally {
    if (my === loadSeq) loading.value = false;
  }
}

function startCreate() {
  formError.value = '';
  editor.value = {
    id: null, title: '', publisher: '', status: 'draft', units: [],
    subject: filters.subject || SUBJECTS[0].value, grade: filters.grade || GRADES[0].value,
  };
}

async function openEditor(t) {
  formError.value = '';
  error.value = '';
  try {
    const { data } = await api(`/curriculum-templates/${t.id}`);
    editor.value = {
      id: data.id, title: data.title, publisher: data.publisher || '', status: data.status,
      subject: data.subject, grade: data.grade, units: (data.units || []).map(newUnit),
    };
  } catch (e) {
    error.value = e.message || '載入失敗';
  }
}

function addUnit() {
  editor.value.units.push(newUnit());
}

function move(i, delta) {
  const list = editor.value.units;
  const j = i + delta;
  if (j < 0 || j >= list.length) return;
  [list[i], list[j]] = [list[j], list[i]];
}

async function save() {
  const e = editor.value;
  if (!e || saving.value) return;
  formError.value = '';
  if (!e.title) {
    formError.value = '請填範本名稱';
    return;
  }
  if (e.units.some((u) => !u.title)) {
    formError.value = '單元名稱不能空白';
    return;
  }
  saving.value = true;
  try {
    const meta = { title: e.title, publisher: e.publisher || null, status: e.status };
    let id = e.id;
    if (id) {
      await api(`/curriculum-templates/${id}`, { method: 'PATCH', body: JSON.stringify(meta) });
    } else {
      const res = await api('/curriculum-templates', {
        method: 'POST',
        body: JSON.stringify({ ...meta, campus_id: Number(props.branchId), subject: e.subject, grade: e.grade }),
      });
      id = res.data.id;
      e.id = id; // a retry after a failed units save must not create a second template
    }
    await api(`/curriculum-templates/${id}/units`, {
      method: 'PUT',
      body: JSON.stringify({ units: e.units.map((u) => ({ title: u.title, note: u.note || null, exam_sequence: u.exam_sequence })) }),
    });
    editor.value = null;
    await load();
  } catch (err) {
    formError.value = err.message || '儲存失敗';
  } finally {
    saving.value = false;
  }
}

async function archive(t) {
  error.value = '';
  try {
    await api(`/curriculum-templates/${t.id}/archive`, { method: 'POST' });
    if (editor.value?.id === t.id) editor.value = null;
    await load();
  } catch (e) {
    error.value = e.message || '封存失敗';
  }
}

onMounted(load);
watch(() => props.branchId, () => {
  editor.value = null;
  load();
});
</script>

<style scoped>
.ctp-select,
.ctp-input { height: 2.25rem; min-width: 0; border-radius: 0.375rem; border: 1px solid var(--border); background: var(--background, transparent); padding: 0 0.5rem; font-size: 0.875rem; }
.ctp-field { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.875rem; }
.ctp-unit .ctp-input { width: 100%; }
</style>
