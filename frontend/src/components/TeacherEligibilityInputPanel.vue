<template>
  <section class="eligibility-input-panel">
    <div class="input-panel-heading">
      <div>
        <div class="eyebrow"><span class="material-symbols-outlined">fact_check</span>資料補登與審核</div>
        <h3>把系統無法自動得知的薪資要件補進來</h3>
        <p>全校放假請用課程管理的「連假批次請假」。這裡平常是補系統沒抓到的老師請假；假日曆只在薪資缺資料時才用。送出後核准前可修改或撤回。</p>
      </div>
      <button class="btn-outline small" type="button" :disabled="loading" @click="loadInputs">
        <span class="material-symbols-outlined">sync</span>更新待辦
      </button>
    </div>

    <div class="workflow-steps" aria-label="資料流程">
      <span class="workflow-step active"><b>1</b>主任補登</span>
      <span class="workflow-arrow">→</span>
      <span class="workflow-step"><b>2</b>主任確認</span>
      <span class="workflow-arrow">→</span>
      <span class="workflow-step"><b>3</b>總部核准（扣除／行政加給／底薪）</span>
    </div>

    <div class="input-panel-grid">
      <form class="data-form" @submit.prevent="submitForm">
        <div class="form-toolbar">
          <div>
            <span class="form-kicker">{{ editingKey ? '修改資料' : '新增資料' }}</span>
            <strong>{{ formTitle }}</strong>
          </div>
          <select v-model="formType" aria-label="資料類型">
            <option value="event">假日／公休／請假</option>
            <option value="achievement">升學成果／年度績優</option>
            <option value="deduction">扣除案件</option>
            <option value="allowance">行政加給</option>
          </select>
        </div>

        <template v-if="formType === 'event'">
          <label>資料類型
            <select v-model="eventForm.event_type">
              <option value="leave">請假／補課抵扣</option>
              <option value="holiday">假日曆補登（少用）</option>
              <option value="official_closure">官方活動／統一公休（少用）</option>
            </select>
          </label>
          <p class="field-hint">{{ eventHint }}</p>
          <label v-if="eventForm.event_type === 'leave'">老師
            <select v-model="eventForm.teacher_id" required>
              <option value="">請選擇老師</option>
              <option v-for="teacher in teachers" :key="teacher.teacher_id" :value="teacher.teacher_id">{{ teacher.teacher_name }}（{{ teacher.teacher_id }}）</option>
            </select>
          </label>
          <div class="form-two-col">
            <label>日期
              <input v-model="eventForm.event_date" type="date" required />
            </label>
            <label v-if="eventForm.event_type === 'leave'">請假時數
              <input v-model="eventForm.hours" type="number" min="0" step="0.5" required />
            </label>
          </div>
          <label v-if="eventForm.event_type === 'leave'">假別
            <input v-model.trim="eventForm.leave_type" placeholder="例：特休、假日假、事假" />
          </label>
          <div v-if="eventForm.event_type === 'leave'" class="form-two-col">
            <label>假日假可抵時數
              <input v-model="eventForm.holiday_leave_hours" type="number" min="0" step="0.5" placeholder="沒有則留白" />
            </label>
            <label>補課狀態
              <select v-model="eventForm.makeup_completed">
                <option value="">尚未確認</option>
                <option value="true">已完成補課</option>
                <option value="false">未完成補課</option>
              </select>
            </label>
          </div>
          <label>佐證／備註
            <textarea v-model.trim="eventForm.evidence" rows="2" placeholder="可填公告編號、檔案名稱、補課紀錄或備註"></textarea>
          </label>
        </template>

        <template v-else-if="formType === 'achievement'">
          <label>老師
            <select v-model="achievementForm.teacher_id" required>
              <option value="">請選擇老師</option>
              <option v-for="teacher in teachers" :key="teacher.teacher_id" :value="teacher.teacher_id">{{ teacher.teacher_name }}（{{ teacher.teacher_id }}）</option>
            </select>
          </label>
          <label>成果類型
            <select v-model="achievementForm.outcome_key">
              <option value="student_outcome">學生升學成果</option>
              <option value="employee_of_year">年度績優員工</option>
            </select>
          </label>
          <div class="form-two-col">
            <label>學生編號（升學成果）
              <input v-model="achievementForm.student_id" type="number" min="1" placeholder="可留白，若系統已有名單" />
            </label>
            <label>科目
              <input v-model.trim="achievementForm.subject" placeholder="例：英文" />
            </label>
          </div>
          <label v-if="achievementForm.outcome_key === 'employee_of_year'">得獎年度
            <input v-model="achievementForm.award_year" type="number" min="2027" max="2100" placeholder="2027起適用次年度12個月" required />
          </label>
          <div class="form-two-col">
            <label>生效日
              <input v-model="achievementForm.starts_on" type="date" required />
            </label>
            <label>失效日
              <input v-model="achievementForm.ends_on" type="date" required />
            </label>
          </div>
          <label>證明文件／連結／檔名
            <textarea v-model.trim="achievementForm.evidence" rows="2" required placeholder="請填證明文件名稱、雲端連結或檔案編號"></textarea>
          </label>
        </template>

        <template v-else-if="formType === 'deduction'">
          <label>老師
            <select v-model="deductionForm.teacher_id" required>
              <option value="">請選擇老師</option>
              <option v-for="teacher in teachers" :key="teacher.teacher_id" :value="teacher.teacher_id">{{ teacher.teacher_name }}（{{ teacher.teacher_id }}）</option>
            </select>
          </label>
          <label>案件類型
            <select v-model="deductionForm.deduction_key">
              <option value="harassment">疑似騷擾成立</option>
              <option value="major_complaint">重大客訴成立</option>
              <option value="unexcused_late">無故遲到早退影響營運</option>
              <option value="property_damage">嚴重破壞公物</option>
              <option value="rights_loss">造成補習班權益受損</option>
            </select>
          </label>
          <div class="form-two-col">
            <label>生效日
              <input v-model="deductionForm.starts_on" type="date" required />
            </label>
            <label>失效日（可留白）
              <input v-model="deductionForm.ends_on" type="date" />
            </label>
          </div>
          <label>事實說明／審核備註
            <textarea v-model.trim="deductionForm.reason" rows="3" required placeholder="請填事實、相關紀錄與主任確認依據"></textarea>
          </label>
          <p class="form-warning"><span class="material-symbols-outlined">security</span>扣除案件一定要經主任確認，再由總部核准；未完成不會自動扣10%。</p>
        </template>

        <template v-else>
          <label>老師
            <select v-model="allowanceForm.teacher_id" required>
              <option value="">請選擇老師</option>
              <option v-for="teacher in teachers" :key="'a'+teacher.teacher_id" :value="teacher.teacher_id">{{ teacher.teacher_name }}（{{ teacher.teacher_id }}）</option>
            </select>
          </label>
          <label>職務
            <select v-model="allowanceForm.role_key">
              <option value="admin_assist">行政協助</option>
              <option value="head_tutor">總導師</option>
              <option value="deputy_director">副主任</option>
            </select>
          </label>
          <div class="form-two-col">
            <label>加給倍率（0–10%）
              <input v-model.number="allowanceForm.rate" type="number" min="0" max="10" step="0.5" required />
            </label>
            <label>生效日
              <input v-model="allowanceForm.starts_on" type="date" required />
            </label>
          </div>
          <label>失效日（可留白）
            <input v-model="allowanceForm.ends_on" type="date" />
          </label>
          <label>判定依據
            <textarea v-model.trim="allowanceForm.reason" rows="3" required placeholder="主任判定職務與倍率的依據"></textarea>
          </label>
          <p class="form-warning"><span class="material-symbols-outlined">security</span>行政加給要主任確認、總部核准後才進教師倍率；未完成不能鎖定本月。</p>
        </template>

        <div v-if="formError" class="form-error" role="alert">{{ formError }}</div>
        <button class="btn-primary submit-button" type="submit" :disabled="saving || teachers.length === 0">
          <span class="material-symbols-outlined">{{ editingKey ? 'save' : 'send' }}</span>{{ saving ? '送出中…' : (editingKey ? '儲存修改' : '送出待審核') }}
        </button>
        <button v-if="editingKey" class="btn-outline submit-button" type="button" :disabled="saving" @click="cancelEdit">取消修改</button>
        <p v-if="teachers.length === 0" class="field-hint">目前分校沒有可選的正職老師，請先確認老師與分校資料。</p>
      </form>

      <div class="review-queue">
        <div class="queue-heading">
          <div>
            <span class="form-kicker">待辦清單</span>
            <strong>待主任處理 {{ pendingCount }} 筆</strong>
          </div>
          <span class="queue-total">共 {{ totalInputCount }} 筆</span>
        </div>
        <div v-if="loading" class="queue-empty">載入待辦中…</div>
        <div v-else-if="queueItems.length === 0" class="queue-empty">
          <span class="material-symbols-outlined">task_alt</span>
          <strong>目前沒有待處理資料</strong>
          <small>送出後會列在這裡。核准前可修改或撤回；核准後才會算進薪資。</small>
        </div>
        <div v-else class="queue-list">
          <article v-for="item in queueItems" :key="item.key" class="queue-item">
            <div class="queue-item-top">
              <div>
                <strong>{{ item.title }}</strong>
                <small>{{ item.subtitle }}</small>
              </div>
              <span :class="['queue-status', item.tone]">{{ item.statusLabel }}</span>
            </div>
            <p v-if="item.description">{{ item.description }}</p>
            <div class="queue-actions">
              <button v-if="item.canEdit" class="btn-outline small" type="button" :disabled="busyKey === item.key" @click="startEdit(item)">修改</button>
              <button v-if="item.canWithdraw" class="btn-outline small" type="button" :disabled="busyKey === item.key" @click="withdrawItem(item)">{{ busyKey === item.key ? '處理中…' : '撤回' }}</button>
              <button v-if="item.action === 'approve-event'" class="btn-soft" type="button" :disabled="busyKey === item.key" @click="runAction(item)">{{ busyKey === item.key ? '處理中…' : '主任核准' }}</button>
              <button v-if="item.action === 'verify-achievement'" class="btn-soft" type="button" :disabled="busyKey === item.key" @click="runAction(item)">{{ busyKey === item.key ? '處理中…' : '確認成果' }}</button>
              <button v-if="item.action === 'confirm-deduction'" class="btn-soft" type="button" :disabled="busyKey === item.key" @click="runAction(item)">{{ busyKey === item.key ? '處理中…' : '主任確認' }}</button>
              <button v-if="item.action === 'approve-deduction'" class="btn-primary small" type="button" :disabled="busyKey === item.key" @click="runAction(item)">{{ busyKey === item.key ? '處理中…' : '總部核准' }}</button>
              <button v-if="item.action === 'confirm-allowance'" class="btn-soft" type="button" :disabled="busyKey === item.key" @click="runAction(item)">{{ busyKey === item.key ? '處理中…' : '主任確認' }}</button>
              <button v-if="item.action === 'approve-allowance'" class="btn-primary small" type="button" :disabled="busyKey === item.key" @click="runAction(item)">{{ busyKey === item.key ? '處理中…' : '總部核准' }}</button>
            </div>
          </article>
        </div>
      </div>
    </div>
    <p v-if="message" class="panel-message" role="status">{{ message }}</p>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
  approveTeacherEligibilityAdminAllowance,
  approveTeacherEligibilityDeduction,
  approveTeacherEligibilityEvent,
  confirmTeacherEligibilityAdminAllowance,
  confirmTeacherEligibilityDeduction,
  createTeacherEligibilityAchievement,
  createTeacherEligibilityAdminAllowance,
  createTeacherEligibilityDeduction,
  createTeacherEligibilityEvent,
  fetchTeacherEligibilityInputs,
  updateTeacherEligibilityAchievement,
  updateTeacherEligibilityAdminAllowance,
  updateTeacherEligibilityDeduction,
  updateTeacherEligibilityEvent,
  verifyTeacherEligibilityAchievement,
  withdrawTeacherEligibilityAchievement,
  withdrawTeacherEligibilityAdminAllowance,
  withdrawTeacherEligibilityDeduction,
  withdrawTeacherEligibilityEvent,
} from '../lib/teacherEligibilityApi.js';
import { eventKindHint, eventKindLabel, eventSubtitle } from '../lib/teacherEligibilityDisplay.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  teachers: { type: Array, default: () => [] },
  userRole: { type: String, default: '' },
  start: { type: String, default: '' },
  end: { type: String, default: '' },
});
const emit = defineEmits(['changed']);

