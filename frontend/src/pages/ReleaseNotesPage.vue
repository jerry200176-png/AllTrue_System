<template>
  <div class="tw:mx-auto tw:flex tw:max-w-3xl tw:flex-col tw:gap-4 tw:text-foreground" data-testid="release-notes-page">
    <header class="tw:pb-2">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
        <h1 class="tw:text-tpl-page tw:font-bold tw:tracking-tight">版本更新</h1>
        <Button
          as="a"
          variant="outline"
          class="tw:h-12 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:text-foreground tw:no-underline tw:shadow-none tw:transition-colors"
          :href="changelogUrl"
          target="_blank"
          rel="noopener noreferrer"
        >
          查看完整 CHANGELOG
          <ExternalLink class="tw:size-4" :stroke-width="2" aria-hidden="true" />
        </Button>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">用白話整理近期會影響工作的改變；完整工程紀錄仍保留在 CHANGELOG。</p>
      <p class="tw:mt-1 tw:flex tw:flex-wrap tw:gap-x-3 tw:text-tpl-caption tw:text-tpl-secondary tw:tabular-nums">
        <span v-if="latestNote">最新版本 <strong class="tw:text-foreground">{{ latestNote.version }}</strong></span>
        <span>{{ notes.length }} 則公告</span>
      </p>
    </header>

    <ReleaseNoteCard v-if="latestNote" :note="latestNote" featured />

    <Card v-if="notes.length === 0" class="tw:gap-0 tw:py-6 tw:shadow-none" data-testid="release-notes-empty">
      <CardContent class="tw:px-5">
        <h2 class="tw:text-tpl-list tw:font-bold">目前尚無可顯示的更新內容</h2>
        <p class="tw:mt-1 tw:text-tpl-body tw:text-tpl-secondary">有新的核准公告時，會在這裡顯示。</p>
      </CardContent>
    </Card>

    <p v-if="olderNotes.length" class="tw:mt-2 tw:text-tpl-caption tw:text-tpl-secondary">
      顯示最近 {{ recentNotes.length + (latestNote ? 1 : 0) }} 則；點擊查看其他公告。
    </p>

    <ReleaseNoteCard v-for="note in recentNotes" :key="note.id || `${note.version}-${note.title}`" :note="note" />

    <details v-if="olderNotes.length" class="tw:group/older" data-testid="release-notes-older" @toggle="olderOpened = olderOpened || $event.target.open">
      <Button
        as="summary"
        variant="outline"
        class="tw:h-auto tw:min-h-12 tw:w-full tw:cursor-pointer tw:list-none tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none tw:transition-colors tw:[&::-webkit-details-marker]:hidden"
      >
        <ChevronRight class="tw:size-5 tw:transition-transform tw:group-open/older:rotate-90 tw:motion-reduce:transition-none" :stroke-width="2" aria-hidden="true" />
        查看更早公告（{{ olderNotes.length }} 則）
      </Button>
      <div v-if="olderOpened" class="tw:mt-4 tw:flex tw:flex-col tw:gap-4">
        <ReleaseNoteCard
          v-for="note in olderNotes"
          :key="note.id || `${note.version}-${note.title}`"
          :note="note"
          class="tw:[content-visibility:auto] tw:[contain-intrinsic-size:auto_13rem]"
        />
      </div>
    </details>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { ChevronRight, ExternalLink } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import ReleaseNoteCard from '@/components/release-notes/ReleaseNoteCard.vue';
import { notesForRole } from '../lib/releaseNotes';

const props = defineProps({
  userRole: { type: String, default: '' },
});

const changelogUrl =
  'https://github.com/jerry200176-png/AllTrue_System/blob/main/docs/CHANGELOG.md';

const notes = computed(() => notesForRole(props.userRole));
const latestNote = computed(() => notes.value[0] || null);
const recentNotes = computed(() => notes.value.slice(1, 4));
const olderNotes = computed(() => notes.value.slice(4));
// Older notes (600+) are built on first open, then kept: most visits never open them.
const olderOpened = ref(false);
</script>
