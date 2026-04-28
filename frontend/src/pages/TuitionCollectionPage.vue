<template>
  <div class="tc-page">
    <div class="tc-header">
      <div>
        <h2>帳務中心</h2>
        <p class="tc-subtitle">待處理、已結清查詢、收款與收據紀錄</p>
      </div>
      <button class="tc-refresh-btn" @click="refreshActiveTab()" :disabled="activeTabLoading">
        <span class="material-symbols-outlined" style="font-size:17px">refresh</span>
        重新整理
      </button>
    </div>

    <div class="acct-tabs" role="tablist" aria-label="帳務中心分頁">
      <button
        v-for="tab in ACCOUNTING_TABS"
        :key="tab.key"
        type="button"
        class="acct-tab"
        :class="{ active: activeAccountingTab === tab.key }"
        role="tab"
        :aria-selected="activeAccountingTab === tab.key"
        @click="activeAccountingTab = tab.key"
      >
        <span class="material-symbols-outlined" style="font-size:18px">{{ tab.icon }}</span>
        {{ tab.label }}
      </button>
    </div>

    <section v-if="activeAccountingTab === 'receivables'">
    <!-- Skeleton loading -->
    <div v-if="loading && !rows.length" class="tc-skeleton-area">
      <div class="tc-summary">
        <div class="tc-card tc-card--skeleton" v-for="i in 5" :key="i">
          <span class="skel skel-num"></span>
          <span class="skel skel-label"></span>
        </div>
      </div>
      <div class="tc-table-wrap">
        <table class="tc-table">
          <thead>
            <tr>
              <th v-for="i in 8" :key="i"><span class="skel skel-th"></span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in 5" :key="i">
              <td v-for="j in 8" :key="j"><span class="skel skel-cell" :style="{ width: j === 1 ? '120px' : j <= 4 ? '72px' : '80px' }"></span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-else-if="error" class="tc-error">
      <span class="material-symbols-outlined" style="font-size:20px">error</span>
      {{ error }}
    </div>

    <template v-else>
      <!-- Summary cards -->
      <div class="tc-summary" v-if="rows.length">
        <div class="tc-card tc-card--total">
          <span class="tc-card-num">{{ rows.length }}</span>
          <span class="tc-card-label">筆提醒</span>
        </div>
        <div class="tc-card tc-card--danger">
          <span class="tc-card-num">{{ statusCounts.unpaid + statusCounts.partial }}</span>
          <span class="tc-card-label">未繳費</span>
        </div>
        <div class="tc-card tc-card--overdue">
          <span class="tc-card-num">{{ overdueRows.length }}</span>
          <span class="tc-card-label">逾期</span>
          <span class="tc-card-sub">{{ formatCurrency(overdueTotal) }}</span>
        </div>
        <div class="tc-card tc-card--warn">
          <span class="tc-card-num">{{ statusCounts.pending_report }}</span>
          <span class="tc-card-label">待核帳</span>
        </div>
        <div class="tc-card tc-card--outstanding">
          <span class="tc-card-num">{{ formatCurrency(totalOutstanding) }}</span>
          <span class="tc-card-label">
            未結清
            <span v-if="collectionRate !== null" class="tc-rate" :style="{ color: collectionRateColor(collectionRate) }">
              收款率 {{ collectionRate }}%
            </span>
            <span v-else class="tc-rate">收款率 —</span>
          </span>
        </div>
      </div>

      <div v-if="!rows.length" class="tc-empty">
        <span class="material-symbols-outlined" style="font-size:52px;color:var(--success)">check_circle</span>
        <p>本分校目前無待催繳課程</p>
        <button class="tc-cta-btn" @click="$emit('navigate', 'tuition-report')">查看當月學收</button>
      </div>

      <div v-else>
        <!-- Status Tabs -->
        <div class="tc-tabs">
          <button
            v-for="tab in TAB_DEFS"
            :key="tab.key"
            class="tc-tab"
            :class="{ 'tc-tab--active': activeTab === tab.key }"
            @click="activeTab = tab.key"
          >
            {{ tab.label }}
            <span class="tc-tab-badge">{{ tabCounts[tab.key] }}</span>
          </button>
        </div>

        <!-- Toolbar: Search + CSV export -->
        <div class="tc-toolbar">
          <div class="tc-search-wrap">
            <span class="material-symbols-outlined tc-search-icon">search</span>
            <input
              v-model="searchQuery"
              class="tc-search-input"
              type="text"
              placeholder="搜尋學生姓名…"
              autocomplete="off"
            />
            <button v-if="searchQuery" class="tc-search-clear" @click="searchQuery = ''" title="清除">
              <span class="material-symbols-outlined" style="font-size:16px">close</span>
            </button>
          </div>
          <span v-if="searchQuery" class="tc-search-hint">
            {{ filteredRows.length }} 筆符合「{{ searchQuery }}」
          </span>
          <div class="tc-toolbar-right">
            <div v-if="loading" class="tc-inline-loading">
              <span class="material-symbols-outlined spin" style="font-size:16px">progress_activity</span>
              更新中…
            </div>
            <button
              class="tc-btn tc-btn--csv"
              @click="exportCSV"
              :disabled="!filteredRows.length || csvExporting"
              :title="!filteredRows.length ? '目前無資料可匯出' : '匯出 CSV'"
            >
              <span class="material-symbols-outlined" :class="{ spin: csvExporting }" style="font-size:16px">download</span>
              匯出 CSV
            </button>
          </div>
        </div>

        <!-- Empty state for current tab -->
        <div v-if="!filteredRows.length" class="tc-empty" style="padding:32px 0">
          <span v-if="searchQuery" class="material-symbols-outlined" style="font-size:40px;color:var(--text-light)">person_search</span>
          <span v-else class="material-symbols-outlined" style="font-size:48px;color:var(--text-light)">inbox</span>
          <p v-if="searchQuery">找不到包含「{{ searchQuery }}」的學生</p>
          <p v-else>此分類目前無資料</p>
          <button v-if="searchQuery" class="tc-cta-btn tc-cta-btn--ghost" @click="searchQuery = ''">清除搜尋</button>
          <button v-else-if="activeTab !== 'all'" class="tc-cta-btn tc-cta-btn--ghost" @click="activeTab = 'all'">查看全部</button>
        </div>

        <!-- Table -->
        <div v-else class="tc-table-wrap">
          <table class="tc-table">
            <thead>
              <tr>
                <th class="tc-th-sort" @click="toggleSort('student_name')">
                  學生
                  <span v-if="sortKey === 'student_name'" class="tc-sort-arrow">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                </th>
                <th class="tc-th-sort" @click="toggleSort('subject')">
                  科目
                  <span v-if="sortKey === 'subject'" class="tc-sort-arrow">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                </th>
                <th class="tc-col-mode">模式</th>
                <th>狀態</th>
                <th class="tc-col-currency tc-th-sort" @click="toggleSort('charge')">
                  應繳
                  <span v-if="sortKey === 'charge'" class="tc-sort-arrow">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                </th>
                <th class="tc-col-currency">已繳</th>
                <th class="tc-col-currency tc-th-sort" @click="toggleSort('outstanding')">
                  未結清
                  <span v-if="sortKey === 'outstanding'" class="tc-sort-arrow">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                </th>
                <th class="tc-col-date">最近付款</th>
                <th class="tc-col-date tc-th-sort" @click="toggleSort('due_date')">
                  到期／逾期
                  <span v-if="sortKey === 'due_date'" class="tc-sort-arrow">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                </th>
                <th class="tc-col-actions">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredRows" :key="r.id" :class="rowClass(r)">
                <td class="tc-cell-name">{{ r.student_name }}</td>
                <td>{{ r.subject }}</td>
                <td class="tc-col-mode">
                  <span class="mode-tag" :class="r.schedule_mode">
                    {{ r.schedule_mode === 'date' ? '月結' : '堂數' }}
                  </span>
                </td>
                <td>
                  <span class="status-tag" :class="statusClass(r)">
                    {{ statusLabel(r) }}
                  </span>
                </td>
                <td class="tc-col-currency">{{ r.charge != null ? formatCurrency(r.charge) : '—' }}</td>
                <td class="tc-col-currency">{{ r.paid_amount != null ? formatCurrency(r.paid_amount) : '—' }}</td>
                <td class="tc-col-currency" :class="{ 'tc-outstanding-warn': r.outstanding > 0 }">
                  {{ r.outstanding != null ? formatCurrency(r.outstanding) : '—' }}
                </td>
                <td class="tc-col-date">
                  <span v-if="r.last_paid_at" class="paid-date" :title="'最後一筆付款日，非本期是否結清的依據'">{{ r.last_paid_at }}</span>
                  <span v-else class="text-light">—</span>
                </td>
                <td class="tc-col-date">
                  <template v-if="r.due_date">
                    {{ r.due_date }}
                    <span v-if="r.days_until_settlement != null && r.days_until_settlement < 0" class="overdue-tag">
                      逾期{{ Math.abs(r.days_until_settlement) }}天
                    </span>
                    <span v-else-if="r.days_until_settlement != null && r.days_until_settlement <= 2" class="soon-tag">
                      {{ r.days_until_settlement }}天後
                    </span>
                  </template>
                  <template v-else-if="r.schedule_mode === 'count'">
                    <button
                      v-if="r.id"
                      class="tc-sessions-link"
                      @click="openSessionDetail(r)"
                      :title="'點入查看上課明細'"
                    >剩 {{ r.remaining_sessions }} 堂 <span class="material-symbols-outlined" style="font-size:13px;vertical-align:middle">open_in_new</span></button>
                    <span v-else class="text-light">剩 {{ r.remaining_sessions }} 堂</span>
                  </template>
                  <span v-else class="text-light">—</span>
                </td>
                <td class="tc-col-actions">
                  <div class="tc-actions">
                    <button class="tc-btn tc-btn--ledger" @click="openLedgerForClass(r)" title="查看學生帳務對帳">
                      <span class="material-symbols-outlined">account_balance</span>
                      對帳
                    </button>

                    <!-- unpaid / partial: slip + 核帳登記 -->
                    <template v-if="r.payment_status === 'unpaid' || r.payment_status === 'partial'">
                      <button class="tc-btn tc-btn--slip" @click="openSlip(r)" title="產生繳費通知單">
                        <span class="material-symbols-outlined">receipt_long</span>
                        繳費單
                      </button>
                      <button class="tc-btn tc-btn--confirm" @click="openPaymentEntry(r)" title="核帳登記" :disabled="actionLoading === r.id">
                        <span class="material-symbols-outlined">check_circle</span>
                        核帳登記
                      </button>
                    </template>

                    <!-- pending_report: confirm / reject -->
                    <template v-if="r.payment_status === 'pending_report'">
                      <button class="tc-btn tc-btn--confirm" @click="confirmReport(r)" :disabled="actionLoading === r.id" title="確認入帳">
                        <span v-if="actionLoading === r.id" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
                        <span v-else class="material-symbols-outlined">check_circle</span>
                        確認入帳
                      </button>
                      <button class="tc-btn tc-btn--reject" @click="rejectReport(r)" :disabled="actionLoading === r.id" title="退回">
                        <span class="material-symbols-outlined">cancel</span>
                        退回
                      </button>
                    </template>

                    <!-- paid / renew_needed / monthly_due_soon: void + receipt -->
                    <template v-if="r.payment_status === 'paid' || r.payment_status === 'renew_needed' || r.payment_status === 'monthly_due_soon'">
                      <button class="tc-btn tc-btn--receipt" @click="viewReceiptForClass(r)" title="查看收據">
                        <span class="material-symbols-outlined">receipt</span>
                        收據
                      </button>
                      <button v-if="canVoid" class="tc-btn tc-btn--void" @click="openVoidDialog(r)" :disabled="actionLoading === r.id" title="撤銷收款">
                        <span class="material-symbols-outlined">undo</span>
                        撤銷
                      </button>
                      <template v-if="r.payment_status === 'renew_needed'">
                        <span v-if="r.has_newer_course" class="tc-newer-badge" :title="'已有新課程 #' + r.newer_course_id + '（剩餘 ' + (r.newer_course_remaining ?? 0) + ' 堂）'">已有新課程</span>
                        <span v-else class="tc-renew-hint">需續課</span>
                        <button class="tc-btn tc-btn--settle" @click="openSettleDialog(r)" :disabled="settleLoading === r.id" title="確認舊課程已被新課程取代，點此關閉">
                          <span v-if="settleLoading === r.id" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
                          <span v-else class="material-symbols-outlined">task_alt</span>
                          結案
                        </button>
                      </template>
                    </template>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>
    </template>
    </section>

    <section v-else class="acct-panel">
      <div class="acct-filter-card">
        <div class="acct-filter-grid">
          <label>
            起日
            <input v-model="accountingFilters.start" type="date" />
          </label>
          <label>
            迄日
            <input v-model="accountingFilters.end" type="date" />
          </label>
          <label>
            學生
            <input v-model="accountingFilters.student" type="text" placeholder="搜尋學生姓名" />
          </label>
          <label>
            科目
            <input v-model="accountingFilters.subject" type="text" placeholder="全部科目" />
          </label>
          <label>
            付款方式
            <select v-model="accountingFilters.payment_method">
              <option value="">全部</option>
              <option value="cash">現金</option>
              <option value="transfer">匯款</option>
            </select>
          </label>
        </div>
        <div class="acct-filter-actions">
          <button class="tc-btn tc-btn--ghost" @click="loadAccountingPayments()" :disabled="accountingLoading">
            <span class="material-symbols-outlined" :class="{ spin: accountingLoading }" style="font-size:16px">refresh</span>
            查詢
          </button>
          <button class="tc-btn tc-btn--csv" @click="exportAccountingCSV" :disabled="!accountingRows.length || accountingExporting">
            <span class="material-symbols-outlined" :class="{ spin: accountingExporting }" style="font-size:16px">download</span>
            匯出 CSV
          </button>
          <button class="tc-btn tc-btn--receipt" @click="exportAccountingPDF" :disabled="!accountingRows.length || accountingExporting">
            <span class="material-symbols-outlined" style="font-size:16px">picture_as_pdf</span>
            匯出 PDF
          </button>
        </div>
      </div>

      <div v-if="accountingError" class="tc-error">
        <span class="material-symbols-outlined" style="font-size:20px">error</span>
        {{ accountingError }}
      </div>

      <div v-if="accountingLoading && !accountingRows.length" class="tc-skeleton-area">
        <div class="tc-summary">
          <div class="tc-card tc-card--skeleton" v-for="i in 5" :key="i">
            <span class="skel skel-num"></span>
            <span class="skel skel-label"></span>
          </div>
        </div>
      </div>

      <template v-else>
        <div class="tc-summary">
          <div class="tc-card tc-card--total">
            <span class="tc-card-num">{{ accountingSummary.total_count || 0 }}</span>
            <span class="tc-card-label">有效收款流水</span>
          </div>
          <div class="tc-card tc-card--info">
            <span class="tc-card-num">{{ accountingSummary.unique_paid_course_count || 0 }}</span>
            <span class="tc-card-label">對應課程數</span>
          </div>
          <div class="tc-card" :class="{ 'tc-card--warn': (accountingSummary.duplicate_payment_course_count || 0) > 0 }">
            <span class="tc-card-num">{{ accountingSummary.duplicate_payment_course_count || 0 }}</span>
            <span class="tc-card-label">同課程多筆收款</span>
          </div>
          <div class="tc-card tc-card--success">
            <span class="tc-card-num">{{ formatCurrency(accountingSummary.cash_total || 0) }}</span>
            <span class="tc-card-label">現金</span>
          </div>
          <div class="tc-card tc-card--info">
            <span class="tc-card-num">{{ formatCurrency(accountingSummary.transfer_total || 0) }}</span>
            <span class="tc-card-label">匯款</span>
          </div>
          <div class="tc-card tc-card--outstanding">
            <span class="tc-card-num">{{ formatCurrency(accountingSummary.grand_total || 0) }}</span>
            <span class="tc-card-label">合計</span>
          </div>
          <div class="tc-card tc-card--warn">
            <span class="tc-card-num">{{ accountingSummary.prepaid_count || 0 }}</span>
            <span class="tc-card-label">預收筆數</span>
          </div>
        </div>
        <p class="tc-summary-note">
          「有效收款流水」是一張張收據/核帳紀錄；「對應課程數」是這些收款對應到的不同課程。若同課程多筆收款大於 0，請用逐筆撤銷沖銷錯帳，不要刪除原紀錄。
        </p>

        <div v-if="!accountingRows.length" class="tc-empty">
          <span class="material-symbols-outlined" style="font-size:48px;color:var(--text-light)">receipt_long</span>
          <p>此區間尚無已核帳收款</p>
          <button class="tc-cta-btn tc-cta-btn--ghost" @click="activeAccountingTab = 'receivables'">前往待處理</button>
        </div>

        <div v-else class="tc-table-wrap">
          <table class="tc-table acct-table">
            <thead>
              <tr>
                <th>收據編號</th>
                <th>繳費日期</th>
                <th>學生</th>
                <th>科目</th>
                <th>第一堂課</th>
                <th class="tc-col-currency">現金</th>
                <th class="tc-col-currency">匯款</th>
                <th class="tc-col-currency">合計</th>
                <th>標籤</th>
                <th>核帳人</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in accountingRows" :key="row.report_id">
                <td>{{ row.receipt_no }}</td>
                <td>{{ row.payment_date || '—' }}</td>
                <td class="tc-cell-name">{{ row.student_name }}</td>
                <td>{{ row.subject }}</td>
                <td>{{ row.first_session_date || '尚未排課' }}</td>
                <td class="tc-col-currency">{{ formatCurrency(row.cash_amount || 0) }}</td>
                <td class="tc-col-currency">{{ formatCurrency(row.transfer_amount || 0) }}</td>
                <td class="tc-col-currency">{{ formatCurrency(row.total_amount || 0) }}</td>
                <td>
                  <span v-if="row.is_prepaid" class="acct-chip acct-chip--prepaid">預收</span>
                  <span v-if="row.is_backfilled" class="acct-chip acct-chip--backfill">補建</span>
                  <span v-if="!row.is_prepaid && !row.is_backfilled" class="text-light">—</span>
                </td>
                <td>{{ row.confirmed_by_name || '—' }}</td>
                <td>
                  <div class="tc-actions">
                    <button class="tc-btn tc-btn--ledger" @click="openLedgerForReport(row)" :aria-label="`對帳 ${row.receipt_no}`">
                      <span class="material-symbols-outlined">account_balance</span>
                      對帳
                    </button>
                    <button class="tc-btn tc-btn--receipt" @click="openReceiptByReport(row.report_id)" :aria-label="`查看 ${row.receipt_no}`">
                      <span class="material-symbols-outlined">receipt</span>
                      查看 / PNG
                    </button>
                    <button
                      v-if="canVoid"
                      class="tc-btn tc-btn--void"
                      @click="openVoidDialog(row)"
                      :disabled="voidLoading"
                      :aria-label="`撤銷 ${row.receipt_no}`"
                    >
                      <span class="material-symbols-outlined">undo</span>
                      撤銷
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <section v-if="activeAccountingTab === 'settled'" class="acct-section">
      <div class="acct-filter-bar">
        <div class="acct-filters">
          <label>學生<input v-model="accountingFilters.student" type="text" placeholder="搜尋學生姓名" /></label>
          <label>科目<input v-model="accountingFilters.subject" type="text" placeholder="全部科目" /></label>
        </div>
        <div class="acct-filter-actions">
          <button class="tc-btn tc-btn--ghost" @click="loadSettledCourses()" :disabled="settledLoading">
            <span class="material-symbols-outlined" :class="{ spin: settledLoading }" style="font-size:16px">refresh</span>
            查詢
          </button>
        </div>
      </div>

      <div v-if="settledError" class="tc-error">
        <span class="material-symbols-outlined" style="font-size:20px">error</span>
        {{ settledError }}
      </div>

      <template v-else>
        <div class="tc-summary">
          <div class="tc-card tc-card--total"><span class="tc-card-num">{{ settledSummary.course_count || 0 }}</span><span class="tc-card-label">已結清課程</span></div>
          <div class="tc-card tc-card--success"><span class="tc-card-num">{{ formatCurrency(settledSummary.paid_total || 0) }}</span><span class="tc-card-label">已套用收款</span></div>
          <div class="tc-card" :class="{ 'tc-card--warn': (settledSummary.legacy_count || 0) > 0 }"><span class="tc-card-num">{{ settledSummary.legacy_count || 0 }}</span><span class="tc-card-label">舊制無帳單</span></div>
          <div class="tc-card" :class="{ 'tc-card--warn': (settledSummary.exception_count || 0) > 0 }"><span class="tc-card-num">{{ settledSummary.exception_count || 0 }}</span><span class="tc-card-label">例外待處理</span></div>
          <div class="tc-card tc-card--outstanding"><span class="tc-card-num">{{ formatCurrency(settledSummary.overpaid_total || 0) }}</span><span class="tc-card-label">溢收/待沖銷</span></div>
        </div>
        <p class="tc-summary-note">「已結清查詢」用 Invoice/Payment/Receipt 與課程主檔彙整，不是提醒 queue；堂數制已繳且堂數充足也會出現在這裡。</p>

        <div v-if="settledLoading && !settledRows.length" class="tc-skeleton-area">
          <div class="tc-card tc-card--skeleton"><span class="skel skel-num"></span><span class="skel skel-label"></span></div>
        </div>
        <div v-else-if="!settledRows.length" class="tc-empty">
          <span class="material-symbols-outlined" style="font-size:48px;color:var(--text-light)">task_alt</span>
          <p>目前查無已結清課程</p>
        </div>
        <div v-else class="tc-table-wrap">
          <table class="tc-table acct-table">
            <thead>
              <tr>
                <th>課程</th><th>學生</th><th>科目</th><th>模式</th><th class="tc-col-currency">已套用</th><th>最近付款</th><th>標籤</th><th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in settledRows" :key="row.student_class_id">
                <td>{{ row.course_ref }}</td>
                <td class="tc-cell-name">{{ row.student_name }}</td>
                <td>{{ row.subject }}</td>
                <td>{{ row.schedule_mode === 'date' ? '月結' : '堂數' }}</td>
                <td class="tc-col-currency">{{ formatCurrency(row.paid_amount || 0) }}</td>
                <td>{{ row.last_paid_at || '—' }}</td>
                <td>
                  <span v-if="row.legacy_paid_without_invoice" class="acct-chip acct-chip--backfill">舊制無帳單</span>
                  <span v-if="row.has_exception" class="acct-chip acct-chip--prepaid">例外待處理</span>
                  <span v-if="!row.legacy_paid_without_invoice && !row.has_exception" class="text-light">正常</span>
                </td>
                <td>
                  <div class="tc-actions">
                    <button class="tc-btn tc-btn--ledger" @click="openLedgerForClass(row)"><span class="material-symbols-outlined">account_balance</span>對帳</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <!-- Modals -->
    <PaymentSlipModal
      :show="slipOpen"
      :invoice-id="slipInvoiceId"
      :student-class-id="slipStudentClassId"
      @close="slipOpen = false"
    />

    <PaymentEntryModal
      :show="entryOpen"
      :row="entryRow"
      @close="entryOpen = false"
      @confirmed="onEntryConfirmed"
    />

    <ReceiptModal
      :show="receiptOpen"
      :report-id="receiptReportId"
      @close="receiptOpen = false"
    />

    <AccountingLedgerModal
      :show="ledgerOpen"
      :student-class-id="ledgerStudentClassId"
      :report-id="ledgerReportId"
      :branch-id="branchId"
      @close="ledgerOpen = false"
      @changed="onLedgerChanged"
    />

    <!-- Void Confirmation Dialog -->
    <Transition name="fade">
      <div v-if="voidDialogOpen" class="tc-overlay" @click.self="voidDialogOpen = false">
        <div class="tc-dialog">
          <h3 class="tc-dialog-title">
            <span class="material-symbols-outlined" style="font-size:22px;color:var(--danger)">warning</span>
            撤銷收款確認
          </h3>
          <p class="tc-dialog-desc">此操作不會刪除付款；系統會建立一筆負值沖銷並保留稽核紀錄。請只用於重複入帳、金額錯誤或誤核帳。</p>
          <div class="tc-dialog-info" v-if="voidTarget">
            <span>{{ voidTarget.student_name }} — {{ voidTarget.subject }}</span>
            <small v-if="voidTarget.receipt_no">
              {{ voidTarget.receipt_no }} · {{ voidTarget.payment_date || '未記錄日期' }} · {{ formatCurrency(voidTarget.total_amount || voidTarget.reported_amount || 0) }}
            </small>
          </div>
          <label class="tc-dialog-label">撤銷原因（必填）</label>
          <textarea v-model="voidReason" class="tc-dialog-textarea" placeholder="請輸入撤銷原因…" maxlength="500" rows="3"></textarea>
          <div class="tc-dialog-charcount">{{ voidReason.length }} / 500</div>
          <div class="tc-dialog-btns">
            <button class="tc-btn tc-btn--ghost" @click="voidDialogOpen = false" :disabled="voidLoading">取消</button>
            <button class="tc-btn tc-btn--danger" @click="confirmVoid" :disabled="!voidReason.trim() || voidLoading">
              <span v-if="voidLoading" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
              確認撤銷
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Settle (Close) Confirmation Dialog -->
    <Transition name="fade">
      <div v-if="settleDialogOpen" class="tc-overlay" @click.self="settleDialogOpen = false">
        <div class="tc-dialog">
          <h3 class="tc-dialog-title">
            <span class="material-symbols-outlined" style="font-size:22px;color:var(--primary)">task_alt</span>
            確認結案此課程
          </h3>
          <p class="tc-dialog-desc">結案後此課程將從催繳名單移除，不再追蹤。</p>
          <div class="tc-dialog-info" v-if="settleTarget">
            <div style="margin-bottom:4px"><strong>{{ settleTarget.student_name }}</strong> — {{ settleTarget.subject }}</div>
            <div style="font-size:12px;color:var(--text-light)">課程 #{{ settleTarget.id }}，剩餘 {{ settleTarget.remaining_sessions }} 堂</div>
          </div>
          <div v-if="settleTarget?.has_newer_course" class="tc-settle-newer-info">
            <span class="material-symbols-outlined" style="font-size:16px;color:#16A34A">check_circle</span>
            <span>已偵測到新課程 #{{ settleTarget.newer_course_id }}（開課日 {{ settleTarget.newer_course_start_date || '—' }}，剩餘 {{ settleTarget.newer_course_remaining ?? 0 }} 堂）</span>
          </div>
          <div v-else class="tc-settle-warn-info">
            <span class="material-symbols-outlined" style="font-size:16px;color:#D97706">info</span>
            <span>未偵測到同科目新課程，關閉後此課程不再追蹤</span>
          </div>
          <div class="tc-dialog-btns">
            <button class="tc-btn tc-btn--ghost" @click="settleDialogOpen = false" :disabled="settleLoading">取消</button>
            <button class="tc-btn tc-btn--primary" @click="confirmSettle" :disabled="settleLoading">
              <span v-if="settleLoading" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
              確認結案
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Session Detail Modal -->
    <Transition name="fade">
      <div v-if="sessionDetailOpen" class="tc-overlay" @click.self="sessionDetailOpen = false">
        <div class="tc-dialog tc-dialog--wide">
          <div class="tc-dialog-header">
            <div>
              <h3 class="tc-dialog-title" style="margin-bottom:2px">
                <span class="material-symbols-outlined" style="font-size:20px;color:var(--primary)">history_edu</span>
                上課紀錄查核
              </h3>
              <div v-if="sessionDetailRow" style="font-size:13px;color:var(--text-light)">
                {{ sessionDetailRow.student_name }} — {{ sessionDetailRow.subject }}
              </div>
            </div>
            <button class="tc-dialog-close" @click="sessionDetailOpen = false">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>

          <!-- Summary bar -->
          <div v-if="sessionDetailRow" class="tc-session-summary">
            <span class="tc-ss-item tc-ss-attended">
              <strong>{{ sessionDetailAttended }}</strong> 堂已上
            </span>
            <span class="tc-ss-sep">／</span>
            <span class="tc-ss-item">購買 <strong>{{ sessionDetailRow.sessions_purchased }}</strong> 堂</span>
            <span class="tc-ss-sep">·</span>
            <span class="tc-ss-item">剩餘 <strong>{{ sessionDetailRow.remaining_sessions }}</strong> 堂</span>
          </div>

          <!-- Loading -->
          <div v-if="sessionDetailLoading" class="tc-session-loading">
            <span class="material-symbols-outlined spin">progress_activity</span>
            載入中…
          </div>

          <!-- Empty -->
          <div v-else-if="!sessionDetailList.length" class="tc-session-empty">
            尚無上課紀錄
          </div>

          <!-- Table -->
          <div v-else class="tc-session-table-wrap">
            <table class="tc-session-table">
              <thead>
                <tr>
                  <th>日期</th>
                  <th>時間</th>
                  <th>老師</th>
                  <th>狀態</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in sessionDetailList" :key="s.id" :class="sessionRowClass(s.status)">
                  <td>{{ s.session_date }}</td>
                  <td class="tc-session-time">{{ s.start_time }}–{{ s.end_time }}</td>
                  <td>{{ s.teacher_name || '—' }}</td>
                  <td><span class="tc-session-status" :class="'ss-' + s.status">{{ sessionStatusLabel(s.status) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="tc-dialog-btns" style="margin-top:12px">
            <button class="tc-btn tc-btn--ghost" @click="sessionDetailOpen = false">關閉</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Toast -->
    <Transition name="toast">
      <div v-if="toastMessage" class="tc-toast" :class="toastType">
        <span class="material-symbols-outlined" style="font-size:18px">{{ toastIcon }}</span>
        {{ toastMessage }}
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import PaymentSlipModal from '../components/PaymentSlipModal.vue';
import PaymentEntryModal from '../components/PaymentEntryModal.vue';
import ReceiptModal from '../components/ReceiptModal.vue';
import AccountingLedgerModal from '../components/AccountingLedgerModal.vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

const emit = defineEmits(['navigate']);

const loading = ref(false);
const error = ref('');
const actionLoading = ref(null);

const ACCOUNTING_TABS = [
  { key: 'receivables', label: '待處理', icon: 'payments' },
  { key: 'settled', label: '已結清查詢', icon: 'task_alt' },
  { key: 'payments', label: '收款與收據紀錄', icon: 'receipt_long' },
];
const activeAccountingTab = ref('receivables');
const accountingLoading = ref(false);
const accountingExporting = ref(false);
const accountingError = ref('');
const accountingRows = ref([]);
const settledLoading = ref(false);
const settledError = ref('');
const settledRows = ref([]);
const settledSummary = ref({ course_count: 0, legacy_count: 0, exception_count: 0, paid_total: 0, overpaid_total: 0 });
const accountingSummary = ref({
  total_count: 0,
  cash_total: 0,
  transfer_total: 0,
  grand_total: 0,
  prepaid_count: 0,
});
const accountingFilters = ref(defaultAccountingFilters());

const activeTabLoading = computed(() => (
  activeAccountingTab.value === 'receivables' ? loading.value : activeAccountingTab.value === 'settled' ? settledLoading.value : accountingLoading.value
));

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

function getAuthRole() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.user?.role || session?.role || '';
}

const canVoid = computed(() => {
  const role = getAuthRole();
  return ['director', 'admin', 'super_admin'].includes(role);
});

// ═══ Payment Status Helpers ═══
const STATUS_CONFIG = {
  unpaid:           { label: '未繳費',        cls: 'st-unpaid' },
  partial:          { label: '部分付款',      cls: 'st-partial' },
  pending_report:   { label: '待核帳',        cls: 'st-pending' },
  paid:             { label: '已繳費',        cls: 'st-paid' },
  renew_needed:     { label: '已繳需續課',    cls: 'st-renew' },
  monthly_due_soon: { label: '月結將到期',    cls: 'st-monthly' },
};

function statusLabel(r) {
  const ps = r.payment_status;
  if (ps && STATUS_CONFIG[ps]) return STATUS_CONFIG[ps].label;
  return r.paid ? '已繳費' : '未繳費';
}

function statusClass(r) {
  const ps = r.payment_status;
  if (ps && STATUS_CONFIG[ps]) return STATUS_CONFIG[ps].cls;
  return r.paid ? 'st-paid' : 'st-unpaid';
}

function rowClass(r) {
  const ps = r.payment_status;
  if (ps === 'paid' || ps === 'renew_needed') return 'row-paid';
  return '';
}

function formatCurrency(v) {
  if (v == null || isNaN(v)) return '—';
  return 'NT$ ' + Number(v).toLocaleString('zh-TW');
}

function defaultAccountingFilters() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const last = new Date(y, now.getMonth() + 1, 0).getDate();
  return {
    start: `${y}-${m}-01`,
    end: `${y}-${m}-${String(last).padStart(2, '0')}`,
    student: '',
    subject: '',
    payment_method: '',
  };
}

