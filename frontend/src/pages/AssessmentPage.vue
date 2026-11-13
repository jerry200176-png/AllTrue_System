<template>
  <div class="assessment-page tw:mx-auto tw:flex tw:max-w-6xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:pb-24 tw:text-foreground tw:md:px-0 tw:md:pb-0" data-testid="assessment-page">
    <header class="tw:pb-2" data-guide="assessment-header">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
        <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
          <ClipboardCheck class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          學習檢測
        </h2>
        <Button type="button" :class="actionButton" @click="openCreate">
          <Plus class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          建立檢測
        </Button>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">建立檢測、記錄學生多次結果，與課後評量分開管理。</p>
    </header>

    <section class="tw:grid tw:grid-cols-2 tw:gap-3 tw:min-[721px]:grid-cols-3 tw:min-[1101px]:grid-cols-6" aria-label="檢測摘要" data-testid="assessment-summary">
      <Card v-for="metric in metrics" :key="metric.label" class="tw:gap-0 tw:py-4 tw:shadow-none">
        <CardContent class="tw:flex tw:flex-col tw:gap-1 tw:px-4">
          <span class="tw:text-tpl-caption tw:text-tpl-secondary">{{ metric.label }}</span>
          <span class="tw:text-tpl-num-sm tw:leading-none tw:font-bold tw:tabular-nums">{{ metric.value }}</span>
        </CardContent>
      </Card>
    </section>

    <Card class="tw:gap-4 tw:p-4 tw:shadow-none">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
        <div>
          <h3 class="tw:text-tpl-list tw:font-bold">檢測清單</h3>
          <p class="tw:text-tpl-body tw:text-tpl-secondary">目前分校：{{ branchId }}</p>
        </div>
        <Button ref="refreshButton" type="button" variant="outline" :class="actionButton" :aria-busy="loading ? 'true' : undefined" data-testid="assessment-refresh" @click="refresh">
          <LoaderCircle v-if="loading" class="tw:size-4 tw:animate-spin tw:motion-reduce:animate-none" :stroke-width="2" aria-hidden="true" />
          <RefreshCw v-else class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          重新整理
        </Button>
      </div>
      <div class="tw:flex tw:flex-col tw:gap-4" :aria-busy="loading ? 'true' : 'false'" data-testid="assessment-content">
        <div v-if="loading" class="tw:flex tw:flex-col tw:gap-3" role="status" aria-live="polite" data-testid="assessment-loading">
          <span class="tw:sr-only">載入中</span>
          <Skeleton v-for="n in 4" :key="n" class="tw:h-14 tw:w-full" />
        </div>
        <Alert v-else-if="error" tone="danger" title="無法載入檢測" class="tw:text-tpl-body">
          <p class="tw:text-[length:var(--tpl-text-body)] tw:[overflow-wrap:anywhere]">{{ error }}</p>
          <Button type="button" variant="outline" :class="[actionButton, 'tw:mt-3 tw:text-foreground']" @click="retry">重試</Button>
        </Alert>
        <div v-else-if="!assessments.length" class="tw:flex tw:flex-col tw:items-center tw:gap-2 tw:py-10 tw:text-center" data-testid="assessment-empty">
          <ClipboardCheck class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
          <h4 class="tw:text-tpl-list tw:font-bold">目前還沒有檢測</h4>
          <p class="tw:max-w-prose tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">先建立一份基準檢測，再發布後登錄學生結果。</p>
        </div>
        <template v-else>
          <Alert v-if="closeError" tone="danger" :title="`無法關閉檢測：${closeError.title}`" class="tw:text-tpl-body" data-testid="assessment-close-error">
            <p class="tw:text-[length:var(--tpl-text-body)] tw:[overflow-wrap:anywhere]">{{ closeError.message }}</p>
          </Alert>
          <div class="assessment-desktop-table tw:overflow-x-auto tw:rounded-xl tw:border tw:max-[641px]:hidden">
            <Table class="tw:text-[length:var(--tpl-text-body)] tw:[&_td]:text-[length:var(--tpl-text-body)] tw:[&_th]:text-[length:var(--tpl-text-caption)]">
              <TableCaption class="tw:sr-only">檢測清單</TableCaption>
              <TableHeader>
                <TableRow class="tw:hover:bg-transparent">
                  <TableHead scope="col" :class="headCell">檢測</TableHead>
                  <TableHead scope="col" :class="headCell">範圍</TableHead>
                  <TableHead scope="col" :class="headCell">日期</TableHead>
                  <TableHead scope="col" :class="headCell">狀態</TableHead>
                  <TableHead scope="col" :class="headCell">結果</TableHead>
                  <TableHead scope="col" :class="headCell">操作</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="assessment in assessments" :key="assessment.id">
                  <TableCell class="tw:px-4 tw:py-3 tw:whitespace-normal tw:[overflow-wrap:anywhere]">
                    <strong class="tw:block">{{ assessment.title }}</strong>
                    <small class="tw:mt-1 tw:block tw:text-tpl-secondary">{{ assessment.assessment_type }}</small>
                  </TableCell>
                  <TableCell class="tw:px-4 tw:py-3 tw:whitespace-normal tw:[overflow-wrap:anywhere]">{{ assessment.student_name || '分校共用' }}</TableCell>
                  <TableCell class="tw:px-4 tw:py-3 tw:tabular-nums">{{ assessment.scheduled_for || '未設定' }}</TableCell>
                  <TableCell class="tw:px-4 tw:py-3"><Badge variant="outline" :class="[pillBase, statusTone(assessment.status)]">{{ statusLabel(assessment.status) }}</Badge></TableCell>
                  <TableCell class="tw:px-4 tw:py-3 tw:tabular-nums">{{ assessment.result_count || 0 }}</TableCell>
                  <TableCell class="tw:px-4 tw:py-2">
                    <div class="tw:flex tw:flex-nowrap tw:items-center tw:gap-2">
                      <Button type="button" variant="outline" :class="rowButton" :aria-label="`查看結果：${assessment.title}`" @click="openAssessment(assessment)">結果</Button>
                      <Button v-if="assessment.status === 'draft'" type="button" :class="rowButton" :aria-label="`發布檢測：${assessment.title}`" @click="publish(assessment)">發布</Button>
                      <Button v-if="assessment.status === 'published' && isDirector" type="button" variant="outline" :class="rowButton" :aria-label="`關閉檢測：${assessment.title}`" @click="askClose(assessment)">關閉</Button>
                    </div>
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
          <ul class="assessment-mobile-list tw:flex tw:list-none tw:flex-col tw:gap-3 tw:min-[641px]:hidden" aria-label="檢測清單">
            <li v-for="assessment in assessments" :key="assessment.id">
              <Card class="assessment-mobile-card tw:gap-0 tw:py-4 tw:shadow-none">
                <CardContent class="tw:flex tw:flex-col tw:gap-3 tw:px-4">
                  <div class="tw:flex tw:items-start tw:justify-between tw:gap-3">
                    <div class="tw:min-w-0 tw:[overflow-wrap:anywhere]">
                      <strong class="tw:block tw:text-tpl-list">{{ assessment.title }}</strong>
                      <small class="tw:mt-1 tw:block tw:text-tpl-secondary">{{ assessment.assessment_type }}</small>
                    </div>
                    <Badge variant="outline" :class="[pillBase, statusTone(assessment.status), 'tw:shrink-0']">{{ statusLabel(assessment.status) }}</Badge>
                  </div>
                  <dl class="tw:grid tw:gap-2 tw:text-tpl-body">
                    <div class="tw:grid tw:grid-cols-[3.5rem_minmax(0,1fr)] tw:gap-2">
                      <dt class="tw:font-semibold tw:text-tpl-secondary">範圍</dt>
                      <dd class="tw:min-w-0 tw:[overflow-wrap:anywhere]">{{ assessment.student_name || '分校共用' }}</dd>
                    </div>
                    <div class="tw:grid tw:grid-cols-[3.5rem_minmax(0,1fr)] tw:gap-2">
                      <dt class="tw:font-semibold tw:text-tpl-secondary">日期</dt>
                      <dd class="tw:tabular-nums">{{ assessment.scheduled_for || '未設定' }}</dd>
                    </div>
                    <div class="tw:grid tw:grid-cols-[3.5rem_minmax(0,1fr)] tw:gap-2">
                      <dt class="tw:font-semibold tw:text-tpl-secondary">結果</dt>
                      <dd class="tw:tabular-nums">{{ assessment.result_count || 0 }} 筆</dd>
                    </div>
                  </dl>
                  <div class="tw:grid tw:grid-cols-[repeat(auto-fit,minmax(7.5rem,1fr))] tw:gap-2">
                    <Button type="button" variant="outline" :class="rowButton" :aria-label="`查看結果：${assessment.title}`" @click="openAssessment(assessment)">查看結果</Button>
                    <Button v-if="assessment.status === 'draft'" type="button" :class="rowButton" :aria-label="`發布檢測：${assessment.title}`" @click="publish(assessment)">發布</Button>
                    <Button v-if="assessment.status === 'published' && isDirector" type="button" variant="outline" :class="rowButton" :aria-label="`關閉檢測：${assessment.title}`" @click="askClose(assessment)">關閉</Button>
                  </div>
                </CardContent>
              </Card>
            </li>
          </ul>
        </template>
      </div>
    </Card>

    <!-- 建立檢測. The 建立 button stays enabled, so a missing name says why nothing was sent. -->
    <FormDialog
      :open="showCreate"
      title="建立學習檢測"
      description="先建立檢測定義，再發布後登錄學生結果。"
      submit-label="建立"
      :loading="saving"
      @update:open="showCreate = $event"
      @submit="onCreateSubmit"
    >
      <div class="tw:flex tw:flex-col tw:gap-4">
        <div class="tw:flex tw:flex-col tw:gap-2">
          <Label for="assessment-title" :class="fieldLabel">檢測名稱 <span class="tw:text-[color:var(--ds-danger-text)]">*</span></Label>
          <Input id="assessment-title" v-model="createForm.title" :class="fieldControl" maxlength="120" placeholder="例如：英文單字基準檢測" :aria-invalid="titleError ? 'true' : undefined" :aria-describedby="titleError ? 'assessment-title-error' : undefined" />
          <p v-if="titleError" id="assessment-title-error" class="tw:text-tpl-caption tw:text-[color:var(--ds-danger-text)]" role="alert">{{ titleError }}</p>
        </div>
        <div class="tw:flex tw:flex-col tw:gap-2">
          <Label for="assessment-course" :class="fieldLabel">課程範圍</Label>
          <select id="assessment-course" v-model="createForm.student_class_id" :class="nativeSelect">
            <option value="">{{ isTeacher ? '老師必須選擇課程' : '分校共用檢測' }}</option>
            <option v-for="item in classes" :key="item.id" :value="String(item.id)">{{ item.student_name }} · {{ item.subject || '未設定科目' }}</option>
          </select>
        </div>
        <div class="tw:grid tw:grid-cols-1 tw:gap-4 tw:min-[480px]:grid-cols-3">
          <div class="tw:flex tw:flex-col tw:gap-2">
            <Label for="assessment-type" :class="fieldLabel">類型</Label>
            <select id="assessment-type" v-model="createForm.assessment_type" :class="nativeSelect">
              <option value="baseline">基準</option>
              <option value="checkpoint">階段</option>
              <option value="remediation">補強後</option>
              <option value="other">其他</option>
            </select>
          </div>
          <div class="tw:flex tw:flex-col tw:gap-2">
            <Label for="assessment-max" :class="fieldLabel">滿分</Label>
            <Input id="assessment-max" v-model="createForm.max_score" :class="fieldControl" type="number" inputmode="decimal" step="any" />
          </div>
          <div class="tw:flex tw:flex-col tw:gap-2">
            <Label for="assessment-date" :class="fieldLabel">檢測日期</Label>
            <Input id="assessment-date" v-model="createForm.scheduled_for" :class="fieldControl" type="date" />
          </div>
        </div>
        <div class="tw:flex tw:flex-col tw:gap-2">
          <Label for="assessment-description" :class="fieldLabel">說明</Label>
          <Textarea id="assessment-description" v-model="createForm.description" :class="areaControl" maxlength="10000" :rows="3" placeholder="記錄檢測範圍或教學目的（選填）" />
        </div>
        <p v-if="formError" class="tw:text-tpl-body tw:font-semibold tw:text-[color:var(--ds-danger-text)] tw:[overflow-wrap:anywhere]" role="alert">{{ formError }}</p>
      </div>
    </FormDialog>

    <!-- 關閉檢測確認. The same question as before; a refusal shows in the list area. -->
    <ConfirmDialog
      :open="closeOpen"
      title="關閉檢測"
      :description="closeTarget ? '關閉後不能再修改檢測定義，確定要關閉嗎？' : ''"
      confirm-label="關閉檢測"
      destructive
      :loading="closing"
      @update:open="onCloseOpenChange"
      @confirm="confirmClose"
    />

    <!-- 檢測結果. Right-hand sheet on desktop, full screen on a phone. -->
    <DetailSheet
      :open="detailOpen"
      :title="selectedAssessment?.title || '檢測結果'"
      :description="selectedAssessment ? `${selectedAssessment.title} 的結果、補強與作答` : '檢測結果'"
      @update:open="onDetailOpenChange"
    >
      <div v-if="selectedAssessment" class="tw:flex tw:flex-col tw:gap-4 tw:text-tpl-body" data-testid="assessment-detail">
        <p class="tw:text-tpl-secondary">滿分 {{ selectedAssessment.max_score }} · {{ statusLabel(selectedAssessment.status) }}</p>
        <p v-if="resultError && selectedAssessment.status !== 'published'" class="tw:font-semibold tw:text-[color:var(--ds-danger-text)] tw:[overflow-wrap:anywhere]" role="alert">{{ resultError }}</p>
        <div v-if="resultsLoading" class="tw:flex tw:flex-col tw:gap-3" role="status" aria-live="polite" data-testid="assessment-detail-loading">
          <span class="tw:sr-only">結果載入中…</span>
          <Skeleton v-for="n in 3" :key="n" class="tw:h-14 tw:w-full" />
        </div>
        <template v-else>
          <section v-if="selectedAssessment.status === 'draft'" class="question-builder" :class="panelBox" aria-labelledby="assessment-builder-title">
            <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-2">
              <div>
                <h3 id="assessment-builder-title" class="tw:text-tpl-list tw:font-bold">配置檢測題目</h3>
                <p class="tw:text-tpl-secondary">只可選擇已核准題目；發布後會固定題目版本。</p>
              </div>
              <Button type="button" variant="outline" :class="rowButton" :disabled="questionLoading" :aria-busy="questionLoading ? 'true' : undefined" @click="loadQuestionCatalog">
                <LoaderCircle v-if="questionLoading" class="tw:size-4 tw:animate-spin tw:motion-reduce:animate-none" aria-hidden="true" />重新載入題庫
              </Button>
            </div>
            <p v-if="questionError" class="tw:mt-3 tw:font-semibold tw:text-[color:var(--ds-danger-text)] tw:[overflow-wrap:anywhere]" role="alert">{{ questionError }}</p>
            <p v-if="questionLoading" class="tw:py-4 tw:text-center tw:text-tpl-secondary" role="status">題庫載入中…</p>
            <p v-else-if="!questionCatalog.length" class="tw:py-4 tw:text-center tw:text-tpl-secondary">目前沒有可用的已核准題目，請先到題庫完成核准。</p>
            <ul v-else class="tw:my-3 tw:flex tw:max-h-64 tw:list-none tw:flex-col tw:gap-2 tw:overflow-y-auto" aria-label="已核准題目">
              <li v-for="question in questionCatalog" :key="question.id">
                <label :for="`assessment-question-${question.id}`" class="question-picker-row tw:flex tw:min-h-12 tw:cursor-pointer tw:items-start tw:gap-3 tw:rounded-lg tw:border tw:p-3">
                  <Checkbox :id="`assessment-question-${question.id}`" class="tw:mt-0.5 tw:size-6 tw:shrink-0" :model-value="selectedQuestionIds.includes(Number(question.id))" @update:model-value="toggleQuestion(question.id, $event)" />
                  <span class="tw:min-w-0 tw:[overflow-wrap:anywhere]"><strong class="tw:block">{{ question.prompt }}</strong><small class="tw:mt-1 tw:block tw:text-tpl-secondary">{{ question.bank_name }} · {{ question.knowledge_tag }} · 難度 {{ question.difficulty }}</small></span>
                </label>
              </li>
            </ul>
            <Button type="button" :class="rowButton" :disabled="!selectedQuestionIds.length || questionRows.length > 0 || savingQuestions" :aria-busy="savingQuestions ? 'true' : undefined" @click="configureQuestions">
              <LoaderCircle v-if="savingQuestions" class="tw:size-4 tw:animate-spin tw:motion-reduce:animate-none" aria-hidden="true" />{{ questionRows.length ? `已配置 ${questionRows.length} 題` : `配置 ${selectedQuestionIds.length} 題` }}
            </Button>
          </section>

          <p v-if="!results.length" class="tw:py-4 tw:text-center tw:text-tpl-secondary">尚未登錄結果。</p>
          <ul v-else class="tw:flex tw:list-none tw:flex-col tw:gap-2" aria-label="檢測結果清單">
            <li v-for="result in results" :key="result.id" class="tw:flex tw:flex-col tw:gap-2 tw:rounded-lg tw:border tw:p-3">
              <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-2">
                <div class="tw:min-w-0 tw:[overflow-wrap:anywhere]">
                  <strong class="tw:block">{{ result.student_name || result.student_id }}</strong>
                  <small class="tw:mt-1 tw:block tw:text-tpl-secondary">第 {{ result.attempt_no }} 次</small>
                </div>
                <Badge variant="outline" :class="[pillBase, statusTone(result.status)]">{{ result.status === 'reviewed' ? '已審核' : '待審' }}</Badge>
              </div>
              <p class="tw:tabular-nums">{{ result.score }}/{{ result.max_score }}（{{ result.percent }}%）</p>
              <div class="tw:flex tw:flex-wrap tw:gap-2">
                <Button type="button" variant="outline" :class="rowButton" :aria-label="`補強追蹤：${result.student_name || result.student_id}，${result.remediation_count || 0} 筆`" @click="openRemediation(result)">{{ result.remediation_count || 0 }} 筆</Button>
                <Button v-if="isDirector && result.status === 'submitted'" type="button" :class="rowButton" :aria-label="`審核結果：${result.student_name || result.student_id} 第 ${result.attempt_no} 次`" @click="reviewResult(result)">審核</Button>
              </div>
            </li>
          </ul>

          <section v-if="selectedAssessment.status === 'published' && questionRows.length" class="attempt-panel" :class="panelBox" aria-labelledby="assessment-attempt-title">
            <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-2">
              <div>
                <h3 id="assessment-attempt-title" class="tw:text-tpl-list tw:font-bold">數位作答</h3>
                <p class="tw:text-tpl-secondary">教職員代學生開啟作答；客觀題自動評分，簡答題送主任複核。</p>
              </div>
              <Badge variant="outline" :class="[pillBase, statusTone('published')]">{{ questionRows.length }} 題</Badge>
            </div>
            <div class="attempt-start tw:mt-3 tw:flex tw:flex-col tw:gap-2">
              <Label for="assessment-attempt-student" :class="fieldLabel">學生／課程</Label>
              <select id="assessment-attempt-student" v-model="attemptForm.student_class_id" :class="nativeSelect" @change="syncAttemptStudent">
                <option value="">請選擇</option>
                <option v-for="item in assessmentStudents" :key="item.student_class_id" :value="String(item.student_class_id)">{{ item.name }}</option>
              </select>
              <Button type="button" :class="[rowButton, 'tw:self-start']" :disabled="!attemptForm.student_class_id || attemptSaving" :aria-busy="attemptSaving ? 'true' : undefined" @click="startAttempt">
                <LoaderCircle v-if="attemptSaving" class="tw:size-4 tw:animate-spin tw:motion-reduce:animate-none" aria-hidden="true" />開始一次作答
              </Button>
            </div>
            <p v-if="attemptError" class="tw:mt-3 tw:font-semibold tw:text-[color:var(--ds-danger-text)] tw:[overflow-wrap:anywhere]" role="alert">{{ attemptError }}</p>
            <ul v-if="attempts.length" class="tw:my-3 tw:flex tw:list-none tw:flex-col tw:gap-2" aria-label="作答紀錄">
              <li v-for="attempt in attempts" :key="attempt.id" class="attempt-row tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:rounded-lg tw:border tw:p-3">
                <div class="tw:min-w-0 tw:[overflow-wrap:anywhere]">
                  <strong class="tw:block">{{ attempt.student_name || attempt.student_id }}</strong>
                  <small class="tw:mt-1 tw:block tw:text-tpl-secondary">第 {{ attempt.attempt_no }} 次 · {{ attemptStatusLabel(attempt.status) }}</small>
                </div>
                <span class="tw:tabular-nums">{{ attempt.score == null ? '尚未計分' : `${attempt.score}/${attempt.max_score}（${attempt.percent}%）` }}</span>
                <Button type="button" variant="outline" :class="rowButton" :aria-label="`${attempt.status === 'submitted' && isDirector ? '複核' : '檢視'}作答：${attempt.student_name || attempt.student_id} 第 ${attempt.attempt_no} 次`" @click="openAttempt(attempt.id)">{{ attempt.status === 'submitted' && isDirector ? '複核' : '檢視' }}</Button>
              </li>
            </ul>
            <p v-if="attemptLoading" class="tw:py-4 tw:text-center tw:text-tpl-secondary" role="status">作答資料載入中…</p>
            <div v-if="activeAttempt" class="attempt-editor tw:mt-3 tw:flex tw:flex-col tw:gap-3 tw:border-t tw:pt-3">
              <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                <h4 class="tw:text-tpl-list tw:font-bold">{{ activeAttempt.student_name || activeAttempt.student_id }} · 第 {{ activeAttempt.attempt_no }} 次</h4>
                <Badge variant="outline" :class="[pillBase, statusTone(activeAttempt.status)]">{{ attemptStatusLabel(activeAttempt.status) }}</Badge>
              </div>
              <fieldset v-for="question in activeAttempt.questions" :key="question.id" class="attempt-question tw:flex tw:min-w-0 tw:flex-col tw:gap-2 tw:border-b tw:pb-3">
                <legend class="tw:mb-1 tw:max-w-full tw:[overflow-wrap:anywhere]"><strong>{{ question.position }}. {{ question.prompt }}</strong><small class="tw:mt-1 tw:block tw:text-tpl-secondary">{{ question.knowledge_tag }} · 難度 {{ question.difficulty }}</small></legend>
                <div v-if="question.question_type === 'single_choice' || question.question_type === 'true_false'" class="tw:flex tw:flex-col tw:gap-2">
                  <label v-for="choice in (question.choices || (question.question_type === 'true_false' ? ['true', 'false'] : []))" :key="choice" :class="choiceRow"><input v-model="answerDraft[String(question.id)]" type="radio" :name="'q-' + question.id" :value="choice" :disabled="activeAttempt.status !== 'in_progress'" class="tw:size-5 tw:shrink-0" />{{ choice }}</label>
                </div>
                <div v-else-if="question.question_type === 'multiple_choice'" class="tw:flex tw:flex-col tw:gap-2">
                  <label v-for="choice in (question.choices || [])" :key="choice" :class="choiceRow"><input v-model="answerDraft[String(question.id)]" type="checkbox" :value="choice" :disabled="activeAttempt.status !== 'in_progress'" class="tw:size-5 tw:shrink-0" />{{ choice }}</label>
                </div>
                <Textarea v-else v-model="answerDraft[String(question.id)]" :class="areaControl" :rows="2" :disabled="activeAttempt.status !== 'in_progress'" :aria-label="`第 ${question.position} 題答案`" placeholder="填寫學生答案" />
              </fieldset>
              <div v-if="activeAttempt.status === 'in_progress'" class="tw:flex tw:flex-wrap tw:justify-end tw:gap-2">
                <Button type="button" variant="outline" :class="rowButton" :disabled="attemptSaving" @click="saveAttempt(false)">儲存草稿</Button>
                <Button type="button" :class="rowButton" :disabled="attemptSaving" :aria-busy="attemptSaving ? 'true' : undefined" @click="saveAttempt(true)">
                  <LoaderCircle v-if="attemptSaving" class="tw:size-4 tw:animate-spin tw:motion-reduce:animate-none" aria-hidden="true" />送出作答
                </Button>
              </div>
              <div v-if="isDirector && activeAttempt.status === 'submitted'" class="review-editor tw:flex tw:flex-col tw:gap-3 tw:rounded-lg tw:border tw:border-[var(--ds-warning)] tw:bg-[var(--ds-warning-wash)] tw:p-3">
                <h4 class="tw:text-tpl-list tw:font-bold">簡答人工複核</h4>
                <div v-for="answer in activeAttempt.answers.filter((row) => row.status === 'needs_review')" :key="answer.id" class="review-row tw:flex tw:flex-col tw:gap-2 tw:border-b tw:pb-3">
                  <div class="tw:min-w-0 tw:[overflow-wrap:anywhere]"><strong class="tw:block">{{ answer.position }}. {{ answer.prompt }}</strong><small class="tw:mt-1 tw:block">學生答案：{{ answer.answer || '未作答' }}</small></div>
                  <Label :for="`assessment-review-${answer.id}`" class="tw:sr-only">{{ answer.position }}. 分數</Label>
                  <Input :id="`assessment-review-${answer.id}`" v-model="reviewScores[answer.id]" :class="fieldControl" type="number" inputmode="decimal" min="0" :max="answer.max_score" step="0.01" placeholder="分數" />
                </div>
                <Button type="button" :class="[rowButton, 'tw:self-start']" :disabled="attemptSaving" :aria-busy="attemptSaving ? 'true' : undefined" @click="reviewAttempt">
                  <LoaderCircle v-if="attemptSaving" class="tw:size-4 tw:animate-spin tw:motion-reduce:animate-none" aria-hidden="true" />完成人工複核
                </Button>
              </div>
            </div>
          </section>
          <p v-else-if="selectedAssessment.status === 'published'" class="tw:py-4 tw:text-center tw:text-tpl-secondary">尚未配置題目；此檢測仍可使用下方的紙本結果登錄。</p>

          <section v-if="selectedResult" class="remediation-panel" :class="panelBox" aria-labelledby="assessment-remediation-title">
            <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-2">
              <div>
                <h3 id="assessment-remediation-title" class="tw:text-tpl-list tw:font-bold">補強追蹤：{{ selectedResult.student_name || selectedResult.student_id }}</h3>
                <p class="tw:text-tpl-secondary">從檢測結果建立知識缺口與後續行動。</p>
              </div>
              <Button type="button" variant="outline" :class="rowButton" @click="selectedResult = null">收合</Button>
            </div>
            <p v-if="remediationLoading" class="tw:py-4 tw:text-center tw:text-tpl-secondary" role="status">補強資料載入中…</p>
            <template v-else>
              <p v-if="remediationError" class="tw:mt-3 tw:font-semibold tw:text-[color:var(--ds-danger-text)] tw:[overflow-wrap:anywhere]" role="alert">{{ remediationError }}</p>
              <p v-if="!remediationActions.length" class="tw:py-4 tw:text-center tw:text-tpl-secondary">尚未建立補強行動。</p>
              <ul v-else class="tw:my-3 tw:flex tw:list-none tw:flex-col tw:gap-2" aria-label="補強行動">
                <li v-for="action in remediationActions" :key="action.id" class="remediation-row tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:border-b tw:pb-2">
                  <div class="tw:min-w-0 tw:[overflow-wrap:anywhere]">
                    <strong class="tw:block">{{ action.knowledge_tag }}</strong>
                    <small class="tw:mt-1 tw:block tw:text-tpl-secondary">{{ action.plan || '未填寫計畫' }}<span v-if="action.due_date"> · 到期 {{ action.due_date }}</span></small>
                  </div>
                  <Badge variant="outline" :class="[pillBase, statusTone(action.status)]">{{ remediationStatusLabel(action.status) }}</Badge>
                  <Button v-if="action.status === 'open'" type="button" variant="outline" :class="rowButton" :aria-label="`開始補強：${action.knowledge_tag}`" @click="updateRemediation(action, 'in_progress')">開始</Button>
                  <Button v-if="action.status === 'in_progress'" type="button" :class="rowButton" :aria-label="`完成補強：${action.knowledge_tag}`" @click="updateRemediation(action, 'completed')">完成</Button>
                </li>
              </ul>
              <div class="remediation-form tw:mt-3 tw:flex tw:flex-col tw:gap-3">
                <div class="tw:flex tw:flex-col tw:gap-2">
                  <Label for="assessment-remediation-tag" :class="fieldLabel">知識缺口</Label>
                  <Input id="assessment-remediation-tag" v-model="remediationForm.knowledge_tag" :class="fieldControl" maxlength="120" placeholder="例如：英文／過去式" />
                </div>
                <div class="tw:flex tw:flex-col tw:gap-2">
                  <Label for="assessment-remediation-type" :class="fieldLabel">行動類型</Label>
                  <select id="assessment-remediation-type" v-model="remediationForm.action_type" :class="nativeSelect">
                    <option value="practice">練習</option>
                    <option value="retake">重測</option>
                    <option value="teacher_followup">老師追蹤</option>
                    <option value="other">其他</option>
                  </select>
                </div>
                <div class="tw:flex tw:flex-col tw:gap-2">
                  <Label for="assessment-remediation-due" :class="fieldLabel">預計完成</Label>
                  <Input id="assessment-remediation-due" v-model="remediationForm.due_date" :class="fieldControl" type="date" />
                </div>
                <div class="tw:flex tw:flex-col tw:gap-2">
                  <Label for="assessment-remediation-plan" :class="fieldLabel">補強計畫</Label>
                  <Textarea id="assessment-remediation-plan" v-model="remediationForm.plan" :class="areaControl" :rows="2" maxlength="10000" placeholder="描述學生下一步要完成的練習或教學安排" />
                </div>
                <Button type="button" :class="[rowButton, 'tw:self-start']" :disabled="!remediationForm.knowledge_tag.trim() || savingRemediation" :aria-busy="savingRemediation ? 'true' : undefined" @click="createRemediation">
                  <LoaderCircle v-if="savingRemediation" class="tw:size-4 tw:animate-spin tw:motion-reduce:animate-none" aria-hidden="true" />建立補強行動
                </Button>
              </div>
            </template>
          </section>

          <section v-if="selectedAssessment.status === 'published'" class="result-entry tw:flex tw:flex-col tw:gap-3 tw:border-t tw:pt-4" aria-labelledby="assessment-entry-title">
            <h3 id="assessment-entry-title" class="tw:text-tpl-list tw:font-bold">登錄學生結果</h3>
            <div class="tw:flex tw:flex-col tw:gap-2">
              <Label for="assessment-result-student" :class="fieldLabel">學生／課程</Label>
              <select id="assessment-result-student" v-model="resultForm.student_class_id" :class="nativeSelect" @change="syncStudent">
                <option value="">請選擇</option>
                <option v-for="item in assessmentStudents" :key="item.student_class_id" :value="String(item.student_class_id)">{{ item.name }}</option>
              </select>
            </div>
            <div class="tw:flex tw:flex-col tw:gap-2">
              <Label for="assessment-result-score" :class="fieldLabel">分數</Label>
              <Input id="assessment-result-score" v-model="resultForm.score" :class="fieldControl" type="number" inputmode="decimal" min="0" :max="selectedAssessment.max_score" step="0.01" />
            </div>
            <div class="tw:flex tw:flex-col tw:gap-2">
              <Label for="assessment-result-notes" :class="fieldLabel">備註</Label>
              <Textarea id="assessment-result-notes" v-model="resultForm.notes" :class="areaControl" :rows="2" maxlength="10000" />
            </div>
            <p v-if="resultError" class="tw:font-semibold tw:text-[color:var(--ds-danger-text)] tw:[overflow-wrap:anywhere]" role="alert">{{ resultError }}</p>
            <Button type="button" :class="[rowButton, 'tw:self-start']" :disabled="!resultForm.student_id || String(resultForm.score) === '' || savingResult" :aria-busy="savingResult ? 'true' : undefined" @click="saveResult">
              <LoaderCircle v-if="savingResult" class="tw:size-4 tw:animate-spin tw:motion-reduce:animate-none" aria-hidden="true" />儲存結果
            </Button>
          </section>
        </template>
      </div>
    </DetailSheet>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { ClipboardCheck, LoaderCircle, Plus, RefreshCw } from 'lucide-vue-next';
