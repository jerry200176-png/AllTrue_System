<script setup>
import { computed } from 'vue';
import { ChevronRight } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

// One 版本更新 note (#4989). `featured` is the newest note: the page's one hero card,
// with the 最近一次更新 badge and no 操作細節 (same content as the At* page it replaces).
const props = defineProps({
  note: { type: Object, required: true },
  featured: { type: Boolean, default: false },
});

const IMPORTANCE = {
  action_required: { label: '需要注意', tone: 'tw:bg-[var(--ds-danger-wash)] tw:text-[color:var(--ds-danger-text)]' },
  major: { label: '重要更新', tone: 'tw:bg-[var(--ds-warning-wash)] tw:text-foreground' },
};
const importance = computed(() => IMPORTANCE[props.note.importance]
  || { label: '本週更新', tone: props.featured ? 'tw:bg-card tw:text-tpl-secondary' : 'tw:bg-secondary tw:text-tpl-secondary' });
const sections = computed(() => (Array.isArray(props.note.sections) && props.note.sections.length > 0
  ? props.note.sections
  : [{ title: '你現在可以', items: props.note.items || [] }]));
const badge = 'tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)]';
</script>

<template>
  <Card
    :data-testid="featured ? 'release-note-featured' : 'release-note'"
    :class="['tw:gap-0 tw:py-5 tw:shadow-none', featured && 'tw:border-transparent tw:bg-[image:var(--ui-hero-gradient)]']"
  >
    <CardContent class="tw:flex tw:flex-col tw:gap-2 tw:px-5">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
        <Badge :variant="featured ? 'default' : 'secondary'" :class="[badge, 'tw:font-bold tw:tabular-nums']" data-testid="release-note-version">{{ note.version }}</Badge>
        <Badge v-if="note.importance" variant="outline" :class="[badge, 'tw:border-transparent', importance.tone]" :data-importance="note.importance">{{ importance.label }}</Badge>
        <Badge v-if="featured" variant="outline" :class="[badge, 'tw:border-foreground/20']">最近一次更新</Badge>
        <span v-else-if="note.date" class="tw:text-tpl-caption tw:text-tpl-secondary">{{ note.date }}</span>
      </div>
      <h2 class="tw:mt-1 tw:text-tpl-list tw:font-bold tw:text-balance">{{ note.title }}</h2>
      <p v-if="note.summary" :class="['tw:text-tpl-body tw:text-pretty', !featured && 'tw:text-tpl-secondary']">{{ note.summary }}</p>
      <p v-if="featured && note.effectiveAt && note.effectiveAt !== note.publishedAt" class="tw:text-tpl-caption tw:text-tpl-secondary">
        已於 {{ note.effectiveAt }} 生效
      </p>
      <details v-if="!featured" class="tw:group/note tw:mt-2">
        <Button
          as="summary"
          variant="secondary"
          class="tw:h-auto tw:min-h-12 tw:cursor-pointer tw:list-none tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:transition-colors tw:[&::-webkit-details-marker]:hidden"
        >
          <ChevronRight class="tw:size-5 tw:transition-transform tw:group-open/note:rotate-90 tw:motion-reduce:transition-none" :stroke-width="2" aria-hidden="true" />
          查看操作細節
        </Button>
        <div class="tw:mt-4 tw:grid tw:grid-cols-[repeat(auto-fit,minmax(14.5rem,1fr))] tw:gap-x-6 tw:gap-y-4 tw:border-t tw:border-border tw:pt-4">
          <section v-for="section in sections" :key="`${note.id || note.version}-${section.title}`" class="tw:min-w-0">
            <h3 class="tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">{{ section.title }}</h3>
            <ul class="tw:list-disc tw:pl-5 tw:text-tpl-body tw:break-words">
              <li v-for="row in section.items" :key="row" class="tw:mt-1">{{ row }}</li>
            </ul>
          </section>
        </div>
      </details>
    </CardContent>
  </Card>
</template>
