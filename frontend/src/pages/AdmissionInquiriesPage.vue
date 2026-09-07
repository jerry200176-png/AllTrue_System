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
          <span :class="{ active: step >= 1 }" :aria-current="step === 1 ? 'step' : undefined">1 基本資料</span>
          <span :class="{ active: step >= 2 }" :aria-current="step === 2 ? 'step' : undefined">2 學習需求</span>
        </div>
        <p v-if="errorMessage" class="admission-error" role="alert" aria-live="assertive">{{ errorMessage }}</p>
        <fieldset v-if="step === 1">
          <legend id="admission-step-title" tabindex="-1">先讓我們知道怎麼聯絡您</legend>
          <label for="admission-campus">分校 <span>*</span>
            <select id="admission-campus" v-model="publicForm.campus_id" required>
              <option value="" disabled>請選擇方便的分校</option>
              <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
            </select>
            <small v-if="presetBranchInfo && publicForm.campus_id === presetBranchInfo.id" class="admission-branch-preset-hint">
              已為您預選「{{ presetBranchInfo.name }}」
            </small>
          </label>
          <label for="admission-parent-name">家長稱呼 <span>*</span><input id="admission-parent-name" v-model.trim="publicForm.parent_name" required maxlength="64" autocomplete="name" /></label>
          <label for="admission-parent-phone">聯絡電話 <span>*</span><input id="admission-parent-phone" v-model.trim="publicForm.parent_phone" required maxlength="32" inputmode="tel" autocomplete="tel" /></label>
          <label for="admission-student-name">學生姓名 <span>*</span><input id="admission-student-name" v-model.trim="publicForm.student_name" required maxlength="64" /></label>
          <button class="admission-button" type="button" @click="advancePublicStep">下一步</button>
        </fieldset>
        <fieldset v-else>
          <legend id="admission-step-title" tabindex="-1">孩子想學什麼？</legend>
          <label for="admission-grade">年級 <span>*</span>
            <select id="admission-grade" v-model="publicForm.grade" required>
              <option value="" disabled>請選擇年級</option>
              <option v-for="grade in grades" :key="grade.value" :value="grade.value">{{ grade.label }}</option>
            </select>
          </label>
          <label for="admission-school">學校 <span>*</span><input id="admission-school" v-model.trim="publicForm.school_name" required maxlength="128" /></label>
          <label for="admission-subject">想詢問的科目 <span>*</span>
            <select id="admission-subject" v-model="publicForm.subject" required>
              <option value="" disabled>請選擇科目</option>
              <option v-for="subject in subjects" :key="subject.value" :value="subject.value">{{ subject.label }}</option>
            </select>
          </label>
          <label for="admission-slot">方便時段 <span>*</span>
            <select id="admission-slot" v-model="publicForm.preferred_slots[0]" required>
              <option value="" disabled>請選擇一個時段</option>
              <option v-for="slot in slots" :key="slot" :value="slot">{{ slot }}</option>
            </select>
          </label>
          <label>補充說明 <textarea v-model.trim="publicForm.public_notes" maxlength="500" rows="3" placeholder="例如：希望加強的單元（選填）"></textarea></label>
          <label class="admission-consent"><input v-model="publicForm.consent" type="checkbox" required /> 我同意 AllTrue 以此需求聯絡我 <span>*</span></label>
          <div class="admission-actions">
            <button class="admission-button secondary" type="button" @click="setPublicStep(1)">上一步</button>
            <button class="admission-button" type="submit" :disabled="busy">{{ busy ? '送出中…' : '送出問班需求' }}</button>
          </div>
        </fieldset>
      </form>
    </section>

    <template v-if="!standalone">
      <header class="admission-staff-header">
        <div class="admission-header-main">
          <div class="admission-kicker">招生工作流 · 諮詢與試聽進度</div>
          <h1>新生問班</h1>
          <p>集中處理分校家長問班需求、預約體驗試聽並推進正式報名。</p>
        </div>
        <div class="admission-header-actions">
          <button class="admission-button secondary" type="button" :title="publicFormUrl" @click="copyPublicLink">
            <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>{{ copySuccess ? '已複製連結' : '複製公開問班連結' }}
          </button>
          <button class="admission-button secondary" type="button" @click="openPublicForm">
            <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>查看公開問班表單
          </button>
          <button class="admission-button secondary" type="button" :disabled="loading" @click="loadQueue">
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span>重新整理
          </button>
        </div>
      </header>

      <div class="admission-filters-bar">
        <div class="admission-filters" role="search" aria-label="詢問篩選">
          <label for="admission-status-filter">
            <span class="admission-filter-label">狀態篩選</span>
            <select id="admission-status-filter" v-model="statusFilter" @change="loadQueue">
              <option value="">全部狀態</option>
              <option v-for="(label, key) in statusNames" :key="key" :value="key">{{ label }}</option>
            </select>
          </label>
        </div>
        <div class="admission-stats-strip" aria-label="處理摘要">
          <span v-if="urgentCount" class="admission-stat-chip urgent">
            <span class="material-symbols-outlined" aria-hidden="true">schedule</span>{{ urgentCount }} 筆需盡速聯絡
          </span>
          <span v-if="unclaimedCount" class="admission-stat-chip unassigned">
            <span class="material-symbols-outlined" aria-hidden="true">person_alert</span>{{ unclaimedCount }} 筆待認領
          </span>
          <span class="admission-stat-chip">
            <span class="material-symbols-outlined" aria-hidden="true">inbox</span>共 {{ inquiries.length }} 筆詢問
          </span>
        </div>
      </div>

      <p v-if="errorMessage" class="admission-error" role="alert" aria-live="assertive">{{ errorMessage }}</p>
      <div v-if="loading" class="admission-skeleton" role="status" aria-label="載入詢問中"><div v-for="n in 4" :key="n" class="admission-skeleton-row"></div></div>

      <div v-else-if="!inquiries.length" class="admission-empty">
        <div class="admission-empty-hero">
          <span class="material-symbols-outlined admission-empty-icon" aria-hidden="true">how_to_reg</span>
          <h2>目前沒有新詢問</h2>
          <p class="admission-empty-lead">公開問班送出後，這裡會出現下一步工作。主任可在此進行「聯絡家長 → 安排試聽 → 記錄結果 → 正式報名」完整推進。</p>
        </div>
        <div class="admission-empty-flow" aria-label="招生處理流程">
          <div class="admission-flow-step"><div class="admission-flow-num">1</div><strong>家長送出需求</strong><small>透過公開問班頁面提交</small></div>
          <span class="admission-flow-arrow" aria-hidden="true">→</span>
          <div class="admission-flow-step"><div class="admission-flow-num">2</div><strong>主任電訪確認</strong><small>了解年級科目與合適時段</small></div>
          <span class="admission-flow-arrow" aria-hidden="true">→</span>
          <div class="admission-flow-step"><div class="admission-flow-num">3</div><strong>安排體驗試聽</strong><small>選派老師並建立試聽課程</small></div>
          <span class="admission-flow-arrow" aria-hidden="true">→</span>
          <div class="admission-flow-step"><div class="admission-flow-num">4</div><strong>試聽轉正報名</strong><small>試聽後沿用資料轉正式課</small></div>
        </div>
        <div class="admission-empty-actions">
          <button class="admission-button" type="button" @click="copyPublicLink">
            <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>{{ copySuccess ? '已複製問班連結！' : '複製公開問班連結' }}
          </button>
          <button class="admission-button secondary" type="button" @click="openPublicForm">
            <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>查看公開問班表單
          </button>
          <button class="admission-button secondary" type="button" @click="loadQueue">
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span>重新整理
          </button>
        </div>
        <div class="admission-empty-hint-card">
          <div class="admission-hint-title"><span class="material-symbols-outlined" aria-hidden="true">help_outline</span><strong>家長若透過 LINE、電話或現場來訪？</strong></div>
          <p>您可以開啟公開問班表單代為記錄，或點擊「複製公開問班連結」將表單分享至 LINE 官方帳號、社群貼文或即時訊息。家長送出後將立即在此顯示，學生資料不需在排課時重複建檔。</p>
        </div>
      </div>

      <div v-else class="admission-staff-grid">
        <section class="admission-queue" aria-label="詢問清單">
          <div class="admission-queue-header">待處理隊列（點擊檢視詳情）</div>
          <button v-for="item in inquiries" :key="item.id" type="button" :class="['admission-queue-item', { selected: selectedId === item.id }]" :aria-pressed="selectedId === item.id" @click="selectInquiry(item.id)">
            <div class="admission-queue-top">
              <span :class="['admission-status', 'status-' + item.status]">{{ statusLabel(item.status) }}</span>
              <span v-if="getFollowUpMeta(item.follow_up_at)" :class="['admission-badge', getFollowUpMeta(item.follow_up_at).type]">{{ getFollowUpMeta(item.follow_up_at).label }}</span>
              <span v-else-if="!item.owner_id && !['enrolled', 'lost'].includes(item.status)" class="admission-badge unassigned">待認領</span>
            </div>
            <div class="admission-queue-title-row">
              <span class="admission-queue-name">{{ item.student_name }}</span>
              <span v-if="item.subject" class="admission-queue-subject">{{ item.subject }}</span>
            </div>
            <div class="admission-queue-meta-list">
              <small>{{ item.parent_phone }}</small>
              <small>負責：{{ item.owner_name || '尚未認領' }}</small>
            </div>
            <div class="admission-queue-next-action">
              <small class="admission-next">下一步：{{ nextActionLabel(item.next_action) }}</small>
            </div>
          </button>
        </section>

        <article v-if="detail" id="admission-detail" class="admission-detail">
          <div class="admission-pipeline-wrapper" aria-label="招生推進階段">
            <div class="admission-pipeline">
              <div v-for="(stage, idx) in PIPELINE_STAGES" :key="stage.key" :class="['admission-pipeline-node', getStageState(stage.key, detail.status)]">
                <div class="admission-pipeline-marker">
                  <span v-if="getStageState(stage.key, detail.status) === 'completed'" class="material-symbols-outlined" aria-hidden="true">check</span>
                  <span v-else>{{ idx + 1 }}</span>
                </div>
                <span class="admission-pipeline-label">{{ stage.label }}</span>
              </div>
            </div>
          </div>

          <div class="admission-detail-head">
            <div class="admission-detail-title-group">
              <div class="admission-detail-badges">
                <span :class="['admission-status', 'status-' + detail.status]">{{ statusLabel(detail.status) }}</span>
                <span v-if="getFollowUpMeta(detail.follow_up_at)" :class="['admission-badge', getFollowUpMeta(detail.follow_up_at).type]">{{ getFollowUpMeta(detail.follow_up_at).label }}</span>
              </div>
              <h2>{{ detail.student_name }}</h2>
              <p>{{ detail.grade }} · {{ detail.school_name }} · {{ detail.subject }}</p>
            </div>
            <a class="admission-phone" :href="'tel:' + detail.parent_phone">
              <span class="material-symbols-outlined" aria-hidden="true">phone</span>{{ detail.parent_name }} · {{ detail.parent_phone }}
            </a>
          </div>

          <dl class="admission-meta">
            <div><dt>年級與學校</dt><dd>{{ detail.grade || '未填' }} · {{ detail.school_name || '未填' }}</dd></div>
            <div><dt>方便時段</dt><dd>{{ (detail.preferred_slots || []).join('、') || '未填' }}</dd></div>
            <div><dt>目前負責</dt><dd>{{ detail.owner_name || '尚未認領' }}</dd></div>
            <div><dt>下步行動</dt><dd>{{ nextActionLabel(detail.next_action) }}</dd></div>
            <div><dt>下次追蹤</dt><dd>{{ detail.follow_up_at ? formatDate(detail.follow_up_at) : '尚未安排' }}</dd></div>
          </dl>

          <p v-if="detail.public_notes" class="admission-note"><strong>家長留言備註：</strong>{{ detail.public_notes }}</p>

          <div v-if="!detail.owner_id && !['enrolled', 'lost'].includes(detail.status)" class="admission-panel admission-owner-panel">
            <div class="admission-panel-header">
              <span class="material-symbols-outlined" aria-hidden="true">assignment_ind</span>
              <div><h3>先認領這筆詢問</h3><p class="admission-hint">認領後您會成為負責主任，系統自動記錄由您負責聯絡與追蹤。</p></div>
            </div>
            <button class="admission-button" type="button" :disabled="busy" @click="claimInquiry">認領負責此詢問</button>
          </div>

          <div class="admission-workflow" aria-label="當前推進動作">
            <div class="admission-action-box">
              <div class="admission-action-title">
                <span class="material-symbols-outlined" aria-hidden="true">play_circle</span>
                <strong>當前建議下一步：{{ nextActionLabel(detail.next_action) }}</strong>
              </div>

              <div v-if="detail.status === 'new'" class="admission-action-content">
                <p>請先電話聯絡家長確認學生目前學習狀況、想加強科目，並評估是否預約體驗試聽。</p>
                <div class="admission-form-row">
                  <label>電訪聯絡備註（選填）<textarea v-model.trim="contactNote" rows="2" placeholder="例如：家長希望週三晚上試聽，主要加強段考複習"></textarea></label>
                </div>
                <div class="admission-action-buttons">
                  <button class="admission-button" type="button" :disabled="busy" @click="contactInquiry">記錄已電訪聯絡</button>
                  <button class="admission-button secondary danger-text" type="button" :disabled="busy" @click="markLost">標為暫不繼續</button>
                </div>
                <div class="admission-direct-trial-toggle">
                  <button type="button" class="admission-link-btn" @click="showDirectTrial = !showDirectTrial">
                    {{ showDirectTrial ? '收起直接排試聽' : '家長已明確預約？點此直接安排試聽 →' }}
                  </button>
                </div>
              </div>

              <div v-if="detail.status === 'contacted' || (detail.status === 'new' && showDirectTrial)" class="admission-action-content">
                <p>為學生選派老師、日期與時段建立試聽課。系統會自動建置試聽課程，後續若轉正式課可直接沿用。</p>
                <div class="admission-trial-form">
                  <label>試聽老師 <span>*</span>
                    <select v-model="trial.teacher_id" required>
                      <option value="" disabled>請選擇試聽授課老師</option>
                      <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}</option>
                    </select>
                  </label>
                  <div class="admission-grid-2">
                    <label>試聽日期 <span>*</span><input v-model="trial.trial_date" type="date" required /></label>
                    <label>開始時間 <span>*</span><input v-model="trial.start_time" type="time" required /></label>
                  </div>
                </div>
                <div class="admission-action-buttons">
                  <button class="admission-button" type="button" :disabled="busy || !trial.teacher_id || !trial.trial_date" @click="scheduleTrial">建立體驗試聽課</button>
                  <button v-if="detail.status === 'contacted'" class="admission-button secondary danger-text" type="button" :disabled="busy" @click="markLost">標為暫不繼續</button>
                </div>
              </div>

              <div v-if="detail.status === 'trial_scheduled'" class="admission-action-content">
                <p>試聽課程已建立。試聽結束後，請在此記錄學生出席狀況與家長回饋。</p>
                <div class="admission-form-row">
                  <label>試聽結果 <span>*</span>
                    <select v-model="trialResult" required>
                      <option value="attended">已出席（滿意，有意願報名）</option>
                      <option value="no_show">未到（未依約出席）</option>
                      <option value="cancelled">取消（家長或學生取消）</option>
                      <option value="not_suitable">不合適（程度或時段無法配合）</option>
                    </select>
                  </label>
                </div>
                <div class="admission-action-buttons">
                  <button class="admission-button" type="button" :disabled="busy" @click="recordResult">記錄試聽結果</button>
                  <button class="admission-button secondary danger-text" type="button" :disabled="busy" @click="markLost">標為暫不繼續</button>
                </div>
              </div>

              <div v-if="detail.status === 'trial_completed'" class="admission-action-content">
                <p>試聽已完成（結果：<strong>{{ resultLabel(detail.trial_result) }}</strong>）。若學生決定繼續上課，可直接轉為正式報名；若暫不繼續亦可結案。</p>
                <div class="admission-grid-2">
                  <label>正式開課日 <span>*</span><input v-model="formal.start_date" type="date" required /></label>
                  <label>預購堂數 <span>*</span><input v-model.number="formal.sessions" type="number" min="1" max="100" required /></label>
                </div>
                <div class="admission-action-buttons">
                  <button class="admission-button" type="button" :disabled="busy || !formal.start_date" @click="enroll">轉正式報名（建立正式課程）</button>
                  <button class="admission-button secondary danger-text" type="button" :disabled="busy" @click="markLost">標為暫不繼續</button>
                </div>
              </div>
            </div>

            <div v-if="detail.status === 'enrolled'" class="admission-success compact" role="status">
              <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>已連結正式課程，學生資料與排課已完成閉環。
            </div>
            <div v-if="detail.status === 'lost'" class="admission-empty compact" role="status">
              <span class="material-symbols-outlined" aria-hidden="true">info</span>此詢問已結案（暫不繼續）。
            </div>
          </div>

          <div v-if="!['enrolled', 'lost'].includes(detail.status)" class="admission-panel">
            <div class="admission-panel-header">
              <span class="material-symbols-outlined" aria-hidden="true">event_repeat</span>
              <div><h3>安排下次追蹤日期</h3><p class="admission-hint">設定提醒日期，系統會在當天標記為「今日需追蹤」，避免遺漏任何潛在學生。</p></div>
            </div>
            <div class="admission-mini-form">
              <input v-model="followUpAt" type="date" aria-label="下次追蹤日期" />
              <button class="admission-button secondary" type="button" :disabled="busy" @click="saveFollowUp">儲存追蹤日期</button>
            </div>
          </div>

          <section v-if="detail.history?.length" class="admission-history" aria-label="詢問歷程">
            <h3>處理歷程紀錄</h3>
            <ol>
              <li v-for="event in detail.history" :key="event.occurred_at + event.reason_code">
                <strong>{{ historyLabel(event) }}</strong>
                <time>{{ formatDateTime(event.occurred_at) }}</time>
              </li>
            </ol>
          </section>
        </article>
      </div>
    </template>
  </main>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { admissionAction, convertAdmissionTrial, getAdmissionBranches, getAdmissionInquiry, getAdmissionInquiries, getAdmissionTeachers, submitAdmissionInquiry } from '../api';
