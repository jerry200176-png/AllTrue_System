<template>
  <article class="slip" :class="`slip--${slip.type}`">
    <header class="slip-top">
      <div class="slip-brand">
        <img :src="logoUrl" alt="" class="slip-logo" />
        <div>
          <div class="slip-brand-name">{{ BRAND_TITLE }}</div>
          <div v-if="slip.campus_name" class="slip-brand-campus">{{ slip.campus_name }}</div>
        </div>
      </div>
      <div class="slip-doc">
        <div class="slip-doc-title">{{ slip.title }}</div>
        <div class="slip-doc-ref">{{ slip.ref_label }}</div>
      </div>
    </header>

    <section class="slip-hero">
      <div class="slip-hero-amount">
        <div class="slip-label">{{ slip.amount_label }}</div>
        <div class="slip-amount">{{ formatAmount(slip.amount) }}</div>
        <div v-if="slip.sub_amounts" class="slip-sub">{{ slip.sub_amounts }}</div>
      </div>
      <div v-if="slip.due" class="slip-hero-due">
        <div class="slip-label">{{ slip.due.label }}</div>
        <div class="slip-due">{{ slip.due.value }}</div>
        <div v-if="slip.due.hint" class="slip-overdue">{{ slip.due.hint }}</div>
      </div>
    </section>

    <dl class="slip-meta">
      <div v-for="m in slip.meta" :key="m.label">
        <dt>{{ m.label }}</dt>
        <dd>{{ m.value }}</dd>
      </div>
    </dl>

    <table v-if="slip.items.length" class="slip-items">
      <thead>
        <tr><th>項目</th><th>期間／堂數</th><th class="num">金額</th></tr>
      </thead>
      <tbody>
        <tr v-for="(item, i) in slip.items" :key="i">
          <td>{{ item.description }}</td>
          <td class="muted">{{ item.period }}</td>
          <td class="num">{{ item.amount }}</td>
        </tr>
      </tbody>
    </table>

    <section v-if="slip.sessions.length" class="slip-sessions">
      <div class="slip-section-title">
        本期上課日期
        <span class="slip-chip">共 {{ slip.sessions.length }} 堂</span>
        <span v-if="attendedCount" class="slip-chip slip-chip--done">已上 {{ attendedCount }} 堂</span>
      </div>
      <ol class="slip-session-list">
        <li v-for="(s, i) in slip.sessions" :key="s.class_session_id || i">
          <span class="slip-session-no">{{ i + 1 }}</span>
          <span class="slip-session-date">{{ formatSessionDate(s.date) }}</span>
          <span class="slip-session-time">{{ formatTime(s) }}</span>
          <span v-if="hasSubject" class="slip-session-subject">{{ s.subject }}</span>
          <span class="slip-status" :class="`is-${statusTone(s.status)}`">{{ STATUS_ZH[s.status] || s.status || '排定' }}</span>
        </li>
      </ol>
    </section>

    <section v-if="slip.note" class="slip-note">
      <div class="slip-label">備註</div>
      <p>{{ slip.note }}</p>
    </section>

    <footer class="slip-foot">
      <span>此通知單僅供繳費確認用，如已繳費請忽略。</span>
      <span>{{ formatBrandTitle(slip.campus_name) }}・{{ todayLabel }}</span>
    </footer>
  </article>
</template>

<script setup>
import { computed } from 'vue';
// Cropped 256px mark: the full logo.png has wide white margins.
import logoUrl from '../assets/slip-logo.png';

// Pure view of one payment slip (invoice slip-data or tuition-slip payload).
// PaymentSlipModal fetches the data and exports this element as the PNG.
const props = defineProps({
  data: { type: Object, required: true },
});

function formatAmount(n) {
  return 'NT$ ' + Number(n || 0).toLocaleString('zh-TW');
}
function formatDate(d) {
  if (!d) return '—';
  return String(d).slice(0, 10).replace(/-/g, '/');
}
const WEEKDAY = ['日', '一', '二', '三', '四', '五', '六'];
function formatSessionDate(d) {
  const day = new Date(`${String(d).slice(0, 10)}T00:00:00`);
  return Number.isNaN(day.getTime()) ? formatDate(d) : `${formatDate(d)}（${WEEKDAY[day.getDay()]}）`;
}
function formatTime(s) {
  if (s.start_time && s.end_time) return `${s.start_time}–${s.end_time}`;
  return s.start_time || '—';
}
const BRAND_TITLE = '台北全真一對一補習班';
function formatBrandTitle(campusName) {
  const branch = String(campusName || '').trim();
  return branch ? `${BRAND_TITLE}｜${branch}` : BRAND_TITLE;
}
const todayLabel = new Date().toLocaleDateString('zh-TW');