function paymentMethodLabel(method) {
  return method === 'transfer' ? '匯款' : method === 'cash' ? '現金' : method || '—';
}

function openReceiptByReport(reportId) {
  receiptReportId.value = Number(reportId);
  receiptOpen.value = true;
}

function refreshActiveTab() {
  if (activeAccountingTab.value === 'receivables') {
    loadAlerts();
  } else if (activeAccountingTab.value === 'settled') {
    loadSettledCourses();
  } else {
    loadAccountingPayments();
  }
}

// ═══ Alerts ═══
const rows = ref([]);
const searchQuery = ref('');
const slipOpen = ref(false);
const slipInvoiceId = ref(null);
const slipStudentClassId = ref(null);
const ledgerOpen = ref(false);
const ledgerStudentClassId = ref(null);
const ledgerReportId = ref(null);

function openLedgerForClass(row) {
  ledgerStudentClassId.value = row?.id || row?.student_class_id || null;
  ledgerReportId.value = null;
  ledgerOpen.value = true;
}

function openLedgerForReport(row) {
  ledgerStudentClassId.value = row?.student_class_id || null;
  ledgerReportId.value = row?.report_id || null;
  ledgerOpen.value = true;
}

// ═══ Tab Filter ═══
const activeTab = ref('all');
const TAB_DEFS = [
  { key: 'all', label: '全部' },
  { key: 'unpaid', label: '未繳' },
  { key: 'overdue', label: '逾期' },
  { key: 'pending_report', label: '待核帳' },
  { key: 'paid', label: '已繳' },
];