import { GRADES, SUBJECTS } from '../lib/constants';
import { buildPublicAdmissionsUrl, parsePublicAdmissionsContext, matchPresetCampus } from '../lib/admissionsUrl';
import perfFlags from '../lib/perfFlags';

const props = defineProps({
  standalone: { type: Boolean, default: false },
  branchId: { type: [Number, String], default: null },
  token: { type: String, default: '' },
  enabled: { type: Boolean, default: null },
});
const standalone = computed(() => props.standalone);
const clientEnabled = computed(() => props.enabled !== null && props.enabled !== undefined ? Boolean(props.enabled) : Boolean(perfFlags.ADMISSIONS_FUNNEL_V1));
const branches = ref([]), presetBranchInfo = ref(null), inquiries = ref([]), teachers = ref([]), detail = ref(null);
const selectedId = ref(null), step = ref(1), busy = ref(false), loading = ref(false), submitted = ref(false);
const errorMessage = ref(''), contactNote = ref(''), followUpAt = ref(''), statusFilter = ref('');
const trialResult = ref('attended'), showDirectTrial = ref(false), copySuccess = ref(false);
let copyTimer = null;

const publicForm = ref({ campus_id: '', parent_name: '', parent_phone: '', student_name: '', grade: '', school_name: '', subject: '', preferred_slots: [''], public_notes: '', consent: false });
const trial = ref({ teacher_id: '', trial_date: '', start_time: '16:00', duration_minutes: 120 });
const formal = ref({ sessions: 8, start_date: '' });
const grades = GRADES, subjects = SUBJECTS;
const slots = ['平日下午', '平日晚上', '週六上午', '週六下午', '週日上午'];

