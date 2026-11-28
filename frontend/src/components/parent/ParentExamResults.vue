<script setup>
// 段考成績 in the parent portal: the latest closed rounds the child sat, each subject's score and the teacher's words. Read only.
// Nothing to show (no rounds, still loading, or the fetch failed) hides the whole section: it is an extra, not a page a parent
// came for, so it never adds an empty card or an error box to the 學習 tab. An answer for an older token (child switched) is dropped.
// The teacher's words are plain text (interpolation, never v-html); line breaks are kept.
import { ref, watch } from 'vue';
import { getParentExamResults } from '../../api';
import { formatMd } from '../../lib/learningPlanGantt';
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card';

const props = defineProps({
  token: { type: String, required: true },
});

const rounds = ref([]);
let seq = 0;

async function load() {
  const my = ++seq;
  rounds.value = [];
  try {
    const body = await getParentExamResults(props.token);
    if (my === seq) rounds.value = (Array.isArray(body?.data) ? body.data : []).filter((r) => r?.subjects?.length);
  } catch {
    // Hidden on failure; see the note above.
  }
}

const num = (v) => Number(v).toLocaleString('en-US', { maximumFractionDigits: 2 });
const scoreText = (s) => (s.is_absent ? '缺考' : `${num(s.score)} / ${num(s.max_score)}`);

watch(() => props.token, load);
load();
</script>

<template>
  <Card v-if="rounds.length" class="tw:gap-3 tw:py-5 tw:shadow-none" data-testid="parent-exam-results">
    <CardHeader class="tw:px-5">
      <CardTitle class="tw:m-0 tw:text-tpl-list tw:font-bold">段考成績</CardTitle>
    </CardHeader>
    <CardContent class="tw:flex tw:flex-col tw:gap-4 tw:px-5">
      <section v-for="r in rounds" :key="r.id" class="tw:flex tw:flex-col tw:gap-2" :aria-labelledby="`exam-round-${r.id}`" data-testid="exam-round">
        <h4 :id="`exam-round-${r.id}`" class="tw:m-0 tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-2 tw:text-tpl-body tw:font-bold">
          <span class="tw:break-words tw:text-pretty">{{ r.title }}</span>
          <span v-if="formatMd(r.exam_date)" class="tw:text-tpl-caption tw:font-normal tw:text-tpl-secondary tw:tabular-nums">{{ formatMd(r.exam_date) }}</span>
        </h4>
        <ul class="tw:m-0 tw:flex tw:list-none tw:flex-col tw:p-0">
          <li v-for="(s, i) in r.subjects" :key="i" class="tw:flex tw:flex-col tw:gap-1 tw:border-t tw:border-border tw:py-3" data-testid="exam-subject">
            <p class="tw:m-0 tw:flex tw:items-baseline tw:justify-between tw:gap-3 tw:text-tpl-body">
              <span class="tw:font-semibold tw:break-words">{{ s.name }}</span>
              <span class="tw:whitespace-nowrap tw:tabular-nums" :class="s.is_absent ? 'tw:text-tpl-secondary' : 'tw:font-semibold'">{{ scoreText(s) }}</span>
            </p>
            <p v-if="s.parent_comment" class="tw:m-0 tw:text-tpl-body tw:text-tpl-secondary tw:whitespace-pre-line tw:break-words" data-testid="exam-comment">老師的話：{{ s.parent_comment }}</p>
          </li>
        </ul>
      </section>
    </CardContent>
  </Card>
</template>