const STATUS_ZH = {
  attended: '已到課', completed: '已完課', late: '遲到', absent: '缺席',
  excused: '事假', scheduled: '排定', leave: '請假', leave_adjusted: '調課',
};
const DONE = ['attended', 'completed', 'late', 'absent', 'excused'];
function statusTone(status) {
  if (['attended', 'completed'].includes(status)) return 'done';
  if (['late', 'absent'].includes(status)) return 'warn';
  if (['leave', 'leave_adjusted', 'excused'].includes(status)) return 'leave';
  return 'planned';
}
const attendedCount = computed(() => (slip.value?.sessions || []).filter(s => DONE.includes(s.status)).length);
const hasSubject = computed(() => {
  const subjects = new Set((slip.value?.sessions || []).map(s => s.subject).filter(Boolean));
  return subjects.size > 1;
});

function normalizeSlipData(raw) {
  if (raw.invoice_id) {
    const items = (raw.items || []).map(i => ({
      description: i.description || '—',
      period: i.period_start && i.period_end
        ? `${formatDate(i.period_start)} – ${formatDate(i.period_end)}`
        : '—',
      amount: formatAmount(i.amount),
    }));
    return {
      type: 'invoice',
      student_name: raw.student_name,
      campus_name: raw.campus_name,
      title: '繳費通知單',
      amount_label: raw.status === 'partial' ? '尚欠金額' : '應繳金額',
      amount: raw.remaining,
      sub_amounts: raw.status === 'partial'
        ? `應繳總額 ${formatAmount(raw.total_amount)}・已繳 ${formatAmount(raw.paid_amount)}`
        : null,
      ref_label: `帳單編號 #${raw.invoice_id}`,
      due: raw.due_date ? { label: '繳費期限', value: formatDate(raw.due_date) } : null,
      meta: [
        { label: '學生', value: raw.student_name || '—' },
        ...(items.length === 1 && items[0].period !== '—' ? [{ label: '服務期間', value: items[0].period }] : []),
        { label: '開立日期', value: formatDate(raw.issue_date) },
      ],
      items,
      note: raw.note,
      sessions: raw.sessions || [],
      filename: `繳費單_${raw.student_name}_${raw.invoice_id}.png`,
    };
  }
  const modeLabel = raw.schedule_mode === 'date' ? '月結制' : '堂數制';
  const hasCanonicalPayable = raw.payable_status === 'invoiced' && raw.payable_amount != null;
  const displayedAmount = hasCanonicalPayable ? raw.payable_amount : (raw.estimated_amount ?? raw.charge ?? 0);
  const items = [{
    description: `${raw.subject}（${modeLabel}${hasCanonicalPayable ? '' : '・估算'}）`,
    period: raw.schedule_mode === 'date' && raw.period_sessions != null
      ? `本期 ${raw.period_sessions} 堂`
      : (raw.remaining_sessions != null ? `剩餘 ${raw.remaining_sessions} 堂` : '—'),
    amount: displayedAmount ? formatAmount(displayedAmount) : '—',
  }];

  const days = raw.days_until_settlement;
  return {
    type: 'tuition',
    student_name: raw.student_name,
    campus_name: raw.campus_name,
    title: '繳費通知',
    amount_label: hasCanonicalPayable ? '應繳費用' : '預估金額（尚無帳單）',
    amount: displayedAmount,
    sub_amounts: null,
    ref_label: raw.subject,
    due: raw.due_date
      ? {
          label: '繳費期限',
          value: formatDate(raw.due_date),
          hint: days != null && days < 0 ? `已逾期 ${Math.abs(days)} 天` : null,
        }
      : null,
    meta: [
      { label: '學生', value: raw.student_name || '—' },
      { label: '產生日期', value: todayLabel },
    ],
    items,
    note: raw.note,
    sessions: raw.sessions || [],
    filename: `繳費通知_${raw.student_name}_${raw.student_class_id}.png`,
  };
}

const slip = computed(() => normalizeSlipData(props.data));
defineExpose({ slip });
</script>

<style scoped>
/* ─── The slip (exported as PNG) ───────────────────────────────
   Fixed light palette on purpose: the image goes to parents and must look
   the same regardless of the staff member's dark-mode setting. */