const formType = ref('event');
const loading = ref(false);
const saving = ref(false);
const busyKey = ref('');
const formError = ref('');
const message = ref('');
const editingKey = ref('');
const inputRecords = reactive({ events: [], achievements: [], deductions: [], admin_allowances: [] });

const today = new Date().toISOString().slice(0, 10);
const eventForm = reactive({
  event_type: 'leave', event_date: props.start || today, teacher_id: '', hours: '', leave_type: '',
  holiday_leave_hours: '', makeup_completed: '', evidence: '',
});
const achievementForm = reactive({
  teacher_id: '', student_id: '', outcome_key: 'student_outcome', subject: '', award_year: '', evidence: '',
  starts_on: props.start || today, ends_on: props.end || today,
});
const deductionForm = reactive({
  teacher_id: '', deduction_key: 'harassment', reason: '', starts_on: props.start || today, ends_on: '',
});
const allowanceForm = reactive({
  teacher_id: '', role_key: 'admin_assist', rate: 5, reason: '', starts_on: props.start || today, ends_on: '',
});

const formTitle = computed(() => ({
  event: '假日／公休／請假事件', achievement: '升學成果／年度績優', deduction: '扣除案件', allowance: '行政加給',
}[formType.value]));
const eventHint = computed(() => eventKindHint(eventForm.event_type));
const isHq = computed(() => props.userRole === 'super_admin');
const totalInputCount = computed(() => queueItems.value.length);
const queueItems = computed(() => {
  const teachersById = new Map(props.teachers.map((teacher) => [String(teacher.teacher_id), teacher.teacher_name]));
  const teacherName = (id) => teachersById.get(String(id)) || '未指定老師';
  const items = [];
  for (const row of inputRecords.events) {
    if (row.status === 'withdrawn') continue;
    const pending = row.status !== 'approved';
    items.push({
      key: `event-${row.id}`, kind: 'event', id: row.id, row,
      title: eventKindLabel(row.event_type), subtitle: eventSubtitle(row, teacherName(row.teacher_id)),
      description: row.evidence || row.leave_type || '',
      statusLabel: pending ? '待主任核准' : '已核准', tone: pending ? 'pending' : 'done',
      action: pending ? 'approve-event' : '', canEdit: pending, canWithdraw: pending,
    });
  }
  for (const row of inputRecords.achievements) {
    if (row.status === 'withdrawn') continue;
    const pending = row.status !== 'verified';
    items.push({
      key: `achievement-${row.id}`, kind: 'achievement', id: row.id, row,
      title: achievementLabel(row.outcome_key), subtitle: `${teacherName(row.teacher_id)}｜${row.starts_on || '未設生效日'}`,
      description: row.evidence || '尚未填寫證明文件',
      statusLabel: pending ? '待主任確認' : '已確認', tone: pending ? 'pending' : 'done',
      action: pending ? 'verify-achievement' : '', canEdit: pending, canWithdraw: pending,
    });
  }
  for (const row of inputRecords.deductions) {
    if (row.status === 'approved' || row.status === 'withdrawn') {
      if (row.status === 'withdrawn') continue;
      items.push({
        key: `deduction-${row.id}`, kind: 'deduction', id: row.id, row,
        title: deductionLabel(row.deduction_key), subtitle: `${teacherName(row.teacher_id)}｜${row.starts_on || '未設生效日'}`,
        description: row.reason || '尚未填寫事實說明', statusLabel: '已核准', tone: 'done',
        action: '', canEdit: false, canWithdraw: false,
      });
      continue;
    }
    const confirmed = Boolean(row.director_confirmed_at);
    items.push({
      key: `deduction-${row.id}`, kind: 'deduction', id: row.id, row,
      title: deductionLabel(row.deduction_key), subtitle: `${teacherName(row.teacher_id)}｜${row.starts_on || '未設生效日'}`,
      description: row.reason || '尚未填寫事實說明',
      statusLabel: confirmed ? '待總部核准' : '待主任確認', tone: confirmed ? 'hq' : 'pending',
      action: confirmed && isHq.value ? 'approve-deduction' : (confirmed ? '' : 'confirm-deduction'),
      canEdit: !confirmed, canWithdraw: !confirmed,
    });
  }
  for (const row of inputRecords.admin_allowances) {
    if (row.status === 'withdrawn') continue;
    if (row.status === 'approved') {
      items.push({
        key: `allowance-${row.id}`, kind: 'allowance', id: row.id, row,
        title: allowanceLabel(row.role_key), subtitle: `${teacherName(row.teacher_id)}｜${row.rate}%｜${row.starts_on || '未設生效日'}`,
        description: row.reason || '', statusLabel: '已核准', tone: 'done',
        action: '', canEdit: false, canWithdraw: false,
      });
      continue;
    }
    const confirmed = Boolean(row.director_confirmed_at);
    items.push({
      key: `allowance-${row.id}`, kind: 'allowance', id: row.id, row,
      title: allowanceLabel(row.role_key), subtitle: `${teacherName(row.teacher_id)}｜${row.rate}%｜${row.starts_on || '未設生效日'}`,
      description: row.reason || '',
      statusLabel: confirmed ? '待總部核准' : '待主任確認', tone: confirmed ? 'hq' : 'pending',
      action: confirmed && isHq.value ? 'approve-allowance' : (confirmed ? '' : 'confirm-allowance'),
      canEdit: !confirmed, canWithdraw: !confirmed,
    });
  }
  return items;
});
const pendingCount = computed(() => queueItems.value.filter((item) => item.action || item.canEdit).length);