import { ConfirmDialog, DetailSheet, FormDialog } from '@/components/app-overlay';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { isDirectorRole } from '../lib/roleCapabilities.js';
import { answerMapFromAttempt, attemptStatusLabel, buildAnswerPayload } from '../lib/assessmentRunner.js';

const props = defineProps({ branchId: [String, Number], userRole: String });
const base = `${import.meta.env.VITE_API_BASE || '/api'}/v1`;
const isTeacher = computed(() => props.userRole === 'teacher');
const isDirector = computed(() => isDirectorRole(props.userRole));
const assessments = ref([]);
const classes = ref([]);
const summary = reactive({ assessment_count: 0, result_count: 0, average_percent: null, reviewed_count: 0, remediation_open_count: 0, remediation_overdue_count: 0, remediation_completed_count: 0 });
const loading = ref(false);
const error = ref('');
const saving = ref(false);
const showCreate = ref(false);
const formError = ref('');
const titleError = ref('');
const refreshButton = ref(null);
const closeTarget = ref(null);
const closeOpen = ref(false);
const closing = ref(false);
const closeError = ref(null);
const selectedAssessment = ref(null);
const detailOpen = ref(false);
const results = ref([]);
const assessmentStudents = ref([]);
const resultsLoading = ref(false);
const savingResult = ref(false);
const resultError = ref('');
const selectedResult = ref(null);
const remediationActions = ref([]);
const remediationLoading = ref(false);
const remediationError = ref('');
const savingRemediation = ref(false);
const createForm = reactive({ title: '', description: '', assessment_type: 'checkpoint', max_score: 100, scheduled_for: '', student_class_id: '' });
const resultForm = reactive({ student_id: '', student_class_id: '', score: '', notes: '' });
const remediationForm = reactive({ knowledge_tag: '', action_type: 'practice', plan: '', due_date: '' });
const questionRows = ref([]);
const questionCatalog = ref([]);
const selectedQuestionIds = ref([]);
const questionLoading = ref(false);
const questionError = ref('');
const savingQuestions = ref(false);
const attempts = ref([]);
const attemptForm = reactive({ student_class_id: '' });
const activeAttempt = ref(null);
const answerDraft = reactive({});
const attemptLoading = ref(false);
const attemptSaving = ref(false);
const attemptError = ref('');
const reviewScores = reactive({});