function isOverdue(r) {
  return (r.days_until_settlement != null && r.days_until_settlement < 0) &&
    ['unpaid', 'partial', 'pending_report'].includes(r.payment_status);
}

const tabCounts = computed(() => {
  const c = { all: 0, unpaid: 0, overdue: 0, pending_report: 0, paid: 0 };
  rows.value.forEach(r => {
    c.all++;
    const ps = r.payment_status;
    if (isOverdue(r)) c.overdue++;
    if (ps === 'unpaid' || ps === 'partial') c.unpaid++;
    else if (ps === 'pending_report') c.pending_report++;
    else if (ps === 'paid' || ps === 'renew_needed' || ps === 'monthly_due_soon') c.paid++;
  });
  return c;
});

const statusCounts = computed(() => {
  const c = { unpaid: 0, partial: 0, pending_report: 0, paid: 0, renew_needed: 0, monthly_due_soon: 0 };
  rows.value.forEach(r => {
    const ps = r.payment_status || (r.paid ? 'paid' : 'unpaid');
    if (c[ps] !== undefined) c[ps]++;
  });
  return c;
});

const OUTSTANDING_STATUSES = ['unpaid', 'partial', 'pending_report'];
const totalOutstanding = computed(() => {
  return rows.value
    .filter(r => OUTSTANDING_STATUSES.includes(r.payment_status))
    .reduce((sum, r) => sum + (r.outstanding || 0), 0);
});