function achievementLabel(key) { return key === 'employee_of_year' ? '年度績優員工' : '學生升學成果'; }
function deductionLabel(key) {
  return ({ harassment: '疑似騷擾成立', major_complaint: '重大客訴成立', unexcused_late: '無故遲到早退影響營運', property_damage: '嚴重破壞公物', rights_loss: '造成補習班權益受損' }[key] || key || '扣除案件');
}
function allowanceLabel(key) {
  return ({ admin_assist: '行政協助', head_tutor: '總導師', deputy_director: '副主任' }[key] || key || '行政加給');
}

function branchPayload(payload) { return props.branchId ? { ...payload, branch_id: Number(props.branchId) } : payload; }
function resetForm() {
  editingKey.value = '';
  eventForm.event_type = 'leave'; eventForm.event_date = props.start || today; eventForm.teacher_id = ''; eventForm.hours = '';
  eventForm.leave_type = ''; eventForm.holiday_leave_hours = ''; eventForm.makeup_completed = ''; eventForm.evidence = '';
  achievementForm.teacher_id = ''; achievementForm.student_id = ''; achievementForm.outcome_key = 'student_outcome'; achievementForm.subject = '';
  achievementForm.award_year = ''; achievementForm.evidence = ''; achievementForm.starts_on = props.start || today; achievementForm.ends_on = props.end || today;
  deductionForm.teacher_id = ''; deductionForm.deduction_key = 'harassment'; deductionForm.reason = ''; deductionForm.starts_on = props.start || today; deductionForm.ends_on = '';
  allowanceForm.teacher_id = ''; allowanceForm.role_key = 'admin_assist'; allowanceForm.rate = 5; allowanceForm.reason = ''; allowanceForm.starts_on = props.start || today; allowanceForm.ends_on = '';
}

