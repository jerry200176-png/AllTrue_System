<script>
import { computed } from 'vue';
import CourseSessionCalendar from './CourseSessionCalendar.vue';

const TABS = [
  { id: 'overview', label: '總覽' },
  { id: 'sessions', label: '排課與堂次' },
  { id: 'settings', label: '課程設定' },
  { id: 'billing', label: '帳務與合約' },
  { id: 'records', label: '紀錄' },
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
    const activeTab = computed({
      get: () => props.tab,
      set: (v) => emit('update:tab', v),
    });
    const act = (evt) => {
      if (typeof evt === 'string') emit('action', { name: evt });
      else emit('action', evt && typeof evt === 'object' ? evt : { name: evt });
    };
    const scheduleCta = () => (props.isManualOccurrence ? '＋新增下一堂' : (props.isMonthlyMode ? '排月結' : '排課'));
    const billingType = () => (props.course.payment_type === 'session' ? '堂數制' : '月結');
    const materialized = computed(() => (props.sessionUnits || []).filter((u) => !u.isProjected));
    return { tabs: TABS, activeTab, act, scheduleCta, billingType, materialized };
  },
};
</script>

<template>
  <div class="cmw" role="dialog" aria-modal="true" :aria-label="`管理課程：${subjectLabel || '課程'}`" data-testid="course-manager" @keydown.esc.prevent="$emit('close')">
    <div class="cmw__scrim" @click="$emit('close')" />
    <div class="cmw__panel">
      <header class="cmw__head">
        <button type="button" class="cmw__back" data-testid="course-manager-close" @click="$emit('close')">
          <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> 課程管理
        </button>
        <div class="cmw__id">
          <h2 class="cmw__title">
            <template v-if="studentName">{{ studentName }} · </template>{{ subjectLabel }}
            <span class="cmw__badge">{{ statusLabel }}</span>
          </h2>
          <p class="cmw__meta">{{ course.teacher_name || '待指派' }} · {{ classTypeLabel }}<template v-if="course.room_name"> · {{ course.room_name }}</template></p>
          <p class="cmw__kpis"><span>{{ remainingLabel }}</span><span v-if="nextSessionLabel">下堂 {{ nextSessionLabel }}</span><span>{{ paymentLabel }}</span><span v-if="scheduleSummary">{{ scheduleSummary }}</span></p>
        </div>
      </header>
      <nav class="cmw__tabs" aria-label="課程管理分區">
        <button v-for="t in tabs" :key="t.id" type="button" class="cmw__tab" :class="{ on: activeTab === t.id }" :data-testid="`course-manager-tab-${t.id}`" :aria-selected="activeTab === t.id" @click="activeTab = t.id">{{ t.label }}</button>
      </nav>
      <div class="cmw__body">
        <div v-if="activeTab === 'overview'" data-testid="course-manager-overview" class="cmw__stack">
          <section class="cmw__card">
            <h3>這門課</h3>
            <dl class="cmw__facts">
              <div><dt>學生</dt><dd>{{ studentName || '—' }}</dd></div>
              <div><dt>科目</dt><dd>{{ subjectLabel || '—' }}</dd></div>
              <div><dt>狀態</dt><dd>{{ statusLabel || '—' }}</dd></div>
              <div><dt>班型</dt><dd>{{ classTypeLabel || '—' }}</dd></div>
              <div><dt>老師</dt><dd>{{ course.teacher_name || '待指派' }}</dd></div>
              <div><dt>教室</dt><dd>{{ course.room_name || '—' }}</dd></div>
              <div><dt>排課</dt><dd>{{ scheduleSummary || '未排定' }}</dd></div>
              <div><dt>堂次</dt><dd>{{ remainingLabel || '—' }}</dd></div>
              <div><dt>下一堂</dt><dd>{{ nextSessionLabel || '—' }}</dd></div>
              <div><dt>繳費</dt><dd>{{ paymentLabel || '—' }}</dd></div>
            </dl>
          </section>
          <section v-if="overviewNeeds.length" class="cmw__card">
            <h3>需要處理</h3>
            <div v-for="n in overviewNeeds" :key="n.id" class="cmw__need">
              <div><strong>{{ n.title }}</strong><p v-if="n.detail">{{ n.detail }}</p></div>
              <button v-if="n.action" type="button" class="small primary" @click="act(n.action)">{{ n.actionLabel || '前往' }}</button>
            </div>
          </section>
          <section class="cmw__card">
            <h3>課程狀態</h3>
            <p>目前：{{ statusLabel }}</p>
            <div class="cmw__row">
              <button v-if="course.status !== 'inactive'" type="button" class="small ghost" @click="act('pause')">暫停課程</button>
              <button v-else type="button" class="small primary" @click="act('resume')">恢復課程</button>
              <button v-if="canClose" type="button" class="small ghost" @click="act('close')">結束課程</button>
            </div>
          </section>
          <section class="cmw__card cmw__danger">
            <h3>危險操作</h3>
            <button type="button" class="small danger" @click="act('delete')">刪除課程</button>
          </section>
        </div>

        <div v-else-if="activeTab === 'sessions'" data-testid="course-manager-sessions" class="cmw__stack">
          <div class="cmw__row">
            <button v-if="isSessionMode || isMonthlyMode" type="button" class="small primary" @click="act('manual-session')">{{ scheduleCta() }}</button>
            <button v-if="isSessionMode && canQuickAdd" type="button" class="small ghost" @click="act('quick-add')">補課 / 補登</button>
            <button v-else-if="isMonthlyMode" type="button" class="small ghost" @click="act('monthly-session')">新增月結堂次</button>
            <button type="button" class="small ghost" @click="$emit('toggle-notes')">{{ showSessionNotes ? '備註 ▲' : '備註 ▼' }}</button>
            <button v-if="cancelledUnits.length" type="button" class="small ghost" @click="$emit('toggle-cancelled')">{{ showCancelled ? '已調走／取消 ▲' : `含 ${cancelledUnits.length} 堂已調走／取消 ▼` }}</button>
          </div>
          <p v-if="sessionLoadFailed || planningStatus" class="cmw__hint">
            <strong>{{ sessionLoadFailed ? '堂次載入失敗' : planningStatus?.title }}</strong>
            — {{ sessionLoadFailed ? '目前無法確認最新堂次狀態。' : planningStatus?.message }}
            <button v-if="sessionLoadFailed" type="button" class="small primary" @click="act('retry-sessions')">重新載入</button>
            <button v-else-if="['quick_add','arrange_makeup'].includes(planningStatus?.action) && canQuickAdd" type="button" class="small primary" @click="act('quick-add')">{{ planningStatus?.action === 'arrange_makeup' ? '安排補課' : '補排堂次' }}</button>
          </p>
          <CourseSessionCalendar v-if="calendarEnabled" :course="course" :sessions="sessionUnits" :create-enabled="createEnabled" @create-day="(p) => $emit('create-day', p)" @quick-add="act('quick-add')" />
          <p v-else class="cmw__hint">行事曆功能尚未啟用；仍可使用下方堂次列表。</p>
          <div v-if="sessionUnits.length" class="dates-chip-grid">
            <button v-for="u in sessionUnits" :key="sessionRowKey(u)" type="button" :class="['date-chip','date-chip-clickable', u.isProjected ? 'date-chip--projected' : 'date-chip--materialized', getSessionStateClass(course, (u.date || '').slice(0,10), u.id)]" @click="$emit('open-session', { unit: u, date: (u.date || '').slice(0,10), id: u.id })">
              <span v-if="getSessionNumber(course, (u.date || '').slice(0,10), u.id)" class="chip-seq">第{{ getSessionNumber(course, (u.date || '').slice(0,10), u.id) }}堂</span>
              <span class="chip-date">{{ formatSessionChipDate(u) }}</span>
              <span v-if="u.isProjected" class="chip-state chip-state--projected">預排</span>
              <span v-else-if="getSessionStateLabel(course, (u.date || '').slice(0,10), u.id)" class="chip-state">{{ getSessionStateLabel(course, (u.date || '').slice(0,10), u.id) }}</span>
              <span v-if="showSessionNotes && isUserNote(u.note)" class="chip-note-text">{{ u.note }}</span>
            </button>
          </div>
          <p v-else class="cmw__hint">尚無可顯示堂次（請確認排課設定）。</p>
          <div v-if="showCancelled && cancelledUnits.length" class="dates-chip-grid">
            <span v-for="u in cancelledUnits" :key="'cx-'+sessionRowKey(u)" class="date-chip cancelled"><span class="chip-date">{{ formatSessionChipDate(u) }}</span><span class="chip-state">已取消</span></span>
          </div>
          <div v-if="pendingMakeups.length" class="cmw__card">
            <strong>待補課（{{ pendingMakeups.length }} 堂）</strong>
            <div v-for="ms in pendingMakeups" :key="ms.id" class="cmw__need">
              <span>{{ formatMakeupDate(ms) }}</span>
              <button type="button" class="small" @click="act({ name: 'cancel-makeup', payload: ms })">取消補課</button>
            </div>
          </div>
        </div>

        <div v-else-if="activeTab === 'settings'" class="cmw__settings" data-testid="course-manager-settings">
          <slot name="settings" />
        </div>

        <div v-else-if="activeTab === 'billing'" data-testid="course-manager-billing" class="cmw__stack">
          <section class="cmw__card">
            <h3>帳務摘要</h3>
            <dl class="cmw__facts">
              <div><dt>計費</dt><dd>{{ billingType() }}</dd></div>
              <div><dt>堂次</dt><dd>{{ remainingLabel || '—' }}</dd></div>
              <div><dt>付款</dt><dd>{{ paymentLabel || '—' }}</dd></div>
              <div v-if="course.last_paid_at"><dt>最近付款</dt><dd>{{ course.last_paid_at }}</dd></div>
            </dl>
            <div class="cmw__row">
              <button type="button" class="small ghost" @click="act('invoice')">查看帳單</button>
              <button type="button" class="small ghost" @click="act('tuition')">查看帳務</button>
              <button v-if="course.usage_balance_status === 'review_required'" type="button" class="small primary" @click="act('ledger')">堂數待對帳</button>
            </div>
          </section>
          <section class="cmw__card">
            <h3>合約操作</h3>
            <div class="cmw__row">
              <button type="button" class="small primary" @click="act('purchase')">{{ purchaseLabel }}</button>
              <button type="button" class="small ghost" @click="act('contract-adjust')">合約／堂次調整</button>
              <button v-if="canPackagePreview" type="button" class="small ghost" @click="act('package-preview')">轉多科方案預檢</button>
            </div>
          </section>
          <section class="cmw__card">
            <h3>收款</h3>
            <div class="cmw__row">
              <button v-if="paymentNoticeAvailable" type="button" class="small ghost" @click="act('payment-slip')">繳費通知</button>
              <button type="button" class="small ghost" @click="act('tuition')">前往帳務中心</button>
            </div>
            <p class="cmw__hint">收款與對帳仍走既有帳務流程。</p>
          </section>
        </div>

        <div v-else-if="activeTab === 'records'" data-testid="course-manager-records" class="cmw__stack">
          <section class="cmw__card">
            <h3>已建立堂次</h3>
            <p v-if="!materialized.length" class="cmw__hint">尚無可列示的已建立堂次。</p>
            <ul v-else class="cmw__list">
              <li v-for="u in materialized" :key="sessionRowKey(u)"><span>{{ formatSessionChipDate(u) }}</span><span>{{ getSessionStateLabel(course, (u.date || '').slice(0,10), u.id) || '已建立' }}</span></li>
            </ul>
          </section>
          <section class="cmw__card">
            <h3>已取消／已調走</h3>
            <p v-if="!cancelledUnits.length" class="cmw__hint">沒有已取消或已調走堂次。</p>
            <ul v-else class="cmw__list">
              <li v-for="u in cancelledUnits" :key="'r-'+sessionRowKey(u)"><span>{{ formatSessionChipDate(u) }}</span><span>已取消</span></li>
            </ul>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cmw{position:fixed;inset:0;z-index:1200;display:flex;justify-content:flex-end}