// ═══ Summary Computed ═══
const overdueRows = computed(() => rows.value.filter(isOverdue));
const overdueTotal = computed(() => overdueRows.value.reduce((s, r) => s + (r.outstanding || 0), 0));
const totalCharge = computed(() => rows.value.reduce((s, r) => s + (r.charge || 0), 0));
const totalPaid = computed(() => rows.value.reduce((s, r) => s + (r.paid_amount || 0), 0));
const collectionRate = computed(() => {
  if (totalCharge.value === 0) return null;
  return Math.round((totalPaid.value / totalCharge.value) * 100);
});
function collectionRateColor(rate) {
  if (rate === null) return '';
  if (rate === 0) return 'var(--danger)';
  if (rate < 80) return '#D97706';
  return 'var(--success)';
}

// ═══ Sort ═══
const sortKey = ref('');
const sortDir = ref('');
const SORTABLE_COLS = [
  { key: 'student_name', label: '學生' },
  { key: 'subject', label: '科目' },
  { key: 'charge', label: '應繳' },
  { key: 'outstanding', label: '未結清' },
  { key: 'due_date', label: '到期／逾期' },
];

function toggleSort(key) {
  if (sortKey.value !== key) {
    sortKey.value = key;
    sortDir.value = 'asc';
  } else if (sortDir.value === 'asc') {
    sortDir.value = 'desc';
  } else {
    sortKey.value = '';
    sortDir.value = '';
  }
}