async function loadInputs() {
  loading.value = true;
  try {
    const data = await fetchTeacherEligibilityInputs({ start: props.start, end: props.end, branchId: props.branchId });
    inputRecords.events = data.events || [];
    inputRecords.achievements = data.achievements || [];
    inputRecords.deductions = data.deductions || [];
    inputRecords.admin_allowances = data.admin_allowances || [];
  } catch (error) {
    formError.value = error?.message || '無法載入薪資要件待辦';
  } finally { loading.value = false; }
}

function cancelEdit() {
  resetForm();
  message.value = '';
}

function startEdit(item) {
  editingKey.value = item.key;
  formType.value = item.kind;
  const row = item.row || {};
  if (item.kind === 'event') {
    eventForm.event_type = row.event_type || 'leave';
    eventForm.event_date = row.event_date || today;
    eventForm.teacher_id = row.teacher_id ? String(row.teacher_id) : '';
    eventForm.hours = row.hours ?? '';
    eventForm.leave_type = row.leave_type || '';
    eventForm.holiday_leave_hours = row.holiday_leave_hours ?? '';
    eventForm.makeup_completed = row.makeup_completed === true || row.makeup_completed === 1 ? 'true' : (row.makeup_completed === false || row.makeup_completed === 0 ? 'false' : '');
    eventForm.evidence = row.evidence || '';
  } else if (item.kind === 'achievement') {
    achievementForm.teacher_id = String(row.teacher_id || '');
    achievementForm.student_id = row.student_id || '';
    achievementForm.outcome_key = row.outcome_key || 'student_outcome';
    achievementForm.subject = row.subject || '';
    achievementForm.award_year = row.award_year || '';
    achievementForm.evidence = row.evidence || '';
    achievementForm.starts_on = row.starts_on || today;
    achievementForm.ends_on = row.ends_on || today;
  } else if (item.kind === 'allowance') {
    allowanceForm.teacher_id = String(row.teacher_id || '');
    allowanceForm.role_key = row.role_key || 'admin_assist';
    allowanceForm.rate = Number(row.rate || 0);
    allowanceForm.reason = row.reason || '';
    allowanceForm.starts_on = row.starts_on || today;
    allowanceForm.ends_on = row.ends_on || '';
  } else {
    deductionForm.teacher_id = String(row.teacher_id || '');
    deductionForm.deduction_key = row.deduction_key || 'harassment';
    deductionForm.reason = row.reason || '';
    deductionForm.starts_on = row.starts_on || today;
    deductionForm.ends_on = row.ends_on || '';
  }
}