const statusNames = { new: '新詢問', contacted: '已聯絡', trial_scheduled: '已安排試聽', trial_completed: '已完成試聽', enrolled: '已報名', lost: '暫不繼續' };
const nextActionNames = { claim: '先認領負責', contact: '聯絡家長', schedule_trial: '安排試聽', record_result: '記錄試聽結果', enroll: '轉正式報名', enroll_or_lost: '報名或結案', mark_lost: '標為暫不繼續', done: '已完成', review: '檢視' };
const PIPELINE_STAGES = [{ key: 'new', label: '新詢問' }, { key: 'contacted', label: '已聯絡' }, { key: 'trial_scheduled', label: '安排試聽' }, { key: 'trial_completed', label: '試聽結果' }, { key: 'enrolled', label: '正式報名' }];

const statusLabel = s => statusNames[s] || s;
const nextActionLabel = a => nextActionNames[a] || a;
const resultLabel = r => ({ attended: '已出席', no_show: '未到', cancelled: '取消', not_suitable: '不合適' }[r] || r);
const formatDate = v => String(v).slice(0, 10);
const formatDateTime = v => v ? new Date(v).toLocaleString('zh-TW', { dateStyle: 'short', timeStyle: 'short' }) : '';
const historyLabel = e => ({ submit: '收到問班需求', contacted: '已聯絡家長', owner_assigned: '認領負責', trial_scheduled: '已安排試聽', trial_completed: '已記錄試聽結果', enrolled: '已連結正式課程', lost: '已結案', follow_up_saved: '已更新追蹤' }[e.reason_code] || '更新詢問');

