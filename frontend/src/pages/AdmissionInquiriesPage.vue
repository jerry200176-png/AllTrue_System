<template>
  <main :class="['admission-page', standalone ? 'admission-page-public' : 'admission-page-staff']">
    <section v-if="standalone && !clientEnabled" class="admission-public-card admission-disabled" role="status">
      <div class="admission-kicker">全真一對一</div>
      <h1>問班入口準備中</h1>
      <p class="admission-lead">請稍後再試，或直接聯絡附近分校。</p>
    </section>
    <section v-if="standalone && clientEnabled" class="admission-public-card">
      <div class="admission-kicker">全真一對一</div>
      <h1>找到適合孩子的學習安排</h1>
      <p class="admission-lead">留下需求，分校主任會與您聯絡安排試聽。</p>

      <div v-if="submitted" class="admission-success" role="status">
        <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
        <h2>已收到問班需求</h2>
        <p>謝謝您，我們會依照您留下的時段與需求聯絡。</p>
        <button class="admission-button" type="button" @click="resetPublic">再提交一筆需求</button>
      </div>

      <form v-else class="admission-form" @submit.prevent="submitPublic">
        <div class="admission-progress" aria-label="問班進度">
          <span :class="{ active: step >= 1 }">1 基本資料</span>
          <span :class="{ active: step >= 2 }">2 學習需求</span>
        </div>
        <p v-if="errorMessage" class="admission-error" role="alert" aria-live="assertive">{{ errorMessage }}</p>
        <fieldset v-if="step === 1">
          <legend>先讓我們知道怎麼聯絡您</legend>
          <label>分校 <span>*</span>
            <select v-model="publicForm.campus_id" required>
              <option value="" disabled>請選擇方便的分校</option>
              <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
            </select>
          </label>
          <label>家長稱呼 <span>*</span><input v-model.trim="publicForm.parent_name" required maxlength="64" autocomplete="name" /></label>
          <label>聯絡電話 <span>*</span><input v-model.trim="publicForm.parent_phone" required maxlength="32" inputmode="tel" autocomplete="tel" /></label>
          <label>學生姓名 <span>*</span><input v-model.trim="publicForm.student_name" required maxlength="64" /></label>
          <button class="admission-button" type="button" @click="step = 2">下一步</button>
        </fieldset>
        <fieldset v-else>
          <legend>孩子想學什麼？</legend>
          <label>年級 <span>*</span>
            <select v-model="publicForm.grade" required>
              <option value="" disabled>請選擇年級</option>
              <option v-for="grade in grades" :key="grade" :value="grade">{{ grade }}</option>
            </select>
          </label>
          <label>學校 <span>*</span><input v-model.trim="publicForm.school_name" required maxlength="128" /></label>
          <label>想詢問的科目 <span>*</span>
            <select v-model="publicForm.subject" required>
              <option value="" disabled>請選擇科目</option>
              <option v-for="subject in subjects" :key="subject" :value="subject">{{ subject }}</option>
            </select>
          </label>
          <label>方便時段 <span>*</span>
            <select v-model="publicForm.preferred_slots[0]" required>
              <option value="" disabled>請選擇一個時段</option>
              <option v-for="slot in slots" :key="slot" :value="slot">{{ slot }}</option>
            </select>
          </label>
          <label>補充說明 <textarea v-model.trim="publicForm.public_notes" maxlength="500" rows="3" placeholder="例如：希望加強的單元（選填）"></textarea></label>
          <label class="admission-consent"><input v-model="publicForm.consent" type="checkbox" required /> 我同意 AllTrue 以此需求聯絡我 <span>*</span></label>
          <div class="admission-actions">
            <button class="admission-button secondary" type="button" @click="step = 1">上一步</button>
            <button class="admission-button" type="submit" :disabled="busy">{{ busy ? '送出中…' : '送出問班需求' }}</button>
          </div>
        </fieldset>
      </form>
    </section>

    <template v-else>
      <header class="admission-staff-header">
        <div><div class="admission-kicker">招生工作流</div><h1>新生問班</h1><p>從新詢問一路推進到試聽與報名，學生資料只建立一次。</p></div>
        <button class="admission-button secondary" type="button" :disabled="loading" @click="loadQueue">重新整理</button>
      </header>
      <div class="admission-filters" role="search" aria-label="詢問篩選">
        <label>狀態
          <select v-model="statusFilter" @change="loadQueue">
            <option value="">全部</option>
            <option v-for="(label, key) in statusNames" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
      </div>
      <p v-if="errorMessage" class="admission-error" role="alert" aria-live="assertive">{{ errorMessage }}</p>
      <div v-if="loading" class="admission-skeleton" role="status" aria-label="載入詢問中">
        <div class="admission-skeleton-row" v-for="n in 4" :key="n"></div>
      </div>
      <div v-else-if="!inquiries.length" class="admission-empty"><span class="material-symbols-outlined" aria-hidden="true">inbox</span><h2>目前沒有新詢問</h2><p>公開問班送出後，這裡會出現下一步工作。</p><button class="admission-button secondary" type="button" @click="loadQueue">重新整理</button></div>
      <div v-else class="admission-staff-grid">
        <section class="admission-queue" aria-label="詢問清單">
          <button v-for="item in inquiries" :key="item.id" type="button" :class="['admission-queue-item', { selected: selectedId === item.id }]" @click="selectInquiry(item.id)">
            <span class="admission-status">{{ statusLabel(item.status) }}</span>
            <strong>{{ item.student_name }}</strong>
            <small>{{ item.subject }} · {{ item.parent_phone }}</small>
            <small>負責：{{ item.owner_name || '尚未認領' }}</small>
            <small class="admission-next">下一步：{{ nextActionLabel(item.next_action) }}</small>
          </button>
        </section>
        <article v-if="detail" class="admission-detail">
          <div class="admission-detail-head"><div><span class="admission-status">{{ statusLabel(detail.status) }}</span><h2>{{ detail.student_name }}</h2><p>{{ detail.grade }} · {{ detail.school_name }} · {{ detail.subject }}</p></div><a class="admission-phone" :href="'tel:' + detail.parent_phone">{{ detail.parent_name }} · {{ detail.parent_phone }}</a></div>
          <dl class="admission-meta">
            <div><dt>方便時段</dt><dd>{{ (detail.preferred_slots || []).join('、') || '未填' }}</dd></div>
            <div><dt>目前負責</dt><dd>{{ detail.owner_name || '尚未認領' }}</dd></div>
            <div><dt>下一步</dt><dd>{{ nextActionLabel(detail.next_action) }}</dd></div>
            <div><dt>下次追蹤</dt><dd>{{ detail.follow_up_at ? formatDate(detail.follow_up_at) : '尚未安排' }}</dd></div>
          </dl>
          <p v-if="detail.public_notes" class="admission-note">{{ detail.public_notes }}</p>
          <div v-if="!detail.owner_id" class="admission-panel admission-owner-panel">
            <h3>先認領這筆詢問</h3>
            <p class="admission-hint">認領後你會成為負責主任，後續聯絡與追蹤都會留在這筆紀錄。</p>
            <button class="admission-button" type="button" :disabled="busy" @click="claimInquiry">由我負責</button>
          </div>
          <div v-if="!['enrolled', 'lost'].includes(detail.status)" class="admission-panel">
            <h3>安排下次追蹤</h3>
            <div class="admission-mini-form"><input v-model="followUpAt" type="date" aria-label="下次追蹤日期" /><button class="admission-button" type="button" :disabled="busy" @click="saveFollowUp">儲存追蹤</button></div>
          </div>
          <section v-if="detail.history?.length" class="admission-history" aria-label="詢問歷程">
            <h3>處理歷程</h3>
            <ol><li v-for="event in detail.history" :key="event.occurred_at + event.reason_code"><strong>{{ historyLabel(event) }}</strong><time>{{ formatDateTime(event.occurred_at) }}</time></li></ol>
          </section>
          <div class="admission-workflow">
            <div v-if="['new', 'contacted'].includes(detail.status)" class="admission-panel">
              <h3>聯絡與安排試聽</h3>
              <textarea v-model="contactNote" rows="2" maxlength="1000" placeholder="記錄本次聯絡重點（選填）"></textarea>
              <button class="admission-button" type="button" :disabled="busy" @click="contactInquiry">標記已聯絡</button>
              <div class="admission-mini-form">
                <select v-model="trial.teacher_id" required><option value="" disabled>選擇老師</option><option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name || teacher.Name || teacher.username }}</option></select>
                <input v-model="trial.trial_date" type="date" required aria-label="試聽日期" />
                <input v-model="trial.start_time" type="time" required aria-label="開始時間" />
                <input v-model="trial.duration_minutes" type="number" min="30" max="480" step="30" required aria-label="分鐘數" />
              </div>
              <button class="admission-button" type="button" :disabled="busy" @click="scheduleTrial">建立試聽（帶入學生資料）</button>
              <button class="admission-button secondary" type="button" :disabled="busy" @click="markLost">暫不繼續</button>
            </div>
            <div v-if="detail.status === 'trial_scheduled'" class="admission-panel">
              <h3>記錄試聽結果</h3>
              <select v-model="trialResult"><option value="attended">已出席</option><option value="no_show">未到</option><option value="cancelled">取消</option><option value="not_suitable">不合適</option></select>
              <button class="admission-button" type="button" :disabled="busy" @click="recordResult">儲存結果</button>
            </div>
            <div v-if="detail.status === 'trial_completed' && detail.trial_result === 'attended'" class="admission-panel">
              <h3>開啟既有報名流程</h3>
              <p class="admission-hint">學生與家長資料已沿用；只需補正式課程的堂數與開課日。</p>
              <div class="admission-mini-form"><input v-model="formal.sessions" type="number" min="1" max="500" aria-label="正式堂數" /><input v-model="formal.start_date" type="date" aria-label="正式開課日" /></div>
              <button class="admission-button" type="button" :disabled="busy" @click="enroll">轉正式報名</button>
              <button class="admission-button secondary" type="button" :disabled="busy" @click="markLost">暫不報名／結案</button>
            </div>
            <div v-if="detail.status === 'trial_completed' && detail.trial_result !== 'attended'" class="admission-panel">
              <h3>試聽未轉正式</h3>
              <p class="admission-hint">結果：{{ resultLabel(detail.trial_result) }}。可結案或稍後再聯絡。</p>
              <button class="admission-button" type="button" :disabled="busy" @click="markLost">標為暫不繼續</button>
            </div>
            <div v-if="detail.status === 'enrolled'" class="admission-success compact" role="status"><span class="material-symbols-outlined" aria-hidden="true">task_alt</span>已連結正式課程，學生不需重複建立。</div>
            <div v-if="detail.status === 'lost'" class="admission-empty compact" role="status">此詢問已結案（暫不繼續）。</div>
          </div>
        </article>
      </div>
    </template>
  </main>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { admissionAction, convertAdmissionTrial, getAdmissionBranches, getAdmissionInquiry, getAdmissionInquiries, getAdmissionTeachers, submitAdmissionInquiry } from '../api';