.slip {
  --slip-accent: #EF6C00;
  --slip-accent-wash: #FFF3E0;
  --slip-ink: #1F2937;
  --slip-ink-2: #4B5563;
  --slip-mute: #6B7280;
  --slip-line: #E5E7EB;
  --slip-soft: #F9FAFB;
  --slip-done: #15803D;
  --slip-done-wash: #DCFCE7;
  --slip-warn: #B45309;
  --slip-warn-wash: #FEF3C7;
  --slip-leave: #6D28D9;
  --slip-leave-wash: #EDE9FE;
  --slip-paper: #FFFFFF;
  flex: 0 0 auto;
  width: 580px;
  box-sizing: border-box;
  background: var(--slip-paper);
  color: var(--slip-ink);
  border-radius: 16px;
  border-top: 6px solid var(--slip-accent);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
  padding: 28px 32px 22px;
  font-family: 'Noto Sans TC', 'Inter', 'PingFang TC', 'Microsoft JhengHei', sans-serif;
  font-size: 13px;
  line-height: 1.5;
}
.slip--tuition {
  --slip-accent: #C2410C;
  --slip-accent-wash: #FFEDD5;
}
.slip-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding-bottom: 18px;
  border-bottom: 1px solid var(--slip-line);
}
.slip-brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
.slip-logo { width: 56px; height: 56px; object-fit: contain; flex: 0 0 auto; }
.slip-brand-name { font-size: 15px; font-weight: 700; }
.slip-brand-campus { font-size: 12.5px; color: var(--slip-mute); }
.slip-doc { text-align: right; flex: 0 0 auto; }
.slip-doc-title { font-size: 20px; font-weight: 800; color: var(--slip-accent); letter-spacing: 0.04em; }
.slip-doc-ref { font-size: 12px; color: var(--slip-mute); }

.slip-label { font-size: 12px; font-weight: 600; color: var(--slip-mute); }
.slip-hero {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
  margin: 18px 0 14px;
  padding: 18px 20px;
  background: var(--slip-accent-wash);
  border-radius: 12px;
}
.slip-amount {
  font-family: 'Inter', 'Noto Sans TC', sans-serif;
  font-size: 34px;
  font-weight: 800;
  color: var(--slip-ink);
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
}
.slip-sub { font-size: 12px; color: var(--slip-ink-2); margin-top: 2px; }
.slip-hero-due { text-align: right; }
.slip-due { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; }
.slip-overdue { font-size: 12px; font-weight: 700; color: var(--slip-accent); }

.slip-meta {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px 16px;
  margin: 0 0 16px;
}
.slip-meta dt { font-size: 11.5px; color: var(--slip-mute); }
.slip-meta dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; }

.slip-items {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 18px;
}
.slip-items th {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--slip-mute);
  text-align: left;
  padding: 8px 0;
  border-bottom: 1px solid var(--slip-line);
}
.slip-items td {
  padding: 10px 0;
  border-bottom: 1px solid var(--slip-line);
  vertical-align: top;
}
.slip-items td + td, .slip-items th + th { padding-left: 12px; }
.slip-items .num { text-align: right; white-space: nowrap; font-weight: 600; font-variant-numeric: tabular-nums; }
.slip-items .muted { color: var(--slip-ink-2); font-size: 12px; white-space: nowrap; font-variant-numeric: tabular-nums; }

.slip-section-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 700;
  margin-bottom: 8px;
}
.slip-chip {
  font-size: 11px;
  font-weight: 600;
  color: var(--slip-ink-2);
  background: var(--slip-soft);
  border: 1px solid var(--slip-line);
  border-radius: 999px;
  padding: 1px 8px;
  white-space: nowrap;
}
.slip-chip--done { color: var(--slip-done); background: var(--slip-done-wash); border-color: transparent; }
.slip-session-list { list-style: none; margin: 0; padding: 0; }
.slip-session-list li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 10px;
  border-radius: 6px;
  font-variant-numeric: tabular-nums;
}
.slip-session-list li:nth-child(odd) { background: var(--slip-soft); }
.slip-session-no { width: 18px; color: var(--slip-mute); font-size: 11px; text-align: right; }
.slip-session-date { width: 128px; font-weight: 600; }
.slip-session-time { width: 96px; color: var(--slip-ink-2); }
.slip-session-subject { flex: 1; color: var(--slip-mute); font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.slip-status {
  margin-left: auto;
  font-size: 11px;
  font-weight: 700;
  border-radius: 4px;
  padding: 1px 8px;
  white-space: nowrap;
}
.slip-status.is-done { color: var(--slip-done); background: var(--slip-done-wash); }
.slip-status.is-warn { color: var(--slip-warn); background: var(--slip-warn-wash); }
.slip-status.is-leave { color: var(--slip-leave); background: var(--slip-leave-wash); }
.slip-status.is-planned { color: var(--slip-ink-2); background: var(--slip-soft); border: 1px solid var(--slip-line); }

.slip-note {
  margin-top: 16px;
  padding: 10px 14px;
  border-left: 3px solid var(--slip-accent);
  background: var(--slip-soft);
  border-radius: 0 8px 8px 0;
}
.slip-note p { margin: 2px 0 0; white-space: pre-wrap; color: var(--slip-ink-2); }

.slip-foot {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  margin-top: 20px;
  padding-top: 14px;
  border-top: 1px solid var(--slip-line);
  font-size: 11px;
  color: var(--slip-mute);
  text-align: center;
}
</style>