const publicFormUrl = computed(() => {
  if (typeof window === 'undefined') return buildPublicAdmissionsUrl({ branchId: props.branchId });
  return buildPublicAdmissionsUrl({ origin: window.location.origin || '', pathname: window.location.pathname || '', branchId: props.branchId });
});
const unclaimedCount = computed(() => inquiries.value.filter(i => !i.owner_id && !['enrolled', 'lost'].includes(i.status)).length);
const urgentCount = computed(() => inquiries.value.filter(i => {
  const m = getFollowUpMeta(i.follow_up_at);
  return m && (m.type === 'overdue' || m.type === 'today');
}).length);

function getFollowUpMeta(dateStr) {
  if (!dateStr) return null;
  const target = String(dateStr).slice(0, 10);
  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  if (target < today) return { type: 'overdue', label: `逾期 (${target.slice(5)})` };
  if (target === today) return { type: 'today', label: '今日需追蹤' };
  return { type: 'future', label: `追蹤 ${target.slice(5)}` };
}

function getStageState(stageKey, currentStatus) {
  const order = ['new', 'contacted', 'trial_scheduled', 'trial_completed', 'enrolled'];
  if (currentStatus === 'lost') return 'inactive';
  const cIdx = order.indexOf(currentStatus), tIdx = order.indexOf(stageKey);
  if (tIdx < cIdx) return 'completed';
  if (tIdx === cIdx) return 'current';
  return 'pending';
}

