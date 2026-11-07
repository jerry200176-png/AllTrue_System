<script>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { trapTab } from '../../lib/focusTrap.js';
import { RENEW_LESSONS_LABEL } from '../../lib/courseIntent.js';
import { monthlyPaymentLabel, periodPaymentLabel } from '../../lib/courseMoneyState.js';
import CourseSessionCalendar from './CourseSessionCalendar.vue';
import ActionMenu from '../ActionMenu.vue';
import { ArrowLeft, X, CalendarClock, Wallet, Repeat, Hash, ChevronRight } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { BlurFade } from '../motion';

const TABS = [
  { id: 'overview', label: '總覽' },
  { id: 'sessions', label: '堂次' },
  { id: 'settings', label: '設定' },
  { id: 'billing', label: '帳務' },
];

export default {
  name: 'CourseManager',
  components: { CourseSessionCalendar, ActionMenu, Button, Badge, BlurFade, ArrowLeft, X, CalendarClock, Wallet, Repeat, Hash, ChevronRight },
  props: {
    course: { type: Object, required: true },
    // { primary, groups } from courseActions(): the same model the course row uses (調課／代課／轉課 live in ⋯).
    actionModel: { type: Object, default: null },
    canMoveLesson: { type: Function, default: () => false },
    // in-app #486: 預覽分期更正 is a headquarters (POP-only) tool; a director can open it but never finish it.
    canPreviewCorrection: { type: Boolean, default: false },
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
    canRestoreCancelled: { type: Function, default: () => false },
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
    purchaseLabel: { type: String, default: RENEW_LESSONS_LABEL },
    paymentNoticeAvailable: { type: Boolean, default: false },
    canPackagePreview: { type: Boolean, default: false },
    formatSessionChipDate: { type: Function, required: true },
    getSessionStateClass: { type: Function, required: true },
    getSessionStateLabel: { type: Function, required: true },
    getSessionNumber: { type: Function, required: true },
    getSessionNumberMap: { type: Function, default: null },
    sessionRowKey: { type: Function, required: true },
    isUserNote: { type: Function, required: true },
    formatMakeupDate: { type: Function, required: true },
    // #4178: called with the course after close when the opener left the page (e.g. closed under 「進行中」).
    focusFallback: { type: Function, default: null },
  },
  emits: ['close', 'update:tab', 'action', 'open-session', 'create-day', 'toggle-cancelled', 'restore-cancelled', 'toggle-notes', 'move-lesson'],
  setup(props, { emit }) {
    // Dialog focus: land on the title, keep Tab inside, give focus back to whatever opened the drawer (管理課程).
    const panelRef = ref(null);
    const titleRef = ref(null);
    let opener = null;
    onMounted(() => {
      opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      nextTick(() => titleRef.value?.focus());
    });
    onBeforeUnmount(() => {
      if (opener && opener.isConnected && typeof opener.focus === 'function') opener.focus();
      // The opener's row can leave the list in this same render; then focus would fall to <body>.
      const course = props.course;
      nextTick(() => {
        const active = document.activeElement;
        if (!active || active === document.body || !active.isConnected) props.focusFallback?.(course);
      });
    });
    const onKeydown = (e) => { if (e.key === 'Tab') trapTab(e, panelRef.value); };
    // Roving tabindex tabs: ←/→ move and select, Home/End jump.
    const onTabKey = (e) => {
      const keys = { ArrowRight: 1, ArrowLeft: -1 };
      const ids = TABS.map((t) => t.id);
      let i = ids.indexOf(props.tab);
      if (e.key in keys) i = (i + keys[e.key] + ids.length) % ids.length;
      else if (e.key === 'Home') i = 0;
      else if (e.key === 'End') i = ids.length - 1;
      else return;
      e.preventDefault();
      emit('update:tab', ids[i]);
      nextTick(() => document.getElementById(`cm-tab-${ids[i]}`)?.focus());
    };
    const sessionsView = ref('list');
    const dangerOpen = ref(false);
    const selectedPeriod = ref('');
    const monthlySummary = computed(() => props.course?.monthly_payment);
    // in-app #510: list only the months with a real gap or out-of-contract lesson.
    const gapMonths = computed(() => (monthlySummary.value?.session_review || []).filter((m) => m.uncovered_sessions || m.outside_contract_sessions));
    const selectedPayment = computed(() => monthlySummary.value?.periods?.find((p) => p.billing_period === (selectedPeriod.value || monthlySummary.value.billing_period)));
    const effectivePaymentLabel = computed(() => selectedPeriod.value && selectedPayment.value
      ? `${selectedPeriod.value} ${periodPaymentLabel(selectedPayment.value.payment_status)}`
      : (monthlyPaymentLabel(props.course) || props.paymentLabel));
    watch(() => props.course?.id, () => {
      sessionsView.value = 'list';
      dangerOpen.value = false;
      selectedPeriod.value = '';
    });
    watch(() => props.calendarEnabled, (on) => {
      if (!on && sessionsView.value === 'calendar') sessionsView.value = 'list';
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
    // Built once per course sessions; list rows were each rescanning sessionUnits (O(n^2)).
    const sessionNumberMap = computed(() => (props.getSessionNumberMap ? props.getSessionNumberMap(props.course) : null));
    function sessionNo(u) {
      const date = (u.date || '').slice(0, 10);
      const m = sessionNumberMap.value;
      if (!m) return props.getSessionNumber(props.course, date, u.id);
      return (u.id ? m.byId.get(Number(u.id)) : m.byDate.get(date)) ?? null;
    }
    function onMoveSession({ sessionId, fromDate, toDate }) {
      const unit = (props.sessionUnits || []).find((x) => Number(x.id) === Number(sessionId) && String(x.date || '').slice(0, 10) === fromDate);
      if (unit) emit('move-lesson', { mode: 'reschedule', unit, date: toDate });
    }
    function onSelectDay({ date }) {
      const u = (props.sessionUnits || []).find((x) => String(x.date || '').slice(0, 10) === date);
      if (u) emit('open-session', { unit: u, date, id: u.id });
    }
    // Template look (#4918): 56px buttons, gold only on the one primary action, ink/secondary elsewhere.
    const cls = {
      primary: 'tw:h-14 tw:px-6 tw:text-[length:var(--tpl-text-body)] tw:font-bold tw:bg-[var(--ds-brand-gold)] tw:text-[var(--ds-brand-glyph)] tw:hover:bg-[var(--ds-brand-gold-deep)]',
      second: 'tw:h-14 tw:px-5 tw:text-[length:var(--tpl-text-body)]',
      group: 'tw:overflow-hidden tw:rounded-2xl tw:border tw:border-border tw:bg-card tw:shadow-tpl-card',
      label: 'tw:text-[length:var(--tpl-text-caption)] tw:text-tpl-secondary',
      tile: 'tpl-tile tw:rounded-[0.6875rem] tw:shadow-tpl-tile',
    };
    const cat = (c) => `var(--ds-cat-${c}-deep)`;
    const badgeCls = { ok: 'tw:bg-[var(--ds-success-wash)] tw:text-foreground', warn: 'tw:bg-[var(--ds-warning-wash)] tw:text-foreground', neutral: 'tw:bg-secondary tw:text-tpl-secondary' };
    return {
      cls, badgeCls, cat, CalendarClock, Wallet, Repeat, Hash, panelRef, titleRef, onKeydown, onTabKey, tabs: TABS, activeTab, act, primaryScheduleCta, billingType, statusTone,
      sessionsView, dangerOpen, listUnits, sessionNo, onSelectDay, onMoveSession, monthlySummary, gapMonths, selectedPeriod, effectivePaymentLabel, periodPaymentLabel,
    };
  },
};
</script>

<template>
  <div class="cmw tw:text-[length:var(--tpl-text-body)]" role="dialog" aria-modal="true" aria-labelledby="cm-title" data-testid="course-manager" @keydown.esc.prevent="$emit('close')" @keydown="onKeydown">
    <div class="cmw__scrim" @click="$emit('close')" />
    <div ref="panelRef" class="cmw__panel">
      <header class="cmw__head">
        <div class="tw:flex tw:items-center tw:justify-between tw:gap-2">
          <button type="button" class="cmw__back tw:inline-flex tw:h-14 tw:items-center tw:gap-2 tw:whitespace-nowrap tw:rounded-full tw:pr-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:text-tpl-secondary" data-testid="course-manager-close" @click="$emit('close')">
            <ArrowLeft :size="24" :stroke-width="2.2" aria-hidden="true" />課程管理
          </button>
          <button type="button" class="cmw__x tw:grid tw:size-14 tw:place-items-center tw:rounded-full" aria-label="關閉" @click="$emit('close')">
            <X :size="26" :stroke-width="2.2" aria-hidden="true" />
          </button>
        </div>
        <div class="tw:mt-2">
          <h2 id="cm-title" ref="titleRef" class="cmw__title tw:flex tw:flex-wrap tw:items-center tw:gap-3 tw:text-[length:var(--tpl-text-page)] tw:font-bold tw:leading-tight" tabindex="-1">
            <span><template v-if="studentName"><span class="tw:whitespace-nowrap">{{ studentName }}</span> · </template><span class="tw:whitespace-nowrap">{{ subjectLabel }}</span></span>
            <Badge variant="outline" class="cmw__badge tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)]" :class="[`cmw__badge--${statusTone}`, badgeCls[statusTone]]" data-testid="course-manager-status">{{ statusLabel }}</Badge>
          </h2>
          <p class="tw:mt-1 tw:text-tpl-secondary">{{ course.teacher_name || '待指派' }} · {{ classTypeLabel }}<template v-if="course.room_name"> · {{ course.room_name }}</template></p>
        </div>
        <div v-if="actionModel?.primary || actionModel?.groups?.length" class="tw:mt-3 tw:flex tw:items-center tw:gap-3">
          <Button v-if="actionModel?.primary" :class="cls.primary" class="tw:flex-1 tw:sm:flex-none" data-testid="course-manager-primary" @click="act(actionModel.primary.id)">{{ actionModel.primary.label }}</Button>
          <ActionMenu
            v-if="actionModel?.groups?.length"
            :groups="actionModel.groups"
            :label="`${subjectLabel || '課程'} 的更多課程操作`"
            trigger-class="cmw__more"
            @select="act"
          />
        </div>
      </header>
      <nav class="cmw__tabs tw:grid tw:grid-cols-4 tw:gap-1 tw:rounded-2xl tw:bg-secondary tw:p-1" role="tablist" aria-label="課程管理分區">
        <button
          v-for="t in tabs"
          :key="t.id"
          type="button"
          role="tab"
          class="cmw__tab tw:h-14 tw:rounded-xl tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:text-tpl-secondary"
          :class="{ on: activeTab === t.id, 'tw:bg-card tw:text-foreground tw:shadow-sm': activeTab === t.id }"
          :data-testid="`course-manager-tab-${t.id}`"
          :aria-selected="activeTab === t.id"
          :id="`cm-tab-${t.id}`"
          :aria-controls="`cm-panel-${t.id}`"
          :tabindex="activeTab === t.id ? 0 : -1"
          @keydown="onTabKey"
          @click="activeTab = t.id"
        >{{ t.label }}</button>
      </nav>
      <div class="cmw__body">
        <section v-if="monthlySummary && ['overview', 'billing'].includes(activeTab)" class="cmw__card tw:mb-4 tw:p-5" :class="cls.group" data-testid="monthly-payment-periods" aria-label="月結期間與付款">
          <h3 class="tw:text-[length:var(--tpl-text-list)] tw:font-bold">合約 {{ monthlySummary.contract_start || '待確認' }} ～ {{ monthlySummary.contract_end || '待確認' }}</h3>
          <label class="tw:mt-3 tw:flex tw:flex-wrap tw:items-center tw:gap-3">查看帳期
            <select v-model="selectedPeriod" aria-label="查看月結帳期" class="tw:h-14 tw:min-w-0 tw:flex-1 tw:rounded-xl tw:border tw:bg-card tw:px-3">
              <option value="">{{ monthlySummary.billing_period }}（待處理）</option>
              <option v-for="period in monthlySummary.periods" :key="period.billing_period" :value="period.billing_period">{{ period.billing_period }}</option>
            </select>
          </label>
          <div class="cmw__period-table">
            <table>
              <thead><tr><th scope="col">帳期／服務期間</th><th scope="col">帳單付款登錄</th><th scope="col">未收金額</th></tr></thead>
              <tbody><tr v-for="period in monthlySummary.periods" :key="period.billing_period">
                <th scope="row">{{ period.billing_period }}<small v-if="period.period_start"> {{ period.period_start }} ～ {{ period.period_end }}</small></th>
                <td>{{ periodPaymentLabel(period.payment_status) }}<small v-if="period.source === 'unattributed'">尚無對應帳單</small><div v-if="period.amount_discrepancy" class="tw:mt-2" data-testid="period-amount-mismatch"><p>帳單金額和已上堂數算的不同，請到帳單更正。</p><Button type="button" variant="secondary" :class="cls.second" class="tw:mt-2" data-testid="period-fix-invoice" @click="act('invoice')">到帳單更正</Button></div></td>
                <td>{{ period.outstanding_amount == null ? '待核對' : `NT$ ${Number(period.outstanding_amount).toLocaleString()}` }}</td>
              </tr></tbody>
            </table>
          </div>
          <template v-if="gapMonths.length">
            <p class="cmw__hint">系統登錄收款：{{ monthlySummary.registered_paid_amount == null ? '待核對' : `NT$ ${Number(monthlySummary.registered_paid_amount).toLocaleString()}` }}。此為帳務紀錄，實際收款與月份歸屬仍須核對。</p>
            <div class="cmw__period-table">
              <table aria-label="依上課日期核對費用">
                <thead><tr><th scope="col">上課月份</th><th scope="col">已上課費用試算</th><th scope="col">需核對事項</th></tr></thead>
                <tbody><tr v-for="month in gapMonths" :key="month.calendar_month">
                  <th scope="row">{{ month.calendar_month }}</th>
                  <td><details><summary>{{ month.completed_sessions }} 堂 · {{ month.estimated_charge == null ? '費率待核對' : `NT$ ${Number(month.estimated_charge).toLocaleString()}` }}</summary>
                    <ul><li v-for="session in month.sessions" :key="session.session_id">{{ session.date }} · {{ session.rate_unit === 'hour' ? '每小時' : '每堂' }} NT$ {{ Number(session.rate).toLocaleString() }} · 試算 {{ session.estimated_charge == null ? '待核對' : `NT$ ${Number(session.estimated_charge).toLocaleString()}` }}<template v-if="session.amendment_id">（依定價調整）</template></li></ul>
                  </details></td>
                  <td><span v-if="month.uncovered_sessions">{{ month.uncovered_sessions }} 堂沒有對應帳單服務期間</span><small v-if="month.outside_contract_sessions">{{ month.outside_contract_sessions }} 堂超出合約日期，需一併更正</small></td>
                </tr></tbody>
              </table>
            </div>
            <p class="cmw__hint">試算依已上課日期與當日費率，包含越界堂次；請假、取消與待上課不計入。試算金額尚未成為應收帳單。</p>
          </template>
          <div v-if="monthlySummary.review_required" class="tw:mt-3">
            <Button variant="secondary" :class="cls.second" @click="act('invoice')">查看登錄帳單與收款</Button>
          </div>
        </section>

        <div
          v-if="activeTab === 'overview'"
          id="cm-panel-overview"
          role="tabpanel"
          aria-labelledby="cm-tab-overview"
          data-testid="course-manager-overview"
          class="cmw__stack"
        >
          <BlurFade :class="cls.group" class="tw:p-5" aria-label="營運狀態">
            <p :class="cls.label">堂次</p>
            <p class="tw:text-[length:var(--tpl-text-num-sm)] tw:font-bold tw:leading-none">{{ remainingLabel || '—' }}</p>
          </BlurFade>
          <BlurFade :delay="60" :class="cls.group" aria-label="課程摘要">
            <div v-for="row in [
              { k: '下一堂', v: nextSessionLabel || '—', icon: CalendarClock, c: 'blue' },
              { k: '付款', v: effectivePaymentLabel || '—', icon: Wallet, c: 'green' },
              { k: '固定時段', v: scheduleSummary || '未排定', icon: Repeat, c: 'violet' },
            ]" :key="row.k" class="tw:flex tw:min-h-[var(--tpl-row-height)] tw:items-center tw:gap-4 tw:border-t tw:px-5 tw:py-3 first:tw:border-t-0">
              <span :class="cls.tile" :style="{ background: cat(row.c) }"><component :is="row.icon" :size="22" :stroke-width="2.2" aria-hidden="true" /></span>
              <div class="tw:min-w-0"><p :class="cls.label">{{ row.k }}</p><p class="tw:text-[length:var(--tpl-text-list)] tw:font-semibold tw:break-words">{{ row.v }}</p></div>
            </div>
          </BlurFade>
          <BlurFade v-if="overviewNeeds.length" :delay="120" class="cmw__card--needs tw:p-5" :class="cls.group">
            <h3 class="tw:text-[length:var(--tpl-text-list)] tw:font-bold">需要處理</h3>
            <div v-for="n in overviewNeeds" :key="n.id" class="cmw__need tw:mt-3 tw:flex tw:items-center tw:justify-between tw:gap-3 tw:border-t tw:pt-3">
              <div><strong>{{ n.title }}</strong><p v-if="n.detail" class="tw:text-tpl-secondary">{{ n.detail }}</p></div>
              <Button v-if="n.action" :class="cls.primary" @click="act(n.action)">{{ n.actionLabel || '前往' }}</Button>
            </div>
          </BlurFade>
          <BlurFade :delay="180" class="cmw__card--life tw:p-5" :class="cls.group">
            <h3 class="tw:text-[length:var(--tpl-text-list)] tw:font-bold">課程狀態</h3>
            <p class="tw:text-tpl-secondary">目前：{{ statusLabel }}</p>
            <div class="tw:mt-3 tw:flex tw:flex-wrap tw:gap-3">
              <Button v-if="course.status !== 'inactive'" variant="secondary" :class="cls.second" @click="act('pause')">暫停課程</Button>
              <Button v-else :class="cls.primary" @click="act('resume')">恢復課程</Button>
              <Button v-if="canClose" variant="secondary" :class="cls.second" @click="act('close')">結束課程</Button>
            </div>
          </BlurFade>
        </div>

        <div
          v-else-if="activeTab === 'sessions'"
          id="cm-panel-sessions"
          role="tabpanel"
          aria-labelledby="cm-tab-sessions"
          data-testid="course-manager-sessions"
          class="cmw__stack"
        >
          <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3">
            <div class="tw:flex tw:flex-wrap tw:gap-3">
              <Button
                v-if="isSessionMode || isMonthlyMode"
                :class="cls.primary"
                data-testid="course-manager-schedule-cta"
                @click="act('manual-session')"
              >{{ primaryScheduleCta() }}</Button>
              <Button
                v-if="isSessionMode && canQuickAdd"
                variant="secondary"
                :class="cls.second"
                data-testid="course-manager-quick-add"
                @click="act('quick-add')"
              >補課／補登</Button>
              <Button variant="secondary" :class="cls.second" @click="$emit('toggle-notes')">{{ showSessionNotes ? '備註 ▲' : '備註 ▼' }}</Button>
              <Button v-if="cancelledUnits.length" variant="secondary" :class="cls.second" @click="$emit('toggle-cancelled')">{{ showCancelled ? '已調走／取消 ▲' : `含 ${cancelledUnits.length} 堂已調走／取消 ▼` }}</Button>
            </div>
            <div class="tw:grid tw:grid-cols-2 tw:gap-1 tw:rounded-2xl tw:bg-secondary tw:p-1" role="group" aria-label="堂次檢視">
              <button type="button" class="tw:h-14 tw:rounded-xl tw:border-0 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold" :class="sessionsView === 'calendar' ? 'tw:bg-card tw:shadow-sm' : 'tw:bg-transparent tw:text-tpl-secondary'" data-testid="course-manager-view-calendar" @click="sessionsView = 'calendar'">月曆</button>
              <button type="button" class="tw:h-14 tw:rounded-xl tw:border-0 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold" :class="sessionsView === 'list' ? 'tw:bg-card tw:shadow-sm' : 'tw:bg-transparent tw:text-tpl-secondary'" data-testid="course-manager-view-list" @click="sessionsView = 'list'">列表</button>
            </div>
          </div>
          <p v-if="sessionLoadFailed || planningStatus" class="cmw__hint">
            <strong>{{ sessionLoadFailed ? '堂次載入失敗' : planningStatus?.title }}</strong>
            — {{ sessionLoadFailed ? '目前無法確認最新堂次狀態。' : planningStatus?.message }}
            <Button v-if="sessionLoadFailed" :class="cls.primary" @click="act('retry-sessions')">重新載入</Button>
            <Button v-else-if="['quick_add','arrange_makeup'].includes(planningStatus?.action) && canQuickAdd" :class="cls.primary" @click="act('quick-add')">{{ planningStatus?.action === 'arrange_makeup' ? '安排補課' : '補排堂次' }}</Button>
          </p>
          <template v-if="sessionsView === 'calendar'">
            <CourseSessionCalendar
              v-if="calendarEnabled"
              :course="course"
              :sessions="sessionUnits"
              :create-enabled="createEnabled"
              :show-quick-add="false"
              @create-day="(p) => $emit('create-day', p)"
              :state-label="(u) => getSessionStateLabel(course, (u.date || '').slice(0, 10), u.id)"
              :can-move-session="canMoveLesson"
              @select-day="onSelectDay"
              @move-session="onMoveSession"
            />
            <p v-else class="cmw__hint">行事曆尚未啟用，請改用列表。</p>
          </template>
          <template v-else>
            <div v-if="listUnits.length" class="cmw__session-list" :class="cls.group" data-testid="course-manager-session-list">
              <div v-for="u in listUnits" :key="sessionRowKey(u)" class="cmw__session-item tw:border-t first:tw:border-t-0">
                <button
                  type="button"
                  class="cmw__session-row"
                  :class="[u.isProjected ? 'is-projected' : 'is-materialized', getSessionStateClass(course, (u.date || '').slice(0,10), u.id)]"
                  @click="$emit('open-session', { unit: u, date: (u.date || '').slice(0,10), id: u.id })"
                >
                  <span :class="cls.tile" :style="{ background: cat(u.isProjected ? 'violet' : 'blue') }"><Hash :size="22" :stroke-width="2.2" aria-hidden="true" /></span>
                  <span class="tw:min-w-0 tw:flex-1">
                    <span class="cmw__session-date tw:block tw:text-[length:var(--tpl-text-list)] tw:font-semibold">{{ formatSessionChipDate(u) }}</span>
                    <span class="cmw__session-seq tw:text-tpl-secondary">{{ sessionNo(u) ? `第${sessionNo(u)}堂` : '' }}</span>
                    <span v-if="showSessionNotes && isUserNote(u.note)" class="cmw__session-note tw:block tw:text-tpl-secondary">{{ u.note }}</span>
                  </span>
                  <span class="cmw__session-state tw:text-tpl-secondary">{{ u.isProjected ? '預排' : (getSessionStateLabel(course, (u.date || '').slice(0,10), u.id) || '已建立') }}</span>
                  <ChevronRight :size="22" class="tw:text-tpl-secondary" aria-hidden="true" />
                </button>
                <span v-if="canMoveLesson(u)" class="cmw__session-moves">
                  <Button variant="secondary" :class="cls.second" data-testid="lesson-reschedule" :aria-label="`調課 ${formatSessionChipDate(u)}`" @click="$emit('move-lesson', { mode: 'reschedule', unit: u })">調課</Button>
                  <Button variant="secondary" :class="cls.second" data-testid="lesson-substitute" :aria-label="`代課 ${formatSessionChipDate(u)}`" @click="$emit('move-lesson', { mode: 'substitute', unit: u })">代課</Button>
                </span>
              </div>
            </div>
            <p v-else class="cmw__hint">還沒有堂次，請先排課。</p>
          </template>
          <div v-if="showCancelled && cancelledUnits.length" class="cmw__session-list--cancelled" :class="cls.group" data-testid="course-manager-cancelled-list">
            <div v-for="u in cancelledUnits" :key="'cx-'+sessionRowKey(u)" class="cmw__session-row is-cancelled">
              <span class="cmw__session-date tw:flex-1 tw:font-semibold">{{ formatSessionChipDate(u) }}</span>
              <span class="cmw__session-state tw:text-tpl-secondary">已取消</span>
              <Button v-if="canRestoreCancelled(u)" variant="secondary" :class="cls.second" data-testid="drawer-restore-cancelled" @click="$emit('restore-cancelled', u)">復原</Button>
            </div>
          </div>
          <div v-if="pendingMakeups.length" class="cmw__card tw:p-5" :class="cls.group">
            <strong>待補課（{{ pendingMakeups.length }} 堂）</strong>
            <div v-for="ms in pendingMakeups" :key="ms.id" class="cmw__need tw:mt-3 tw:flex tw:items-center tw:justify-between tw:gap-3 tw:border-t tw:pt-3">
              <span>{{ formatMakeupDate(ms) }}</span>
              <Button variant="secondary" :class="cls.second" @click="act({ name: 'cancel-makeup', payload: ms })">取消補課</Button>
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
          class="cmw__stack"
        >
          <section class="cmw__card tw:p-5" :class="cls.group">
            <h3 class="tw:text-[length:var(--tpl-text-list)] tw:font-bold">帳務摘要</h3>
            <dl class="tw:mt-3 tw:grid tw:grid-cols-2 tw:gap-4">
              <div><dt :class="cls.label">計費</dt><dd class="tw:text-[length:var(--tpl-text-list)] tw:font-semibold">{{ billingType() }}</dd></div>
              <div><dt :class="cls.label">堂次</dt><dd class="tw:text-[length:var(--tpl-text-list)] tw:font-semibold">{{ remainingLabel || '—' }}</dd></div>
              <div><dt :class="cls.label">付款</dt><dd class="tw:text-[length:var(--tpl-text-list)] tw:font-semibold">{{ effectivePaymentLabel || '—' }}</dd></div>
              <div v-if="course.last_paid_at"><dt :class="cls.label">最近付款</dt><dd class="tw:text-[length:var(--tpl-text-list)] tw:font-semibold">{{ course.last_paid_at }}</dd></div>
            </dl>
            <div class="tw:mt-4 tw:flex tw:flex-wrap tw:gap-3">
              <Button variant="secondary" :class="cls.second" data-testid="course-manager-invoice" @click="act('invoice')">在這裡看帳務</Button>
              <Button v-if="course.usage_balance_status === 'review_required'" :class="cls.primary" @click="act('ledger')">堂數待對帳</Button>
            </div>
          </section>
          <section class="cmw__card tw:p-5" :class="cls.group">
            <h3 class="tw:text-[length:var(--tpl-text-list)] tw:font-bold">合約</h3>
            <div class="tw:mt-3 tw:flex tw:flex-wrap tw:gap-3">
              <Button v-if="isMonthlyMode && monthlySummary && canPreviewCorrection" variant="secondary" :class="cls.second" @click="act('monthly-correction')">預覽分期更正</Button>
              <Button :class="cls.primary" @click="act('purchase')">{{ purchaseLabel }}</Button>
              <Button variant="secondary" :class="cls.second" @click="act('contract-adjust')">合約／堂次調整</Button>
              <Button v-if="canPackagePreview" variant="secondary" :class="cls.second" @click="act('package-preview')">轉多科方案預檢</Button>
            </div>
          </section>
          <section v-if="paymentNoticeAvailable" class="cmw__card tw:p-5" :class="cls.group">
            <h3 class="tw:text-[length:var(--tpl-text-list)] tw:font-bold">收款</h3>
            <div class="tw:mt-3"><Button variant="secondary" :class="cls.second" @click="act('payment-slip')">產生繳費單</Button></div>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cmw{position:fixed;inset:0;z-index:1200;display:flex;justify-content:flex-end}
.cmw__scrim{position:absolute;inset:0;background:color-mix(in srgb,var(--ds-ink) 30%,transparent)}
.cmw__panel{position:relative;display:flex;flex-direction:column;width:min(1120px,100vw);height:100%;background:var(--ds-canvas-soft);color:var(--ds-ink);box-shadow:var(--ds-shadow-2)}
.cmw__head{padding:8px 20px 12px;border-bottom:1px solid var(--ds-hairline);background:var(--ds-canvas)}
.cmw__back,.cmw__x{border:0;background:transparent;cursor:pointer}
.cmw__x:hover,.cmw__back:hover{background:var(--ds-canvas-soft)}
.cmw__title:focus{outline:none}
.cmw__back:focus-visible,.cmw__x:focus-visible,.cmw__tab:focus-visible{outline:2px solid var(--ds-ink);outline-offset:2px}
.cmw *{border-color:var(--ds-hairline)}
.cmw__tabs{width:calc(100% - 40px);max-width:960px;margin:12px auto 0}
.cmw__tab{border:0;background:transparent;cursor:pointer;white-space:nowrap;font-size:inherit}
.cmw__body{flex:1;overflow:auto;padding:16px 20px 32px}
.cmw__stack{display:grid;gap:16px;width:100%;max-width:960px;margin:0 auto}
.cmw__hint{margin:8px 0 0;color:var(--ds-ink-secondary)}
.cmw__card--needs{border-color:color-mix(in srgb,var(--ds-warning) 35%,var(--ds-hairline))}
.cmw__session-item{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.cmw__session-row{display:flex;gap:16px;align-items:center;text-align:left;flex:1 1 100%;min-height:var(--tpl-row-height);padding:12px 20px;border:0;background:transparent;cursor:pointer;color:inherit;font-size:inherit}
.cmw__session-row.is-cancelled{cursor:default;opacity:.85}
.cmw__session-moves{display:flex;gap:8px;padding:0 20px 12px;margin-left:auto}
.cmw__period-table{overflow-x:auto;margin-top:12px}
.cmw__period-table table{width:100%;border-collapse:collapse;font-size:var(--tpl-text-caption);font-variant-numeric:tabular-nums}
.cmw__period-table th,.cmw__period-table td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--ds-hairline)}
.cmw__period-table small{display:block;font-weight:400;color:var(--ds-ink-secondary)}
:deep(.cmw__more){min-width:56px;min-height:56px;border-radius:999px;font-size:var(--tpl-text-body)}
@media (max-width:720px){.cmw__head{padding:4px 12px 12px}.cmw__tabs{width:calc(100% - 24px)}.cmw__body{padding:12px}}
@media (prefers-reduced-motion:reduce){.cmw *{transition:none!important;animation:none!important}}
</style>
