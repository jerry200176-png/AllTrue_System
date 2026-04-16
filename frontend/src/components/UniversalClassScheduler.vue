<template>
  <div class="modal-overlay">
    <div class="modal universal-scheduler-modal">
      <header class="scheduler-header">
        <h3>{{ title }}</h3>
        <p class="scheduler-subtitle">
          {{ packageMode
            ? '建立多科共用堂數方案：多個科目共享同一份堂數池，每次上課（不論科目）扣 1 堂。'
            : '手動勾選的日期可自由選擇，不限固定上課星期；系統會依固定星期自動補齊其餘堂次，若今天尚未下課也可排入今日。'
          }}
        </p>
        <div class="mode-tabs">
          <button
            type="button"
            :class="['mode-tab', { active: !packageMode }]"
            @click="packageMode = false"
          >一般課程</button>
          <button
            type="button"
            :class="['mode-tab', { active: packageMode }]"
            @click="packageMode = true"
          >多科共用方案</button>
        </div>
      </header>

      <div v-if="packageMode" class="scheduler-layout package-layout">
        <section class="panel-stack">
          <article class="scheduler-card">
            <h4>方案基本資料</h4>
            <div class="scheduler-grid">
              <div class="form-group">
                <label>學生 *</label>
                <SearchableSelect
                  v-model="pkgForm.student_id"
                  :options="studentOptions"
                  placeholder="輸入學生姓名搜尋..."
                />
              </div>
              <div class="form-group">
                <label>方案名稱 *</label>
                <input v-model="pkgForm.name" type="text" placeholder="例：王小明 24 堂多科方案" maxlength="128" />
              </div>
              <div class="form-group">
                <label>總堂數 *</label>
                <input v-model.number="pkgForm.total_sessions" type="number" min="1" max="999" />
              </div>
              <div class="form-group">
                <label>每堂費率 *</label>
                <input v-model.number="pkgForm.rate" type="number" min="0" step="50" />
              </div>
              <div class="form-group">
                <label>上課類型 *</label>
                <select v-model="pkgForm.class_type">
                  <option value="one_on_one">一對一</option>
                  <option value="one_on_two">一對二</option>
                  <option value="one_on_three">一對三</option>
                  <option value="tutoring">輔導</option>
                </select>
              </div>
              <div class="form-group">
                <label>繳費日期（選填）</label>
                <input v-model="pkgForm.paid_at" type="date" />
                <p v-if="pkgForm.paid_at" class="field-note" style="color:#2e7d32;">已填寫繳費日期，儲存後將自動標示為已繳費</p>
              </div>
            </div>
          </article>

          <article class="scheduler-card">
            <h4>科目清單（{{ pkgForm.subjects.length }} 科）</h4>
            <div
              v-for="(subj, idx) in pkgForm.subjects"
              :key="idx"
              :class="['pkg-card', { 'pkg-card-active': pkgActiveSubjectIdx === idx }]"
              :style="{ borderLeftColor: pkgSubjectColor(idx) }"
              @click="pkgActiveSubjectIdx = idx"
            >
              <div class="pkg-card-header">
                <span class="pkg-card-dot" :style="{ background: pkgSubjectColor(idx) }"></span>
                <div class="pkg-card-fields">
                  <select v-model="subj.subject" class="pkg-inline-select" @click.stop>
                    <option v-for="s in subjectOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                  </select>
                  <SearchableSelect
                    v-model="subj.teacher_id"
                    :options="teacherOptions"
                    placeholder="老師"
                    class="pkg-inline-teacher"
                    @click.stop
                  />
                  <input v-model.number="subj.duration_hours" type="number" min="0.5" step="0.5" class="pkg-inline-dur" title="時長（小時）" @click.stop />
                  <input v-model="subj.start_date" type="date" class="pkg-inline-date" title="開始日期" @click.stop />
                </div>
                <button
                  v-if="pkgForm.subjects.length > 1"
                  type="button"
                  class="pkg-remove-btn"
                  title="移除此科目"
                  @click.stop="pkgForm.subjects.splice(idx, 1); if (pkgActiveSubjectIdx >= pkgForm.subjects.length) pkgActiveSubjectIdx = Math.max(0, pkgForm.subjects.length - 1)"
                >✕</button>
              </div>
              <div v-if="subj.confirmed_dates.length > 0" class="pkg-card-dates">
                <span class="pkg-card-count">{{ subj.confirmed_dates.length }} 堂已補登</span>
                <span
                  v-for="(d, di) in subj.confirmed_dates"
                  :key="d"
                  class="pkg-date-chip"
                  :style="{ borderColor: pkgSubjectColor(idx), color: pkgSubjectColor(idx) }"
                >{{ d.slice(5) }}<button type="button" @click.stop="removePkgConfirmedDate(idx, di)">✕</button></span>
              </div>
              <div v-else class="pkg-card-dates">
                <span class="field-note">點選右側月曆補登已上課日期</span>
              </div>
            </div>
            <button
              v-if="pkgForm.subjects.length < 10"
              type="button"
              class="pkg-add-btn"
              @click="addPkgSubject"
            >＋ 新增科目</button>
          </article>
        </section>

        <section class="panel-stack">
          <article class="scheduler-card calendar-card">
            <div class="calendar-header">
              <button class="ghost small" type="button" @click="shiftPkgMonth(-1)">上個月</button>
              <strong>{{ pkgMonthLabel }}</strong>
              <button class="ghost small" type="button" @click="shiftPkgMonth(1)">下個月</button>
            </div>
            <div class="pkg-cal-active-label">
              點選日期補登至：<strong :style="{ color: pkgSubjectColor(pkgActiveSubjectIdx) }">{{ pkgSubjectLabel(pkgForm.subjects[pkgActiveSubjectIdx]?.subject) }}</strong>
            </div>
            <div class="pkg-cal-tabs">
              <button
                v-for="(subj, idx) in pkgForm.subjects"
                :key="idx"
                type="button"
                :class="['pkg-cal-tab', { active: pkgActiveSubjectIdx === idx }]"
                :style="pkgActiveSubjectIdx === idx ? { background: pkgSubjectColor(idx), borderColor: pkgSubjectColor(idx) } : { borderColor: pkgSubjectColor(idx), color: pkgSubjectColor(idx) }"
                @click="pkgActiveSubjectIdx = idx"
              >{{ pkgSubjectLabel(subj.subject) }}</button>
            </div>
            <div class="calendar-weekdays">
              <span v-for="wd in weekdayText" :key="wd">{{ wd }}</span>
            </div>
            <div class="calendar-grid">
              <button
                v-for="cell in pkgCalendarCells"
                :key="cell.key"
                type="button"
                :class="['calendar-cell', { muted: !cell.inMonth || !cell.isPast, 'pkg-cell-disabled': !cell.isPast }]"
                :disabled="!cell.isPast"
                @click="onPkgDateClick(cell)"
              >
                <span>{{ cell.day }}</span>
                <div v-if="cell.subjectIndices.length > 0" class="pkg-cell-dots">
                  <span
                    v-for="si in cell.subjectIndices"
                    :key="si"
                    class="pkg-cell-dot"
                    :style="{ background: pkgSubjectColor(si) }"
                  ></span>
                </div>
              </button>
            </div>
          </article>

          <article class="scheduler-card">
            <h4>方案摘要</h4>
            <div class="pkg-summary-grid">
              <div class="pkg-summary-item pkg-summary-total">
                <div class="pkg-summary-num">{{ pkgForm.total_sessions }}</div>
                <div class="pkg-summary-lbl">總堂數</div>
              </div>
              <div class="pkg-summary-item pkg-summary-used">
                <div class="pkg-summary-num">{{ pkgTotalConfirmedCount }}</div>
                <div class="pkg-summary-lbl">已補登</div>
              </div>
              <div class="pkg-summary-item pkg-summary-remain">
                <div class="pkg-summary-num">{{ Math.max(0, pkgForm.total_sessions - pkgTotalConfirmedCount) }}</div>
                <div class="pkg-summary-lbl">剩餘</div>
              </div>
            </div>
            <div class="pkg-summary-meta">
              <span>{{ pkgForm.subjects.length }} 科</span>
              <span>{{ pkgForm.rate }} 元/堂</span>
              <span>{{ { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' }[pkgForm.class_type] || pkgForm.class_type }}</span>
            </div>
            <p class="hint-text">
              方案建立後，每個科目會產生獨立的學生課程，堂數從共用池扣除。
              補登的日期會建立「已上課」堂次並自動扣堂。
            </p>
          </article>
        </section>
      </div>

      <div v-else class="scheduler-layout">
        <section class="panel-stack">
          <article class="scheduler-card">
            <h4>欄位設定</h4>
            <div class="scheduler-grid">
              <div class="form-group">
                <label>學生 *</label>
                <SearchableSelect
                  v-model="form.student_id"
                  :options="studentOptions"
                  placeholder="輸入學生姓名搜尋..."
                />
              </div>

              <div class="form-group">
                <label>老師 *</label>
                <SearchableSelect
                  v-model="form.teacher_id"
                  :options="teacherOptions"
                  placeholder="輸入老師姓名搜尋..."
                />
              </div>

              <div class="form-group">
                <label>預設科目 *</label>
                <select v-model="form.subject">
                  <option v-for="s in subjectOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <p class="field-note">新增星期／新增時段時的預設科目；下方各時段可改為不同科目（會建立多筆學生課程）。</p>
              </div>

              <div v-if="scopeWarning" class="scope-warning-banner" style="grid-column: 1 / -1;">
                ⚠️ {{ scopeWarning }}（仍可儲存）
              </div>

              <div class="form-group">
                <label>上課類型 *</label>
                <select v-model="form.class_type">
                  <option value="one_on_one">一對一</option>
                  <option value="one_on_two">一對二</option>
                  <option value="one_on_three">一對三</option>
                  <option value="tutoring">輔導</option>
                  <option value="trial">試聽</option>
                </select>
              </div>

              <div class="form-group">
                <label>{{ hasPerDayDuration ? '每小時費用 *' : '單堂費用 *' }}</label>
                <input v-model.number="form.price_per_session" type="number" min="0" step="50" />
              </div>

              <div class="form-group">
                <label>繳費方式 *</label>
                <select v-model="form.payment_type">
                  <option value="session">按堂數</option>
                  <option value="monthly">每月固定</option>
                </select>
              </div>

              <div v-if="form.payment_type === 'session'" class="form-group">
                <label>購買總堂數 *</label>
                <input v-model.number="form.total_classes" type="number" min="1" />
              </div>

              <template v-else>
                <div class="form-group">
                  <label>結算日 *</label>
                  <select v-model.number="form.settlement_day">
                    <option :value="null">請選擇</option>
                    <option v-for="day in settlementDayOptions" :key="day" :value="day">每月 {{ day }} 號</option>
                  </select>
                </div>

                <div class="form-group">
                  <label>本月預排堂數 *</label>
                  <input v-model.number="form.monthly_sessions" type="number" min="1" />
                  <p class="field-note">月結課不扣購買堂數，這裡只決定本次要先建立幾堂課。</p>
                  <p v-if="monthlyCapacityHint" class="field-note">{{ monthlyCapacityHint }}</p>
                  <p v-if="monthlyCapacityWarning" class="field-note warning-text">{{ monthlyCapacityWarning }}</p>
                </div>
              </template>

              <div class="form-group">
                <label>上課教室</label>
                <select v-model="form.room_id">
                  <option value="">未指定</option>
                  <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
              </div>

              <div class="form-group">
                <label>繳費日期（選填）</label>
                <input v-model="form.paid_at" type="date" />
                <p v-if="form.paid_at" class="field-note" style="color:#2e7d32;">已填寫繳費日期，儲存後將自動標示為已繳費</p>
              </div>

              <div class="form-group">
                <label>開課日 *</label>
                <input v-model="form.course_start_date" type="date" />
                <p class="field-note">系統自動排課將從此日起算，不會在此日之前建立預排堂次。</p>
                <p v-if="courseStartDateFarWarning" class="field-note warning-text">{{ courseStartDateFarWarning }}</p>
              </div>
            </div>
          </article>

          <article class="scheduler-card">
            <h4>固定上課星期</h4>
            <div class="scheduler-grid">
              <div class="form-group">
                <label>預設上課時長（小時） *</label>
                <input v-model.number="form.duration_hours" type="number" min="0.5" step="0.5" inputmode="decimal" />
                <p class="field-note">新增星期時的預設時長，可在下方逐日調整。</p>
              </div>
            </div>
            <div class="weekday-row">
              <label
                v-for="day in weekdayOptions"
                :key="day.value"
                :class="['weekday-chip', { selected: form.days_of_week.includes(day.value) }]"
              >
                <input v-model="form.days_of_week" type="checkbox" :value="day.value" />
                <span>{{ day.label }}</span>
              </label>
            </div>
            <div v-if="selectedDays.length > 0" class="weekday-slot-grid">
              <template v-for="day in selectedDays" :key="`day-${day}`">
                <div
                  v-for="(globalIdx, slotNum) in getSlotIndicesForDay(day)"
                  :key="`slot-${globalIdx}`"
                  class="weekday-slot-row per-day-row"
                >
                  <span class="weekday-slot-day">{{ slotNum === 0 ? `週${weekdayLabelMap[day]}` : '' }}</span>
                  <select
                    class="slot-subject-select"
                    :value="form.day_time_slots[globalIdx].subject || form.subject"
                    @change="updateSlotSubject(globalIdx, $event.target.value)"
                  >
                    <option v-for="s in subjectOptions" :key="`sub-${globalIdx}-${s.value}`" :value="s.value">{{ s.label }}</option>
                  </select>
                  <select
                    :value="form.day_time_slots[globalIdx].start_time"
                    @change="updateSlot(globalIdx, $event.target.value)"
                  >
                    <option v-for="t in halfHourTimeOptions" :key="`${globalIdx}-${t}`" :value="t">{{ t }}</option>
                  </select>
                  <input
                    type="number"
                    class="per-day-dur"
                    :value="form.day_time_slots[globalIdx].duration_hours"
                    min="0.5"
                    step="0.5"
                    inputmode="decimal"
                    @change="updateSlotDur(globalIdx, $event.target.value)"
                  />
                  <small class="field-note">~ {{ computeSessionEndTime(form.day_time_slots[globalIdx].start_time, form.day_time_slots[globalIdx].duration_hours) }}</small>
                  <button
                    v-if="getSlotIndicesForDay(day).length > 1"
                    type="button"
                    class="slot-remove-btn"
                    title="移除此時段"
                    @click="removeSlotAt(globalIdx)"
                  >✕</button>
                </div>
                <div class="slot-add-row">
                  <button type="button" class="slot-add-btn" @click="addSlotForDay(day)">＋ 新增時段</button>
                </div>
              </template>
            </div>
          </article>

          <article class="scheduler-card">
            <h4>備註</h4>
            <div class="form-group">
              <textarea v-model="form.memo" rows="2" placeholder="可選填"></textarea>
            </div>
          </article>
        </section>

        <section class="panel-stack">
          <article class="scheduler-card calendar-card">
            <div class="calendar-header">
              <button class="ghost small" type="button" @click="shiftMonth(-1)">上個月</button>
              <strong>{{ monthLabel }}</strong>
              <button class="ghost small" type="button" @click="shiftMonth(1)">下個月</button>
            </div>

            <div class="calendar-weekdays">
              <span v-for="wd in weekdayText" :key="wd">{{ wd }}</span>
            </div>

            <div class="calendar-grid">
              <button
                v-for="cell in calendarCells"
                :key="cell.key"
                type="button"
                :class="[
                  'calendar-cell',
                  {
                    muted: !cell.inMonth,
                    confirmed: cell.status === 'manual-confirmed',
                    'manual-future': cell.status === 'manual-future',
                    future: cell.status === 'future',
                    'course-start-marker': cell.isCourseStart,
                  },
                ]"
                @click="onDateClick(cell)"
              >
                <span>{{ cell.day }}</span>
                <small v-if="cell.status === 'manual-confirmed'" class="cell-label cell-label-confirmed">補登</small>
                <small v-else-if="cell.status === 'manual-future'" class="cell-label cell-label-scheduled">預排</small>
                <small v-else-if="cell.status === 'future'" class="cell-label cell-label-auto">預排</small>
                <small v-if="cell.isCourseStart" class="cell-flag">開課</small>
              </button>
            </div>
          </article>

          <article class="scheduler-card">
            <h4>日曆摘要</h4>
            <div class="summary-row">
              <div v-if="confirmedDates.length > 0" class="summary-pill confirmed">
                <div class="summary-label">補登已上（堂）</div>
                <strong>{{ confirmedDates.length }}</strong>
              </div>
              <div class="summary-pill future">
                <div class="summary-label">預排未上（堂）</div>
                <strong>{{ manualScheduledDates.length + futureSessionOccurrences.length }}</strong>
              </div>
              <div class="summary-pill total">
                <div class="summary-label">{{ plannedCountLabel }}</div>
                <strong>{{ safePlannedSessions }}</strong>
              </div>
            </div>

            <div v-if="form.course_start_date" class="course-start-info">
              開課日：<strong>{{ form.course_start_date }}</strong>
              <span v-if="form.course_start_date > getCurrentTodayYmd()" class="course-start-badge">尚未開課</span>
            </div>

            <div class="legend-row">
              <span class="legend-chip confirmed">補登已上</span>
              <span class="legend-chip manual-future">手動預排</span>
              <span class="legend-chip future">系統預排</span>
            </div>

            <p class="hint-text">
              開課日前不會建立預排堂次。系統從開課日起，依固定上課星期自動補齊剩餘堂次。
              <template v-if="form.payment_type === 'monthly'">月結課只會累積已使用堂次，不會扣剩餘堂數。</template>
            </p>

            <div class="calendar-actions">
              <button class="ghost small" type="button" @click="clearConfirmedDates" :disabled="manualDates.length === 0">
                清除手動日期
              </button>
            </div>

            <div v-if="confirmedDates.length > 0" class="date-list confirmed">
              <p>補登已上日期：</p>
              <div>{{ confirmedDates.join('、') }}</div>
            </div>

            <div v-if="manualScheduledDates.length > 0" class="date-list manual-future-list">
              <p>手動預排日期（未上）：</p>
              <div>{{ manualScheduledDates.join('、') }}</div>
            </div>

            <div v-if="futureSessionOccurrences.length > 0" class="date-list future">
              <p>系統預排（每列一堂）：</p>
              <div>{{ futureSessionLines.join('、') }}</div>
            </div>
          </article>
        </section>
      </div>

      <div class="modal-actions">
        <button class="ghost" type="button" @click="$emit('cancel')">取消</button>
        <button
          class="primary"
          type="button"
          :disabled="submitting"
          @click="packageMode ? submitPackage() : submit()"
        >
          {{ submitting ? '送出中...' : (packageMode ? '建立方案' : submitLabel) }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { createUniversalClassSchedule } from '../lib/universalSchedulerApi';
import { createMultiSubjectPackage } from '../lib/coursePackagesApi';
import { checkTeacherScope } from '../lib/constants';
import { fetchSubjectOptions } from '../lib/subjectsApi';
import SearchableSelect from './SearchableSelect.vue';

const weekdayOptions = [
  { value: 1, label: '週一' },
  { value: 2, label: '週二' },
  { value: 3, label: '週三' },
  { value: 4, label: '週四' },
  { value: 5, label: '週五' },
  { value: 6, label: '週六' },
  { value: 7, label: '週日' },
];
const weekdayLabelMap = { 1: '一', 2: '二', 3: '三', 4: '四', 5: '五', 6: '六', 7: '日' };

const weekdayText = ['一', '二', '三', '四', '五', '六', '日'];
const halfHourTimeOptions = buildHalfHourTimeOptions();
const settlementDayOptions = Array.from({ length: 31 }, (_, index) => index + 1);

const subjectOptions = ref([
  { value: 'Chinese', label: '國文' },
  { value: 'English', label: '英文' },
  { value: 'Math', label: '數學' },
  { value: 'Science', label: '理化' },
  { value: 'Chemistry', label: '化學' },
  { value: 'Physics', label: '物理' },
  { value: 'Biology', label: '生物' },
  { value: 'Social', label: '社會' },
]);

const props = defineProps({
  title: { type: String, default: '通用排課' },
  submitLabel: { type: String, default: '儲存排課' },
  branchId: { type: [Number, String], default: null },
  students: { type: Array, default: () => [] },
  teachers: { type: Array, default: () => [] },
  rooms: { type: Array, default: () => [] },
  initialStudentId: { type: [Number, String], default: '' },
  initialTeacherId: { type: [Number, String], default: '' },
  /** 從智慧排課格子點選時帶入，例如 '11:00' */
  initialStartTime: { type: String, default: '' },
  /** 從日／週檢視格子點選時帶入的星期 1–7 */
  initialDaysOfWeek: { type: Array, default: () => [] },
  /** 打開時月曆預設顯示此日所在月份 YYYY-MM-DD */
  initialCalendarYmd: { type: String, default: '' },
  mode: { type: String, default: 'create' },
});

const emit = defineEmits(['success', 'cancel', 'duplicate-course']);

const nowAtOpen = new Date();

function parseYmdToMonthDate(ymd) {
  if (!ymd || typeof ymd !== 'string') return null;
  const m = String(ymd).slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return null;
  const y = Number(m[1]);
  const mo = Number(m[2]);
  if (!Number.isFinite(y) || !Number.isFinite(mo) || mo < 1 || mo > 12) return null;
  return new Date(y, mo - 1, 1);
}

function normalizeInitialDaysOfWeek(raw) {
  if (!Array.isArray(raw) || raw.length === 0) return [];
  return [...new Set(raw.map((d) => Number(d)).filter((d) => d >= 1 && d <= 7))].sort((a, b) => a - b);
}

function normalizeHalfHourTime(raw) {
  const [hRaw, mRaw] = String(raw || '00:00').split(':');
  const hour = Math.max(0, Math.min(23, Number(hRaw) || 0));
  const minute = Number(mRaw) || 0;
  const rounded = minute < 15 ? 0 : (minute < 45 ? 30 : 0);
  const carryHour = minute >= 45 ? 1 : 0;
  const finalHour = (hour + carryHour) % 24;
  return `${String(finalHour).padStart(2, '0')}:${String(rounded).padStart(2, '0')}`;
}

const bootMonth = parseYmdToMonthDate(props.initialCalendarYmd);
const currentMonth = ref(bootMonth || new Date(nowAtOpen.getFullYear(), nowAtOpen.getMonth(), 1));
const submitting = ref(false);

const seededStart = normalizeHalfHourTime(props.initialStartTime || '16:00');

const form = reactive({
  student_id: props.initialStudentId ? Number(props.initialStudentId) : '',
  teacher_id: props.initialTeacherId ? Number(props.initialTeacherId) : '',
  subject: 'Math',
  class_type: 'one_on_one',
  confirmed_dates: [],
  total_classes: 8,
  settlement_day: null,
  monthly_sessions: 4,
  days_of_week: normalizeInitialDaysOfWeek(props.initialDaysOfWeek),
  day_time_slots: [],
  start_time: seededStart,
  duration_hours: 2,
  price_per_session: 1000,
  payment_type: 'session',
  room_id: '',
  memo: '',
  paid_at: '',
  course_start_date: toYmd(new Date()),
});

const packageMode = ref(false);

const pkgForm = reactive({
  student_id: props.initialStudentId ? Number(props.initialStudentId) : '',
  name: '',
  total_sessions: 24,
  rate: 1000,
  class_type: 'one_on_one',
  paid_at: '',
  subjects: [
    { subject: 'Math', teacher_id: '', duration_hours: 2, start_date: '', confirmed_dates: [] },
  ],
});

const PKG_SUBJECT_COLORS = [
  '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
  '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1',
];

const pkgCurrentMonth = ref(new Date(nowAtOpen.getFullYear(), nowAtOpen.getMonth(), 1));
const pkgActiveSubjectIdx = ref(0);

const pkgTotalConfirmedCount = computed(() =>
  pkgForm.subjects.reduce((sum, s) => sum + (s.confirmed_dates?.length || 0), 0)
);

const pkgAllConfirmedMap = computed(() => {
  const map = {};
  pkgForm.subjects.forEach((s, idx) => {
    for (const d of (s.confirmed_dates || [])) {
      if (!map[d]) map[d] = [];
      map[d].push(idx);
    }
  });
  return map;
});

const pkgCalendarCells = computed(() => {
  const first = new Date(pkgCurrentMonth.value.getFullYear(), pkgCurrentMonth.value.getMonth(), 1);
  const firstWeekday = ((first.getDay() + 6) % 7);
  const start = new Date(first);
  start.setDate(first.getDate() - firstWeekday);
  const todayYmd = toYmd(new Date());
  const cells = [];
  for (let i = 0; i < 42; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    const ymd = toYmd(d);
    const inMonth = d.getMonth() === pkgCurrentMonth.value.getMonth();
    const isPast = ymd < todayYmd;
    const subjectIndices = pkgAllConfirmedMap.value[ymd] || [];
    cells.push({ key: ymd, ymd, day: d.getDate(), inMonth, isPast, subjectIndices });
  }
  return cells;
});

function shiftPkgMonth(delta) {
  const base = pkgCurrentMonth.value;
  pkgCurrentMonth.value = new Date(base.getFullYear(), base.getMonth() + delta, 1);
}

const pkgMonthLabel = computed(() => {
  const y = pkgCurrentMonth.value.getFullYear();
  const m = String(pkgCurrentMonth.value.getMonth() + 1).padStart(2, '0');
  return `${y}-${m}`;
});

function onPkgDateClick(cell) {
  if (!cell?.ymd || !cell.isPast) return;
  const idx = pkgActiveSubjectIdx.value;
  const subj = pkgForm.subjects[idx];
  if (!subj) return;
  const pos = subj.confirmed_dates.indexOf(cell.ymd);
  if (pos >= 0) {
    subj.confirmed_dates.splice(pos, 1);
    return;
  }
  if (pkgTotalConfirmedCount.value + 1 > pkgForm.total_sessions) {
    alert(`補登堂數合計不可超過總堂數 ${pkgForm.total_sessions}`);
    return;
  }
  subj.confirmed_dates.push(cell.ymd);
  subj.confirmed_dates.sort();
}

function removePkgConfirmedDate(idx, dateIdx) {
  const subj = pkgForm.subjects[idx];
  if (!subj) return;
  subj.confirmed_dates.splice(dateIdx, 1);
}

function addPkgSubject() {
  if (pkgForm.subjects.length >= 10) return;
  const existing = pkgForm.subjects.map((s) => s.subject);
  const next = ['English', 'Science', 'Chinese', 'Physics', 'Chemistry', 'Biology', 'Social'].find((s) => !existing.includes(s)) || 'English';
  pkgForm.subjects.push({ subject: next, teacher_id: '', duration_hours: 2, start_date: '', confirmed_dates: [] });
}

function pkgSubjectColor(idx) {
  return PKG_SUBJECT_COLORS[idx % PKG_SUBJECT_COLORS.length];
}

function pkgSubjectLabel(subjectKey) {
  const opt = subjectOptions.value.find((s) => s.value === subjectKey);
  return opt ? opt.label : subjectKey;
}

const studentOptions = computed(() => (
  (props.students || []).map((student) => ({
    value: Number(student?.id ?? 0),
    label: student?.name || `#${student?.id ?? ''}`,
  })).filter((student) => Number.isFinite(student.value) && student.value > 0)
));
const teacherOptions = computed(() => (
  (props.teachers || []).map((teacher) => {
    const id = Number(teacher?.id ?? 0);
    const label = String(
      teacher?.name
      || teacher?.Name
      || teacher?.T_Name
      || teacher?.username
      || teacher?.LoginName
      || `老師#${id || ''}`
    ).trim();
    return { value: id, label };
  }).filter((teacher) => Number.isFinite(teacher.value) && teacher.value > 0 && teacher.label)
));

const selectedTeacher = computed(() => {
  const tid = Number(form.teacher_id);
  return tid > 0 ? (props.teachers || []).find((t) => Number(t.id) === tid) : null;
});

const selectedStudent = computed(() => {
  const sid = Number(form.student_id);
  return sid > 0 ? (props.students || []).find((s) => Number(s.id) === sid) : null;
});

const scopeWarning = computed(() => {
  const teacher = selectedTeacher.value;
  if (!teacher) return null;
  const studentGrade = selectedStudent.value?.grade || selectedStudent.value?.ClassID || null;
  const subs = new Set();
  for (const slot of form.day_time_slots || []) {
    subs.add(String(slot?.subject || form.subject || '').trim() || String(form.subject));
  }
  if (subs.size === 0) subs.add(String(form.subject || ''));
  const parts = [];
  for (const subj of subs) {
    if (!subj) continue;
    const w = checkTeacherScope(teacher, subj, studentGrade, subjectOptions.value);
    if (w) parts.push(`${subj}: ${w}`);
  }
  return parts.length ? parts.join('；') : null;
});

const apiScopeWarning = ref('');

const safePlannedSessions = computed(() => (
  form.payment_type === 'monthly'
    ? Math.max(1, Number(form.monthly_sessions) || 1)
    : Math.max(1, Number(form.total_classes) || 1)
));
const selectedDays = computed(() => (
  [...new Set((form.days_of_week || []).map((d) => Number(d)).filter((d) => d >= 1 && d <= 7))].sort((a, b) => a - b)
));
/** 每個星期幾的時段列表（依開始時間排序），用於多堂／同日多時段 */
function orderedSlotsForWeekday(day) {
  const d = Number(day);
  if (d < 1 || d > 7) return [];
  const out = [];
  for (let i = 0; i < (form.day_time_slots || []).length; i += 1) {
    if (Number(form.day_time_slots[i]?.day || 0) === d) {
      const start = normalizeHalfHourTime(form.day_time_slots[i]?.start_time || '16:00');
      const durH = Number(form.day_time_slots[i]?.duration_hours || 0) || form.duration_hours;
      out.push({
        index: i,
        start_time: start,
        duration_hours: durH,
        duration_minutes: durationHoursToMinutes(durH),
        subject: String(form.day_time_slots[i]?.subject || form.subject || ''),
      });
    }
  }
  out.sort((a, b) => a.start_time.localeCompare(b.start_time));
  return out;
}

function sessionCountForWeekday(day) {
  const d = Number(day);
  if (d < 1 || d > 7 || !selectedDays.value.includes(d)) {
    return 0;
  }
  const n = orderedSlotsForWeekday(day).length;
  return n > 0 ? n : 1;
}

function sessionCountForYmd(ymd) {
  const dow = weekdayOneToSeven(new Date(`${ymd}T12:00:00`));
  // 手動日期不限固定星期，若該星期無設定時段則以 1 堂計
  if (selectedDays.value.includes(dow)) {
    return sessionCountForWeekday(dow);
  }
  return 1;
}

function slotEndDateTimeFromParts(ymd, startTimeStr, durHours) {
  const durationMinutes = durationHoursToMinutes(durHours);
  if (durationMinutes < 30) return null;
  const [year, month, day] = String(ymd || '').split('-').map(Number);
  const normalizedStart = normalizeHalfHourTime(startTimeStr || '00:00');
  const [hour, minute] = normalizedStart.split(':').map(Number);
  if (!year || !month || !day || !Number.isFinite(hour) || !Number.isFinite(minute)) return null;
  const endAt = new Date(year, month - 1, day, hour, minute, 0, 0);
  endAt.setMinutes(endAt.getMinutes() + durationMinutes);
  return endAt;
}

/** 該日上課時段中最晚的下課時間（同日多時段用於「今天是否算已上完」） */
function getLatestSlotEndDateTime(ymd) {
  const dow = weekdayOneToSeven(new Date(`${ymd}T12:00:00`));
  const slots = orderedSlotsForWeekday(dow);
  let latest = null;
  for (const s of slots) {
    const endAt = slotEndDateTimeFromParts(ymd, s.start_time, s.duration_hours);
    if (endAt && (!latest || endAt > latest)) latest = endAt;
  }
  return latest;
}

const slotStartTimeByDay = computed(() => {
  const map = {};
  for (const day of selectedDays.value) {
    const slots = orderedSlotsForWeekday(day);
    if (slots[0]) map[day] = String(slots[0].start_time || '').slice(0, 5);
  }
  return map;
});
const slotDurationByDay = computed(() => {
  const map = {};
  for (const day of selectedDays.value) {
    const slots = orderedSlotsForWeekday(day);
    if (slots[0]) map[day] = slots[0].duration_hours;
  }
  return map;
});
const hasPerDayDuration = computed(() => {
  const vals = (form.day_time_slots || []).map((s) => Number(s.duration_hours) || form.duration_hours);
  if (vals.length < 2) return false;
  return new Set(vals.map((v) => v.toFixed(1))).size > 1;
});
const plannedCountLabel = computed(() => (
  form.payment_type === 'monthly' ? '本月預排堂數' : '購買總堂數'
));

const courseStartDateFarWarning = computed(() => {
  if (!form.course_start_date) return '';
  const startMs = new Date(`${form.course_start_date}T00:00:00`).getTime();
  const todayMs = new Date(getCurrentTodayYmd() + 'T00:00:00').getTime();
  const diffDays = Math.round((startMs - todayMs) / 86400000);
  if (diffDays > 180) return `開課日距今 ${diffDays} 天（約 ${Math.round(diffDays / 30)} 個月），請確認是否正確。`;
  return '';
});

watch(() => form.course_start_date, (val) => {
  if (!val) return;
  const monthDate = parseYmdToMonthDate(val);
  if (monthDate) {
    currentMonth.value = monthDate;
  }
});

const manualDates = computed(() => sortDates(form.confirmed_dates || []));
const manualDateSet = computed(() => new Set(manualDates.value));
const confirmedDates = computed(() => manualDates.value.filter((date) => isManualDateConfirmed(date)));
const manualScheduledDates = computed(() => manualDates.value.filter((date) => !isManualDateConfirmed(date)));

const manualSessionCount = computed(() => (
  manualDates.value.reduce((sum, ymd) => sum + sessionCountForYmd(ymd), 0)
));

const futureSessionOccurrences = computed(() => {
  const remaining = safePlannedSessions.value - manualSessionCount.value;
  if (remaining <= 0) return [];

  const daySet = new Set((form.days_of_week || []).map((d) => Number(d)).filter((d) => d >= 1 && d <= 7));
  if (daySet.size === 0) return [];

  const todayYmd = getCurrentTodayYmd();
  const courseStart = form.course_start_date || '';
  const effectiveToday = (courseStart > todayYmd) ? courseStart : todayYmd;
  const manual = manualDates.value;
  const lastManual = manual.length > 0 ? manual[manual.length - 1] : null;
  const effectiveAnchor = (lastManual && lastManual >= effectiveToday) ? lastManual : effectiveToday;
  const targetMonthYm = form.payment_type === 'monthly' ? effectiveAnchor.slice(0, 7) : '';
  const selected = [];
  const seenDay = new Set(manual);
  const cursor = new Date(`${effectiveAnchor}T00:00:00`);
  let guard = 0;
  while (selected.length < remaining && guard < 3660) {
    guard += 1;
    const candidate = new Date(cursor);
    const ymd = toYmd(candidate);
    const dow = weekdayOneToSeven(candidate);
    const canUseDay = (!lastManual || ymd > lastManual)
      && daySet.has(dow)
      && ymd >= effectiveToday
      && (form.payment_type !== 'monthly' || ymd.slice(0, 7) === targetMonthYm)
      && !seenDay.has(ymd);
    if (canUseDay) {
      seenDay.add(ymd);
      const slots = orderedSlotsForWeekday(dow);
      const slotList = slots.length ? slots : [{
        start_time: normalizeHalfHourTime(form.start_time || '16:00'),
        duration_minutes: durationHoursToMinutes(form.duration_hours),
        subject: form.subject,
      }];
      for (const slot of slotList) {
        if (selected.length >= remaining) break;
        selected.push({
          ymd,
          start_time: slot.start_time,
          duration_minutes: slot.duration_minutes,
          subject: slot.subject || form.subject,
        });
      }
    }
    cursor.setDate(cursor.getDate() + 1);
  }
  return selected;
});

const futureSessionLines = computed(() => (
  futureSessionOccurrences.value.map((o) => `${o.ymd} ${o.start_time}`)
));

const monthlyAvailableTotal = computed(() => manualSessionCount.value + futureSessionOccurrences.value.length);
const monthlyCapacityHint = computed(() => {
  if (form.payment_type !== 'monthly') return '';
  if ((form.days_of_week || []).length === 0) return '';
  return `本月依目前星期設定最多可排 ${monthlyAvailableTotal.value} 堂（含手動指定）。`;
});
const monthlyCapacityWarning = computed(() => {
  if (form.payment_type !== 'monthly') return '';
  const requested = Number(safePlannedSessions.value || 0);
  if (requested <= 0) return '';
  if (requested <= monthlyAvailableTotal.value) return '';
  return `目前設定超出本月可排上限，請降低本月預排堂數或調整固定星期。`;
});

const monthLabel = computed(() => {
  const y = currentMonth.value.getFullYear();
  const m = String(currentMonth.value.getMonth() + 1).padStart(2, '0');
  return `${y}-${m}`;
});

watch(
  () => form.days_of_week,
  () => {
    syncDaySlotsFromSelection();
  },
  { deep: true, immediate: true }
);

watch(
  () => form.day_time_slots,
  (slots) => {
    if (Array.isArray(slots) && slots.length > 0 && slots[0]?.start_time) {
      form.start_time = normalizeHalfHourTime(slots[0].start_time);
    }
  },
  { deep: true }
);

/** 預設科目變更時：仍跟著舊預設的時段一併更新；已手動選成其他科目的時段不動 */
watch(
  () => form.subject,
  (newSub, oldSub) => {
    if (oldSub === undefined || oldSub === null || String(oldSub).trim() === '') {
      return;
    }
    if (String(newSub) === String(oldSub)) {
      return;
    }
    const slots = [...(form.day_time_slots || [])];
    if (slots.length === 0) {
      return;
    }
    let changed = false;
    const next = String(newSub);
    const prev = String(oldSub);
    for (let i = 0; i < slots.length; i += 1) {
      const s = slots[i];
      const cur = s?.subject;
      const curStr = cur != null && String(cur).trim() !== '' ? String(cur) : '';
      if (!curStr || curStr === prev) {
        slots[i] = { ...s, subject: next };
        changed = true;
      }
    }
    if (changed) {
      form.day_time_slots = slots;
    }
  }
);

watch(() => form.student_id, (val) => {
  if (val !== pkgForm.student_id) pkgForm.student_id = val;
});
watch(() => pkgForm.student_id, (val) => {
  if (val !== form.student_id) form.student_id = val;
});

const futureDateSet = computed(() => (
  new Set(futureSessionOccurrences.value.map((o) => o.ymd))
));

const manualConfirmedSet = computed(() => new Set(confirmedDates.value));
const manualFutureSet = computed(() => new Set(manualScheduledDates.value));

const calendarCells = computed(() => {
  const first = new Date(currentMonth.value.getFullYear(), currentMonth.value.getMonth(), 1);
  const firstWeekday = ((first.getDay() + 6) % 7); // Mon=0
  const start = new Date(first);
  start.setDate(first.getDate() - firstWeekday);
  const courseStart = form.course_start_date || '';

  const cells = [];
  for (let i = 0; i < 42; i += 1) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    const ymd = toYmd(d);

    let status = '';
    if (manualConfirmedSet.value.has(ymd)) status = 'manual-confirmed';
    else if (manualFutureSet.value.has(ymd)) status = 'manual-future';
    else if (futureDateSet.value.has(ymd)) status = 'future';

    cells.push({
      key: ymd,
      ymd,
      day: d.getDate(),
      inMonth: d.getMonth() === currentMonth.value.getMonth(),
      status,
      isCourseStart: courseStart === ymd,
    });
  }
  return cells;
});

onMounted(async () => {
  try {
    const opts = await fetchSubjectOptions({ branchId: props.branchId });
    if (opts.length > 0) subjectOptions.value = opts;
  } catch { /* keep defaults */ }
});

function toYmd(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function getCurrentTodayYmd() {
  return toYmd(new Date());
}

function weekdayOneToSeven(date) {
  const jsDay = date.getDay();
  return jsDay === 0 ? 7 : jsDay;
}

function shiftMonth(delta) {
  const base = currentMonth.value;
  currentMonth.value = new Date(base.getFullYear(), base.getMonth() + delta, 1);
}

function sortDates(arr) {
  return [...new Set(arr)].sort((a, b) => a.localeCompare(b));
}

function buildHalfHourTimeOptions() {
  const slots = [];
  for (let hour = 0; hour < 24; hour += 1) {
    for (const minute of [0, 30]) {
      slots.push(`${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`);
    }
  }
  return slots;
}

function durationHoursToMinutes(rawHours) {
  const hours = Number(rawHours);
  if (!Number.isFinite(hours) || hours <= 0) return 0;
  return Math.round(hours * 60);
}

function getSessionEndDateTime(ymd) {
  return getLatestSlotEndDateTime(ymd);
}

function computeSessionEndTime(startTimeRaw, durHours) {
  const normalizedStart = normalizeHalfHourTime(startTimeRaw || '00:00');
  const durationMinutes = durationHoursToMinutes(durHours != null ? durHours : form.duration_hours);
  if (durationMinutes < 30) return '--:--';
  const [hour, minute] = normalizedStart.split(':').map(Number);
  if (!Number.isFinite(hour) || !Number.isFinite(minute)) return '--:--';
  const endAt = new Date(2000, 0, 1, hour, minute, 0, 0);
  endAt.setMinutes(endAt.getMinutes() + durationMinutes);
  return `${String(endAt.getHours()).padStart(2, '0')}:${String(endAt.getMinutes()).padStart(2, '0')}`;
}

function syncDaySlotsFromSelection() {
  const grouped = new Map();
  for (const slot of (form.day_time_slots || [])) {
    const day = Number(slot?.day || 0);
    if (day < 1 || day > 7) continue;
    if (!grouped.has(day)) grouped.set(day, []);
    grouped.get(day).push({
      day,
      start_time: normalizeHalfHourTime(slot?.start_time || '16:00'),
      duration_hours: Number(slot?.duration_hours || 0) || form.duration_hours,
      subject: String(slot?.subject || form.subject || ''),
    });
  }

  const firstBucket = grouped.values().next().value || [];
  const firstSlot = firstBucket[0] || null;
  const baseTime = firstSlot
    ? normalizeHalfHourTime(firstSlot.start_time || '16:00')
    : normalizeHalfHourTime(form.start_time || '16:00');
  const baseDuration = Number(firstSlot?.duration_hours || 0) || form.duration_hours;

  const rebuilt = [];
  for (const day of selectedDays.value) {
    const slots = grouped.get(day);
    if (Array.isArray(slots) && slots.length > 0) {
      for (const slot of slots) {
        rebuilt.push({
          day,
          start_time: normalizeHalfHourTime(slot.start_time || baseTime),
          duration_hours: Number(slot.duration_hours || 0) || baseDuration,
          subject: String(slot.subject || form.subject || ''),
        });
      }
      continue;
    }
    rebuilt.push({
      day,
      start_time: baseTime,
      duration_hours: baseDuration,
      subject: String(form.subject || ''),
    });
  }
  form.day_time_slots = rebuilt;
}

function getSlotIndicesForDay(day) {
  const d = Number(day);
  if (d < 1 || d > 7) return [];
  const indices = [];
  for (let i = 0; i < (form.day_time_slots || []).length; i += 1) {
    if (Number(form.day_time_slots[i]?.day || 0) === d) indices.push(i);
  }
  return indices;
}

function updateSlot(index, nextTime) {
  const idx = Number(index);
  if (!Number.isInteger(idx) || idx < 0) return;
  const slots = [...(form.day_time_slots || [])];
  if (!slots[idx]) return;
  slots[idx] = { ...slots[idx], start_time: normalizeHalfHourTime(nextTime) };
  form.day_time_slots = slots;
}

function updateSlotDur(index, rawValue) {
  const idx = Number(index);
  if (!Number.isInteger(idx) || idx < 0) return;
  const slots = [...(form.day_time_slots || [])];
  if (!slots[idx]) return;
  const dur = Math.max(0.5, Math.round(Number(rawValue) * 2) / 2);
  slots[idx] = { ...slots[idx], duration_hours: dur };
  form.day_time_slots = slots;
}

function updateSlotSubject(index, nextSubject) {
  const idx = Number(index);
  if (!Number.isInteger(idx) || idx < 0) return;
  const slots = [...(form.day_time_slots || [])];
  if (!slots[idx]) return;
  slots[idx] = { ...slots[idx], subject: String(nextSubject || form.subject) };
  form.day_time_slots = slots;
}

function addSlotForDay(day) {
  const d = Number(day);
  if (d < 1 || d > 7) return;
  if (!selectedDays.value.includes(d)) return;
  const indices = getSlotIndicesForDay(d);
  const lastIdx = indices.length ? indices[indices.length - 1] : -1;
  const base = lastIdx >= 0 ? form.day_time_slots[lastIdx] : null;
  const slot = {
    day: d,
    start_time: normalizeHalfHourTime(base?.start_time || form.start_time || '16:00'),
    duration_hours: Number(base?.duration_hours || 0) || form.duration_hours,
    subject: String(base?.subject || form.subject || ''),
  };
  form.day_time_slots = [...(form.day_time_slots || []), slot];
}

function removeSlotAt(index) {
  const idx = Number(index);
  if (!Number.isInteger(idx) || idx < 0) return;
  const slots = [...(form.day_time_slots || [])];
  const target = slots[idx];
  if (!target) return;
  const sameDayCount = slots.filter((slot) => Number(slot?.day || 0) === Number(target.day || 0)).length;
  if (sameDayCount <= 1) return;
  slots.splice(idx, 1);
  form.day_time_slots = slots;
}

function isTodaySessionEnded() {
  const latestEnd = getLatestSlotEndDateTime(getCurrentTodayYmd());
  return Boolean(latestEnd && new Date() >= latestEnd);
}

function clearConfirmedDates() {
  form.confirmed_dates = [];
}

function isManualDateConfirmed(ymd) {
  const todayYmd = getCurrentTodayYmd();
  // 產品規則（勿擅自改動）：手動點選「今天以前」＝已上完／補登；「今天」則依是否已過該日「最晚」下課時間（同日多時段）。
  // 詳見 docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md
  if (ymd < todayYmd) return true;
  if (ymd > todayYmd) return false;
  const latestEnd = getLatestSlotEndDateTime(ymd);
  return Boolean(latestEnd && new Date() >= latestEnd);
}

function onDateClick(cell) {
  if (!cell?.ymd) return;
  const todayYmd = getCurrentTodayYmd();
  if (selectedDays.value.length > 0 && cell.ymd >= todayYmd) {
    const dow = weekdayOneToSeven(new Date(`${cell.ymd}T12:00:00`));
    if (!selectedDays.value.includes(dow)) {
      const names = ['', '一', '二', '三', '四', '五', '六', '日'];
      alert(`此日為週${names[dow] || dow}，不在已勾選的固定上課星期內；請只選固定排課日，或先調整「固定上課星期」。`);
      return;
    }
  }
  const courseStart = form.course_start_date || '';
  if (courseStart && cell.ymd < courseStart && cell.ymd >= todayYmd) {
    const removing = manualDateSet.value.has(cell.ymd);
    if (!removing && !confirm(`此日期 (${cell.ymd}) 早於開課日 (${courseStart})，將視為補登已上課。確定要加入嗎？`)) {
      return;
    }
  }
  if (form.payment_type === 'monthly') {
    const targetYm = toYmd(currentMonth.value).slice(0, 7);
    if (cell.ymd.slice(0, 7) !== targetYm) {
      alert('月結課程僅可選擇同一月份日期。');
      return;
    }
  }
  const current = new Set(manualDateSet.value);
  if (current.has(cell.ymd)) {
    current.delete(cell.ymd);
    form.confirmed_dates = sortDates([...current]);
    return;
  }

  const addCount = sessionCountForYmd(cell.ymd);
  if (manualSessionCount.value + addCount > safePlannedSessions.value) {
    alert(`手動指定不可超過${plannedCountLabel.value}（${safePlannedSessions.value} 堂）；此日會佔 ${addCount} 堂。`);
    return;
  }

  current.add(cell.ymd);
  form.confirmed_dates = sortDates([...current]);
}

async function submitPackage() {
  const branchId = Number(props.branchId || 0);
  if (branchId <= 0) { alert('請先選擇分校後再建立課程'); return; }
  if (!pkgForm.student_id) { alert('請選擇學生'); return; }
  if (!pkgForm.name.trim()) { alert('請輸入方案名稱'); return; }
  if (!pkgForm.total_sessions || pkgForm.total_sessions < 1) { alert('總堂數至少為 1'); return; }
  if (pkgForm.subjects.length === 0) { alert('請至少新增一個科目'); return; }
  for (let i = 0; i < pkgForm.subjects.length; i++) {
    const s = pkgForm.subjects[i];
    if (!s.subject) { alert(`第 ${i + 1} 科目未選擇科目`); return; }
    if (!s.teacher_id) { alert(`第 ${i + 1} 科目未選擇老師`); return; }
    if (!s.start_date) { alert(`第 ${i + 1} 科目未填寫開始日期`); return; }
  }
  const subjectNames = pkgForm.subjects.map((s) => s.subject);
  const dupes = subjectNames.filter((v, i) => subjectNames.indexOf(v) !== i);
  if (dupes.length > 0) {
    if (!confirm(`科目「${[...new Set(dupes)].join('、')}」重複出現，確定要繼續嗎？`)) return;
  }
  if (pkgTotalConfirmedCount.value > pkgForm.total_sessions) {
    alert(`補登堂數合計（${pkgTotalConfirmedCount.value}）超過總堂數（${pkgForm.total_sessions}）`);
    return;
  }

  submitting.value = true;
  try {
    const payload = {
      student_id: Number(pkgForm.student_id),
      branch_id: branchId,
      name: pkgForm.name.trim(),
      total_sessions: Number(pkgForm.total_sessions),
      rate: Number(pkgForm.rate) || 0,
      rate_unit: 'session',
      class_type: pkgForm.class_type || 'one_on_one',
      paid_at: pkgForm.paid_at || null,
      subjects: pkgForm.subjects.map((s) => ({
        subject_name: s.subject,
        teacher_id: Number(s.teacher_id),
        duration_hours: Number(s.duration_hours) || 2,
        start_date: s.start_date || null,
        confirmed_dates: (s.confirmed_dates || []).filter(Boolean),
      })),
    };
    const result = await createMultiSubjectPackage(payload);
    const memberCount = result?.members?.length ?? pkgForm.subjects.length;
    alert(`方案「${pkgForm.name}」已建立，包含 ${memberCount} 個科目，共 ${pkgForm.total_sessions} 堂。`);
    emit('success', result);
  } catch (err) {
    alert(err?.message || '建立方案失敗，請稍後再試');
  } finally {
    submitting.value = false;
  }
}

async function submit() {
  const branchId = Number(props.branchId || 0);
  if (branchId <= 0) {
    alert('請先選擇分校後再建立課程');
    return;
  }
  const todayYmd = getCurrentTodayYmd();
  if (!form.student_id) {
    alert('請選擇學生');
    return;
  }
  if (!form.teacher_id) {
    alert('請選擇老師');
    return;
  }
  const selectedTeacher = (props.teachers || []).find((teacher) => String(teacher.id) === String(form.teacher_id || ''));
  if (selectedTeacher) {
    const branchIds = Array.isArray(selectedTeacher.branch_ids)
      ? selectedTeacher.branch_ids.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
      : [];
    const teacherBranch = Number(selectedTeacher.branch_id || 0);
    if (branchIds.length > 0 && !branchIds.includes(branchId) && teacherBranch !== branchId) {
      alert('所選老師未綁定目前分校，請改選其他老師');
      return;
    }
  }
  const slotDurList = (form.day_time_slots || []).length
    ? (form.day_time_slots || []).map((s) => durationHoursToMinutes(Number(s.duration_hours) || form.duration_hours))
    : [durationHoursToMinutes(form.duration_hours)];
  const minSlotM = Math.min(...slotDurList);
  const maxSlotM = Math.max(...slotDurList);
  if (minSlotM < 30) {
    alert('上課時長至少為 0.5 小時');
    return;
  }
  if (maxSlotM > 480) {
    alert('上課時長不可超過 8 小時');
    return;
  }
  const durationMinutes = maxSlotM;
  if (manualSessionCount.value > safePlannedSessions.value) {
    alert(`手動指定堂數不可超過${plannedCountLabel.value}`);
    return;
  }

  if (form.payment_type === 'monthly' && !form.settlement_day) {
    alert('月結課請先選擇結算日');
    return;
  }
  const projectedFutureYmds = [...new Set(futureSessionOccurrences.value.map((o) => o.ymd))];
  if (form.payment_type === 'monthly') {
    const targetYm = toYmd(currentMonth.value).slice(0, 7);
    const allDates = sortDates([...manualDates.value, ...projectedFutureYmds]);
    const hasCrossMonth = allDates.some((date) => String(date).slice(0, 7) !== targetYm);
    if (hasCrossMonth) {
      alert('月結課程僅能建立在同一月份，請調整手動日期或月份。');
      return;
    }
  }

  const remainingSessions = safePlannedSessions.value - manualSessionCount.value;
  if (remainingSessions > 0 && (form.days_of_week || []).length === 0) {
    alert('尚有未排堂次，請先設定固定上課星期讓系統推算未來日期');
    return;
  }
  if (selectedDays.value.length > 0) {
    const missing = selectedDays.value.find((day) => !slotStartTimeByDay.value[day]);
    if (missing) {
      alert(`請設定週${weekdayLabelMap[missing]}的上課時間`);
      return;
    }
  }
  if (futureSessionOccurrences.value.some((o) => o.ymd < todayYmd)) {
    alert('系統預排日期不可早於今天，請調整固定上課星期');
    return;
  }
  if ((manualSessionCount.value + futureSessionOccurrences.value.length) !== safePlannedSessions.value) {
    alert(`系統無法補齊至${plannedCountLabel.value}，請調整固定上課星期或手動指定日期`);
    return;
  }

  const sessionPlan = [];
  for (const ymd of manualDates.value) {
    const dow = weekdayOneToSeven(new Date(`${ymd}T12:00:00`));
    const indices = getSlotIndicesForDay(dow);
    const kind = isManualDateConfirmed(ymd) ? 'confirmed' : 'future';
    if (indices.length > 0) {
      for (const idx of indices) {
        const s = form.day_time_slots[idx];
        sessionPlan.push({
          session_date: ymd,
          start_time: normalizeHalfHourTime(s.start_time),
          kind,
          subject: String(s.subject || form.subject),
        });
      }
    } else {
      // 手動日期不在固定上課星期，使用全域預設時間建立
      sessionPlan.push({
        session_date: ymd,
        start_time: normalizeHalfHourTime(form.start_time || '16:00'),
        kind,
        subject: String(form.subject),
      });
    }
  }
  for (const fs of futureSessionOccurrences.value) {
    sessionPlan.push({
      session_date: fs.ymd,
      start_time: fs.start_time,
      kind: 'future',
      subject: String(fs.subject || form.subject),
    });
  }
  sessionPlan.sort((a, b) => {
    const c = a.session_date.localeCompare(b.session_date);
    if (c !== 0) return c;
    return a.start_time.localeCompare(b.start_time);
  });

  submitting.value = true;
  try {
    const normalizedStartTime = normalizeHalfHourTime(form.start_time);
    const normalizedDaySlots = [];
    for (const day of selectedDays.value) {
      for (const slot of orderedSlotsForWeekday(day)) {
        normalizedDaySlots.push({
          day,
          start_time: slot.start_time,
          duration_minutes: slot.duration_minutes,
          subject: String(slot.subject || form.subject),
        });
      }
    }
    const payload = {
      branch_id: branchId,
      student_id: Number(form.student_id),
      teacher_id: Number(form.teacher_id),
      subject: form.subject,
      class_type: form.class_type,
      confirmed_dates: [],
      future_dates: [],
      session_plan: sessionPlan,
      days_of_week: [...(form.days_of_week || [])].map((d) => Number(d)).filter((d) => d >= 1 && d <= 7),
      day_time_slots: normalizedDaySlots,
      start_time: normalizedStartTime,
      duration_minutes: durationMinutes,
      rate_unit: hasPerDayDuration.value ? 'hour' : 'session',
      price_per_session: Math.max(0, Number(form.price_per_session) || 0),
      payment_type: form.payment_type || 'session',
      settlement_day: form.payment_type === 'monthly' ? Number(form.settlement_day) || null : null,
      monthly_sessions: form.payment_type === 'monthly' ? safePlannedSessions.value : null,
      room_id: form.room_id ? Number(form.room_id) : null,
      memo: form.memo || null,
      paid_at: form.paid_at || null,
      course_start_date: form.course_start_date || null,
      mode: props.mode,
    };

    if (form.payment_type === 'session') {
      payload.total_classes = safePlannedSessions.value;
    }

    const result = await createUniversalClassSchedule(payload);
    const createdConfirmed = Number(result?.created_confirmed_sessions ?? sessionPlan.filter((r) => r.kind === 'confirmed').length);
    const createdFuture = Number(result?.created_future_sessions ?? sessionPlan.filter((r) => r.kind === 'future').length);
    const autoBackfilled = Number(result?.auto_backfilled_sessions ?? 0);
    const autoBackfillSuffix = autoBackfilled > 0
      ? `，其中 ${autoBackfilled} 堂因新增時已過下課時間，系統已自動補登核准`
      : '';
    let msg = `已建立 ${createdConfirmed + createdFuture} 堂課（已上課 ${createdConfirmed}、未來預排 ${createdFuture}）${autoBackfillSuffix}`;
    if (result?.dual_teacher_warning) {
      msg += `\n\n⚠️ ${result.dual_teacher_warning}`;
    }
    if (result?.scope_warning) {
      msg += `\n\n⚠️ 學段提示：${result.scope_warning}`;
    }
    alert(msg);
    emit('success', result);
  } catch (err) {
    if (err?.isDuplicateCourse) {
      emit('duplicate-course', {
        conflicts: err.conflicts,
        originalPayload: err.originalPayload,
        message: err.message,
      });
      return;
    }
    alert(err?.message || '排課失敗，請稍後再試');
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.universal-scheduler-modal {
  width: min(1180px, 96vw);
  max-height: 94vh;
  overflow-y: auto;
  padding: 0;
  border-radius: 20px;
  background: #f9fafb;
}

.scheduler-header {
  padding: 22px 24px 14px;
  border-bottom: 1px solid #e5e7eb;
  background: #f9fafb;
}

.scheduler-subtitle {
  margin-top: 6px;
  color: var(--text-light);
  font-size: 13px;
}

.scheduler-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  gap: 16px;
  padding: 18px 20px;
  background: #f9fafb;
}

.panel-stack {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.scheduler-card {
  border: 1px solid #e5e7eb;
  border-radius: 20px;
  padding: 16px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}

.scheduler-card h4 {
  margin-bottom: 12px;
  font-size: 14px;
  color: var(--text);
}

.scheduler-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-group-full {
  grid-column: 1 / -1;
}

.field-note {
  font-size: 12px;
  color: var(--text-light);
}
.warning-text {
  color: #b45309;
  font-weight: 600;
}

.weekday-row {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.weekday-chip {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 58px;
  padding: 7px 11px;
  border-radius: 999px;
  border: 1px solid #d6deeb;
  background: #fff;
  cursor: pointer;
  text-align: center;
}

.weekday-chip.selected {
  background: #ecf4ff;
  border-color: #6ea8fe;
}

.weekday-chip input[type='checkbox'] {
  position: absolute;
  width: 0;
  height: 0;
  opacity: 0;
  pointer-events: none;
}

.weekday-chip span {
  display: inline-block;
  white-space: nowrap;
  line-height: 1;
}

.weekday-slot-grid {
  margin-top: 12px;
  display: grid;
  gap: 8px;
}

.weekday-slot-row {
  display: grid;
  grid-template-columns: 56px minmax(120px, 170px) auto;
  gap: 8px;
  align-items: center;
}
.weekday-slot-row.per-day-row {
  grid-template-columns: 56px minmax(88px, 120px) minmax(88px, 130px) 70px auto minmax(28px, 32px);
}
.slot-subject-select {
  min-width: 0;
  padding: 6px 8px;
  border: 1px solid #d6deeb;
  border-radius: 8px;
  font-size: 13px;
  background: #fff;
}
.per-day-dur {
  width: 70px;
  text-align: center;
  padding: 5px 4px;
  border: 1px solid #d6deeb;
  border-radius: 8px;
  font-size: 13px;
}

.weekday-slot-day {
  font-size: 12px;
  color: var(--text-light);
}

.calendar-card {
  padding-bottom: 12px;
}

.calendar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.calendar-weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
  text-align: center;
  font-size: 12px;
  color: #59677a;
  margin-bottom: 6px;
}

.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}

.calendar-cell {
  border: 1px solid #d8e0ed;
  background: #fff;
  border-radius: 10px;
  min-height: 44px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  font-size: 13px;
}

.calendar-cell small {
  font-size: 10px;
  font-weight: 600;
}

.calendar-cell.muted {
  opacity: 0.45;
}

.calendar-cell.confirmed {
  border-color: #52b36f;
  background: #ebf9ef;
  color: #16783b;
  font-weight: 700;
}

.calendar-cell.manual-future {
  border-color: #8b5cf6;
  background: #f3f0ff;
  color: #5b21b6;
  font-weight: 700;
}

.calendar-cell.future {
  border-color: #71a5ff;
  background: #ecf4ff;
  color: #1657c1;
  font-weight: 700;
}

.calendar-cell.course-start-marker {
  box-shadow: inset 0 -3px 0 0 #f59e0b;
}

.cell-label {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.02em;
  line-height: 1;
}
.cell-label-confirmed { color: #16783b; }
.cell-label-scheduled { color: #5b21b6; }
.cell-label-auto { color: #1657c1; }
.cell-flag {
  font-size: 8px;
  font-weight: 700;
  color: #b45309;
  background: #fef3c7;
  border-radius: 3px;
  padding: 0 3px;
  line-height: 1.3;
}

.summary-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 10px;
}

.summary-pill {
  border-radius: 10px;
  padding: 10px;
  border: 1px solid #d9e1ef;
  background: #f8fbff;
}

.summary-pill .summary-label {
  font-size: 11px;
  color: #60758f;
}

.summary-pill strong {
  font-size: 18px;
}

.summary-pill.confirmed {
  background: #ecf9ef;
  border-color: #b8e6c4;
  color: #177a3c;
}

.summary-pill.future {
  background: #edf4ff;
  border-color: #bed7ff;
  color: #1858bc;
}

.legend-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 8px;
}

.legend-chip {
  border-radius: 999px;
  padding: 5px 10px;
  font-size: 12px;
}

.legend-chip.confirmed {
  background: #ecf9ef;
  color: #177a3c;
}

.legend-chip.manual-future {
  background: #f3f0ff;
  color: #5b21b6;
}

.legend-chip.future {
  background: #edf4ff;
  color: #1858bc;
}

.course-start-info {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  padding: 8px 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
  font-size: 13px;
  color: #92400e;
}
.course-start-badge {
  display: inline-block;
  padding: 2px 8px;
  background: #fef3c7;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  color: #b45309;
}

.hint-text {
  color: var(--text-light);
  font-size: 12px;
  line-height: 1.55;
}

.calendar-actions {
  margin-top: 10px;
}

.date-list {
  margin-top: 10px;
  padding: 10px;
  border-radius: 10px;
  border: 1px solid #d9e1ef;
  font-size: 12px;
}

.date-list p {
  margin-bottom: 6px;
  font-weight: 700;
}

.date-list.confirmed {
  background: #f6fcf7;
  border-color: #cae8d2;
}

.date-list.manual-future-list {
  background: #faf8ff;
  border-color: #ddd6fe;
}

.date-list.future {
  background: #f7faff;
  border-color: #c8daf7;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 0 20px 18px;
}

@media (max-width: 980px) {
  .scheduler-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .scheduler-grid {
    grid-template-columns: 1fr;
  }

  .summary-row {
    grid-template-columns: 1fr;
  }
}

.scope-warning-banner {
  background: #fffbeb;
  border: 1px solid #f59e0b;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  color: #92400e;
  line-height: 1.5;
}

.mode-tabs {
  display: flex;
  gap: 0;
  margin-top: 12px;
  border: 1px solid #d6deeb;
  border-radius: 10px;
  overflow: hidden;
  width: fit-content;
}
.mode-tab {
  padding: 7px 18px;
  border: none;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
  transition: background 0.15s, color 0.15s;
}
.mode-tab + .mode-tab {
  border-left: 1px solid #d6deeb;
}
.mode-tab.active {
  background: #3b82f6;
  color: #fff;
  font-weight: 600;
}

.package-layout {
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.8fr);
}

/* --- Subject cards --- */
.pkg-card {
  border: 1px solid #e2e8f0;
  border-left: 4px solid #3b82f6;
  border-radius: 10px;
  padding: 12px 14px;
  margin-bottom: 10px;
  background: #fff;
  cursor: pointer;
  transition: box-shadow .15s, border-color .15s;
}
.pkg-card:hover {
  box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.pkg-card-active {
  box-shadow: 0 2px 12px rgba(59,130,246,.12);
  border-color: #93c5fd;
  background: #f8fbff;
}
.pkg-card-header {
  display: flex;
  align-items: center;
  gap: 8px;
}
.pkg-card-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.pkg-card-fields {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  flex: 1;
  align-items: center;
}
.pkg-inline-select {
  padding: 4px 8px;
  border-radius: 6px;
  border: 1px solid #d1d5db;
  font-size: 13px;
  max-width: 110px;
}
.pkg-inline-teacher {
  max-width: 140px;
  font-size: 13px;
}
.pkg-inline-dur {
  width: 52px;
  padding: 4px 6px;
  border-radius: 6px;
  border: 1px solid #d1d5db;
  font-size: 13px;
  text-align: center;
}
.pkg-inline-date {
  padding: 4px 6px;
  border-radius: 6px;
  border: 1px solid #d1d5db;
  font-size: 12px;
  max-width: 130px;
}
.pkg-remove-btn {
  width: 26px;
  height: 26px;
  border: 1px solid #fca5a5;
  border-radius: 8px;
  background: #fff;
  color: #ef4444;
  cursor: pointer;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.pkg-remove-btn:hover {
  background: #fef2f2;
}
.pkg-card-dates {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  min-height: 22px;
}
.pkg-card-count {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  margin-right: 4px;
}
.pkg-date-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  background: #fff;
  border: 1px solid #b8e6c4;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
}
.pkg-date-chip button {
  border: none;
  background: none;
  color: #ef4444;
  cursor: pointer;
  font-size: 11px;
  padding: 0 2px;
  line-height: 1;
}
.pkg-add-btn {
  margin-top: 8px;
  width: 100%;
  padding: 8px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: transparent;
  color: #64748b;
  font-size: 13px;
  cursor: pointer;
  transition: border-color .15s, color .15s;
}
.pkg-add-btn:hover {
  border-color: #3b82f6;
  color: #3b82f6;
}

/* --- Package calendar --- */
.calendar-card { padding-bottom: 14px; }

.pkg-cal-active-label {
  text-align: center;
  font-size: 13px;
  color: #475569;
  margin: 6px 0 2px;
}
.pkg-cal-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  justify-content: center;
  margin: 8px 0 10px;
}
.pkg-cal-tab {
  padding: 3px 12px;
  border: 1.5px solid #d1d5db;
  border-radius: 999px;
  background: #fff;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: all .15s;
}
.pkg-cal-tab.active {
  color: #fff !important;
}
.pkg-cell-disabled {
  opacity: .35;
  cursor: default !important;
}
.pkg-cell-dots {
  display: flex;
  gap: 2px;
  justify-content: center;
  margin-top: 2px;
}
.pkg-cell-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

/* --- Package summary --- */
.pkg-summary-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 8px;
  margin-bottom: 10px;
}
.pkg-summary-item {
  text-align: center;
  padding: 10px 6px;
  border-radius: 10px;
}
.pkg-summary-num {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
}
.pkg-summary-lbl {
  font-size: 11px;
  color: #64748b;
  margin-top: 2px;
}
.pkg-summary-total {
  background: #eff6ff;
  color: #1e40af;
}
.pkg-summary-total .pkg-summary-num { color: #1e40af; }
.pkg-summary-used {
  background: #ecfdf5;
  color: #065f46;
}
.pkg-summary-used .pkg-summary-num { color: #065f46; }
.pkg-summary-remain {
  background: #fefce8;
  color: #854d0e;
}
.pkg-summary-remain .pkg-summary-num { color: #854d0e; }

.pkg-summary-meta {
  display: flex;
  gap: 12px;
  justify-content: center;
  font-size: 12px;
  color: #64748b;
  margin-bottom: 8px;
}
.pkg-summary-meta span {
  padding: 2px 10px;
  background: #f1f5f9;
  border-radius: 999px;
}

@media (max-width: 760px) {
  .package-layout {
    grid-template-columns: 1fr;
  }
  .pkg-card-fields {
    flex-direction: column;
    align-items: stretch;
  }
  .pkg-inline-select,
  .pkg-inline-teacher,
  .pkg-inline-dur,
  .pkg-inline-date {
    max-width: 100%;
    width: 100%;
  }
}
</style>