async function copyPublicLink() {
  const url = publicFormUrl.value;
  try {
    if (navigator?.clipboard?.writeText) await navigator.clipboard.writeText(url);
    else {
      const ta = document.createElement('textarea');
      ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    }
    copySuccess.value = true;
    if (copyTimer) clearTimeout(copyTimer);
    copyTimer = setTimeout(() => { copySuccess.value = false; }, 2500);
  } catch { /* copy fallback */ }
}

function openPublicForm() {
  if (typeof window !== 'undefined') window.open(publicFormUrl.value, '_blank', 'noopener,noreferrer');
}

function setPublicStep(v) {
  step.value = v;
  nextTick(() => document.getElementById('admission-step-title')?.focus());
}
function advancePublicStep(e) {
  const invalid = e.currentTarget.closest('fieldset')?.querySelector(':invalid');
  if (invalid) { invalid.focus(); return; }
  setPublicStep(2);
}

function applyPresetBranch() {
  if (!branches.value.length) return;
  const target = parsePublicAdmissionsContext({
    hash: typeof window !== 'undefined' ? window.location.hash : '',
    search: typeof window !== 'undefined' ? window.location.search : '',
    propBranchId: props.branchId,
  });
  if (target) {
    const matched = matchPresetCampus(branches.value, target);
    if (matched) { publicForm.value.campus_id = matched.id; presetBranchInfo.value = matched; return; }
  }
  presetBranchInfo.value = null;
}

async function loadPublic() {
  try { branches.value = await getAdmissionBranches(); applyPresetBranch(); } catch (err) { errorMessage.value = err.message; }
}
async function submitPublic() {
  busy.value = true; errorMessage.value = '';
  try { await submitAdmissionInquiry({ ...publicForm.value, campus_id: Number(publicForm.value.campus_id), preferred_slots: publicForm.value.preferred_slots.filter(Boolean) }); submitted.value = true; } catch (err) { errorMessage.value = err.message; } finally { busy.value = false; }
}
function resetPublic() {
  submitted.value = false;
  publicForm.value = { campus_id: presetBranchInfo.value ? presetBranchInfo.value.id : '', parent_name: '', parent_phone: '', student_name: '', grade: '', school_name: '', subject: '', preferred_slots: [''], public_notes: '', consent: false };
  setPublicStep(1);
}

async function loadQueue() {
  if (!props.token || !props.branchId) return;
  loading.value = true; errorMessage.value = '';
  try {
    const data = await getAdmissionInquiries(props.token, props.branchId, statusFilter.value || undefined);
    inquiries.value = data.data || [];
    if (selectedId.value && inquiries.value.some(i => i.id === selectedId.value)) await selectInquiry(selectedId.value);
    else if (inquiries.value[0]) await selectInquiry(inquiries.value[0].id);
    else { selectedId.value = null; detail.value = null; }
  } catch (err) { errorMessage.value = err.message; } finally { loading.value = false; }
}
async function selectInquiry(id) {
  selectedId.value = id; errorMessage.value = ''; showDirectTrial.value = false;
  try { detail.value = await getAdmissionInquiry(props.token, id); contactNote.value = detail.value.staff_notes || ''; followUpAt.value = detail.value.follow_up_at ? formatDate(detail.value.follow_up_at) : ''; } catch (err) { errorMessage.value = err.message; }
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
  try {
    const conv = await convertAdmissionTrial(props.token, detail.value.trial_student_class_id, { sessions: Number(formal.value.sessions), start_date: formal.value.start_date, class_type: 'one_on_one' });
    await admissionAction(props.token, detail.value.id, 'enroll', { student_class_id: conv?.new_course?.id });
    await selectInquiry(detail.value.id); await loadQueue();
  } catch (err) { errorMessage.value = err.message; } finally { busy.value = false; }
}
async function runAction(action, payload) {
  if (!detail.value) return;
  busy.value = true; errorMessage.value = '';
  try { await admissionAction(props.token, detail.value.id, action, payload); await selectInquiry(detail.value.id); await loadQueue(); } catch (err) { errorMessage.value = err.message; } finally { busy.value = false; }
}
async function loadStaff() {
  await loadQueue();
  try { teachers.value = await getAdmissionTeachers(props.token, props.branchId); } catch (err) { errorMessage.value = err.message; }
}