// Presentation only. Controls are 48px tall; table heads override the global `th` rule.
const headCell = 'tw:h-12 tw:px-4 tw:font-semibold tw:normal-case tw:text-tpl-secondary';
const actionButton = 'tw:h-12 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const rowButton = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const pillBase = 'tw:border-transparent tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)] tw:font-bold';
const fieldLabel = 'tw:text-tpl-body tw:font-semibold';
const fieldControl = 'tw:h-12 tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]';
const areaControl = 'tw:min-h-24 tw:resize-y tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]';
const nativeSelect = 'tw:h-12 tw:w-full tw:rounded-md tw:border tw:border-input tw:bg-background tw:px-3 tw:text-[length:var(--tpl-text-body)] tw:text-foreground tw:outline-none tw:focus-visible:border-ring tw:focus-visible:ring-[3px] tw:focus-visible:ring-ring/50 tw:disabled:cursor-not-allowed tw:disabled:bg-secondary';
const panelBox = 'tw:rounded-xl tw:border tw:p-4';
const choiceRow = 'tw:flex tw:min-h-12 tw:cursor-pointer tw:items-center tw:gap-3';
const TONES = {
  success: 'tw:bg-[var(--ds-success-wash)] tw:text-[color:var(--ds-success-text)]',
  warning: 'tw:bg-[var(--ds-warning-wash)] tw:text-[color:var(--ds-warning-text)]',
  info: 'tw:bg-[var(--ds-info-wash)] tw:text-[color:var(--ds-info)]',
  quiet: 'tw:bg-secondary tw:text-tpl-secondary',
};
function statusTone(status) {
  if (['published', 'completed', 'reviewed'].includes(status)) return TONES.success;
  if (['draft', 'open', 'submitted'].includes(status)) return TONES.warning;
  if (status === 'in_progress') return TONES.info;
  return TONES.quiet;
}
const metrics = computed(() => [
  { label: '檢測數', value: summary.assessment_count },
  { label: '結果筆數', value: summary.result_count },
  { label: '平均分數', value: summary.average_percent == null ? '—' : `${summary.average_percent}%` },
  { label: '已審核結果', value: summary.reviewed_count },
  { label: '待補強', value: summary.remediation_open_count },
  { label: '逾期補強', value: summary.remediation_overdue_count },
]);