import perfFlags from '../lib/perfFlags';

const props = defineProps({ standalone: { type: Boolean, default: false }, branchId: { type: [Number, String], default: null }, token: { type: String, default: '' } });
const standalone = computed(() => props.standalone);
const clientEnabled = perfFlags.ADMISSIONS_FUNNEL_V1;
const branches = ref([]);
const inquiries = ref([]);
const teachers = ref([]);
const detail = ref(null);
const selectedId = ref(null);
const step = ref(1);
const busy = ref(false);
const loading = ref(false);
const submitted = ref(false);
const errorMessage = ref('');
const contactNote = ref('');
const followUpAt = ref('');
const statusFilter = ref('');
const trialResult = ref('attended');
const publicForm = ref({ campus_id: '', parent_name: '', parent_phone: '', student_name: '', grade: '', school_name: '', subject: '', preferred_slots: [''], public_notes: '', consent: false });
const trial = ref({ teacher_id: '', trial_date: '', start_time: '16:00', duration_minutes: 120 });
const formal = ref({ sessions: 8, start_date: '' });
const grades = ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'J1', 'J2', 'J3', 'H1', 'H2', 'H3'];
const subjects = ['Chinese', 'English', 'Math', 'Physics', 'Chemistry', 'Science', 'Biology', 'Social'];
const slots = ['平日下午', '平日晚上', '週六上午', '週六下午', '週日上午'];
const statusNames = { new: '新詢問', contacted: '已聯絡', trial_scheduled: '已安排試聽', trial_completed: '已完成試聽', enrolled: '已報名', lost: '暫不繼續' };
const nextActionNames = { claim: '先認領負責', contact: '聯絡家長', schedule_trial: '安排試聽', record_result: '記錄試聽結果', enroll: '轉正式報名', enroll_or_lost: '報名或結案', mark_lost: '標為暫不繼續', done: '已完成', review: '檢視' };
const statusLabel = status => statusNames[status] || status;
const nextActionLabel = action => nextActionNames[action] || action;
const resultLabel = result => ({ attended: '已出席', no_show: '未到', cancelled: '取消', not_suitable: '不合適' }[result] || result);
const formatDate = value => String(value).slice(0, 10);
const formatDateTime = value => value ? new Date(value).toLocaleString('zh-TW', { dateStyle: 'short', timeStyle: 'short' }) : '';
const historyLabel = event => ({ submit: '收到問班需求', contacted: '已聯絡家長', owner_assigned: '認領負責', trial_scheduled: '已安排試聽', trial_completed: '已記錄試聽結果', enrolled: '已連結正式課程', lost: '已結案', follow_up_saved: '已更新追蹤' }[event.reason_code] || '更新詢問');