onMounted(async () => { if (standalone.value) await loadPublic(); else await loadStaff(); });
watch(() => props.branchId, async (val, prev) => {
  if (standalone.value) applyPresetBranch();
  else if (val && val !== prev) await loadStaff();
});
</script>

<style scoped>
.admission-page { min-height: 100%; color: var(--ds-ink); font-family: var(--font-ui, system-ui, sans-serif); }
.admission-branch-preset-hint { display: block; margin-top: 4px; font-size: 13px; color: var(--ds-cta); font-weight: 500; }
.admission-page-public { display: grid; place-items: center; padding: 24px 16px; background: linear-gradient(150deg, var(--ds-primary-wash), var(--ds-canvas)); }
.admission-public-card { width: min(100%, 560px); padding: clamp(24px, 5vw, 48px); border: 1px solid var(--ds-hairline); border-radius: 16px; background: var(--ds-canvas); box-shadow: var(--ds-shadow-2); }
.admission-kicker { color: var(--ds-cta); font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
h1 { margin: 8px 0; font-size: clamp(24px, 4vw, 32px); line-height: 1.25; color: var(--ds-ink); }
.admission-lead, .admission-hint { color: var(--ds-ink-mute); line-height: 1.5; font-size: 14px; }
.admission-form { margin-top: 24px; }
.admission-progress { display: flex; gap: 8px; margin-bottom: 24px; color: var(--ds-ink-mute); font-size: 13px; }
.admission-progress span { flex: 1; padding-bottom: 8px; border-bottom: 2px solid var(--ds-hairline); }
.admission-progress .active { color: var(--ds-cta); border-color: var(--ds-cta); font-weight: 700; }
fieldset { border: 0; padding: 0; margin: 0; }
legend { margin-bottom: 16px; font-size: 18px; font-weight: 700; color: var(--ds-ink); }
label { display: grid; gap: 6px; margin: 12px 0; font-size: 13px; font-weight: 600; color: var(--ds-ink); }
label span { color: var(--ds-danger); }
input, select, textarea { width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid var(--ds-hairline-input); border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas); color: var(--ds-ink); font: inherit; font-size: 14px; }
textarea { min-height: 80px; resize: vertical; }
input:focus, select:focus, textarea:focus { outline: 2px solid var(--ds-primary); outline-offset: 1px; }

.admission-button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 10px 20px; border: 0; border-radius: var(--ds-radius-md, 8px); background: var(--ds-cta); color: var(--ds-on-cta); font-weight: 700; font-size: 14px; cursor: pointer; transition: background .15s ease; text-decoration: none; }
.admission-button:hover:not(:disabled) { background: var(--ds-cta-hover); }
.admission-button:disabled { opacity: .55; cursor: wait; }
.admission-button.secondary { border: 1px solid var(--ds-hairline-input); background: var(--ds-canvas); color: var(--ds-ink); }
.admission-button.secondary:hover:not(:disabled) { background: var(--ds-canvas-soft); }
.admission-button.text-btn { border: 0; background: transparent; color: var(--ds-cta); padding: 8px 12px; font-weight: 600; }
.admission-button.danger-text { color: var(--ds-danger); border-color: var(--ds-danger-wash); }
.admission-button.danger-text:hover { background: var(--ds-danger-wash); }
.admission-actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 22px; }
.admission-actions .admission-button:last-child { flex: 1; }
.admission-consent { display: flex; align-items: center; gap: 8px; }
.admission-consent input { width: 20px; min-height: 20px; }
.admission-error { margin: 14px 0; padding: 12px 14px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-danger-wash); color: var(--ds-danger); font-size: 14px; font-weight: 600; }

.admission-page-staff { padding: 24px clamp(16px, 3.5vw, 36px) 64px; max-width: 1320px; margin: 0 auto; }
.admission-staff-header { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; }
.admission-header-main h1 { margin: 4px 0 6px; }
.admission-header-main p { color: var(--ds-ink-mute); font-size: 14px; margin: 0; }
.admission-header-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.admission-header-actions .material-symbols-outlined { font-size: 18px; }

.admission-filters-bar { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.admission-filters { min-width: 200px; }
.admission-filters label { margin: 0; gap: 4px; }
.admission-filter-label { font-size: 12px; color: var(--ds-ink-mute); font-weight: 600; }
.admission-stats-strip { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.admission-stat-chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); font-size: 12px; color: var(--ds-ink); }
.admission-stat-chip.unassigned { background: var(--ds-primary-wash); color: var(--ds-cta); font-weight: 600; border-color: var(--ds-hairline-input); }
.admission-stat-chip.urgent { background: var(--ds-warning-wash); color: var(--ds-warning); font-weight: 600; border-color: var(--ds-hairline-input); }

