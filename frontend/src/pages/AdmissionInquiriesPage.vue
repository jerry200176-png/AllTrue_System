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
            <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
            {{ copySuccess ? '已複製連結' : '複製公開問班連結' }}
          </button>
          <button class="admission-button secondary" type="button" @click="openPublicForm">
            <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
            查看公開問班表單
          </button>
          <button class="admission-button secondary" type="button" :disabled="loading" @click="loadQueue">
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
            重新整理
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
        <div v-if="inquiries.length" class="admission-stats-strip">
          <span class="admission-stat-chip">
            <strong>{{ inquiries.length }}</strong> 筆詢問
          </span>
          <span v-if="unclaimedCount" class="admission-stat-chip unassigned">
            <strong>{{ unclaimedCount }}</strong> 筆待認領
          </span>
          <span v-if="urgentCount" class="admission-stat-chip urgent">
            <strong>{{ urgentCount }}</strong> 筆待追蹤
          </span>
        </div>
      </div>

      <p v-if="errorMessage" class="admission-error" role="alert" aria-live="assertive">{{ errorMessage }}</p>

      <div v-if="loading" class="admission-skeleton" role="status" aria-label="載入詢問中">
        <div v-for="n in 4" :key="n" class="admission-skeleton-row"></div>
      </div>

      <div v-else-if="!inquiries.length" class="admission-empty">
        <div class="admission-empty-hero">
          <span class="material-symbols-outlined admission-empty-icon" aria-hidden="true">how_to_reg</span>
          <h2>目前沒有新詢問</h2>
          <p class="admission-empty-lead">
            公開問班送出後，這裡會出現下一步工作。主任可在此進行「聯絡家長 → 安排試聽 → 記錄結果 → 正式報名」完整推進。
          </p>
        </div>

        <div class="admission-empty-flow" aria-label="招生處理流程">
          <div class="admission-flow-step">
            <div class="admission-flow-num">1</div>
            <strong>家長送出需求</strong>
            <small>透過公開問班頁面提交</small>
          </div>
          <span class="admission-flow-arrow" aria-hidden="true">→</span>
          <div class="admission-flow-step">
            <div class="admission-flow-num">2</div>
            <strong>主任電訪確認</strong>
            <small>了解年級科目與合適時段</small>
          </div>
          <span class="admission-flow-arrow" aria-hidden="true">→</span>
          <div class="admission-flow-step">
            <div class="admission-flow-num">3</div>
            <strong>安排體驗試聽</strong>
            <small>選派老師並建立試聽課程</small>
          </div>
          <span class="admission-flow-arrow" aria-hidden="true">→</span>
          <div class="admission-flow-step">
            <div class="admission-flow-num">4</div>
            <strong>試聽轉正報名</strong>
            <small>試聽後沿用資料轉正式課</small>
          </div>
        </div>

        <div class="admission-empty-actions">
          <button class="admission-button" type="button" @click="copyPublicLink">
            <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
            {{ copySuccess ? '已複製問班連結！' : '複製公開問班連結' }}
          </button>
          <button class="admission-button secondary" type="button" @click="openPublicForm">
            <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
            查看公開問班表單
          </button>
          <button class="admission-button secondary" type="button" @click="loadQueue">
            <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
            重新整理
          </button>
        </div>

        <div class="admission-empty-hint-card">
          <div class="admission-hint-title">
            <span class="material-symbols-outlined" aria-hidden="true">help_outline</span>
            <strong>家長若透過 LINE、電話或現場來訪？</strong>
          </div>
          <p>
            您可以開啟公開問班表單代為記錄，或點擊「複製公開問班連結」將表單分享至 LINE 官方帳號、社群貼文或即時訊息。家長送出後將立即在此顯示，學生資料不需在排課時重複建檔。
          </p>
        </div>
      </div>

      <div v-else class="admission-staff-grid">
        <section class="admission-queue" aria-label="詢問清單">
          <div class="admission-queue-header">
            <span class="admission-queue-title">詢問列表 ({{ inquiries.length }})</span>
          </div>
          <button
            v-for="item in inquiries"
            :key="item.id"
            type="button"
            :class="['admission-queue-item', { selected: selectedId === item.id }]"
            :aria-pressed="selectedId === item.id"
            @click="selectInquiry(item.id)"
          >
            <div class="admission-queue-top">
              <span :class="['admission-status', `status-${item.status}`]">{{ statusLabel(item.status) }}</span>
              <span v-if="getFollowUpMeta(item.follow_up_at)" :class="['admission-badge', getFollowUpMeta(item.follow_up_at).type]">
                {{ getFollowUpMeta(item.follow_up_at).label }}
              </span>
              <span v-else-if="!item.owner_id && item.status !== 'enrolled' && item.status !== 'lost'" class="admission-badge unassigned">
                待認領
              </span>
            </div>
            <div class="admission-queue-title-row">
              <strong class="admission-queue-name">{{ item.student_name }}</strong>
              <span class="admission-queue-subject">{{ item.subject }}</span>
            </div>
            <div class="admission-queue-meta-list">
              <small>{{ item.parent_phone }}</small>
              <small>目前負責：{{ item.owner_name || '尚未認領' }}</small>
              <small v-if="item.follow_up_at">下次追蹤：{{ formatDate(item.follow_up_at) }}</small>
            </div>
            <div class="admission-queue-next-action">
              <small class="admission-next">下一步：{{ nextActionLabel(item.next_action) }}</small>
            </div>
          </button>
        </section>

        <article v-if="detail" id="admission-detail" class="admission-detail">
          <!-- Stepper: 5 Lifecycle Stages -->
          <div class="admission-pipeline-wrapper" aria-label="詢問生命週期">
            <div class="admission-pipeline">
              <div
                v-for="(st, idx) in PIPELINE_STAGES"
                :key="st.key"
                :class="['admission-pipeline-node', getStageState(st.key, detail.status)]"
              >
                <div class="admission-pipeline-marker">
                  <span v-if="getStageState(st.key, detail.status) === 'completed'" class="material-symbols-outlined" aria-hidden="true">check</span>
                  <span v-else>{{ idx + 1 }}</span>
                </div>
                <span class="admission-pipeline-label">{{ st.label }}</span>
              </div>
              <div v-if="detail.status === 'lost'" class="admission-pipeline-node lost">
                <div class="admission-pipeline-marker">
                  <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </div>
                <span class="admission-pipeline-label">已結案</span>
              </div>
            </div>
          </div>

          <!-- Detail Head -->
          <div class="admission-detail-head">
            <div class="admission-detail-title-group">
              <div class="admission-detail-badges">
                <span :class="['admission-status', `status-${detail.status}`]">{{ statusLabel(detail.status) }}</span>
                <span v-if="getFollowUpMeta(detail.follow_up_at)" :class="['admission-badge', getFollowUpMeta(detail.follow_up_at).type]">
                  {{ getFollowUpMeta(detail.follow_up_at).label }}
                </span>
              </div>
              <h2>{{ detail.student_name }}</h2>
              <p class="admission-detail-subhead">
                {{ detail.grade }} · {{ detail.school_name || '學校未填' }} · {{ detail.subject }}
              </p>
            </div>
            <a class="admission-phone-link" :href="'tel:' + detail.parent_phone" :title="'撥打電話給 ' + detail.parent_name">
              <span class="material-symbols-outlined" aria-hidden="true">call</span>
              <span class="admission-phone-info">
                <strong>{{ detail.parent_name }}</strong>
                <span>{{ detail.parent_phone }}</span>
              </span>
            </a>
          </div>

          <!-- Key Metadata -->
          <dl class="admission-meta">
            <div><dt>方便時段</dt><dd>{{ (detail.preferred_slots || []).join('、') || '未填' }}</dd></div>
            <div><dt>目前負責</dt><dd>{{ detail.owner_name || '尚未認領' }}</dd></div>
            <div><dt>下一步</dt><dd>{{ nextActionLabel(detail.next_action) }}</dd></div>
            <div><dt>下次追蹤</dt><dd>{{ detail.follow_up_at ? formatDate(detail.follow_up_at) : '尚未安排' }}</dd></div>
          </dl>

          <!-- Parent Note -->
          <div v-if="detail.public_notes" class="admission-parent-note-block">
            <span class="admission-note-kicker">
              <span class="material-symbols-outlined" aria-hidden="true">chat</span>
              家長備註
            </span>
            <p class="admission-note">{{ detail.public_notes }}</p>
          </div>

          <!-- PRIMARY ACTION FOR CURRENT LIFECYCLE STATE -->
          <div class="admission-workflow">
            <!-- Unassigned Claim Panel -->
            <div v-if="!detail.owner_id && !['enrolled', 'lost'].includes(detail.status)" class="admission-panel admission-owner-panel">
              <div class="admission-panel-intro">
                <span class="material-symbols-outlined" aria-hidden="true">assignment_ind</span>
                <div>
                  <h3>先認領這筆詢問</h3>
                  <p class="admission-hint">認領後你會成為負責主任，後續聯絡與追蹤都會留在這筆紀錄。</p>
                </div>
              </div>
              <button class="admission-button" type="button" :disabled="busy" @click="claimInquiry">由我負責</button>
            </div>

            <!-- Stage 1: NEW -->
            <div v-if="detail.status === 'new'" class="admission-panel admission-action-stage">
              <div class="admission-stage-header">
                <span class="admission-step-pill">第 1 步</span>
                <h3>聯絡與安排試聽</h3>
              </div>
              <p class="admission-hint">撥打電話確認學生目前學習狀況、學校進度與方便試聽時段。</p>
              
              <div class="admission-input-group">
                <label for="admission-contact-notes">本次聯絡紀錄（選填）</label>
                <textarea id="admission-contact-notes" v-model="contactNote" rows="2" maxlength="1000" placeholder="記錄本次聯絡重點（選填）"></textarea>
              </div>

              <div class="admission-cta-bar">
                <button class="admission-button" type="button" :disabled="busy" @click="contactInquiry">
                  <span class="material-symbols-outlined" aria-hidden="true">phone_in_talk</span>
                  標記已聯絡
                </button>
                <button class="admission-button secondary text-btn" type="button" @click="showDirectTrial = !showDirectTrial">
                  <span class="material-symbols-outlined" aria-hidden="true">{{ showDirectTrial ? 'expand_less' : 'expand_more' }}</span>
                  {{ showDirectTrial ? '收合試聽欄位' : '電話中已確定試聽時間？直接排課' }}
                </button>
              </div>

              <!-- Direct Trial Scheduling If Agreed During Initial Call -->
              <div v-if="showDirectTrial" class="admission-quick-trial-box">
                <h4>直接安排試聽時段</h4>
                <div class="admission-mini-form">
                  <select v-model="trial.teacher_id" required aria-label="選擇老師">
                    <option value="" disabled>選擇老師</option>
                    <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name || teacher.Name || teacher.username }}</option>
                  </select>
                  <input v-model="trial.trial_date" type="date" required aria-label="試聽日期" />
                  <input v-model="trial.start_time" type="time" required aria-label="開始時間" />
                  <input v-model="trial.duration_minutes" type="number" min="30" max="480" step="30" required aria-label="分鐘數" />
                </div>
                <button class="admission-button" type="button" :disabled="busy || !trial.teacher_id || !trial.trial_date" @click="scheduleTrial">
                  建立試聽（帶入學生資料）
                </button>
              </div>
            </div>

            <!-- Stage 2: CONTACTED -->
            <div v-if="detail.status === 'contacted'" class="admission-panel admission-action-stage">
              <div class="admission-stage-header">
                <span class="admission-step-pill">第 2 步</span>
                <h3>聯絡與安排試聽</h3>
              </div>
              <p class="admission-hint">已與家長初步聯絡。選派適合的試聽老師與上課時間，系統將自動帶入學生資料完成試聽建立。</p>
              
              <div class="admission-mini-form">
                <label>
                  <span class="admission-field-title">試聽老師 <span>*</span></span>
                  <select v-model="trial.teacher_id" required>
                    <option value="" disabled>選擇老師</option>
                    <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name || teacher.Name || teacher.username }}</option>
                  </select>
                </label>
                <label>
                  <span class="admission-field-title">試聽日期 <span>*</span></span>
                  <input v-model="trial.trial_date" type="date" required aria-label="試聽日期" />
                </label>
                <label>
                  <span class="admission-field-title">開始時間 <span>*</span></span>
                  <input v-model="trial.start_time" type="time" required aria-label="開始時間" />
                </label>
                <label>
                  <span class="admission-field-title">時長 (分鐘) <span>*</span></span>
                  <input v-model="trial.duration_minutes" type="number" min="30" max="480" step="30" required aria-label="分鐘數" />
                </label>
              </div>

              <div class="admission-cta-bar">
                <button class="admission-button" type="button" :disabled="busy || !trial.teacher_id || !trial.trial_date" @click="scheduleTrial">
                  <span class="material-symbols-outlined" aria-hidden="true">event_available</span>
                  建立試聽（帶入學生資料）
                </button>
              </div>
            </div>

            <!-- Stage 3: TRIAL SCHEDULED -->
            <div v-if="detail.status === 'trial_scheduled'" class="admission-panel admission-action-stage">
              <div class="admission-stage-header">
                <span class="admission-step-pill">第 3 步</span>
                <h3>記錄試聽結果</h3>
              </div>
              <p class="admission-hint">試聽課程已預約排入行事曆。課後請與老師確認出席與反饋，並記錄試聽結果以利下一步推進。</p>
              
              <div class="admission-input-group">
                <label for="admission-trial-result-select">出席與試聽狀況</label>
                <select id="admission-trial-result-select" v-model="trialResult">
                  <option value="attended">已出席（適合後續轉正）</option>
                  <option value="no_show">未到</option>
                  <option value="cancelled">取消</option>
                  <option value="not_suitable">不合適</option>
                </select>
              </div>

              <div class="admission-cta-bar">
                <button class="admission-button" type="button" :disabled="busy" @click="recordResult">
                  <span class="material-symbols-outlined" aria-hidden="true">save</span>
                  儲存結果
                </button>
              </div>
            </div>

            <!-- Stage 4: TRIAL COMPLETED (Attended -> Enroll) -->
            <div v-if="detail.status === 'trial_completed' && detail.trial_result === 'attended'" class="admission-panel admission-action-stage">
              <div class="admission-stage-header">
                <span class="admission-step-pill">第 4 步</span>
                <h3>開啟既有報名流程</h3>
              </div>
              <p class="admission-hint">學生與家長資料已沿用；只需補正式課程的堂數與開課日。</p>
              
              <div class="admission-mini-form">
                <label>
                  <span class="admission-field-title">正式堂數 <span>*</span></span>
                  <input v-model="formal.sessions" type="number" min="1" max="500" aria-label="正式堂數" />
                </label>
                <label>
                  <span class="admission-field-title">正式開課日 <span>*</span></span>
                  <input v-model="formal.start_date" type="date" required aria-label="正式開課日" />
                </label>
              </div>

              <div class="admission-cta-bar">
                <button class="admission-button" type="button" :disabled="busy || !formal.start_date" @click="enroll">
                  <span class="material-symbols-outlined" aria-hidden="true">how_to_reg</span>
                  轉正式報名
                </button>
                <button class="admission-button secondary" type="button" :disabled="busy" @click="markLost">
                  暫不報名／結案
                </button>
              </div>
            </div>

            <!-- Stage 4: TRIAL COMPLETED (Not attended) -->
            <div v-if="detail.status === 'trial_completed' && detail.trial_result !== 'attended'" class="admission-panel admission-action-stage">
              <div class="admission-stage-header">
                <span class="admission-step-pill warning">未轉正</span>
                <h3>試聽未轉正式</h3>
              </div>
              <p class="admission-hint">結果：{{ resultLabel(detail.trial_result) }}。可結案或稍後再聯絡。</p>
              
              <div class="admission-cta-bar">
                <button class="admission-button secondary" type="button" :disabled="busy" @click="markLost">
                  標為暫不繼續
                </button>
              </div>
            </div>

            <!-- Terminal: ENROLLED -->
            <div v-if="detail.status === 'enrolled'" class="admission-success compact" role="status">
              <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
              <div class="admission-terminal-message">
                <strong>已完成報名</strong>
                <p>已連結正式課程，學生不需重複建立。</p>
              </div>
            </div>

            <!-- Terminal: LOST -->
            <div v-if="detail.status === 'lost'" class="admission-empty compact" role="status">
              <span class="material-symbols-outlined" aria-hidden="true">archive</span>
              <div class="admission-terminal-message">
                <strong>已結案</strong>
                <p>此詢問已結案（暫不繼續）。</p>
              </div>
            </div>
          </div>

          <!-- SECONDARY ACTIONS (Progressive Disclosure) -->
          <div v-if="!['enrolled', 'lost'].includes(detail.status)" class="admission-secondary-panel">
            <div class="admission-followup-box">
              <div class="admission-followup-header">
                <span class="material-symbols-outlined" aria-hidden="true">calendar_clock</span>
                <h4>安排下次追蹤</h4>
              </div>
              <p class="admission-hint">設定下次與家長確認或聯繫的日期，清單將即時提醒。</p>
              <div class="admission-mini-form">
                <input v-model="followUpAt" type="date" aria-label="下次追蹤日期" />
                <button class="admission-button secondary" type="button" :disabled="busy" @click="saveFollowUp">
                  儲存追蹤
                </button>
              </div>
            </div>

            <!-- Mark Lost Toggle for active inquiries -->
            <div v-if="['new', 'contacted'].includes(detail.status)" class="admission-mark-lost-accordion">
              <details>
                <summary>家長明確表示暫無意願？標為暫不繼續</summary>
                <div class="admission-mark-lost-content">
                  <p class="admission-hint">將此詢問標記結案。後續若家長再次主動詢問，可從結案歷史中查閱。</p>
                  <button class="admission-button secondary danger-text" type="button" :disabled="busy" @click="markLost">
                    暫不繼續
                  </button>
                </div>
              </details>
            </div>
          </div>

          <!-- History Section -->
          <section v-if="detail.history?.length" class="admission-history" aria-label="詢問歷程">
            <div class="admission-history-title-row">
              <span class="material-symbols-outlined" aria-hidden="true">history</span>
              <h3>詢問歷程</h3>
              <small>（共 {{ detail.history.length }} 筆記錄）</small>
            </div>
            <ol>
              <li v-for="(event, idx) in detail.history" :key="idx">
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
const showDirectTrial = ref(false);
const copySuccess = ref(false);
let copyTimer = null;