async function loadPublic() {
  try { branches.value = await getAdmissionBranches(); } catch (error) { errorMessage.value = error.message; }
}
async function submitPublic() {
  busy.value = true; errorMessage.value = '';
  try { await submitAdmissionInquiry({ ...publicForm.value, campus_id: Number(publicForm.value.campus_id), preferred_slots: publicForm.value.preferred_slots.filter(Boolean) }); submitted.value = true; } catch (error) { errorMessage.value = error.message; } finally { busy.value = false; }
}
function resetPublic() {
  submitted.value = false; step.value = 1; publicForm.value = { campus_id: '', parent_name: '', parent_phone: '', student_name: '', grade: '', school_name: '', subject: '', preferred_slots: [''], public_notes: '', consent: false };
}
async function loadQueue() {
  if (!props.token || !props.branchId) return;
  loading.value = true; errorMessage.value = '';
  try {
    const data = await getAdmissionInquiries(props.token, props.branchId, statusFilter.value || undefined);
    inquiries.value = data.data || [];
    if (selectedId.value && inquiries.value.some(item => item.id === selectedId.value)) await selectInquiry(selectedId.value);
    else if (inquiries.value[0]) await selectInquiry(inquiries.value[0].id);
    else { selectedId.value = null; detail.value = null; }
  } catch (error) { errorMessage.value = error.message; } finally { loading.value = false; }
}
async function selectInquiry(id) {
  selectedId.value = id; errorMessage.value = '';
  try { detail.value = await getAdmissionInquiry(props.token, id); contactNote.value = detail.value.staff_notes || ''; followUpAt.value = detail.value.follow_up_at ? formatDate(detail.value.follow_up_at) : ''; } catch (error) { errorMessage.value = error.message; }
}
async function claimInquiry() { await runAction('claim'); }
async function saveFollowUp() { await runAction('follow-up', { follow_up_at: followUpAt.value || null, staff_notes: contactNote.value }); }
async function contactInquiry() { await runAction('contact', { staff_notes: contactNote.value }); }
async function scheduleTrial() { await runAction('trial', { ...trial.value, teacher_id: Number(trial.value.teacher_id), duration_minutes: Number(trial.value.duration_minutes) }); }
async function recordResult() { await runAction('trial-result', { trial_result: trialResult.value }); }
async function markLost() { await runAction('lost', { staff_notes: contactNote.value }); }
async function enroll() {
  if (!detail.value?.trial_student_class_id || !formal.value.start_date) { errorMessage.value = '請填寫正式開課日。'; return; }
  busy.value = true; errorMessage.value = '';
  try { const converted = await convertAdmissionTrial(props.token, detail.value.trial_student_class_id, { sessions: Number(formal.value.sessions), start_date: formal.value.start_date, class_type: 'one_on_one' }); await admissionAction(props.token, detail.value.id, 'enroll', { student_class_id: converted?.new_course?.id }); await selectInquiry(detail.value.id); await loadQueue(); } catch (error) { errorMessage.value = error.message; } finally { busy.value = false; }
}
async function runAction(action, payload) {
  if (!detail.value) return;
  busy.value = true; errorMessage.value = '';
  try { await admissionAction(props.token, detail.value.id, action, payload); await selectInquiry(detail.value.id); await loadQueue(); } catch (error) { errorMessage.value = error.message; } finally { busy.value = false; }
}
async function loadStaff() {
  await loadQueue();
  try { teachers.value = await getAdmissionTeachers(props.token, props.branchId); } catch (error) { errorMessage.value = error.message; }
}
onMounted(async () => { if (standalone.value) await loadPublic(); else await loadStaff(); });
watch(() => props.branchId, async (value, previous) => {
  if (!standalone.value && value && value !== previous) await loadStaff();
});
</script>