.admission-empty { display: grid; gap: 24px; max-width: 760px; margin: 20px auto 40px; padding: 40px 28px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg, 12px); background: var(--ds-canvas); text-align: center; box-shadow: var(--ds-shadow-1); }
.admission-empty.compact { padding: 18px; border-style: dashed; margin: 0; max-width: none; text-align: left; display: flex; gap: 14px; align-items: center; }
.admission-empty-icon { font-size: 52px; color: var(--ds-primary); }
.admission-empty-hero h2 { margin: 0; font-size: 22px; color: var(--ds-ink); }
.admission-empty-lead { color: var(--ds-ink-mute); max-width: 580px; font-size: 14px; line-height: 1.6; margin: 0; }
.admission-empty-flow { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 18px 12px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); flex-wrap: wrap; }
.admission-flow-step { display: grid; justify-items: center; gap: 3px; min-width: 110px; text-align: center; }
.admission-flow-num { width: 24px; height: 24px; border-radius: 50%; background: var(--ds-primary); color: var(--ds-on-primary); font-size: 12px; font-weight: 800; display: grid; place-items: center; }
.admission-flow-step strong { font-size: 13px; color: var(--ds-ink); }
.admission-flow-step small { font-size: 11px; color: var(--ds-ink-mute); }
.admission-flow-arrow { color: var(--ds-ink-mute); font-size: 16px; }
.admission-empty-actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
.admission-empty-hint-card { display: grid; gap: 8px; padding: 16px 20px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-primary-wash); border: 1px solid var(--ds-hairline-input); text-align: left; }
.admission-hint-title { display: flex; align-items: center; gap: 8px; color: var(--ds-cta); font-size: 14px; }
.admission-hint-title .material-symbols-outlined { font-size: 20px; }
.admission-empty-hint-card p { margin: 0; font-size: 13px; line-height: 1.6; color: var(--ds-ink); }

.admission-staff-grid { display: grid; grid-template-columns: minmax(280px, 340px) minmax(0, 1fr); gap: 20px; align-items: start; }
.admission-queue, .admission-detail, .admission-panel { border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg, 12px); background: var(--ds-canvas); box-shadow: var(--ds-shadow-1); }

.admission-queue { overflow: hidden; }
.admission-queue-header { padding: 12px 16px; border-bottom: 1px solid var(--ds-hairline); background: var(--ds-canvas-soft); font-size: 12px; font-weight: 700; color: var(--ds-ink-mute); }
.admission-queue-item { display: grid; gap: 6px; width: 100%; padding: 16px; border: 0; border-bottom: 1px solid var(--ds-hairline); background: transparent; color: var(--ds-ink); text-align: left; cursor: pointer; transition: background .12s ease; font-family: inherit; }
.admission-queue-item:hover { background: var(--ds-canvas-soft); }
.admission-queue-item.selected { background: var(--ds-primary-wash); box-shadow: inset 3px 0 var(--ds-cta); }
.admission-queue-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.admission-queue-title-row { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.admission-queue-name { font-size: 16px; font-weight: 700; color: var(--ds-ink); }
.admission-queue-subject { font-size: 12px; font-weight: 600; color: var(--ds-cta); padding: 1px 6px; background: var(--ds-primary-wash); border-radius: 4px; }
.admission-queue-meta-list { display: grid; gap: 2px; }
.admission-queue-meta-list small { color: var(--ds-ink-mute); font-size: 12px; }
.admission-queue-next-action { margin-top: 2px; }
.admission-next { color: var(--ds-cta) !important; font-weight: 700; font-size: 13px; }

.admission-status { display: inline-flex; width: fit-content; padding: 2px 8px; border-radius: 999px; background: var(--ds-canvas-soft); color: var(--ds-ink); font-size: 11px; font-weight: 700; border: 1px solid var(--ds-hairline); }
.admission-status.status-new { background: var(--ds-info-wash); color: var(--ds-primary); border-color: var(--ds-hairline-input); }
.admission-status.status-contacted { background: var(--ds-warning-wash); color: var(--ds-warning); border-color: var(--ds-hairline-input); }
.admission-status.status-trial_scheduled { background: var(--ds-primary-wash); color: var(--ds-cta); border-color: var(--ds-hairline-input); }
.admission-status.status-trial_completed { background: var(--ds-info-wash); color: var(--ds-cta-hover); border-color: var(--ds-hairline-input); }
.admission-status.status-enrolled { background: var(--ds-success-wash); color: var(--ds-success); border-color: var(--ds-hairline); }
.admission-status.status-lost { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); border-color: var(--ds-hairline); }

.admission-badge { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.admission-badge.overdue { background: var(--ds-danger-wash); color: var(--ds-danger); border: 1px solid var(--ds-hairline); }
.admission-badge.today { background: var(--ds-warning-wash); color: var(--ds-warning); border: 1px solid var(--ds-hairline); }
.admission-badge.future { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); border: 1px solid var(--ds-hairline); }
.admission-badge.unassigned { background: var(--ds-primary-wash); color: var(--ds-cta); border: 1px solid var(--ds-hairline-input); }

