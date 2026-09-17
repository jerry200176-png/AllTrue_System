<script>
import { computed, ref, watch } from 'vue';
import CourseSessionCalendar from './CourseSessionCalendar.vue';

const TABS = [
  { id: 'overview', label: '總覽' },
  { id: 'sessions', label: '排課與堂次' },
  { id: 'settings', label: '課程設定' },
  { id: 'billing', label: '帳務與合約' },
];

export default {
  name: 'CourseManager',
  components: { CourseSessionCalendar },
  props: {
    course: { type: Object, required: true },
    tab: { type: String, default: 'overview' },
    studentName: { type: String, default: '' },
    subjectLabel: { type: String, default: '' },
    classTypeLabel: { type: String, default: '' },
    statusLabel: { type: String, default: '' },
    scheduleSummary: { type: String, default: '' },
    remainingLabel: { type: String, default: '' },
    nextSessionLabel: { type: String, default: '' },
    paymentLabel: { type: String, default: '' },
    overviewNeeds: { type: Array, default: () => [] },
    sessionUnits: { type: Array, default: () => [] },
    cancelledUnits: { type: Array, default: () => [] },
    pendingMakeups: { type: Array, default: () => [] },
    calendarEnabled: { type: Boolean, default: false },
    createEnabled: { type: Boolean, default: false },
    showCancelled: { type: Boolean, default: false },
    showSessionNotes: { type: Boolean, default: false },
    sessionLoadFailed: { type: Boolean, default: false },
    planningStatus: { type: Object, default: null },
    canQuickAdd: { type: Boolean, default: false },
    canClose: { type: Boolean, default: false },
    isSessionMode: { type: Boolean, default: false },
    isMonthlyMode: { type: Boolean, default: false },
    isManualOccurrence: { type: Boolean, default: false },
    purchaseLabel: { type: String, default: '加購堂數' },
    paymentNoticeAvailable: { type: Boolean, default: false },
    canPackagePreview: { type: Boolean, default: false },
    formatSessionChipDate: { type: Function, required: true },
    getSessionStateClass: { type: Function, required: true },
    getSessionStateLabel: { type: Function, required: true },
    getSessionNumber: { type: Function, required: true },
    sessionRowKey: { type: Function, required: true },
    isUserNote: { type: Function, required: true },
    formatMakeupDate: { type: Function, required: true },
  },
  emits: ['close', 'update:tab', 'action', 'open-session', 'create-day', 'toggle-cancelled', 'toggle-notes'],
  setup(props, { emit }) {
    const sessionsView = ref('calendar');
    const dangerOpen = ref(false);
    watch(() => props.course?.id, () => {
      sessionsView.value = 'calendar';
      dangerOpen.value = false;
    });
    const activeTab = computed({
      get: () => props.tab,
      set: (v) => emit('update:tab', v),
    });
    const act = (evt) => {
      if (typeof evt === 'string') emit('action', { name: evt });
      else emit('action', evt && typeof evt === 'object' ? evt : { name: evt });
    };
    const primaryScheduleCta = () => {
      if (props.isManualOccurrence) return '＋新增下一堂';
      if (props.isMonthlyMode) return '新增月結堂次';
      return '排課';
    };
    const billingType = () => (props.course.payment_type === 'session' ? '堂數制' : '月結');
    const statusTone = computed(() => {
      if (props.course?.usage_balance_status === 'review_required') return 'warn';
      const label = props.statusLabel || '';
      if (label.includes('暫停')) return 'warn';
      if (label.includes('結案') || label.includes('結束') || label.includes('合約')) return 'neutral';
      return 'ok';
    });
    const listUnits = computed(() => {
      const units = [...(props.sessionUnits || [])];
      return units.sort((a, b) => String(a.date || '').localeCompare(String(b.date || '')));
    });
    function onSelectDay({ date }) {
      const u = (props.sessionUnits || []).find((x) => String(x.date || '').slice(0, 10) === date);
      if (u) emit('open-session', { unit: u, date, id: u.id });
    }
    return {
      tabs: TABS, activeTab, act, primaryScheduleCta, billingType, statusTone,
      sessionsView, dangerOpen, listUnits, onSelectDay,
    };
  },
};
</script>