function editingId(kind) {
  if (!editingKey.value.startsWith(`${kind}-`)) return null;
  const id = Number(editingKey.value.slice(kind.length + 1));
  return Number.isInteger(id) && id > 0 ? id : null;
}

async function withdrawItem(item) {
  busyKey.value = item.key; formError.value = ''; message.value = '';
  try {
    if (item.kind === 'event') await withdrawTeacherEligibilityEvent(item.id);
    if (item.kind === 'achievement') await withdrawTeacherEligibilityAchievement(item.id);
    if (item.kind === 'deduction') await withdrawTeacherEligibilityDeduction(item.id);
    if (item.kind === 'allowance') await withdrawTeacherEligibilityAdminAllowance(item.id);
    if (editingKey.value === item.key) resetForm();
    await loadInputs(); emit('changed'); message.value = '已撤回，不會進入薪資計算。';
  } catch (error) { formError.value = error?.message || '撤回失敗'; }
  finally { busyKey.value = ''; }
}

async function submitForm() {
  saving.value = true; formError.value = ''; message.value = '';
  try {
    if (formType.value === 'event') {
      const payload = { ...eventForm, teacher_id: eventForm.teacher_id ? Number(eventForm.teacher_id) : null, hours: eventForm.hours === '' ? null : Number(eventForm.hours), holiday_leave_hours: eventForm.holiday_leave_hours === '' ? null : Number(eventForm.holiday_leave_hours), makeup_completed: eventForm.makeup_completed === '' ? null : eventForm.makeup_completed === 'true' };
      if (payload.event_type !== 'leave') { payload.teacher_id = null; payload.hours = null; payload.leave_type = null; payload.holiday_leave_hours = null; payload.makeup_completed = null; }
      const eventId = editingId('event');
      if (eventId) await updateTeacherEligibilityEvent(eventId, branchPayload(payload));
      else await createTeacherEligibilityEvent(branchPayload(payload));
    } else if (formType.value === 'achievement') {
      const payload = { ...achievementForm, teacher_id: Number(achievementForm.teacher_id), student_id: achievementForm.student_id ? Number(achievementForm.student_id) : null, award_year: achievementForm.award_year ? Number(achievementForm.award_year) : null };
      const achievementId = editingId('achievement');
      if (achievementId) await updateTeacherEligibilityAchievement(achievementId, branchPayload(payload));
      else await createTeacherEligibilityAchievement(branchPayload(payload));
    } else if (formType.value === 'allowance') {
      const allowanceId = editingId('allowance');
      const payload = branchPayload({ ...allowanceForm, teacher_id: Number(allowanceForm.teacher_id), ends_on: allowanceForm.ends_on || null });
      if (allowanceId) await updateTeacherEligibilityAdminAllowance(allowanceId, payload);
      else await createTeacherEligibilityAdminAllowance(payload);
    } else {
      const deductionId = editingId('deduction');
      const payload = branchPayload({ ...deductionForm, teacher_id: Number(deductionForm.teacher_id), ends_on: deductionForm.ends_on || null });
      if (deductionId) await updateTeacherEligibilityDeduction(deductionId, payload);
      else await createTeacherEligibilityDeduction(payload);
    }
    const wasEditing = Boolean(editingKey.value);
    resetForm(); await loadInputs(); emit('changed'); message.value = wasEditing ? '已儲存修改，仍須主任核准後才會進入薪資。' : '資料已送出，請在右側待辦清單完成主任確認。核准前可修改或撤回。';
  } catch (error) { formError.value = error?.message || '送出失敗，請檢查欄位後再試'; }
  finally { saving.value = false; }
}