.admission-detail { padding: clamp(20px, 3.5vw, 32px); display: grid; gap: 20px; }
.admission-pipeline-wrapper { padding: 14px 18px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); overflow-x: auto; }
.admission-pipeline { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-width: 480px; }
.admission-pipeline-node { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--ds-ink-mute); flex: 1; }
.admission-pipeline-node:not(:last-child)::after { content: ''; flex: 1; height: 2px; background: var(--ds-hairline); margin: 0 4px; }
.admission-pipeline-node.completed { color: var(--ds-success); }
.admission-pipeline-node.completed::after { background: var(--ds-success); }
.admission-pipeline-node.current { color: var(--ds-cta); font-weight: 800; }
.admission-pipeline-node.lost { color: var(--ds-ink-mute); }
.admission-pipeline-marker { width: 22px; height: 22px; border-radius: 50%; background: var(--ds-hairline); color: var(--ds-ink-mute); display: grid; place-items: center; font-size: 11px; font-weight: 800; }
.admission-pipeline-node.completed .admission-pipeline-marker { background: var(--ds-success); color: var(--ds-on-primary); }
.admission-pipeline-node.completed .admission-pipeline-marker .material-symbols-outlined { font-size: 15px; }
.admission-pipeline-node.current .admission-pipeline-marker { background: var(--ds-cta); color: var(--ds-on-cta); box-shadow: 0 0 0 3px var(--ds-primary-wash); }
.admission-pipeline-node.lost .admission-pipeline-marker { background: var(--ds-ink-mute); color: var(--ds-on-primary); }
.admission-pipeline-label { white-space: nowrap; }

.admission-detail-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--ds-hairline); flex-wrap: wrap; }
.admission-detail-title-group { display: grid; gap: 4px; }
.admission-detail-badges { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.admission-detail-head h2 { margin: 4px 0 2px; font-size: 24px; color: var(--ds-ink); }
.admission-detail-head p { margin: 0; color: var(--ds-ink-mute); font-size: 14px; }
.admission-phone { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline-input); color: var(--ds-cta); font-weight: 700; font-size: 15px; text-decoration: none; }
.admission-phone:hover { background: var(--ds-primary-wash); }

.admission-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin: 0; padding: 14px 18px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); }
.admission-meta dt { font-size: 12px; color: var(--ds-ink-mute); margin-bottom: 4px; font-weight: 600; }
.admission-meta dd { margin: 0; font-size: 14px; font-weight: 700; color: var(--ds-ink); }

.admission-note { margin: 0; padding: 14px 18px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-primary-wash); border: 1px solid var(--ds-hairline-input); font-size: 13px; line-height: 1.6; color: var(--ds-ink); }

.admission-panel { padding: 18px 20px; display: grid; gap: 14px; }
.admission-panel h3 { margin: 0; font-size: 16px; color: var(--ds-ink); }
.admission-panel-header { display: flex; gap: 12px; align-items: flex-start; }
.admission-panel-header .material-symbols-outlined { font-size: 24px; color: var(--ds-cta); margin-top: 2px; }
.admission-panel-header p { margin: 4px 0 0; }
.admission-owner-panel { border-color: var(--ds-hairline-input); background: var(--ds-primary-wash); }
.admission-mini-form { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.admission-mini-form input { max-width: 200px; }

.admission-workflow { display: grid; gap: 16px; }
.admission-action-box { display: grid; gap: 14px; padding: 20px; border-radius: var(--ds-radius-lg, 12px); background: var(--ds-canvas); border: 2px solid var(--ds-cta); box-shadow: var(--ds-shadow-1); }
.admission-action-title { display: flex; align-items: center; gap: 8px; color: var(--ds-cta); font-size: 16px; font-weight: 700; }
.admission-action-content { display: grid; gap: 12px; }
.admission-action-content p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--ds-ink); }
.admission-form-row { display: grid; gap: 6px; }
.admission-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
.admission-trial-form { display: grid; gap: 10px; padding: 14px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); }
.admission-action-buttons { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 4px; }
.admission-direct-trial-toggle { margin-top: 6px; border-top: 1px dashed var(--ds-hairline); padding-top: 8px; }
.admission-link-btn { border: 0; background: transparent; color: var(--ds-cta); font-size: 13px; font-weight: 600; cursor: pointer; padding: 4px 0; text-decoration: underline; }

.admission-history { padding-top: 14px; border-top: 1px solid var(--ds-hairline); }
.admission-history h3 { margin: 0 0 12px; font-size: 15px; color: var(--ds-ink); }
.admission-history ol { margin: 0; padding-left: 20px; display: grid; gap: 10px; font-size: 13px; color: var(--ds-ink); }
.admission-history li { display: flex; justify-content: space-between; gap: 12px; }
.admission-history time { color: var(--ds-ink-mute); font-size: 12px; }

.admission-skeleton { display: grid; gap: 12px; padding: 20px; }
.admission-skeleton-row { height: 48px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); animation: pulse 1.4s ease infinite; }
@keyframes pulse { 0%, 100% { opacity: .6; } 50% { opacity: 1; } }

@media (max-width: 900px) {
  .admission-staff-grid { grid-template-columns: 1fr; }
  .admission-pipeline { min-width: 100%; }
}
@media (max-width: 640px) {
  .admission-page-staff { padding: 16px 12px 48px; }
  .admission-staff-header { flex-direction: column; align-items: stretch; gap: 14px; }
  .admission-header-actions { width: 100%; justify-content: stretch; }
  .admission-header-actions .admission-button { flex: 1; text-align: center; }
  .admission-filters-bar { flex-direction: column; align-items: stretch; gap: 12px; }
  .admission-pipeline-wrapper { padding: 10px 12px; }
  .admission-pipeline-label { font-size: 11px; }
  .admission-meta { grid-template-columns: 1fr 1fr; }
  .admission-action-buttons { flex-direction: column; align-items: stretch; }
  .admission-action-buttons .admission-button { width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
}
</style>