function token() {
  try { return JSON.parse(localStorage.getItem('alltrue_session') || '{}')?.access_token || ''; } catch { return ''; }
}
function headers(json = false) { return { Authorization: `Bearer ${token()}`, Accept: 'application/json', ...(json ? { 'Content-Type': 'application/json' } : {}) }; }
async function api(path, options = {}) {
  const response = await fetch(`${base}${path}`, { ...options, headers: { ...headers(Boolean(options.body)), ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body?.message || Object.values(body?.errors || {})?.flat?.()?.[0] || '操作失敗');
  return body;
}
function statusLabel(status) { return { draft: '草稿', published: '已發布', closed: '已關閉', archived: '已封存' }[status] || status; }
function remediationStatusLabel(status) { return { open: '待處理', in_progress: '進行中', completed: '已完成', cancelled: '已取消' }[status] || status; }
async function loadAll() {
  if (!props.branchId) return;
  loading.value = true; error.value = ''; closeError.value = null;
  try {
    const [list, stats, optionRows] = await Promise.all([
      api(`/assessments?campus_id=${encodeURIComponent(props.branchId)}&per_page=100`),
      api(`/assessment-reports/summary?campus_id=${encodeURIComponent(props.branchId)}`),
      api(`/assessment-options/classes?campus_id=${encodeURIComponent(props.branchId)}`),
    ]);
    assessments.value = list.data || [];
    Object.assign(summary, stats.data || {});
    classes.value = optionRows.data || [];
  } catch (e) { error.value = e.message; } finally { loading.value = false; }
}
// A second press while a read is running is ignored (the old button was disabled while loading).
function refresh() { if (!loading.value) loadAll(); }
// 重試 sits in the error alert, which goes away while loading; keyboard focus moves to 重新整理 instead of being dropped.
function retry() {
  refreshButton.value?.$el?.focus?.();
  loadAll();
}

function openCreate() {
  formError.value = ''; titleError.value = '';
  Object.assign(createForm, { title: '', description: '', assessment_type: 'checkpoint', max_score: 100, scheduled_for: '', student_class_id: '' });
  showCreate.value = true;
}
// A field's reason goes away as soon as that field is edited.
watch(() => createForm.title, () => { titleError.value = ''; });
async function onCreateSubmit() {
  if (saving.value) return;
  // The 建立 button is always enabled: a missing name says why next to the field and calls no API.
  if (!String(createForm.title ?? '').trim()) {
    titleError.value = '請輸入檢測名稱';
    document.getElementById('assessment-title')?.focus();
    return;
  }
  await createAssessment();
}
async function createAssessment() {
  saving.value = true; formError.value = '';
  try {
    const body = { campus_id: Number(props.branchId), title: createForm.title.trim(), description: String(createForm.description ?? '').trim() || null, assessment_type: createForm.assessment_type, max_score: Number(createForm.max_score), scheduled_for: createForm.scheduled_for || null, student_class_id: createForm.student_class_id ? Number(createForm.student_class_id) : null };
    await api('/assessments', { method: 'POST', body: JSON.stringify(body) }); showCreate.value = false; await loadAll();
  } catch (e) { formError.value = e.message; } finally { saving.value = false; }
}
async function publish(assessment) { try { await api(`/assessments/${assessment.id}/publish`, { method: 'POST' }); await loadAll(); } catch (e) { error.value = e.message; } }
function askClose(assessment) { closeTarget.value = assessment; closeError.value = null; closeOpen.value = true; }
// The 關閉檢測 button emits both a close request and `confirm` in the same click (order is the library's),
// so the close is decided one tick later: while the request runs the dialog stays open.
async function onCloseOpenChange(open) {
  await nextTick();
  if (closing.value) return;
  closeOpen.value = open;
}
async function confirmClose() {
  // The target is kept after closing, so a click that lands during the close animation must not send again.
  if (closing.value || !closeOpen.value || !closeTarget.value) return;
  closing.value = true;
  const assessment = closeTarget.value;
  try { await api(`/assessments/${assessment.id}/close`, { method: 'POST' }); closeOpen.value = false; await loadAll(); } catch (e) { closeOpen.value = false; closeError.value = { title: assessment.title, message: e.message }; } finally { closing.value = false; }
}
function onDetailOpenChange(open) { detailOpen.value = open; }
async function openAssessment(assessment) {
  selectedResult.value = null; activeAttempt.value = null; attemptError.value = ''; questionError.value = '';
  selectedAssessment.value = assessment; detailOpen.value = true; resultsLoading.value = true; resultError.value = ''; Object.assign(resultForm, { student_id: '', student_class_id: '', score: '', notes: '' });
  Object.assign(attemptForm, { student_class_id: '' });
  try {
    const [resultResponse, studentResponse, questionResponse, attemptResponse] = await Promise.all([
      api(`/assessments/${assessment.id}/results`),
      api(`/assessments/${assessment.id}/students`),
      api(`/assessments/${assessment.id}/questions`),
      assessment.status === 'published' ? api(`/assessments/${assessment.id}/attempts`) : Promise.resolve({ data: [] }),
    ]);
    results.value = resultResponse.data || []; assessmentStudents.value = studentResponse.data || [];
    questionRows.value = questionResponse.data || []; attempts.value = attemptResponse.data || [];
    if (assessment.status === 'draft') await loadQuestionCatalog();
  } catch (e) { resultError.value = e.message; } finally { resultsLoading.value = false; }
}
function syncStudent() { const row = assessmentStudents.value.find((item) => String(item.student_class_id) === String(resultForm.student_class_id)); resultForm.student_id = row ? String(row.student_id) : ''; }
async function saveResult() {
  savingResult.value = true; resultError.value = '';
  try { await api(`/assessments/${selectedAssessment.value.id}/results`, { method: 'POST', body: JSON.stringify({ student_id: Number(resultForm.student_id), student_class_id: Number(resultForm.student_class_id), score: Number(resultForm.score), notes: String(resultForm.notes ?? '').trim() || null }) }); await openAssessment(selectedAssessment.value); await loadAll(); } catch (e) { resultError.value = e.message; } finally { savingResult.value = false; }
}
async function reviewResult(result) { try { await api(`/assessment-results/${result.id}/review`, { method: 'POST' }); await openAssessment(selectedAssessment.value); await loadAll(); } catch (e) { resultError.value = e.message; } }
async function openRemediation(result) {
  selectedResult.value = result; remediationLoading.value = true; remediationError.value = '';
  Object.assign(remediationForm, { knowledge_tag: '', action_type: 'practice', plan: '', due_date: '' });
  try { const response = await api('/assessment-results/' + result.id + '/remediation-actions'); remediationActions.value = response.data || []; } catch (e) { remediationError.value = e.message; } finally { remediationLoading.value = false; }
}
async function createRemediation() {
  savingRemediation.value = true; remediationError.value = '';
  try {
    const body = { knowledge_tag: remediationForm.knowledge_tag.trim(), action_type: remediationForm.action_type, plan: String(remediationForm.plan ?? '').trim() || null, due_date: remediationForm.due_date || null };
    await api('/assessment-results/' + selectedResult.value.id + '/remediation-actions', { method: 'POST', body: JSON.stringify(body) });
    await openRemediation(selectedResult.value); await loadAll();
  } catch (e) { remediationError.value = e.message; } finally { savingRemediation.value = false; }
}
async function updateRemediation(action, status) {
  remediationError.value = '';
  try { await api('/assessment-remediation-actions/' + action.id, { method: 'PATCH', body: JSON.stringify({ status }) }); await openRemediation(selectedResult.value); await loadAll(); } catch (e) { remediationError.value = e.message; }
}
async function loadQuestionCatalog() {
  questionLoading.value = true; questionError.value = '';
  try {
    const banks = await api(`/question-banks?campus_id=${encodeURIComponent(props.branchId)}`);
    const rows = await Promise.all((banks.data || []).map(async (bank) => {
      const response = await api(`/question-banks/${bank.id}/items?status=approved`);
      return (response.data || []).map((item) => ({ ...item, bank_name: bank.name }));
    }));
    const latestByKey = new Map();
    rows.flat().forEach((item) => {
      const key = item.question_key || `item-${item.id}`;
      const current = latestByKey.get(key);
      if (!current || Number(item.version_no || 0) > Number(current.version_no || 0)) latestByKey.set(key, item);
    });
    questionCatalog.value = [...latestByKey.values()];
    selectedQuestionIds.value = questionRows.value.map((question) => Number(question.question_bank_item_id));
  } catch (e) { questionError.value = e.message; } finally { questionLoading.value = false; }
}
function toggleQuestion(id, checked) {
  const value = Number(id);
  const without = selectedQuestionIds.value.filter((item) => item !== value);
  selectedQuestionIds.value = checked === true ? [...without, value] : without;
}
async function configureQuestions() {
  if (!selectedAssessment.value || !selectedQuestionIds.value.length) return;
  savingQuestions.value = true; questionError.value = '';
  try {
    const response = await api(`/assessments/${selectedAssessment.value.id}/questions`, { method: 'POST', body: JSON.stringify({ question_bank_item_ids: selectedQuestionIds.value }) });
    questionRows.value = response.data || []; await loadAll();
  } catch (e) { questionError.value = e.message; } finally { savingQuestions.value = false; }
}
function syncAttemptStudent() { attemptError.value = ''; }
async function startAttempt() {
  const row = assessmentStudents.value.find((item) => String(item.student_class_id) === String(attemptForm.student_class_id));
  if (!row) return;
  attemptSaving.value = true; attemptError.value = '';
  try {
    const response = await api(`/assessments/${selectedAssessment.value.id}/attempts`, { method: 'POST', body: JSON.stringify({ student_id: Number(row.student_id), student_class_id: Number(row.student_class_id) }) });
    await openAttempt(response.data.id);
    attempts.value = [{ ...response.data }, ...attempts.value];
  } catch (e) { attemptError.value = e.message; } finally { attemptSaving.value = false; }
}
async function openAttempt(attemptId) {
  attemptLoading.value = true; attemptError.value = '';
  try {
    const response = await api(`/assessment-attempts/${attemptId}`); activeAttempt.value = response.data; Object.assign(answerDraft, answerMapFromAttempt(response.data));
    Object.keys(reviewScores).forEach((key) => delete reviewScores[key]);
    (response.data.answers || []).forEach((answer) => { if (answer.status === 'needs_review') reviewScores[answer.id] = answer.score ?? ''; });
  } catch (e) { attemptError.value = e.message; } finally { attemptLoading.value = false; }
}
async function saveAttempt(submit = false) {
  if (!activeAttempt.value) return;
  attemptSaving.value = true; attemptError.value = '';
  try {
    const saved = await api(`/assessment-attempts/${activeAttempt.value.id}/answers`, { method: 'PATCH', body: JSON.stringify({ answers: buildAnswerPayload(activeAttempt.value.questions, answerDraft) }) });
    activeAttempt.value = saved.data;
    if (submit) { const submitted = await api(`/assessment-attempts/${activeAttempt.value.id}/submit`, { method: 'POST' }); activeAttempt.value = submitted.data; await refreshAttempts(); }
  } catch (e) { attemptError.value = e.message; } finally { attemptSaving.value = false; }
}
async function refreshAttempts() { const response = await api(`/assessments/${selectedAssessment.value.id}/attempts`); attempts.value = response.data || []; }
async function reviewAttempt() {
  if (!activeAttempt.value) return;
  attemptSaving.value = true; attemptError.value = '';
  try {
    const reviews = (activeAttempt.value.answers || []).filter((answer) => answer.status === 'needs_review').map((answer) => ({ answer_id: answer.id, score: Number(reviewScores[answer.id] ?? 0), review_note: answer.review_note || null }));
    const response = await api(`/assessment-attempts/${activeAttempt.value.id}/review`, { method: 'POST', body: JSON.stringify({ reviews }) });
    activeAttempt.value = response.data; await refreshAttempts();
  } catch (e) { attemptError.value = e.message; } finally { attemptSaving.value = false; }
}
watch(() => props.branchId, loadAll);
onMounted(loadAll);
</script>