const publicForm = ref({ campus_id: '', parent_name: '', parent_phone: '', student_name: '', grade: '', school_name: '', subject: '', preferred_slots: [''], public_notes: '', consent: false });
const trial = ref({ teacher_id: '', trial_date: '', start_time: '16:00', duration_minutes: 120 });
const formal = ref({ sessions: 8, start_date: '' });
const grades = GRADES;
const subjects = SUBJECTS;
const slots = ['平日下午', '平日晚上', '週六上午', '週六下午', '週日上午'];

const statusNames = {
  new: '新詢問',
  contacted: '已聯絡',
  trial_scheduled: '已安排試聽',
  trial_completed: '已完成試聽',
  enrolled: '已報名',
  lost: '暫不繼續',
};

const nextActionNames = {
  claim: '先認領負責',
  contact: '聯絡家長',
  schedule_trial: '安排試聽',
  record_result: '記錄試聽結果',
  enroll: '轉正式報名',
  enroll_or_lost: '報名或結案',
  mark_lost: '標為暫不繼續',
  done: '已完成',
  review: '檢視',
};

const PIPELINE_STAGES = [
  { key: 'new', label: '新詢問' },
  { key: 'contacted', label: '已聯絡' },
  { key: 'trial_scheduled', label: '安排試聽' },
  { key: 'trial_completed', label: '試聽結果' },
  { key: 'enrolled', label: '正式報名' },
];