<template>
  <div class="cmw" role="dialog" aria-modal="true" :aria-label="`管理課程：${subjectLabel || '課程'}`" data-testid="course-manager" @keydown.esc.prevent="$emit('close')">
    <div class="cmw__scrim" @click="$emit('close')" />
    <div class="cmw__panel">
      <header class="cmw__head">
        <div class="cmw__head-bar">
          <button type="button" class="cmw__back" data-testid="course-manager-close" @click="$emit('close')">
            <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> 課程管理
          </button>
          <button type="button" class="cmw__x" aria-label="關閉" @click="$emit('close')">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </div>
        <div class="cmw__id">
          <h2 class="cmw__title">
            <span><template v-if="studentName">{{ studentName }} · </template>{{ subjectLabel }}</span>
            <span class="cmw__badge" :class="`cmw__badge--${statusTone}`" data-testid="course-manager-status">{{ statusLabel }}</span>
          </h2>
          <p class="cmw__meta">{{ course.teacher_name || '待指派' }} · {{ classTypeLabel }}<template v-if="course.room_name"> · {{ course.room_name }}</template></p>
          <div class="cmw__kpis" aria-label="課程摘要">
            <span v-if="remainingLabel" class="cmw__kpi">{{ remainingLabel }}</span>
            <span v-if="nextSessionLabel" class="cmw__kpi">下堂 {{ nextSessionLabel }}</span>
            <span v-if="paymentLabel" class="cmw__kpi">{{ paymentLabel }}</span>
          </div>
        </div>
      </header>
      <nav class="cmw__tabs" role="tablist" aria-label="課程管理分區">
        <button
          v-for="t in tabs"
          :key="t.id"
          type="button"
          role="tab"
          class="cmw__tab"
          :class="{ on: activeTab === t.id }"
          :data-testid="`course-manager-tab-${t.id}`"
          :aria-selected="activeTab === t.id"
          :id="`cm-tab-${t.id}`"
          :aria-controls="`cm-panel-${t.id}`"
          @click="activeTab = t.id"
        >{{ t.label }}</button>
      </nav>
      <div class="cmw__body">
        <div
          v-if="activeTab === 'overview'"
          id="cm-panel-overview"
          role="tabpanel"
          aria-labelledby="cm-tab-overview"
          data-testid="course-manager-overview"
          class="cmw__stack cmw__stack--overview"
        >
          <section class="cmw__metrics" aria-label="營運狀態">
            <div class="cmw__metric"><span class="cmw__metric-k">堂次</span><strong>{{ remainingLabel || '—' }}</strong></div>
            <div class="cmw__metric"><span class="cmw__metric-k">下一堂</span><strong>{{ nextSessionLabel || '—' }}</strong></div>
            <div class="cmw__metric"><span class="cmw__metric-k">付款</span><strong>{{ paymentLabel || '—' }}</strong></div>
            <div class="cmw__metric"><span class="cmw__metric-k">固定時段</span><strong>{{ scheduleSummary || '未排定' }}</strong></div>
          </section>
          <section v-if="overviewNeeds.length" class="cmw__card cmw__card--needs">
            <h3>需要處理</h3>
            <div v-for="n in overviewNeeds" :key="n.id" class="cmw__need">
              <div><strong>{{ n.title }}</strong><p v-if="n.detail">{{ n.detail }}</p></div>
              <button v-if="n.action" type="button" class="small primary" @click="act(n.action)">{{ n.actionLabel || '前往' }}</button>
            </div>
          </section>
          <section class="cmw__card cmw__card--life">
            <h3>課程狀態</h3>
            <p class="cmw__hint">目前：{{ statusLabel }}</p>
            <div class="cmw__row">
              <button v-if="course.status !== 'inactive'" type="button" class="small ghost" @click="act('pause')">暫停課程</button>
              <button v-else type="button" class="small primary" @click="act('resume')">恢復課程</button>
              <button v-if="canClose" type="button" class="small ghost" @click="act('close')">結束課程</button>
            </div>
          </section>
        </div>

        <div
          v-else-if="activeTab === 'sessions'"
          id="cm-panel-sessions"
          role="tabpanel"
          aria-labelledby="cm-tab-sessions"
          data-testid="course-manager-sessions"
          class="cmw__stack cmw__stack--sessions"
        >
          <div class="cmw__toolbar">
            <div class="cmw__row">
              <button
                v-if="isSessionMode || isMonthlyMode"
                type="button"
                class="small primary"
                data-testid="course-manager-schedule-cta"
                @click="act('manual-session')"
              >{{ primaryScheduleCta() }}</button>
              <button
                v-if="isSessionMode && canQuickAdd"
                type="button"
                class="small ghost"
                data-testid="course-manager-quick-add"
                @click="act('quick-add')"
              >補課／補登</button>
              <button type="button" class="small ghost" @click="$emit('toggle-notes')">{{ showSessionNotes ? '備註 ▲' : '備註 ▼' }}</button>
              <button v-if="cancelledUnits.length" type="button" class="small ghost" @click="$emit('toggle-cancelled')">{{ showCancelled ? '已調走／取消 ▲' : `含 ${cancelledUnits.length} 堂已調走／取消 ▼` }}</button>
            </div>
            <div class="cmw__view-toggle" role="group" aria-label="堂次檢視">
              <button type="button" class="small" :class="{ primary: sessionsView === 'calendar', ghost: sessionsView !== 'calendar' }" data-testid="course-manager-view-calendar" @click="sessionsView = 'calendar'">月曆</button>
              <button type="button" class="small" :class="{ primary: sessionsView === 'list', ghost: sessionsView !== 'list' }" data-testid="course-manager-view-list" @click="sessionsView = 'list'">列表</button>
            </div>
          </div>
          <p v-if="sessionLoadFailed || planningStatus" class="cmw__hint">
            <strong>{{ sessionLoadFailed ? '堂次載入失敗' : planningStatus?.title }}</strong>
            — {{ sessionLoadFailed ? '目前無法確認最新堂次狀態。' : planningStatus?.message }}
            <button v-if="sessionLoadFailed" type="button" class="small primary" @click="act('retry-sessions')">重新載入</button>
            <button v-else-if="['quick_add','arrange_makeup'].includes(planningStatus?.action) && canQuickAdd" type="button" class="small primary" @click="act('quick-add')">{{ planningStatus?.action === 'arrange_makeup' ? '安排補課' : '補排堂次' }}</button>
          </p>
          <template v-if="sessionsView === 'calendar'">
            <CourseSessionCalendar
              v-if="calendarEnabled"
              :course="course"
              :sessions="sessionUnits"
              :create-enabled="createEnabled"
              :show-quick-add="false"
              @create-day="(p) => $emit('create-day', p)"
              @select-day="onSelectDay"
            />
            <p v-else class="cmw__hint">行事曆尚未啟用；請改用列表檢視堂次。</p>
          </template>
          <template v-else>
            <div v-if="listUnits.length" class="cmw__session-list" data-testid="course-manager-session-list">
              <button
                v-for="u in listUnits"
                :key="sessionRowKey(u)"
                type="button"
                class="cmw__session-row"
                :class="[u.isProjected ? 'is-projected' : 'is-materialized', getSessionStateClass(course, (u.date || '').slice(0,10), u.id)]"
                @click="$emit('open-session', { unit: u, date: (u.date || '').slice(0,10), id: u.id })"
              >
                <span class="cmw__session-seq">{{ getSessionNumber(course, (u.date || '').slice(0,10), u.id) ? `第${getSessionNumber(course, (u.date || '').slice(0,10), u.id)}堂` : '—' }}</span>
                <span class="cmw__session-date">{{ formatSessionChipDate(u) }}</span>
                <span class="cmw__session-state">{{ u.isProjected ? '預排' : (getSessionStateLabel(course, (u.date || '').slice(0,10), u.id) || '已建立') }}</span>
                <span v-if="showSessionNotes && isUserNote(u.note)" class="cmw__session-note">{{ u.note }}</span>
              </button>
            </div>
            <p v-else class="cmw__hint">尚無可顯示堂次（請確認排課設定）。</p>
            <div v-if="showCancelled && cancelledUnits.length" class="cmw__session-list cmw__session-list--cancelled">
              <div v-for="u in cancelledUnits" :key="'cx-'+sessionRowKey(u)" class="cmw__session-row is-cancelled">
                <span class="cmw__session-date">{{ formatSessionChipDate(u) }}</span>
                <span class="cmw__session-state">已取消</span>
              </div>
            </div>
          </template>
          <div v-if="pendingMakeups.length" class="cmw__card">
            <strong>待補課（{{ pendingMakeups.length }} 堂）</strong>
            <div v-for="ms in pendingMakeups" :key="ms.id" class="cmw__need">
              <span>{{ formatMakeupDate(ms) }}</span>
              <button type="button" class="small" @click="act({ name: 'cancel-makeup', payload: ms })">取消補課</button>
            </div>
          </div>
        </div>

        <div
          v-else-if="activeTab === 'settings'"
          id="cm-panel-settings"
          role="tabpanel"
          aria-labelledby="cm-tab-settings"
          class="cmw__settings"
          data-testid="course-manager-settings"
        >
          <slot name="settings" />
        </div>

        <div
          v-else-if="activeTab === 'billing'"
          id="cm-panel-billing"
          role="tabpanel"
          aria-labelledby="cm-tab-billing"
          data-testid="course-manager-billing"
          class="cmw__stack cmw__stack--billing"
        >
          <section class="cmw__card">
            <h3>帳務摘要</h3>
            <dl class="cmw__facts">
              <div><dt>計費</dt><dd>{{ billingType() }}</dd></div>
              <div><dt>堂次</dt><dd>{{ remainingLabel || '—' }}</dd></div>
              <div><dt>付款</dt><dd>{{ paymentLabel || '—' }}</dd></div>
              <div v-if="course.last_paid_at"><dt>最近付款</dt><dd>{{ course.last_paid_at }}</dd></div>
            </dl>
            <div class="cmw__row">
              <button type="button" class="small ghost" data-testid="course-manager-invoice" @click="act('invoice')">查看帳單</button>
              <button type="button" class="small ghost" data-testid="course-manager-tuition" @click="act('tuition')">前往帳務中心</button>
              <button v-if="course.usage_balance_status === 'review_required'" type="button" class="small primary" @click="act('ledger')">堂數待對帳</button>
            </div>
          </section>
          <section class="cmw__card">
            <h3>合約</h3>
            <div class="cmw__row">
              <button type="button" class="small primary" @click="act('purchase')">{{ purchaseLabel }}</button>
              <button type="button" class="small ghost" @click="act('contract-adjust')">合約／堂次調整</button>
              <button v-if="canPackagePreview" type="button" class="small ghost" @click="act('package-preview')">轉多科方案預檢</button>
            </div>
          </section>
          <section v-if="paymentNoticeAvailable" class="cmw__card">
            <h3>收款</h3>
            <div class="cmw__row">
              <button type="button" class="small ghost" @click="act('payment-slip')">產生繳費通知</button>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cmw{position:fixed;inset:0;z-index:1200;display:flex;justify-content:flex-end}