// ═══ Filtered + Sorted Rows (3-layer pipeline) ═══
const tabFilteredRows = computed(() => {
  const tab = activeTab.value;
  if (tab === 'all') return rows.value;
  if (tab === 'overdue') return rows.value.filter(isOverdue);
  if (tab === 'unpaid') return rows.value.filter(r => r.payment_status === 'unpaid' || r.payment_status === 'partial');
  if (tab === 'pending_report') return rows.value.filter(r => r.payment_status === 'pending_report');
  if (tab === 'paid') return rows.value.filter(r => ['paid', 'renew_needed', 'monthly_due_soon'].includes(r.payment_status));
  return rows.value;
});

const searchFilteredRows = computed(() => {
  const q = searchQuery.value.trim();
  if (!q) return tabFilteredRows.value;
  return tabFilteredRows.value.filter(r => r.student_name && r.student_name.includes(q));
});

const filteredRows = computed(() => {
  const list = [...searchFilteredRows.value];
  if (!sortKey.value) return list;
  const k = sortKey.value;
  const dir = sortDir.value === 'desc' ? -1 : 1;
  list.sort((a, b) => {
    let va = a[k], vb = b[k];
    if (va == null) va = k === 'charge' || k === 'outstanding' ? -Infinity : '';
    if (vb == null) vb = k === 'charge' || k === 'outstanding' ? -Infinity : '';
    if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
    return String(va).localeCompare(String(vb), 'zh-TW') * dir;
  });
  return list;
});

// ═══ CSV Export ═══
const csvExporting = ref(false);
function exportCSV() {
  const data = filteredRows.value;
  if (!data.length) return;
  csvExporting.value = true;
  const headers = ['學生姓名', '科目', '模式', '狀態', '應繳', '已繳', '未結清', '最近付款日', '到期日', '逾期天數'];
  const csvRows = data.map(r => [
    r.student_name || '',
    r.subject || '',
    r.schedule_mode === 'date' ? '月結' : '堂數',
    statusLabel(r),
    r.charge ?? '',
    r.paid_amount ?? '',
    r.outstanding ?? '',
    r.last_paid_at || '',
    r.due_date || '',
    (r.days_until_settlement != null && r.days_until_settlement < 0) ? Math.abs(r.days_until_settlement) : '',
  ]);
  const bom = '\uFEFF';
  const csv = bom + [headers, ...csvRows].map(row => row.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const d = new Date();
  const ymd = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
  a.href = url;
  a.download = `催繳名單_${ymd}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  setTimeout(() => { csvExporting.value = false; }, 500);
}

function openSlip(row) {
  slipInvoiceId.value = null;
  slipStudentClassId.value = row.id;
  slipOpen.value = true;
}

async function loadAlerts() {
  loading.value = true;
  error.value = '';
  try {
    const token = getToken();
    if (!token) { error.value = '請先登入'; return; }

    const params = new URLSearchParams();
    if (props.branchId != null && props.branchId !== '') {
      params.set('branch_id', String(Number(props.branchId)));
    }

    const resp = await fetch(`/api/v1/alerts/tuition?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) throw new Error(`載入失敗（${resp.status}）`);
    const json = await resp.json();
    const list = Array.isArray(json) ? json : [];

    const statusPriority = { unpaid: 0, partial: 1, pending_report: 2, monthly_due_soon: 3, renew_needed: 4, paid: 5 };
    rows.value = list.sort((a, b) => {
      const pa = statusPriority[a.payment_status] ?? 3;
      const pb = statusPriority[b.payment_status] ?? 3;
      if (pa !== pb) return pa - pb;
      const da = a.days_until_settlement ?? 999;
      const db = b.days_until_settlement ?? 999;
      return da - db;
    });
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

async function loadAccountingPayments() {
  accountingLoading.value = true;
  accountingError.value = '';
  try {
    const token = getToken();
    if (!token) { accountingError.value = '請先登入'; return; }

    const params = new URLSearchParams({
      start: accountingFilters.value.start,
      end: accountingFilters.value.end,
      per_page: '200',
    });
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
    if (accountingFilters.value.student.trim()) params.set('student', accountingFilters.value.student.trim());
    if (accountingFilters.value.subject.trim()) params.set('subject', accountingFilters.value.subject.trim());
    if (accountingFilters.value.payment_method) params.set('payment_method', accountingFilters.value.payment_method);

    const resp = await fetch(`/api/v1/accounting/payments?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `載入失敗（${resp.status}）`);
    }
    const json = await resp.json();
    accountingRows.value = Array.isArray(json.data) ? json.data : [];
    accountingSummary.value = json.summary || accountingSummary.value;
  } catch (e) {
    accountingError.value = e.message || '載入失敗';
  } finally {
    accountingLoading.value = false;
  }
}

async function loadSettledCourses() {
  settledLoading.value = true;
  settledError.value = '';
  try {
    const token = getToken();
    if (!token) { settledError.value = '請先登入'; return; }
    const params = new URLSearchParams();
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
    if (accountingFilters.value.student.trim()) params.set('student', accountingFilters.value.student.trim());
    if (accountingFilters.value.subject.trim()) params.set('subject', accountingFilters.value.subject.trim());
    const resp = await fetch(`/api/v1/accounting/settled-courses?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `載入失敗（${resp.status}）`);
    }
    const json = await resp.json();
    settledRows.value = Array.isArray(json.data) ? json.data : [];
    settledSummary.value = json.summary || settledSummary.value;
  } catch (e) {
    settledError.value = e.message || '載入失敗';
  } finally {
    settledLoading.value = false;
  }
}

async function onLedgerChanged() {
  await Promise.all([loadAlerts(), loadAccountingPayments(), loadSettledCourses()]);
}

async function fetchAccountingExportRows() {
  const token = getToken();
  if (!token) throw new Error('請先登入');
  const params = new URLSearchParams({
    start: accountingFilters.value.start,
    end: accountingFilters.value.end,
  });
  if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
  if (accountingFilters.value.student.trim()) params.set('student', accountingFilters.value.student.trim());
  if (accountingFilters.value.subject.trim()) params.set('subject', accountingFilters.value.subject.trim());
  if (accountingFilters.value.payment_method) params.set('payment_method', accountingFilters.value.payment_method);

  const resp = await fetch(`/api/v1/accounting/payments/export?${params}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  if (!resp.ok) {
    const err = await resp.json().catch(() => ({}));
    throw new Error(err.message || `匯出失敗（${resp.status}）`);
  }
  return resp.json();
}

async function exportAccountingCSV() {
  accountingExporting.value = true;
  try {
    const payload = await fetchAccountingExportRows();
    const data = payload.data || [];
    if (!data.length) {
      showToast('目前篩選條件沒有可匯出的收款與收據紀錄', 'warning');
      return;
    }
    const headers = ['收據編號', '繳費日期', '學生姓名', '科目', '第一堂課日期', '現金', '匯款', '合計', '付款方式', '標籤', '核帳人'];
    const csvRows = data.map(r => [
      r.receipt_no || '',
      r.payment_date || '',
      r.student_name || '',
      r.subject || '',
      r.first_session_date || '尚未排課',
      r.cash_amount || 0,
      r.transfer_amount || 0,
      r.total_amount || 0,
      paymentMethodLabel(r.payment_method),
      [r.is_prepaid ? '預收' : '', r.is_backfilled ? '補建' : ''].filter(Boolean).join(' / '),
      r.confirmed_by_name || '',
    ]);
    const bom = '\uFEFF';
    const csv = bom + [headers, ...csvRows].map(row => row.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `收款與收據紀錄_${accountingFilters.value.start}_${accountingFilters.value.end}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    showToast('已匯出 CSV');
  } catch (e) {
    showToast(e.message || 'CSV 匯出失敗', 'error');
  } finally {
    accountingExporting.value = false;
  }
}