const statusLabel = status => statusNames[status] || status;
const nextActionLabel = action => nextActionNames[action] || action;
const resultLabel = result => ({ attended: '已出席', no_show: '未到', cancelled: '取消', not_suitable: '不合適' }[result] || result);
const formatDate = value => String(value).slice(0, 10);
const formatDateTime = value => value ? new Date(value).toLocaleString('zh-TW', { dateStyle: 'short', timeStyle: 'short' }) : '';
const historyLabel = event => ({ submit: '收到問班需求', contacted: '已聯絡家長', owner_assigned: '認領負責', trial_scheduled: '已安排試聽', trial_completed: '已記錄試聽結果', enrolled: '已連結正式課程', lost: '已結案', follow_up_saved: '已更新追蹤' }[event.reason_code] || '更新詢問');

const publicFormUrl = computed(() => {
  if (typeof window === 'undefined') return '#/admissions';
  const origin = window.location.origin || '';
  const pathname = window.location.pathname || '';
  return `${origin}${pathname}#/admissions`;
});

const unclaimedCount = computed(() => inquiries.value.filter(item => !item.owner_id && !['enrolled', 'lost'].includes(item.status)).length);
const urgentCount = computed(() => inquiries.value.filter(item => {
  const meta = getFollowUpMeta(item.follow_up_at);
  return meta && (meta.type === 'overdue' || meta.type === 'today');
}).length);