.cmw__scrim{position:absolute;inset:0;background:color-mix(in srgb,var(--ds-ink) 45%,transparent)}
.cmw__panel{position:relative;display:flex;flex-direction:column;width:min(1120px,100vw);height:100%;background:var(--ds-canvas-soft);color:var(--ds-ink);box-shadow:var(--ds-shadow-2,0 8px 28px color-mix(in srgb,var(--ds-ink) 18%,transparent))}
.cmw__head{padding:10px 18px 10px;border-bottom:1px solid var(--ds-hairline);background:var(--ds-canvas)}
.cmw__head-bar{display:flex;align-items:center;justify-content:space-between;gap:8px}
.cmw__back,.cmw__x{display:inline-flex;align-items:center;gap:4px;border:0;background:transparent;color:var(--ds-ink-mute);font-size:.875rem;cursor:pointer;padding:2px 0}
.cmw__x{padding:4px;border-radius:6px}
.cmw__x:hover,.cmw__back:hover{color:var(--ds-ink);background:var(--ds-canvas-soft)}
.cmw__title{margin:6px 0 4px;font-size:1.2rem;font-weight:650;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.cmw__badge{font-size:.75rem;font-weight:600;padding:2px 8px;border-radius:999px}
.cmw__badge--ok{background:var(--ds-success-wash,color-mix(in srgb,var(--ds-success) 16%,white));color:var(--ds-success)}
.cmw__badge--warn{background:var(--ds-warning-wash,color-mix(in srgb,var(--ds-warning) 14%,white));color:var(--ds-warning)}
.cmw__badge--neutral{background:var(--ds-canvas-soft);color:var(--ds-ink-mute);border:1px solid var(--ds-hairline)}
.cmw__meta{margin:0;color:var(--ds-ink-mute);font-size:.875rem}
.cmw__kpis{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.cmw__kpi{font-size:.78rem;color:var(--ds-ink-secondary);background:var(--ds-canvas-soft);border:1px solid var(--ds-hairline);border-radius:999px;padding:2px 8px}
.cmw__tabs{display:flex;gap:2px;padding:0 10px;border-bottom:1px solid var(--ds-hairline);background:var(--ds-canvas);overflow-x:auto}
.cmw__tab{border:0;background:transparent;padding:11px 12px;color:var(--ds-ink-mute);cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap}
.cmw__tab.on{color:var(--ds-ink);border-bottom-color:var(--ds-primary);font-weight:600}
.cmw__body{flex:1;overflow:auto;padding:14px 18px 24px}
.cmw__stack{display:grid;gap:12px;width:100%}
.cmw__stack--overview,.cmw__stack--billing{max-width:960px;margin:0 auto}
.cmw__stack--sessions{max-width:none}
.cmw__row{display:flex;flex-wrap:wrap;gap:8px 10px;align-items:center}
.cmw__toolbar{display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center}
.cmw__view-toggle{display:inline-flex;gap:4px;padding:3px;border:1px solid var(--ds-hairline);border-radius:8px;background:var(--ds-canvas)}
.cmw__metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px}
.cmw__metric{background:var(--ds-canvas);border:1px solid var(--ds-hairline);border-radius:10px;padding:12px 14px}
.cmw__metric-k{display:block;font-size:.72rem;color:var(--ds-ink-mute);margin-bottom:4px}
.cmw__metric strong{font-size:.95rem;font-weight:650;word-break:break-word}
.cmw__card{background:var(--ds-canvas);border:1px solid var(--ds-hairline);border-radius:10px;padding:12px 14px}
.cmw__card h3{margin:0 0 8px;font-size:.95rem}
.cmw__card--needs{border-color:color-mix(in srgb,var(--ds-warning) 35%,var(--ds-hairline));background:var(--ds-warning-wash,color-mix(in srgb,var(--ds-warning) 8%,white))}
.cmw__card--life{opacity:.96}
.cmw__facts{margin:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px 12px}
.cmw__facts dt{font-size:.72rem;color:var(--ds-ink-mute)}
.cmw__facts dd{margin:2px 0 0}
.cmw__need{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:6px 0;border-top:1px solid var(--ds-hairline)}
.cmw__need:first-of-type{border-top:0}
.cmw__need p,.cmw__hint{margin:2px 0 0;color:var(--ds-ink-mute);font-size:.85rem}
.cmw__session-list{display:grid;gap:6px}
.cmw__session-row{display:grid;grid-template-columns:4.5rem minmax(7rem,1fr) auto;gap:8px 12px;align-items:center;text-align:left;width:100%;padding:10px 12px;border:1px solid var(--ds-hairline);border-radius:8px;background:var(--ds-canvas);cursor:pointer;color:inherit}
.cmw__session-row.is-projected{border-style:dashed}
.cmw__session-row.is-cancelled{opacity:.75;cursor:default}
.cmw__session-seq{font-size:.78rem;color:var(--ds-ink-mute)}
.cmw__session-date{font-weight:600}
.cmw__session-state{font-size:.8rem;color:var(--ds-ink-secondary)}
.cmw__session-note{grid-column:1/-1;font-size:.8rem;color:var(--ds-ink-mute)}
.cmw__settings{max-width:none}
@media (max-width:720px){.cmw__panel{width:100vw}.cmw__body{padding:12px}.cmw__session-row{grid-template-columns:1fr auto}}
</style>
