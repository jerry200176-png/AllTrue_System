<script setup>
// #4970 step3a: one student profile built from existing, authorized records only.
import { computed, ref, watch } from 'vue';
import { authedFetch } from '../../lib/authedFetch';
import { ymdInTaipei } from '../../lib/sessionDates';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../ui/tabs';
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
const props = defineProps({ show: Boolean, studentId: { type: [Number, String], default: null }, studentName: { type: String, default: '' } });
const emit = defineEmits(['close']);
const items = ref([]), subject = ref(''), from = ref(''), until = ref(''), tab = ref('overview');
const loading = ref(false), error = ref(''), schoolError = ref(false), schoolLoading = ref(false), nextExam = ref(null);
const coverage = ref({ limit_per_source: 300, capped_sources: [] });
const subjects = computed(() => [...new Set(items.value.map(i => i.subject).filter(Boolean))]);
const visible = computed(() => items.value.filter(i => (!subject.value || i.subject === subject.value)
  && (!from.value || i.date >= from.value) && (!until.value || i.date <= until.value)));
const history = computed(() => visible.value.filter(i => ['evaluation', 'attendance'].includes(i.type)));
const scores = computed(() => visible.value.filter(i => ['assessment', 'exam_score'].includes(i.type)));
const latest = computed(() => history.value.find(i => i.session_status !== 'cancelled' && (i.progress || i.content)));
const STATUS = { approved: '已核准', pending: '待審', submitted: '已送出', reviewed: '已覆核', rejected: '已退回', changes_requested: '待修改' };
const scoreText = i => i.is_absent ? '缺考' : i.score == null ? '未登記' : `${i.score}/${i.max_score}`;
let seq = 0;
let opener;
function restoreFocus(event) { event.preventDefault(); if (opener?.isConnected) opener.focus(); }
async function load() {
  const mine = ++seq, id = props.studentId;
  loading.value = true; error.value = ''; schoolError.value = false; schoolLoading.value = false;
  items.value = []; nextExam.value = null; subject.value = ''; from.value = ''; until.value = ''; tab.value = 'overview';
  try {
    const res = await authedFetch(`/api/v1/students/${id}/learning-timeline`, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('refused');
    const body = await res.json();
    if (!Array.isArray(body.items)) throw new Error('invalid');
    if (mine !== seq) return;
    items.value = body.items;
    coverage.value = body.coverage || { limit_per_source: 300, capped_sources: [] };
    loading.value = false; schoolLoading.value = true;
    // Only read secondary school dates after this student's primary permission succeeds.
    try {
      const school = await authedFetch(`/api/v1/students/${id}/exam-periods`, { headers: { Accept: 'application/json' } });
      if (!school.ok) throw new Error('school dates unavailable');
      const data = await school.json();
      if (!Array.isArray(data.data)) throw new Error('invalid school dates');
      if (mine === seq) nextExam.value = data.data.filter(p => p.end_date >= ymdInTaipei(new Date()))
        .sort((a, b) => a.start_date.localeCompare(b.start_date))[0] || null;
    } catch { if (mine === seq) schoolError.value = true; }
  } catch { if (mine === seq) error.value = '載入失敗，請稍後再試'; }
  finally { if (mine === seq) { loading.value = false; schoolLoading.value = false; } }
}
watch(() => [props.show, props.studentId], () => { if (props.show && props.studentId) { opener = document.activeElement; load(); } else seq += 1; }, { immediate: true });
</script>

<template>
  <Dialog :open="show" @update:open="open => { if (!open) emit('close'); }">
    <DialogContent :show-close-button="false" @close-auto-focus="restoreFocus" class="tw:max-w-[calc(100vw-1rem)] tw:sm:max-w-3xl tw:max-h-[calc(100dvh-1rem)] tw:overflow-y-auto tw:p-4 tw:text-tpl-body tw:tabular-nums">
      <DialogHeader class="tw:flex-row tw:items-start tw:justify-between tw:gap-3">
        <div class="tw:min-w-0 tw:space-y-2"><DialogTitle class="tw:text-tpl-list tw:break-words">{{ studentName }} 學習檔案</DialogTitle>
        <DialogDescription class="tw:text-tpl-caption">依新到舊整理上課進度、出席與成績；這裡只供查閱。</DialogDescription></div>
        <Button variant="outline" aria-label="關閉學習檔案" class="tw:min-h-11 tw:shrink-0 tw:text-tpl-body" @click="emit('close')">關閉</Button>
      </DialogHeader>
      <p v-if="loading" role="status">載入中…</p>
      <div v-else-if="error" role="alert"><p>{{ error }}</p><Button variant="outline" class="tw:min-h-11 tw:text-tpl-body" @click="load">重新載入</Button></div>
      <template v-else>
        <div class="tw:grid tw:gap-3 tw:sm:grid-cols-3">
          <label class="tw:grid tw:gap-1">科目
            <select v-model="subject" aria-label="科目" data-testid="timeline-subject" class="profile-filter"><option value="">全部</option><option v-for="s in subjects" :key="s" :value="s">{{ s }}</option></select>
          </label>
          <label class="tw:grid tw:gap-1">起始日期<input v-model="from" aria-label="起始日期" type="date" class="profile-filter" /></label>
          <label class="tw:grid tw:gap-1">結束日期<input v-model="until" aria-label="結束日期" type="date" class="profile-filter" /></label>
        </div>
        <p v-if="coverage.capped_sources?.length" role="status" class="tw:text-muted-foreground">各來源最多顯示最近 {{ coverage.limit_per_source }} 筆，較早紀錄未包含在本頁。</p>
        <Tabs v-model="tab" activation-mode="manual" class="tw:min-w-0">
          <TabsList class="tw:w-full tw:h-auto tw:min-h-12"><TabsTrigger value="overview" class="tw:text-tpl-body tw:min-h-11">總覽</TabsTrigger><TabsTrigger value="history" class="tw:text-tpl-body tw:min-h-11">上課紀錄</TabsTrigger><TabsTrigger value="scores" class="tw:text-tpl-body tw:min-h-11">成績</TabsTrigger></TabsList>
          <TabsContent value="overview" class="tw:space-y-3">
            <Card><CardHeader><CardTitle class="tw:text-tpl-list">最近上課進度</CardTitle></CardHeader><CardContent class="tw:space-y-2 tw:break-words">
              <template v-if="latest"><p>{{ latest.date }} · {{ latest.subject }}</p><p v-if="latest.progress" class="tw:whitespace-pre-wrap">{{ latest.progress }}</p><p v-if="latest.content" class="tw:whitespace-pre-wrap">{{ latest.content }}</p></template>
              <p v-else>尚無符合篩選的上課進度。</p>
            </CardContent></Card>
            <Card><CardHeader><CardTitle class="tw:text-tpl-list">下一次校考</CardTitle></CardHeader><CardContent>
              <p v-if="schoolLoading" role="status">校考日期載入中…</p>
              <p v-else-if="schoolError" role="status">校考日期暫時無法載入；上課與成績紀錄仍可查閱。</p>
              <template v-else-if="nextExam"><p>{{ nextExam.label }}</p><p>{{ nextExam.start_date }} 至 {{ nextExam.end_date }}</p></template>
              <p v-else>目前沒有符合學校、年級與本學期的後續校考日期。</p>
            </CardContent></Card>
          </TabsContent>
          <TabsContent value="history">
            <p v-if="!history.length">尚無符合篩選的上課紀錄。</p>
            <ul v-else class="tw:space-y-3 tw:list-none tw:p-0">
              <li v-for="i in history" :key="`${i.type}-${i.id}`" data-testid="timeline-row" class="tw:rounded-lg tw:border tw:p-3 tw:space-y-2 tw:break-words">
                <strong>{{ i.date }}</strong>
                <div class="tw:flex tw:flex-wrap tw:gap-2"><Badge class="tw:text-tpl-caption" variant="secondary">{{ i.type === 'attendance' ? '出席' : '授課紀錄' }}</Badge><Badge class="tw:text-tpl-caption" v-if="i.subject" variant="outline">{{ i.subject }}</Badge><Badge class="tw:text-tpl-caption" v-if="i.attendance" variant="outline">{{ i.attendance }}</Badge><Badge class="tw:text-tpl-caption" v-if="i.approval_status" variant="outline">{{ STATUS[i.approval_status] || i.approval_status }}</Badge><Badge class="tw:text-tpl-caption" v-if="i.session_status === 'cancelled'" variant="outline">堂次已取消，紀錄供核對</Badge></div>
                <p v-if="i.progress" class="tw:whitespace-pre-wrap">進度：{{ i.progress }}</p><p v-if="i.content" class="tw:whitespace-pre-wrap">內容：{{ i.content }}</p>
                <p v-if="i.homework_status">作業：{{ i.homework_status }}</p><p v-if="i.quiz">小考：{{ i.quiz }}</p><p v-if="i.next_week_scope">下週範圍：{{ i.next_week_scope }}</p>
              </li>
            </ul>
          </TabsContent>
          <TabsContent value="scores">
            <p v-if="!scores.length">尚無符合篩選的可見成績。</p>
            <ul v-else class="tw:space-y-3 tw:list-none tw:p-0">
              <li v-for="i in scores" :key="`${i.type}-${i.id}`" data-testid="timeline-row" class="tw:rounded-lg tw:border tw:p-3 tw:space-y-2 tw:break-words">
                <strong>{{ i.date }}</strong><div class="tw:flex tw:flex-wrap tw:gap-2"><Badge class="tw:text-tpl-caption" variant="secondary">{{ i.type === 'exam_score' ? '段考' : '單元測驗' }}</Badge><Badge class="tw:text-tpl-caption" v-if="i.subject" variant="outline">{{ i.subject }}</Badge></div>
                <p>{{ i.title }}</p><p class="tw:text-lg tw:font-semibold">{{ scoreText(i) }}</p>
              </li>
            </ul>
          </TabsContent>
        </Tabs>
      </template>
    </DialogContent>
  </Dialog>
</template>
<style scoped>
.profile-filter { width: 100%; min-width: 0; min-height: 44px; padding: 6px 8px; border: 1px solid var(--ds-border); border-radius: 6px; background: var(--ds-surface); color: inherit; font: inherit; }
</style>