function getFollowUpMeta(dateStr) {
  if (!dateStr) return null;
  const target = String(dateStr).slice(0, 10);
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  const today = `${year}-${month}-${day}`;
  if (target < today) return { type: 'overdue', label: `逾期 (${target.slice(5)})` };
  if (target === today) return { type: 'today', label: '今日需追蹤' };
  return { type: 'future', label: `追蹤 ${target.slice(5)}` };
}

function getStageState(stageKey, currentStatus) {
  const stageOrder = ['new', 'contacted', 'trial_scheduled', 'trial_completed', 'enrolled'];
  if (currentStatus === 'lost') return 'inactive';
  const currentIndex = stageOrder.indexOf(currentStatus);
  const targetIndex = stageOrder.indexOf(stageKey);
  if (targetIndex < currentIndex) return 'completed';
  if (targetIndex === currentIndex) return 'current';
  return 'pending';
}

async function copyPublicLink() {
  const url = publicFormUrl.value;
  try {
    if (navigator?.clipboard?.writeText) {
      await navigator.clipboard.writeText(url);
    } else {
      const el = document.createElement('textarea');
      el.value = url;
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
    }
    copySuccess.value = true;
    if (copyTimer) clearTimeout(copyTimer);
    copyTimer = setTimeout(() => { copySuccess.value = false; }, 2500);
  } catch {
    if (typeof window !== 'undefined') window.prompt('請複製公開問班連結：', url);
  }
}