async function runAction(item) {
  if (!item.action) return;
  busyKey.value = item.key; formError.value = ''; message.value = '';
  try {
    if (item.action === 'approve-event') await approveTeacherEligibilityEvent(item.id);
    if (item.action === 'verify-achievement') await verifyTeacherEligibilityAchievement(item.id);
    if (item.action === 'confirm-deduction') await confirmTeacherEligibilityDeduction(item.id);
    if (item.action === 'approve-deduction') await approveTeacherEligibilityDeduction(item.id);
    if (item.action === 'confirm-allowance') await confirmTeacherEligibilityAdminAllowance(item.id);
    if (item.action === 'approve-allowance') await approveTeacherEligibilityAdminAllowance(item.id);
    await loadInputs(); emit('changed'); message.value = '待辦狀態已更新，報表將重新計算。';
  } catch (error) { formError.value = error?.message || '審核操作失敗'; }
  finally { busyKey.value = ''; }
}

watch(() => [props.branchId, props.start, props.end], () => { loadInputs(); });
onMounted(loadInputs);
</script>

<style scoped>
.eligibility-input-panel { margin-bottom: 18px; padding: 18px; background: var(--ds-canvas); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--ds-shadow-1); }
.input-panel-heading { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
.eyebrow,.form-kicker { display:flex; align-items:center; gap:6px; color:var(--ds-primary); font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.eyebrow .material-symbols-outlined { font-size:18px; }.input-panel-heading h3 { margin:5px 0 4px; font-size:18px; }.input-panel-heading p { margin:0; color:var(--ds-ink-mute); font-size:13px; }
.workflow-steps { display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin:16px 0; color:var(--ds-ink-mute); font-size:12px; }.workflow-step { display:inline-flex; align-items:center; gap:6px; padding:6px 9px; border-radius:999px; background:var(--ds-canvas-soft); }.workflow-step.active { color:var(--ds-primary); background:var(--ds-primary-wash); }.workflow-step b { display:grid; width:18px; height:18px; place-items:center; border-radius:50%; background:currentColor; color:var(--ds-canvas); font-size:11px; }.workflow-arrow { color:var(--border-strong, var(--border)); }
.input-panel-grid { display:grid; grid-template-columns:minmax(0, 1.1fr) minmax(320px, .9fr); gap:16px; }.data-form,.review-queue { padding:16px; border:1px solid var(--border); border-radius:12px; background:var(--ds-canvas-soft); }.form-toolbar,.queue-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }.form-toolbar strong,.queue-heading strong { display:block; margin-top:3px; }.form-toolbar select { min-width:190px; }
.data-form label { display:flex; flex-direction:column; gap:6px; margin-bottom:11px; color:var(--ds-ink-mute); font-size:12px; font-weight:600; }.data-form input,.data-form select,.data-form textarea { width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid var(--border); border-radius:8px; background:var(--ds-canvas); color:var(--ds-ink); font:inherit; font-weight:400; }.data-form textarea { resize:vertical; }.form-two-col { display:grid; grid-template-columns:1fr 1fr; gap:10px; }.form-warning { display:flex; gap:7px; align-items:flex-start; margin:8px 0 12px; padding:9px 10px; border-radius:8px; color:var(--ds-warning); background:var(--ds-warning-wash); font-size:12px; line-height:1.5; }.form-warning .material-symbols-outlined { font-size:17px; }.form-error { margin:8px 0; color:var(--ds-danger); font-size:12px; }.field-hint { margin:8px 0 0; color:var(--ds-ink-mute); font-size:12px; }.submit-button { width:100%; justify-content:center; }
.queue-total { color:var(--ds-ink-mute); font-size:12px; }.queue-empty { display:flex; min-height:150px; flex-direction:column; align-items:center; justify-content:center; gap:7px; text-align:center; color:var(--ds-ink-mute); }.queue-empty .material-symbols-outlined { font-size:28px; color:var(--ds-success); }.queue-empty small { max-width:230px; line-height:1.5; }.queue-list { display:grid; gap:9px; max-height:380px; overflow:auto; }.queue-item { padding:11px; border:1px solid var(--border); border-radius:9px; background:var(--ds-canvas); }.queue-item-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }.queue-item strong { display:block; font-size:13px; }.queue-item small { display:block; margin-top:4px; color:var(--ds-ink-mute); font-size:11px; }.queue-item p { margin:9px 0; color:var(--ds-ink-mute); font-size:12px; line-height:1.5; white-space:pre-wrap; }.queue-status { flex-shrink:0; padding:4px 7px; border-radius:999px; font-size:11px; }.queue-status.pending { color:var(--ds-warning); background:var(--ds-warning-wash); }.queue-status.hq { color:var(--ds-primary); background:var(--ds-primary-wash); }.queue-status.done { color:var(--ds-success); background:var(--ds-success-wash, var(--ds-primary-wash)); }.queue-actions { display:flex; justify-content:flex-end; gap:7px; }.btn-soft { border:1px solid var(--ds-primary); border-radius:8px; padding:7px 10px; color:var(--ds-primary); background:var(--ds-primary-wash); font-size:12px; cursor:pointer; }.btn-primary { display:inline-flex; align-items:center; gap:6px; border:0; border-radius:8px; padding:9px 12px; color:var(--ds-canvas); background:var(--ds-primary); font-size:12px; cursor:pointer; }.btn-primary.small { padding:7px 10px; }.btn-primary:disabled,.btn-soft:disabled { opacity:.55; cursor:not-allowed; }.btn-outline { display:inline-flex; align-items:center; gap:6px; border:1px solid var(--border); border-radius:8px; padding:8px 10px; color:var(--ds-ink); background:var(--ds-canvas); cursor:pointer; }.btn-outline.small { padding:7px 9px; font-size:12px; }.btn-outline .material-symbols-outlined { font-size:17px; }.panel-message { margin:12px 3px 0; color:var(--ds-success); font-size:12px; }
@media (max-width: 960px) { .input-panel-grid { grid-template-columns:1fr; } }
@media (max-width: 560px) { .input-panel-heading { flex-direction:column; }.form-toolbar { align-items:flex-start; flex-direction:column; }.form-toolbar select { width:100%; }.form-two-col { grid-template-columns:1fr; }.workflow-arrow { display:none; } }
</style>