async function exportAccountingPDF() {
  accountingExporting.value = true;
  const printWindow = window.open('', '_blank');
  if (!printWindow) {
    showToast('瀏覽器封鎖了 PDF 視窗，請允許彈出視窗後重試', 'warning');
    accountingExporting.value = false;
    return;
  }
  printWindow.opener = null;
  printWindow.document.write('<!doctype html><meta charset="utf-8"><title>收款與收據紀錄報表</title><p style="font-family:sans-serif;padding:24px">正在產生收款與收據紀錄報表...</p>');
  printWindow.document.close();

  try {
    const payload = await fetchAccountingExportRows();
    const data = payload.data || [];
    if (!data.length) {
      printWindow.close();
      showToast('目前篩選條件沒有可匯出的收款與收據紀錄', 'warning');
      return;
    }
    if (data.length > 500) {
      printWindow.close();
      showToast('PDF 最多匯出 500 筆，請縮小日期區間或改用 CSV', 'warning');
      return;
    }
    const summary = payload.summary || {};
    const rowsHtml = data.map(r => `
      <tr>
        <td>${escapeHtml(r.receipt_no || '')}</td>
        <td>${escapeHtml(r.payment_date || '')}</td>
        <td>${escapeHtml(r.student_name || '')}</td>
        <td>${escapeHtml(r.subject || '')}</td>
        <td>${escapeHtml(r.first_session_date || '尚未排課')}</td>
        <td class="num">${Number(r.cash_amount || 0).toLocaleString('zh-TW')}</td>
        <td class="num">${Number(r.transfer_amount || 0).toLocaleString('zh-TW')}</td>
        <td class="num">${Number(r.total_amount || 0).toLocaleString('zh-TW')}</td>
        <td>${r.is_prepaid ? '預收' : ''}${r.is_backfilled ? ' 補建' : ''}</td>
        <td>${escapeHtml(r.confirmed_by_name || '')}</td>
      </tr>
    `).join('');
    printWindow.document.open();
    printWindow.document.write(`
      <!doctype html>
      <html>
      <head>
        <meta charset="utf-8" />
        <title>收款與收據紀錄 ${escapeHtml(accountingFilters.value.start)}_${escapeHtml(accountingFilters.value.end)}</title>
        <style>
          body { font-family: "Noto Sans TC", Arial, sans-serif; color: #1f2937; margin: 28px; }
          h1 { margin: 0; font-size: 24px; color: #14532d; }
          .subtitle { margin: 6px 0 20px; color: #6b7280; }
          .summary { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 18px; }
          .card { border: 1px solid #d1d5db; border-radius: 12px; padding: 10px; background: #f8fafc; }
          .card strong { display: block; font-size: 18px; margin-bottom: 4px; }
          table { width: 100%; border-collapse: collapse; font-size: 12px; }
          th { background: #14532d; color: white; text-align: left; padding: 8px; }
          td { border-bottom: 1px solid #e5e7eb; padding: 7px 8px; }
          .num { text-align: right; font-variant-numeric: tabular-nums; }
          .footer { margin-top: 18px; color: #6b7280; font-size: 11px; text-align: right; }
          @media print { body { margin: 18mm; } button { display: none; } }
        </style>
      </head>
      <body>
        <h1>台北全真一對一補習班｜收款與收據紀錄報表</h1>
        <div class="subtitle">區間：${escapeHtml(accountingFilters.value.start)} 至 ${escapeHtml(accountingFilters.value.end)}</div>
        <div class="summary">
          <div class="card"><strong>${summary.total_count || 0}</strong>筆數</div>
          <div class="card"><strong>NT$ ${Number(summary.cash_total || 0).toLocaleString('zh-TW')}</strong>現金</div>
          <div class="card"><strong>NT$ ${Number(summary.transfer_total || 0).toLocaleString('zh-TW')}</strong>匯款</div>
          <div class="card"><strong>NT$ ${Number(summary.grand_total || 0).toLocaleString('zh-TW')}</strong>合計</div>
          <div class="card"><strong>${summary.prepaid_count || 0}</strong>預收</div>
        </div>
        <table>
          <thead><tr><th>收據編號</th><th>繳費日期</th><th>學生</th><th>科目</th><th>第一堂課</th><th>現金</th><th>匯款</th><th>合計</th><th>標籤</th><th>核帳人</th></tr></thead>
          <tbody>${rowsHtml}</tbody>
        </table>
        <div class="footer">產生時間：${new Date().toLocaleString('zh-TW')}</div>
        <script>window.onload = () => setTimeout(() => window.print(), 250);<\/script>
      </body>
      </html>
    `);
    printWindow.document.close();
    showToast('已開啟 PDF 列印報表');
  } catch (e) {
    if (!printWindow.closed) {
      printWindow.close();
    }
    showToast(e.message || 'PDF 匯出失敗', 'error');
  } finally {
    accountingExporting.value = false;
  }
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// ═══ Toast ═══
const toastMessage = ref('');
const toastType = ref('');
const toastIcon = ref('check_circle');

function showToast(msg, type = 'success') {
  toastMessage.value = msg;
  toastType.value = type === 'error' ? 'tc-toast--error' : type === 'warning' ? 'tc-toast--warning' : '';
  toastIcon.value = type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'check_circle';
  setTimeout(() => { toastMessage.value = ''; }, 3000);
}

// ═══ Payment Entry (核帳登記) ═══
const entryOpen = ref(false);
const entryRow = ref(null);

function openPaymentEntry(row) {
  entryRow.value = row;
  entryOpen.value = true;
}

function onEntryConfirmed(result) {
  entryOpen.value = false;
  showToast('已完成核帳登記');

  if (result?.report_id) {
    receiptReportId.value = result.report_id;
    receiptOpen.value = true;
  }

  loadAlerts();
}

// ═══ Confirm / Reject pending reports ═══
async function confirmReport(row) {
  if (!row.latest_payment_report_id) {
    showToast('找不到待確認的回報', 'error');
    return;
  }
  actionLoading.value = row.id;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/payment-reports/${row.latest_payment_report_id}/confirm`, {
      method: 'PUT',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `操作失敗（${resp.status}）`);
    }
    showToast('已確認入帳');
    loadAlerts();
  } catch (e) {
    showToast(e.message || '確認入帳失敗', 'error');
  } finally {
    actionLoading.value = null;
  }
}

async function rejectReport(row) {
  if (!row.latest_payment_report_id) {
    showToast('找不到待確認的回報', 'error');
    return;
  }
  const reason = prompt('請輸入退回原因：');
  if (!reason || !reason.trim()) return;

  actionLoading.value = row.id;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/payment-reports/${row.latest_payment_report_id}/reject`, {
      method: 'PUT',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ rejection_note: reason.trim() }),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `操作失敗（${resp.status}）`);
    }
    showToast('已退回此回報', 'warning');
    loadAlerts();
  } catch (e) {
    showToast(e.message || '退回失敗', 'error');
  } finally {
    actionLoading.value = null;
  }
}

// ═══ Void Dialog ═══
const voidDialogOpen = ref(false);
const voidTarget = ref(null);
const voidReason = ref('');
const voidLoading = ref(false);

function openVoidDialog(row) {
  voidTarget.value = row;
  voidReason.value = '';
  voidDialogOpen.value = true;
}

async function confirmVoid() {
  if (!voidTarget.value || !voidReason.value.trim()) return;

  voidLoading.value = true;
  try {
    const token = getToken();
    const reportId = Number(voidTarget.value.report_id || 0) || await findConfirmedReportForClass(voidTarget.value);
    if (!reportId) {
      showToast('找不到此課程的已確認核帳紀錄', 'error');
      return;
    }

    const resp = await fetch(`/api/v1/payment-reports/${reportId}/void`, {
      method: 'PUT',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ void_reason: voidReason.value.trim() }),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `撤銷失敗（${resp.status}）`);
    }
    voidDialogOpen.value = false;
    showToast('已撤銷收款，狀態已重置', 'warning');
    await Promise.all([loadAlerts(), loadAccountingPayments(), loadSettledCourses()]);
  } catch (e) {
    showToast(e.message || '撤銷失敗', 'error');
  } finally {
    voidLoading.value = false;
  }
}

async function findConfirmedReportForClass(row) {
  try {
    const token = getToken();
    const params = new URLSearchParams({ student_class_id: String(row.id), status: 'confirmed' });
    if (props.branchId != null && props.branchId !== '')
      params.set('branch_id', String(Number(props.branchId)));
    const resp = await fetch(`/api/v1/payment-reports?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) return null;
    const match = ((await resp.json()).data || [])[0];
    return match?.id || null;
  } catch {
    return null;
  }
}

// ═══ Receipt ═══
const receiptOpen = ref(false);
const receiptReportId = ref(null);

async function viewReceiptForClass(row) {
  try {
    const token = getToken();
    const params = new URLSearchParams({ student_class_id: String(row.id), status: 'confirmed' });
    if (props.branchId != null && props.branchId !== '')
      params.set('branch_id', String(Number(props.branchId)));
    const resp = await fetch(`/api/v1/payment-reports?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) return;
    const match = ((await resp.json()).data || [])[0];
    if (match) {
      receiptReportId.value = match.id;
      receiptOpen.value = true;
    } else {
      const isPaid = row.payment_status === 'paid' || row.payment_status === 'renew_needed' || row.payment_status === 'monthly_due_soon';
      showToast(isPaid ? '此課程透過舊系統繳費，無電子收據紀錄' : '找不到此課程的核帳收據', 'warning');
    }
  } catch {
    showToast('查詢收據失敗', 'error');
  }
}

// ═══ Settle (Close) ═══
const settleDialogOpen = ref(false);
const settleTarget = ref(null);
const settleLoading = ref(null);

function openSettleDialog(row) {
  settleTarget.value = row;
  settleDialogOpen.value = true;
}

async function confirmSettle() {
  if (!settleTarget.value) return;
  const row = settleTarget.value;
  settleLoading.value = row.id;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/student-classes/${row.id}/pause`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'pause', reason: 'settled' }),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `結案失敗（${resp.status}）`);
    }
    settleDialogOpen.value = false;
    rows.value = rows.value.filter(r => r.id !== row.id);
    showToast(`已結案：${row.student_name} ${row.subject}`);
  } catch (e) {
    showToast(e.message || '結案失敗，請重試', 'error');
  } finally {
    settleLoading.value = null;
  }
}

// ═══ Session Detail Modal ═══
const sessionDetailOpen = ref(false);
const sessionDetailRow = ref(null);
const sessionDetailList = ref([]);
const sessionDetailLoading = ref(false);

const SESSION_STATUS_LABELS = {
  attended:  '已上課',
  completed: '已上課',
  late:      '遲到',
  leave:     '請假',
  absent:    '未到',
  scheduled: '待上課',
  cancelled: '已取消',
};

function sessionStatusLabel(s) {
  return SESSION_STATUS_LABELS[s] || s;
}

function sessionRowClass(s) {
  if (['attended', 'completed', 'late'].includes(s)) return 'srow-attended';
  if (s === 'absent') return 'srow-absent';
  if (s === 'cancelled') return 'srow-cancelled';
  return '';
}

const sessionDetailAttended = computed(() =>
  sessionDetailList.value.filter(s => ['attended', 'completed', 'late'].includes(s.status)).length
);

async function openSessionDetail(row) {
  sessionDetailRow.value = row;
  sessionDetailList.value = [];
  sessionDetailLoading.value = true;
  sessionDetailOpen.value = true;
  try {
    const token = getToken();
    const params = new URLSearchParams({ student_class_id: String(row.id), per_page: '200' });
    const resp = await fetch(`/api/v1/class-sessions?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const data = await resp.json();
    const rows = (data.data ?? data) || [];
    sessionDetailList.value = [...rows].sort((a, b) => {
      const d = (b.session_date || '').localeCompare(a.session_date || '');
      return d !== 0 ? d : (b.start_time || '').localeCompare(a.start_time || '');
    });
  } catch {
    showToast('載入上課紀錄失敗，請重試', 'error');
    sessionDetailOpen.value = false;
  } finally {
    sessionDetailLoading.value = false;
  }
}