function openPublicForm() {
  if (typeof window !== 'undefined') {
    window.open(publicFormUrl.value, '_blank', 'noopener,noreferrer');
  }
}

function setPublicStep(value) {
  step.value = value;
  nextTick(() => document.getElementById('admission-step-title')?.focus());
}

function advancePublicStep(event) {
  const fieldset = event.currentTarget.closest('fieldset');
  const invalid = fieldset?.querySelector(':invalid');
  if (invalid) {
    invalid.focus();
    return;
  }
  setPublicStep(2);
}

async function loadPublic() {
  try { branches.value = await getAdmissionBranches(); } catch (error) { errorMessage.value = error.message; }
}

async function submitPublic() {
  busy.value = true; errorMessage.value = '';
  try { await submitAdmissionInquiry({ ...publicForm.value, campus_id: Number(publicForm.value.campus_id), preferred_slots: publicForm.value.preferred_slots.filter(Boolean) }); submitted.value = true; } catch (error) { errorMessage.value = error.message; } finally { busy.value = false; }
}

function resetPublic() {
  submitted.value = false; publicForm.value = { campus_id: '', parent_name: '', parent_phone: '', student_name: '', grade: '', school_name: '', subject: '', preferred_slots: [''], public_notes: '', consent: false }; setPublicStep(1);
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
  selectedId.value = id; errorMessage.value = ''; showDirectTrial.value = false;
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
.admission-page { min-height: 100%; color: var(--ds-ink); font-family: var(--font-ui, system-ui, sans-serif); }

/* Public standalone layout */
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

/* Buttons */
.admission-button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 10px 20px; border: 0; border-radius: var(--ds-radius-md, 8px); background: var(--ds-cta); color: var(--ds-on-cta); font-weight: 700; font-size: 14px; cursor: pointer; transition: background .15s ease; text-decoration: none; }
.admission-button:hover:not(:disabled) { background: var(--ds-cta-hover); }
.admission-button:disabled { opacity: .55; cursor: wait; }
.admission-button.secondary { border: 1px solid var(--ds-hairline-input); background: var(--ds-canvas); color: var(--ds-ink); }
.admission-button.secondary:hover:not(:disabled) { background: var(--ds-canvas-soft); }
.admission-button.text-btn { border: 0; background: transparent; color: var(--ds-cta); padding: 8px 12px; font-weight: 600; }
.admission-button.text-btn:hover { text-decoration: underline; background: transparent; }
.admission-button.danger-text { color: var(--ds-danger); border-color: var(--ds-danger-wash); }
.admission-button.danger-text:hover { background: var(--ds-danger-wash); }
.admission-actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 22px; }
.admission-actions .admission-button:last-child { flex: 1; }
.admission-consent { display: flex; align-items: center; gap: 8px; }
.admission-consent input { width: 20px; min-height: 20px; }
.admission-error { margin: 14px 0; padding: 12px 14px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-danger-wash); color: var(--ds-danger); font-size: 14px; font-weight: 600; }

