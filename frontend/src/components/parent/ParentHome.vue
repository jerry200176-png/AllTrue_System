<script setup>
import { computed } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { BlurFade, NumberTicker, ShineBorder } from '@/components/motion';
import { moneyStatusLabel } from '../../lib/parentLabels';

// 首頁: exactly 3 cards, each fact once (#4986 step 1). `home` comes from lib/parentHome.js.
const props = defineProps({
  home: { type: Object, required: true },
});
defineEmits(['leave', 'pay', 'learning']);
// Same digits the old `toLocaleString('en-US')` showed (up to 3 decimals, none when whole).
const decimalsOf = (n) => Math.min(3, (String(Number(n)).split('.')[1] || '').length);
const dueSubline = computed(() => [
  props.home.due?.count > 1 && !props.home.due.review && `共 ${props.home.due.count} 筆`,
  props.home.due?.dueDateLabel && `繳費期限 ${props.home.due.dueDateLabel}`,
].filter(Boolean).join('・'));
</script>

<template>
  <div class="tw:flex tw:flex-col tw:gap-4" data-guide="parent-home">
    <BlurFade>
    <Card class="tw:gap-2 tw:py-5 tw:shadow-none" data-guide="parent-home-next-lesson">
      <CardContent class="tw:px-5">
        <p class="tw:text-sm tw:text-muted-foreground">下一堂課</p>
        <template v-if="home.nextLesson">
          <p class="tw:text-3xl tw:font-bold tw:tracking-tight tw:tabular-nums">
            {{ home.nextLesson.dateLabel }}（{{ home.nextLesson.weekday }}）{{ home.nextLesson.time }}
          </p>
          <p class="tw:text-sm tw:text-muted-foreground">
            {{ [home.nextLesson.subject, home.nextLesson.teacher, home.nextLesson.daysLeft === 0 ? '今天' : `還有 ${home.nextLesson.daysLeft} 天`].filter(Boolean).join('・') }}
          </p>
          <Badge v-if="home.nextLesson.leaveRequested" variant="outline" class="tw:mt-2">請假確認中</Badge>
          <Button v-else-if="home.nextLesson.canLeave" variant="secondary" class="ds-press tw:mt-4 tw:h-11 tw:w-full" @click="$emit('leave', home.nextLesson.session)">請假</Button>
        </template>
        <p v-else class="tw:text-base">目前沒有排定的課，有安排會通知您</p>
      </CardContent>
    </Card>
    </BlurFade>

    <BlurFade :delay="60">
    <Card :class="['tw:gap-2 tw:py-5 tw:shadow-none', home.due && 'tw:border-[var(--ds-brand-ink)] tw:bg-[var(--ds-brand-ink)] tw:text-white']" data-guide="parent-home-money">
      <CardContent class="tw:px-5">
        <p :class="['tw:text-sm', home.due ? 'tw:text-white/60' : 'tw:text-muted-foreground']">{{ home.due && (home.due.count > 1 || home.due.count === 0) ? '待繳學費' : '本期學費' }}</p>
        <template v-if="home.due">
          <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
            <p :class="['tw:font-bold tw:leading-none tw:tracking-tighter', !home.due.review && home.due.amount != null ? 'tw:text-5xl tw:min-[400px]:text-6xl' : 'tw:text-4xl', 'tw:tabular-nums']">
              <template v-if="home.due.review">帳務確認中</template>
              <template v-else-if="home.due.amount == null">有課程待繳</template>
              <template v-else>$<NumberTicker :value="Number(home.due.amount)" :decimals="decimalsOf(home.due.amount)" /></template>
            </p>
            <Badge variant="outline" class="tw:border-white/30 tw:text-white/80">{{ home.due.review ? moneyStatusLabel('review_required') : '待繳' }}</Badge>
          </div>
          <p v-if="dueSubline" class="tw:text-sm tw:text-white/60">{{ dueSubline }}</p>
          <ShineBorder class="tw:mt-4" :colors="['white', 'var(--ds-brand-sun)']">
            <Button class="tw:h-11 tw:w-full tw:bg-[var(--ds-brand-sun)] tw:hover:bg-[var(--ds-brand-gold)] tw:font-bold tw:text-[var(--ds-brand-glyph)] ds-press" @click="$emit('pay')">繳費方式</Button>
          </ShineBorder>
        </template>
        <p v-else class="tw:text-2xl tw:font-semibold">本期學費已繳清，謝謝您</p>
      </CardContent>
    </Card>
    </BlurFade>

    <BlurFade :delay="120">
    <Card class="tw:gap-2 tw:py-5 tw:shadow-none" data-guide="parent-home-feedback">
      <CardContent class="tw:px-5">
        <div class="tw:flex tw:items-center tw:justify-between tw:gap-2">
          <p class="tw:text-sm tw:text-muted-foreground">老師的新回饋</p>
          <Badge v-if="home.feedback?.isNew">新</Badge>
        </div>
        <p v-if="home.feedback" class="tw:text-base">
          {{ home.feedback.dateLabel }} {{ home.feedback.subject }}{{ home.feedback.text ? `：${home.feedback.text}` : '' }}
        </p>
        <p v-else class="tw:text-base">目前沒有新回饋</p>
        <Button variant="secondary" class="ds-press tw:mt-4 tw:h-11 tw:w-full" @click="$emit('learning')">查看回饋並回覆老師</Button>
      </CardContent>
    </Card>
    </BlurFade>
  </div>
</template>