.cmw__scrim{position:absolute;inset:0;background:rgba(18,22,28,.45)}
.cmw__panel{position:relative;display:flex;flex-direction:column;width:min(1120px,100vw);height:100%;background:#f7f5f1;color:#1c1917;box-shadow:-12px 0 40px rgba(28,25,23,.18)}
.cmw__head{padding:12px 18px 8px;border-bottom:1px solid #e7e2da;background:#fffdf9}
.cmw__back{display:inline-flex;align-items:center;gap:4px;border:0;background:transparent;color:#57534e;font-size:.875rem;cursor:pointer;padding:2px 0}
.cmw__title{margin:6px 0 4px;font-size:1.2rem;font-weight:650;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.cmw__badge{font-size:.75rem;font-weight:600;padding:2px 8px;border-radius:999px;background:#e7f0e8;color:#2f5d3a}
.cmw__meta,.cmw__kpis,.cmw__hint{margin:0;color:#57534e;font-size:.875rem}
.cmw__kpis,.cmw__row{display:flex;flex-wrap:wrap;gap:8px 12px}
.cmw__tabs{display:flex;gap:2px;padding:0 10px;border-bottom:1px solid #e7e2da;background:#fffdf9;overflow-x:auto}
.cmw__tab{border:0;background:transparent;padding:11px 12px;color:#57534e;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap}
.cmw__tab.on{color:#1c1917;border-bottom-color:#2f5d3a;font-weight:600}
.cmw__body{flex:1;overflow:auto;padding:14px 18px 24px}
.cmw__stack{display:grid;gap:12px;max-width:760px}
.cmw__card{background:#fffdf9;border:1px solid #e7e2da;border-radius:10px;padding:12px 14px}
.cmw__card h3{margin:0 0 8px;font-size:.95rem}
.cmw__facts{margin:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px 12px}
.cmw__facts dt{font-size:.72rem;color:#78716c}
.cmw__facts dd{margin:2px 0 0}
.cmw__need{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:6px 0;border-top:1px solid #efeae3}
.cmw__need:first-of-type{border-top:0}
.cmw__need p{margin:2px 0 0;color:#57534e;font-size:.85rem}
.cmw__danger{border-color:#e8c5c0}
.cmw__danger h3{color:#9f2d2d}
.cmw__list{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.cmw__list li{display:flex;justify-content:space-between;gap:12px;padding:4px 0;border-bottom:1px solid #efeae3;font-size:.9rem}
button.danger{border:1px solid #c45c5c;background:#fff;color:#9f2d2d;border-radius:6px;padding:4px 10px;cursor:pointer}
@media (max-width:720px){.cmw__panel{width:100vw}.cmw__body{padding:12px}}
</style>
