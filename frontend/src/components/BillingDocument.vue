<template>
  <article class="slip" :class="`slip--${doc.tone}`">
    <header class="slip-top">
      <div class="slip-brand">
        <img :src="logoUrl" alt="" class="slip-logo" />
        <div>
          <div class="slip-brand-name">{{ BRAND_TITLE }}</div>
          <div v-if="doc.campus_name" class="slip-brand-campus">{{ doc.campus_name }}</div>
        </div>
      </div>
      <div class="slip-doc">
        <div class="slip-doc-title">{{ doc.title }}</div>
        <div class="slip-doc-ref">{{ doc.ref_label }}</div>
      </div>
    </header>

    <section class="slip-hero">
      <div class="slip-hero-amount">
        <div class="slip-label">{{ doc.amount_label }}</div>
        <div class="slip-amount">{{ formatAmount(doc.amount) }}</div>
        <div v-if="doc.sub_amounts" class="slip-sub">{{ doc.sub_amounts }}</div>
      </div>
      <div v-if="doc.due" class="slip-hero-due">
        <div class="slip-label">{{ doc.due.label }}</div>
        <div class="slip-due">{{ doc.due.value }}</div>
        <div v-if="doc.due.hint" class="slip-overdue">{{ doc.due.hint }}</div>
      </div>
    </section>

    <dl class="slip-meta">
      <div v-for="m in doc.meta" :key="m.label">
        <dt>{{ m.label }}</dt>
        <dd>{{ m.value }}</dd>
      </div>
    </dl>

    <table v-if="doc.items.length" class="slip-items">
      <thead>
        <tr><th>項目</th><th v-if="hasPeriod">期間／堂數</th><th class="num">金額</th></tr>
      </thead>
      <tbody>
        <tr v-for="(item, i) in doc.items" :key="i">
          <td>{{ item.description }}</td>
          <td v-if="hasPeriod" class="muted">{{ item.period || '—' }}</td>
          <td class="num">{{ item.amount }}</td>
        </tr>
      </tbody>
      <tfoot v-if="doc.total">
        <tr><td :colspan="hasPeriod ? 2 : 1">合計</td><td class="num">{{ doc.total }}</td></tr>
      </tfoot>
    </table>

    <section v-if="doc.sessions.length" class="slip-sessions">
      <div class="slip-section-title">
        {{ doc.session_title || '本期上課日期' }}
        <span class="slip-chip">共 {{ doc.sessions.length }} 堂</span>
        <span v-if="attendedCount" class="slip-chip slip-chip--done">已上 {{ attendedCount }} 堂</span>
      </div>
      <ol class="slip-session-list">
        <li v-for="(s, i) in shownSessions" :key="s.class_session_id || i">
          <span class="slip-session-no">{{ i + 1 }}</span>
          <span class="slip-session-date">{{ formatSessionDate(s.date) }}</span>
          <span class="slip-session-time">{{ formatTime(s) }}</span>
          <span v-if="hasSubject" class="slip-session-subject">{{ s.subject }}</span>
          <span class="slip-status" :class="`is-${statusTone(s.status)}`">{{ STATUS_ZH[s.status] || s.status || '排定' }}</span>
        </li>
      </ol>
      <p v-if="hiddenCount" class="slip-more">另有 {{ hiddenCount }} 堂未列出（共 {{ doc.sessions.length }} 堂）</p>
    </section>

    <section v-if="doc.note" class="slip-note">
      <div class="slip-label">備註</div>
      <p>{{ doc.note }}</p>
    </section>

    <section v-for="sec in doc.sections || []" :key="sec.label" class="slip-note slip-note--plain">
      <div class="slip-label">{{ sec.label }}</div>
      <p>{{ sec.text }}</p>
    </section>

    <div v-if="doc.sign_line" class="slip-sign">
      <span>經辦人：__________</span>
      <span>補習班用印：</span>
    </div>

    <footer class="slip-foot">
      <span>{{ doc.footer_note }}</span>
      <span>{{ formatBrandTitle(doc.campus_name) }}・{{ doc.generated_on }}</span>
    </footer>

    <div v-if="doc.watermark" class="slip-watermark">{{ doc.watermark }}</div>
  </article>
</template>