/* Staff page layout */
.admission-page-staff { padding: 24px clamp(16px, 3.5vw, 36px) 64px; max-width: 1320px; margin: 0 auto; }
.admission-staff-header { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; }
.admission-header-main h1 { margin: 4px 0 6px; }
.admission-header-main p { color: var(--ds-ink-mute); font-size: 14px; margin: 0; }
.admission-header-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.admission-header-actions .material-symbols-outlined { font-size: 18px; }

/* Filter & Stats bar */
.admission-filters-bar { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.admission-filters { min-width: 200px; }
.admission-filters label { margin: 0; gap: 4px; }
.admission-filter-label { font-size: 12px; color: var(--ds-ink-mute); font-weight: 600; }
.admission-stats-strip { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.admission-stat-chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); font-size: 12px; color: var(--ds-ink); }
.admission-stat-chip.unassigned { background: var(--ds-primary-wash); color: var(--ds-cta); font-weight: 600; border-color: var(--ds-hairline-input); }
.admission-stat-chip.urgent { background: var(--ds-warning-wash); color: var(--ds-warning); font-weight: 600; border-color: var(--ds-hairline-input); }

/* Empty state redesign */
.admission-empty { display: grid; gap: 24px; max-width: 760px; margin: 20px auto 40px; padding: 40px 28px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg, 12px); background: var(--ds-canvas); text-align: center; box-shadow: var(--ds-shadow-1); }
.admission-empty.compact { padding: 18px; border-style: dashed; margin: 0; max-width: none; text-align: left; display: flex; gap: 14px; align-items: center; }
.admission-empty-hero { display: grid; justify-items: center; gap: 8px; }
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

