<script setup>
// #4928 step 1: staff read-only learning timeline (evaluations + assessment scores + sign-ins), newest first.
import { computed, ref, watch } from 'vue';
import { authedFetch } from '../../lib/authedFetch';
import AtBadge from '../design-system/AtBadge.vue';
import AtButton from '../design-system/AtButton.vue';

const props = defineProps({ show: Boolean, studentId: { type: [Number, String], default: null }, studentName: { type: String, default: '' } });
const emit = defineEmits(['close']);

const items = ref([]);
const subject = ref('');
const loading = ref(false);
const error = ref('');

const TYPE_LABEL = { evaluation: '評量', assessment: '測驗', attendance: '出席' };
const STATUS_LABEL = { approved: '已核准', pending: '待審', submitted: '已送出', reviewed: '已覆核', rejected: '已退回', changes_requested: '待修改' };
const subjects = computed(() => [...new Set(items.value.map((i) => i.subject).filter(Boolean))]);
const visible = computed(() => (subject.value ? items.value.filter((i) => i.subject === subject.value) : items.value));

// Up to 5 badges per row: type, subject, attendance, approval/status, score.
function badges(i) {
  const out = [{ label: TYPE_LABEL[i.type], tone: 'info' }];
  if (i.subject) out.push({ label: i.subject, tone: 'neutral' });
  if (i.attendance) out.push({ label: i.attendance, tone: i.attendance === '到班' || i.attendance === '遲到' ? 'success' : 'warning' });
  if (i.approval_status) out.push({ label: STATUS_LABEL[i.approval_status] || i.approval_status, tone: 'neutral' });
  if (i.type === 'assessment') out.push({ label: `${i.score}/${i.max_score}`, tone: 'info' });
  return out.slice(0, 5);
}

function detail(i) {
  if (i.type === 'assessment') return i.title || '';
  return [i.homework_status && `作業：${i.homework_status}`, i.quiz && `小考：${i.quiz}`, i.next_week_scope && `下週範圍：${i.next_week_scope}`]
    .filter(Boolean).join('　');
}

let seq = 0; // only the latest request may write state
async function load() {
  const mine = ++seq;
  loading.value = true; error.value = ''; subject.value = '';
  try {
    const res = await authedFetch(`/api/v1/students/${props.studentId}/learning-timeline`, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(String(res.status));
    const body = await res.json();
    if (mine === seq) items.value = body.items || [];
  } catch {
    if (mine === seq) { items.value = []; error.value = '載入失敗，請稍後再試'; }
  } finally {
    if (mine === seq) loading.value = false;
  }
}

watch(() => [props.show, props.studentId], () => { if (props.show && props.studentId) load(); }, { immediate: true });
</script>

<template>
<div v-if="show" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="learning-timeline-title" @click.self="emit('close')">
  <div class="modal" style="width: 640px; max-width: calc(100vw - 32px);">
    <div class="modal-header-row">
      <h3 id="learning-timeline-title">{{ studentName }} 學習歷程</h3>
      <AtButton shape="rect" size="sm" variant="ghost" @click="emit('close')">關閉</AtButton>
    </div>
    <p class="hint">唯讀；整理既有的評量、測驗成績與出席，新到舊。</p>
    <label v-if="subjects.length" class="hint">
      科目
      <select v-model="subject" data-testid="timeline-subject">
        <option value="">全部</option>
        <option v-for="s in subjects" :key="s" :value="s">{{ s }}</option>
      </select>
    </label>
    <div v-if="loading" class="empty-text">載入中…</div>
    <div v-else-if="error" class="error-text">{{ error }}</div>
    <div v-else-if="!visible.length" class="empty-text" data-testid="timeline-empty">尚無學習紀錄</div>
    <ul v-else class="timeline-list" style="list-style: none; padding: 0; max-height: 60vh; overflow: auto;">
      <li v-for="i in visible" :key="`${i.type}-${i.id}`" data-testid="timeline-row" style="padding: 8px 0; border-bottom: 1px solid var(--ds-border);">
        <div><strong>{{ i.date }}</strong></div>
        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin: 4px 0;">
          <AtBadge v-for="(b, n) in badges(i)" :key="n" :tone="b.tone" :label="b.label" />
        </div>
        <div v-if="detail(i)" class="hint">{{ detail(i) }}</div>
      </li>
    </ul>
  </div>
</div>
</template>