// ═══ Watchers ═══
watch(() => props.branchId, () => {
  refreshActiveTab();
}, { flush: 'post' });

watch(activeAccountingTab, (tab) => {
  if (tab === 'receivables') {
    if (!rows.value.length) loadAlerts();
  } else if (tab === 'settled') {
    loadSettledCourses();
  } else {
    loadAccountingPayments();
  }
}, { flush: 'post' });

loadAlerts();
</script>

<style scoped>
/* ─── Page ─── */
.tc-page {
  background: var(--card-bg);
  border-radius: 14px;
  padding: 24px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

/* ─── Header ─── */
.tc-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.tc-header h2 {
  margin: 0;
  font-size: 20px;
  letter-spacing: -0.01em;
}
.tc-subtitle {
  color: var(--text-light);
  font-size: 13px;
  margin-top: 2px;
}
.tc-refresh-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 7px 14px;
  border: 1px solid var(--border);
  background: var(--card-bg);
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: var(--text);
  transition: all 0.15s;
  font-family: inherit;
}
.tc-refresh-btn:hover:not(:disabled) {
  background: var(--bg);
  border-color: var(--primary-light);
  color: var(--primary);
}
.tc-refresh-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* ─── Accounting center tabs ─── */
.acct-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 18px;
  border-bottom: 1px solid var(--border);
  overflow-x: auto;
}
.acct-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  padding: 0 14px;
  border: 0;
  border-bottom: 3px solid transparent;
  background: transparent;
  color: var(--text-light);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.acct-tab:hover {
  color: var(--text);
  background: var(--bg);
}
.acct-tab.active {
  color: var(--primary);
  border-bottom-color: var(--primary);
  background: var(--primary-light, rgba(37,99,235,0.06));
}
.acct-panel {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.acct-filter-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--bg);
}
.acct-filter-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(120px, 1fr));
  gap: 10px;
}
.acct-filter-grid label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  font-size: 12px;
  font-weight: 700;
  color: var(--text-light);
}
.acct-filter-grid input,
.acct-filter-grid select {
  min-height: 38px;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 0 10px;
  background: var(--card-bg);
  color: var(--text);
  font: inherit;
}
.acct-filter-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  flex-wrap: wrap;
}
.acct-chip {
  display: inline-flex;
  align-items: center;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  margin-right: 4px;
}
.acct-chip--prepaid {
  background: #E0F2FE;
  color: #0369A1;
}
.acct-chip--backfill {
  background: #F3F4F6;
  color: #4B5563;
}
.acct-table .tc-btn {
  white-space: nowrap;
}