<style scoped>
.admission-page { min-height: 100%; color: var(--ds-ink); }
.admission-page-public { display: grid; place-items: center; padding: 24px 16px; background: linear-gradient(150deg, var(--ds-primary-wash), var(--ds-canvas)); }
.admission-public-card { width: min(100%, 560px); padding: clamp(24px, 5vw, 48px); border: 1px solid var(--ds-hairline); border-radius: 16px; background: var(--ds-canvas); box-shadow: var(--ds-shadow-2); }
.admission-kicker { color: var(--ds-cta); font-size: 12px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
h1 { margin: 8px 0; font-size: clamp(26px, 5vw, 36px); line-height: 1.2; }
.admission-lead, .admission-staff-header p, .admission-hint { color: var(--ds-ink-mute); line-height: 1.6; }
.admission-form { margin-top: 28px; }
.admission-progress { display: flex; gap: 8px; margin-bottom: 24px; color: var(--ds-ink-mute); font-size: 13px; }
.admission-progress span { flex: 1; padding-bottom: 8px; border-bottom: 2px solid var(--ds-hairline); }
.admission-progress .active { color: var(--ds-cta); border-color: var(--ds-cta); font-weight: 700; }
fieldset { border: 0; } legend { margin-bottom: 16px; font-size: 18px; font-weight: 700; }
label { display: grid; gap: 6px; margin: 14px 0; font-size: 13px; font-weight: 600; } label span { color: var(--ds-danger); }
input, select, textarea { width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid var(--ds-hairline-input); border-radius: var(--ds-radius-md); background: var(--ds-canvas); color: var(--ds-ink); font: inherit; } textarea { min-height: 90px; resize: vertical; }
.admission-button { min-height: 44px; padding: 10px 18px; border: 0; border-radius: var(--ds-radius-md); background: var(--ds-cta); color: var(--ds-on-cta); font-weight: 700; cursor: pointer; } .admission-button:disabled { opacity: .55; cursor: wait; } .admission-button.secondary { border: 1px solid var(--ds-hairline-input); background: transparent; color: var(--ds-ink); }
.admission-actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 22px; } .admission-actions .admission-button:last-child { flex: 1; }
.admission-consent { display: flex; align-items: center; gap: 8px; } .admission-consent input { width: 20px; min-height: 20px; }
.admission-error { margin: 14px 0; padding: 10px 12px; border-radius: var(--ds-radius-md); background: var(--ds-danger-wash); color: var(--ds-danger); }
.admission-success { display: grid; gap: 10px; justify-items: start; padding: 24px 0; } .admission-success .material-symbols-outlined { color: var(--ds-success); font-size: 44px; } .admission-success.compact { display: flex; align-items: center; padding: 16px; border-radius: var(--ds-radius-md); background: var(--ds-success-wash); color: var(--ds-success); }
.admission-page-staff { padding: 28px clamp(16px, 4vw, 40px) 80px; } .admission-staff-header { display: flex; justify-content: space-between; gap: 24px; align-items: start; margin-bottom: 16px; } .admission-staff-header h1 { margin-bottom: 4px; }
.admission-filters { max-width: 240px; margin-bottom: 20px; } .admission-filters label { margin: 0; }
.admission-staff-grid { display: grid; grid-template-columns: minmax(220px, 320px) minmax(0, 1fr); gap: 20px; align-items: start; } .admission-queue, .admission-detail, .admission-panel { border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg); background: var(--ds-canvas); box-shadow: var(--ds-shadow-1); }
.admission-queue { overflow: hidden; } .admission-queue-item { display: grid; gap: 5px; width: 100%; padding: 16px; border: 0; border-bottom: 1px solid var(--ds-hairline); background: transparent; color: var(--ds-ink); text-align: left; cursor: pointer; } .admission-queue-item.selected { background: var(--ds-primary-wash); box-shadow: inset 3px 0 var(--ds-cta); } .admission-queue-item small { color: var(--ds-ink-mute); } .admission-next { color: var(--ds-cta) !important; font-weight: 600; }
.admission-status { display: inline-flex; width: fit-content; padding: 3px 8px; border-radius: 999px; background: var(--ds-info-wash); color: var(--ds-cta); font-size: 11px; font-weight: 700; }
.admission-detail { padding: clamp(18px, 4vw, 28px); } .admission-detail-head { display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap; padding-bottom: 20px; border-bottom: 1px solid var(--ds-hairline); } .admission-detail h2 { margin: 10px 0 4px; } .admission-detail p { color: var(--ds-ink-mute); } .admission-phone { color: var(--ds-cta); font-weight: 700; }
.admission-meta { display: grid; gap: 10px; margin: 18px 0 0; } .admission-meta div { display: grid; gap: 2px; } .admission-meta dt { font-size: 12px; color: var(--ds-ink-mute); } .admission-meta dd { margin: 0; font-weight: 600; }
.admission-owner-panel { margin-top: 18px; } .admission-history { margin-top: 18px; padding: 18px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg); background: var(--ds-surface-0); } .admission-history h3 { margin-bottom: 12px; font-size: 16px; } .admission-history ol { display: grid; gap: 10px; margin: 0; padding-left: 20px; } .admission-history li { display: flex; justify-content: space-between; gap: 12px; color: var(--ds-ink); } .admission-history time { color: var(--ds-ink-mute); font-size: 12px; white-space: nowrap; }
.admission-note { margin: 18px 0; padding: 12px; background: var(--ds-surface-0); border-radius: var(--ds-radius-md); } .admission-workflow { display: grid; gap: 16px; margin-top: 20px; } .admission-panel { display: grid; gap: 12px; padding: 18px; box-shadow: none; } .admission-panel h3 { font-size: 16px; } .admission-mini-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.admission-empty, .admission-loading { display: grid; justify-items: center; gap: 10px; padding: 64px 24px; text-align: center; color: var(--ds-ink-mute); } .admission-empty.compact { padding: 24px; border: 1px dashed var(--ds-hairline); border-radius: var(--ds-radius-md); } .admission-empty .material-symbols-outlined { font-size: 48px; color: var(--ds-primary); }
.admission-skeleton { display: grid; gap: 12px; margin-bottom: 20px; } .admission-skeleton-row { height: 64px; border-radius: var(--ds-radius-md); background: linear-gradient(90deg, var(--ds-surface-0), var(--ds-primary-wash), var(--ds-surface-0)); background-size: 200% 100%; animation: admission-shimmer 1.2s ease-in-out infinite; }
@keyframes admission-shimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
@keyframes admission-spin { to { transform: rotate(360deg); } }
@media (max-width: 720px) { .admission-staff-header { flex-direction: column; } .admission-staff-header .admission-button { width: 100%; } .admission-filters { max-width: none; } .admission-staff-grid { grid-template-columns: 1fr; } .admission-queue { max-height: 260px; overflow: auto; } .admission-mini-form { grid-template-columns: 1fr; } .admission-detail-head { display: grid; } }
@media (prefers-reduced-motion: reduce) { .admission-skeleton-row { animation: none; } }
</style>