/* Main staff two-column grid */
.admission-staff-grid { display: grid; grid-template-columns: minmax(280px, 340px) minmax(0, 1fr); gap: 20px; align-items: start; }
.admission-queue, .admission-detail, .admission-panel { border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg, 12px); background: var(--ds-canvas); box-shadow: var(--ds-shadow-1); }

/* Queue list */
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

/* Status tags and Urgency Badges */
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

/* Detail Article View */
.admission-detail { padding: clamp(20px, 3.5vw, 32px); display: grid; gap: 20px; }

/* Pipeline Stepper */
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

/* Detail Header */
.admission-detail-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--ds-hairline); flex-wrap: wrap; }
.admission-detail-title-group { display: grid; gap: 4px; }
.admission-detail-badges { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.admission-detail-head h2 { margin: 4px 0 2px; font-size: 24px; color: var(--ds-ink); }
.admission-detail-subhead { margin: 0; color: var(--ds-ink-mute); font-size: 14px; font-weight: 500; }

.admission-phone-link { display: inline-flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-primary-wash); border: 1px solid var(--ds-hairline-input); color: var(--ds-cta); text-decoration: none; transition: background .12s ease; }
.admission-phone-link:hover { background: var(--ds-canvas-soft); }
.admission-phone-link .material-symbols-outlined { font-size: 24px; }
.admission-phone-info { display: grid; text-align: left; }
.admission-phone-info strong { font-size: 14px; color: var(--ds-ink); }
.admission-phone-info span { font-size: 13px; font-weight: 700; color: var(--ds-cta); }

/* DL Metadata Grid */
.admission-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 14px; margin: 0; padding: 14px 16px; background: var(--ds-canvas-soft); border-radius: var(--ds-radius-md, 8px); }
.admission-meta div { display: grid; gap: 3px; }
.admission-meta dt { font-size: 12px; color: var(--ds-ink-mute); font-weight: 600; }
.admission-meta dd { margin: 0; font-size: 14px; font-weight: 700; color: var(--ds-ink); }

/* Parent Note Card */
.admission-parent-note-block { padding: 14px 16px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); display: grid; gap: 6px; }
.admission-note-kicker { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: var(--ds-ink-mute); }
.admission-note-kicker .material-symbols-outlined { font-size: 16px; }
.admission-note { margin: 0; font-size: 14px; line-height: 1.6; color: var(--ds-ink); white-space: pre-wrap; }

/* Workflow Action Panels */
.admission-workflow { display: grid; gap: 16px; }
.admission-panel { padding: 20px; box-shadow: none; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg, 12px); }
.admission-panel.admission-action-stage { border-color: var(--ds-hairline-input); background: var(--ds-canvas); box-shadow: var(--ds-shadow-1); }
.admission-stage-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
.admission-step-pill { display: inline-flex; padding: 2px 8px; border-radius: 4px; background: var(--ds-cta); color: var(--ds-on-cta); font-size: 11px; font-weight: 800; letter-spacing: .05em; }
.admission-step-pill.warning { background: var(--ds-warning); color: var(--ds-on-cta); }
.admission-panel h3 { margin: 0; font-size: 17px; font-weight: 700; color: var(--ds-ink); }