<script setup>
import { computed } from 'vue';
import {
  BRAND_TITLE, STATUS_ZH, formatAmount, formatBrandTitle, formatSessionDate, formatTime, statusTone, isDone,
} from '../lib/billingDocumentView.js';
// Cropped 256px mark: the full logo.png has wide white margins.
import logoUrl from '../assets/slip-logo.png';

// One printable billing document (payment slip or receipt), exported as PNG
// by its modal. `doc` is a view model from lib/billingDocumentView.js.
const props = defineProps({
  doc: { type: Object, required: true },
});

const attendedCount = computed(() => props.doc.sessions.filter(s => isDone(s.status)).length);
const hasSubject = computed(() => new Set(props.doc.sessions.map(s => s.subject).filter(Boolean)).size > 1);
const hasPeriod = computed(() => props.doc.items.some(i => i.period));
// Bounded so a long course still exports as one PNG within canvas limits.
const MAX_SESSION_ROWS = 40;
const shownSessions = computed(() => props.doc.sessions.slice(0, MAX_SESSION_ROWS));
const hiddenCount = computed(() => Math.max(0, props.doc.sessions.length - MAX_SESSION_ROWS));
</script>

<style scoped>
/* ─── The slip (exported as PNG) ───────────────────────────────
   Uses the fixed --ds-print-* palette: the image goes to parents and must
   look the same regardless of the staff member's dark-mode setting. */
.slip {
  --slip-accent: var(--ds-print-accent);
  --slip-accent-wash: var(--ds-print-accent-wash);
  --slip-ink: var(--ds-print-ink);
  --slip-ink-2: var(--ds-print-ink-2);
  --slip-mute: var(--ds-print-mute);
  --slip-line: var(--ds-print-line);
  --slip-soft: var(--ds-print-soft);
  --slip-done: var(--ds-print-done);
  --slip-done-wash: var(--ds-print-done-wash);
  --slip-warn: var(--ds-print-warn);
  --slip-warn-wash: var(--ds-print-warn-wash);
  --slip-leave: var(--ds-print-leave);
  --slip-leave-wash: var(--ds-print-leave-wash);
  --slip-paper: var(--ds-print-paper);
  flex: 0 0 auto;
  width: 580px;
  box-sizing: border-box;
  background: var(--slip-paper);
  color: var(--slip-ink);
  border-radius: 16px;
  border-top: 6px solid var(--slip-accent);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
  padding: 28px 32px 22px;
  /* System CJK fonts only: they have real bold weights on every device and
     need no web-font embedding when the PNG is exported (WebKit dropped the
     embedded Noto Sans TC weights). */
  font-family: -apple-system, BlinkMacSystemFont, 'PingFang TC', 'Microsoft JhengHei', 'Noto Sans CJK TC', sans-serif;
  font-size: 13px;
  line-height: 1.5;
  position: relative;
}
.slip--receipt {
  --slip-accent: var(--ds-print-done);
  --slip-accent-wash: var(--ds-print-done-wash);
}
.slip--tuition {
  --slip-accent: var(--ds-print-accent-strong);
  --slip-accent-wash: var(--ds-print-accent-strong-wash);
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
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  gap: 8px 16px;
  margin: 0 0 16px;
}
.slip-meta dt { font-size: 11.5px; color: var(--slip-mute); }
.slip-meta dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }

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

.slip-more { margin: 6px 0 0; font-size: 12px; color: var(--slip-mute); text-align: center; }
.slip-note {
  margin-top: 16px;
  padding: 10px 14px;
  border-left: 3px solid var(--slip-accent);
  background: var(--slip-soft);
  border-radius: 0 8px 8px 0;
}
.slip-note p { margin: 2px 0 0; white-space: pre-wrap; color: var(--slip-ink-2); }

.slip-items tfoot td { font-weight: 700; border-bottom: none; padding-top: 12px; }
.slip-note--plain { border-left-color: var(--slip-line); }
.slip-sign {
  display: flex;
  justify-content: space-between;
  margin-top: 20px;
  font-size: 12px;
  color: var(--slip-ink-2);
}
.slip-watermark {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) rotate(-25deg);
  font-size: 96px;
  font-weight: 900;
  color: var(--ds-print-void);
  pointer-events: none;
  white-space: nowrap;
}
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