/* ─── Summary cards ─── */
.tc-summary {
  display: flex;
  gap: 10px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.tc-summary-note {
  margin: 0 0 18px;
  color: var(--text-light);
  font-size: 13px;
  line-height: 1.5;
}
.tc-card {
  display: flex;
  align-items: baseline;
  gap: 6px;
  padding: 10px 16px;
  border-radius: 10px;
  border: 1px solid var(--border);
}
.tc-card-num {
  font-size: 22px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}
.tc-card-label {
  font-size: 13px;
  color: var(--text-light);
}
.tc-card--total { background: var(--bg); }
.tc-card--total .tc-card-num { color: var(--text); }
.tc-card--danger { background: #FFF5F5; border-color: #FECACA; }
.tc-card--danger .tc-card-num { color: var(--danger); }
.tc-card--success { background: #F0FDF4; border-color: #BBF7D0; }
.tc-card--success .tc-card-num { color: #15803D; font-size: 18px; }
.tc-card--info { background: #EFF6FF; border-color: #BFDBFE; }
.tc-card--info .tc-card-num { color: #1D4ED8; font-size: 18px; }
.tc-card--warn { background: #FFFBEB; border-color: #FDE68A; }
.tc-card--warn .tc-card-num { color: #D97706; }
.tc-card--overdue { background: #FFF5F5; border-color: #FECACA; }
.tc-card--overdue .tc-card-num { color: var(--danger); }
.tc-card-sub { font-size: 12px; color: var(--danger); font-weight: 500; margin-left: 2px; }
.tc-card--outstanding { background: #FFF5F5; border-color: #FECACA; }
.tc-card--outstanding .tc-card-num { color: #DC2626; font-size: 18px; }
.tc-rate { display: block; font-size: 12px; font-weight: 600; margin-top: 2px; }

/* ─── Skeleton ─── */
.tc-card--skeleton {
  background: var(--bg);
  border-color: var(--border);
}
.skel {
  display: inline-block;
  background: linear-gradient(90deg, #e5e7eb 25%, #f3f4f6 50%, #e5e7eb 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s ease infinite;
  border-radius: 4px;
}
.skel-num { width: 36px; height: 24px; }
.skel-label { width: 48px; height: 14px; }
.skel-th { width: 56px; height: 12px; }
.skel-cell { height: 16px; display: block; }
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* ─── States ─── */
.tc-inline-loading {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: var(--text-light);
}
.spin { animation: rotate 1s linear infinite; }
@keyframes rotate { to { transform: rotate(360deg); } }

.tc-error {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--danger);
  padding: 14px 16px;
  background: #FFF5F5;
  border-radius: 10px;
  font-size: 14px;
}
.tc-empty {
  text-align: center;
  padding: 56px 0;
  color: var(--text-light);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}
.tc-empty p { margin: 0; font-size: 14px; }

.tc-cta-btn {
  padding: 8px 20px;
  border-radius: 8px;
  border: 1px solid var(--primary);
  background: var(--primary);
  color: #fff;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s;
}
.tc-cta-btn:hover { opacity: 0.9; }
.tc-cta-btn--ghost {
  background: transparent;
  color: var(--primary);
}
.tc-cta-btn--ghost:hover {
  background: rgba(37,99,235,0.05);
}

/* ─── Tabs ─── */
.tc-tabs {
  display: flex;
  gap: 0;
  margin-top: 16px;
  margin-bottom: 12px;
  border-bottom: 1px solid var(--border);
  overflow-x: auto;
  white-space: nowrap;
  -webkit-overflow-scrolling: touch;
}
.tc-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 16px;
  border: none;
  background: none;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-light);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.15s;
  font-family: inherit;
  min-height: 44px;
}
.tc-tab:hover { color: var(--text); background: var(--bg); }
.tc-tab--active {
  color: var(--primary);
  border-bottom-color: var(--primary);
  background: var(--primary-light, rgba(37,99,235,0.06));
}
.tc-tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 700;
  background: var(--bg);
  color: var(--text-light);
}
.tc-tab--active .tc-tab-badge { background: var(--primary); color: #fff; }

/* ─── Search toolbar ─── */
.tc-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.tc-search-wrap {
  position: relative;
  display: flex;
  align-items: center;
  width: 260px;
}
.tc-search-icon {
  position: absolute;
  left: 10px;
  font-size: 18px;
  color: var(--text-light);
  pointer-events: none;
}
.tc-search-input {
  width: 100%;
  padding: 8px 32px 8px 36px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: var(--bg);
  color: var(--text);
  transition: border-color 0.15s, box-shadow 0.15s;
  outline: none;
}
.tc-search-input:focus {
  border-color: var(--primary-light);
  box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
}
.tc-search-clear {
  position: absolute;
  right: 6px;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--text-light);
  display: flex;
  align-items: center;
  padding: 2px;
}
.tc-search-clear:hover { color: var(--text); }
.tc-search-hint {
  font-size: 13px;
  color: var(--text-light);
}

/* ─── Table ─── */
.tc-table-wrap {
  overflow-x: auto;
  margin: 0 -4px;
}
.tc-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
}
.tc-table thead tr {
  background: var(--bg);
}
.tc-table th {
  text-align: left;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.tc-table th:first-child { border-radius: 8px 0 0 0; }
.tc-table th:last-child { border-radius: 0 8px 0 0; }
.tc-table td {
  padding: 12px 12px;
  border-bottom: 1px solid var(--border);
  font-size: 14px;
  vertical-align: middle;
  white-space: nowrap;
}
.tc-table tbody tr {
  transition: background 0.1s;
}
.tc-table tbody tr:hover {
  background: rgba(0,0,0,0.015);
}
.tc-table tbody tr:last-child td {
  border-bottom: none;
}

.tc-th-sort {
  cursor: pointer;
  user-select: none;
  transition: background 0.15s;
}
.tc-th-sort:hover { background: var(--bg); }
.tc-sort-arrow {
  font-size: 10px;
  margin-left: 3px;
  transition: opacity 0.15s;
}

.tc-toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.tc-btn--csv {
  color: var(--text);
  font-weight: 500;
}
.tc-btn--csv:hover:not(:disabled) { background: var(--bg); border-color: var(--primary-light); color: var(--primary); }
.tc-btn--csv:disabled { opacity: 0.5; cursor: not-allowed; }

.tc-cell-name { font-weight: 500; }

.tc-col-mode { width: 60px; text-align: center; }
.tc-col-currency { width: 90px; text-align: right; font-variant-numeric: tabular-nums; font-size: 13px; }
.tc-col-date { white-space: nowrap; }
.tc-col-actions { width: 1%; white-space: nowrap; }

.row-paid { opacity: 0.55; }
.row-paid:hover { opacity: 0.75; }

.tc-outstanding-warn { color: #DC2626; font-weight: 600; }

/* ─── Tags ─── */
.mode-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}
.mode-tag.count { background: #DBEAFE; color: #1D4ED8; }
.mode-tag.date { background: #EDE9FE; color: #6D28D9; }

.status-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.st-unpaid { background: #FEE2E2; color: #DC2626; }
.st-partial { background: #FED7AA; color: #C2410C; }
.st-pending { background: #FED7AA; color: #C2410C; }
.st-pending::before {
  content: '';
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #C2410C;
  animation: blink 1.2s ease infinite;
}
@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
.st-paid { background: #DCFCE7; color: #16A34A; }
.st-renew { background: #DBEAFE; color: #1D4ED8; }
.st-monthly { background: #FEF3C7; color: #D97706; }

.overdue-tag {
  display: inline-block;
  margin-left: 4px;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  background: #FEE2E2;
  color: #DC2626;
}
.soon-tag {
  display: inline-block;
  margin-left: 4px;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  background: #FEF3C7;
  color: #D97706;
}

.text-light { color: var(--text-light); }
.paid-date { color: #16A34A; font-size: 13px; cursor: help; border-bottom: 1px dotted #16A34A; }

/* ─── Action buttons ─── */
.tc-actions {
  display: flex;
  gap: 6px;
  align-items: center;
}
.tc-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 10px;
  border: 1px solid var(--border);
  background: var(--card-bg);
  border-radius: 7px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  transition: all 0.15s;
  font-family: inherit;
  white-space: nowrap;
}
.tc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.tc-btn .material-symbols-outlined { font-size: 15px; }

.tc-btn--slip { color: var(--primary); }
.tc-btn--slip:hover:not(:disabled) { background: #EFF6FF; border-color: #93C5FD; }

.tc-btn--confirm { color: #16A34A; }
.tc-btn--confirm:hover:not(:disabled) { background: #F0FDF4; border-color: #86EFAC; }

.tc-btn--receipt { color: #15803D; }
.tc-btn--receipt:hover:not(:disabled) { background: #F0FDF4; border-color: #86EFAC; }

.tc-btn--ledger { color: #4338CA; }
.tc-btn--ledger:hover:not(:disabled) { background: #EEF2FF; border-color: #A5B4FC; }

.tc-btn--reject { color: #DC2626; }
.tc-btn--reject:hover:not(:disabled) { background: #FFF5F5; border-color: #FECACA; }

.tc-btn--void { color: #D97706; }
.tc-btn--void:hover:not(:disabled) { background: #FFFBEB; border-color: #FDE68A; }

.tc-btn--ghost {
  background: transparent;
  border-color: var(--border);
  color: var(--text);
}
.tc-btn--ghost:hover:not(:disabled) { background: var(--bg); }

.tc-btn--danger {
  background: #DC2626;
  border-color: #DC2626;
  color: #fff;
}
.tc-btn--danger:hover:not(:disabled) { background: #B91C1C; }
.tc-btn--danger:disabled { background: #FCA5A5; border-color: #FCA5A5; }

.tc-renew-hint {
  font-size: 12px;
  color: var(--text-light);
  margin-left: 2px;
}

.tc-newer-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  background: #DCFCE7;
  color: #16A34A;
  cursor: help;
  white-space: nowrap;
}

.tc-btn--settle { color: #6D28D9; }
.tc-btn--settle:hover:not(:disabled) { background: #F5F3FF; border-color: #C4B5FD; }

.tc-btn--primary {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
}
.tc-btn--primary:hover:not(:disabled) { opacity: 0.9; }
.tc-btn--primary:disabled { opacity: 0.5; }

.tc-settle-newer-info,
.tc-settle-warn-info {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
  margin-bottom: 16px;
  line-height: 1.5;
}
.tc-settle-newer-info {
  background: #F0FDF4;
  border: 1px solid #BBF7D0;
  color: #15803D;
}
.tc-settle-warn-info {
  background: #FFFBEB;
  border: 1px solid #FDE68A;
  color: #92400E;
}

/* ─── Void Dialog ─── */
.tc-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 16px;
}
.tc-dialog {
  background: var(--card-bg);
  border-radius: 14px;
  padding: 24px;
  width: 100%;
  max-width: 440px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.tc-dialog-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 8px;
  font-size: 16px;
}
.tc-dialog-desc {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--text-light);
  line-height: 1.5;
}
.tc-dialog-info {
  padding: 8px 12px;
  background: var(--bg);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 12px;
}
.tc-dialog-info small {
  display: block;
  margin-top: 4px;
  color: var(--text-light);
  font-weight: 400;
}
.tc-dialog-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 6px;
  color: var(--text);
}
.tc-dialog-textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  resize: vertical;
  min-height: 72px;
  outline: none;
  background: var(--bg);
  color: var(--text);
  box-sizing: border-box;
}
.tc-dialog-textarea:focus {
  border-color: var(--primary-light);
  box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
}
.tc-dialog-charcount {
  text-align: right;
  font-size: 11px;
  color: var(--text-light);
  margin-top: 4px;
  margin-bottom: 16px;
}
.tc-dialog-btns {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

/* ─── Sessions link ─── */
.tc-sessions-link {
  background: none;
  border: none;
  padding: 0;
  color: var(--primary);
  font-size: inherit;
  cursor: pointer;
  text-decoration: underline dotted;
  text-underline-offset: 3px;
}
.tc-sessions-link:hover { color: #1d4ed8; }

/* ─── Session Detail Dialog ─── */
.tc-dialog--wide { max-width: 620px; width: 95vw; max-height: 80vh; display: flex; flex-direction: column; }
.tc-dialog-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
.tc-dialog-close { background: none; border: none; cursor: pointer; color: var(--text-light); display: flex; align-items: center; padding: 2px; border-radius: 6px; }
.tc-dialog-close:hover { color: var(--text); background: var(--hover-bg, #f1f5f9); }
.tc-session-summary {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  background: var(--bg, #f8fafc);
  border-radius: 8px;
  font-size: 13px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.tc-ss-attended strong { color: #16a34a; }
.tc-ss-sep { color: var(--text-light); }
.tc-session-loading, .tc-session-empty {
  text-align: center;
  padding: 24px;
  color: var(--text-light);
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.tc-session-table-wrap { overflow-y: auto; flex: 1; border: 1px solid var(--border, #e2e8f0); border-radius: 8px; }
.tc-session-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.tc-session-table thead th {
  position: sticky;
  top: 0;
  background: var(--bg, #f8fafc);
  padding: 8px 10px;
  text-align: left;
  font-weight: 600;
  color: var(--text-light);
  border-bottom: 1px solid var(--border, #e2e8f0);
  font-size: 12px;
}
.tc-session-table tbody tr { border-bottom: 1px solid var(--border, #e2e8f0); }
.tc-session-table tbody tr:last-child { border-bottom: none; }
.tc-session-table td { padding: 7px 10px; }
.tc-session-time { white-space: nowrap; color: var(--text-light); font-size: 12px; }
.srow-cancelled td { opacity: 0.45; }
.srow-absent td { background: #fff5f5; }
.tc-session-status {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}
.ss-attended, .ss-completed { background: #dcfce7; color: #16a34a; }
.ss-late      { background: #fef9c3; color: #ca8a04; }
.ss-leave     { background: #e0f2fe; color: #0369a1; }
.ss-absent    { background: #fee2e2; color: #dc2626; }
.ss-scheduled { background: #f1f5f9; color: #64748b; }
.ss-cancelled { background: #f1f5f9; color: #94a3b8; }

/* ─── Toast ─── */
.tc-toast {
  position: fixed;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 24px;
  background: #1E293B;
  color: #fff;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 500;
  box-shadow: 0 8px 30px rgba(0,0,0,0.18);
  z-index: 9999;
}
.tc-toast--error { background: #DC2626; }
.tc-toast--warning { background: #D97706; }
.toast-enter-active { animation: toastIn 0.25s ease; }
.toast-leave-active { animation: toastOut 0.2s ease forwards; }
@keyframes toastIn { from { opacity: 0; transform: translateX(-50%) translateY(10px); } }
@keyframes toastOut { to { opacity: 0; transform: translateX(-50%) translateY(10px); } }

/* fade transition for dialog */
.fade-enter-active { transition: opacity 0.2s ease; }
.fade-leave-active { transition: opacity 0.15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* ─── Responsive ─── */
@media (max-width: 768px) {
  .tc-page { padding: 16px; }
  .tc-summary { gap: 8px; }
  .tc-card { padding: 8px 12px; }
  .tc-card-num { font-size: 18px; }
  .tc-card--outstanding .tc-card-num { font-size: 15px; }
  .tc-search-wrap { width: 100%; }
  .acct-filter-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
  .acct-tabs { white-space: nowrap; }
  .acct-filter-grid { grid-template-columns: 1fr; }
  .acct-filter-actions { justify-content: stretch; }
  .acct-filter-actions .tc-btn { flex: 1; justify-content: center; }
  .tc-tabs { overflow-x: auto; white-space: nowrap; }
  .tc-toolbar { flex-direction: column; align-items: stretch; }
  .tc-toolbar-right { margin-left: 0; justify-content: flex-end; }
}
</style>