.admission-panel-intro { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 14px; }
.admission-panel-intro .material-symbols-outlined { font-size: 28px; color: var(--ds-primary); }

.admission-input-group { margin: 14px 0; display: grid; gap: 6px; }
.admission-input-group label { font-size: 13px; font-weight: 600; color: var(--ds-ink); margin: 0; }

.admission-cta-bar { display: flex; gap: 12px; align-items: center; margin-top: 16px; flex-wrap: wrap; }
.admission-quick-trial-box { margin-top: 16px; padding: 16px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px dashed var(--ds-hairline-input); display: grid; gap: 12px; }
.admission-quick-trial-box h4 { margin: 0; font-size: 14px; font-weight: 700; }

.admission-mini-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 14px 0; }
.admission-field-title { font-size: 12px; font-weight: 600; color: var(--ds-ink-mute); }

/* Terminal states */
.admission-terminal-message { display: grid; gap: 2px; }
.admission-terminal-message strong { font-size: 15px; }
.admission-terminal-message p { margin: 0; font-size: 13px; }
.admission-success.compact { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-success-wash); color: var(--ds-success); border: 1px solid var(--ds-hairline); }
.admission-success.compact .material-symbols-outlined { font-size: 28px; }

/* Secondary Actions & Accordions */
.admission-secondary-panel { display: grid; gap: 12px; border-top: 1px solid var(--ds-hairline); padding-top: 16px; }
.admission-followup-box { padding: 16px 20px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); }
.admission-followup-header { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.admission-followup-header .material-symbols-outlined { font-size: 18px; color: var(--ds-ink-mute); }
.admission-followup-header h4 { margin: 0; font-size: 14px; font-weight: 700; color: var(--ds-ink); }
.admission-followup-box .admission-mini-form { margin: 10px 0 0; }

.admission-mark-lost-accordion details { font-size: 13px; color: var(--ds-ink-mute); }
.admission-mark-lost-accordion summary { cursor: pointer; font-weight: 600; padding: 6px 0; }
.admission-mark-lost-accordion summary:hover { color: var(--ds-danger); }
.admission-mark-lost-content { padding: 12px 16px; margin-top: 6px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); display: grid; gap: 10px; }

/* History details */
.admission-history { padding: 16px 20px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); }
.admission-history-title-row { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.admission-history-title-row .material-symbols-outlined { font-size: 18px; color: var(--ds-ink-mute); }
.admission-history-title-row h3 { margin: 0; font-size: 14px; font-weight: 700; color: var(--ds-ink); }
.admission-history-title-row small { color: var(--ds-ink-mute); font-size: 12px; }
.admission-history ol { display: grid; gap: 10px; margin: 0; padding-left: 18px; }
.admission-history li { display: flex; justify-content: space-between; gap: 12px; color: var(--ds-ink); font-size: 13px; }
.admission-history time { color: var(--ds-ink-mute); font-size: 12px; white-space: nowrap; }

/* Skeleton */
.admission-skeleton { display: grid; gap: 12px; margin-bottom: 20px; }
.admission-skeleton-row { height: 68px; border-radius: var(--ds-radius-md, 8px); background: linear-gradient(90deg, var(--ds-canvas-soft), var(--ds-primary-wash), var(--ds-canvas-soft)); background-size: 200% 100%; animation: admission-shimmer 1.2s ease-in-out infinite; }
@keyframes admission-shimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }

/* Responsive */
@media (max-width: 900px) {
  .admission-staff-grid { grid-template-columns: 1fr; }
  .admission-queue { max-height: 320px; overflow-y: auto; }
}

@media (max-width: 720px) {
  .admission-page-staff { padding: 16px 12px 64px; }
  .admission-staff-header { flex-direction: column; }
  .admission-header-actions { width: 100%; }
  .admission-header-actions .admission-button { flex: 1; }
  .admission-filters-bar { flex-direction: column; align-items: stretch; }
  .admission-filters { min-width: 0; width: 100%; }
  .admission-pipeline { min-width: 380px; }
  .admission-detail-head { flex-direction: column; }
  .admission-phone-link { width: 100%; justify-content: center; }
  .admission-mini-form { grid-template-columns: 1fr; }
  .admission-cta-bar { flex-direction: column; align-items: stretch; }
  .admission-cta-bar .admission-button { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
  .admission-skeleton-row { animation: none; }
  .admission-button { transition: none; }
}
</style>
