<script setup>
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { moneyStatusLabel } from '../../lib/parentLabels';

// 首頁: exactly 3 cards, each fact once (#4986 step 1). `home` comes from lib/parentHome.js.
defineProps({
  home: { type: Object, required: true },
});
defineEmits(['leave', 'pay', 'learning']);
const money = (n) => Number(n).toLocaleString('en-US');
</script>

<template>
  <div class="tw:flex tw:flex-col tw:gap-3" data-guide="parent-home">
    <Card class="tw:gap-2 tw:py-4" data-guide="parent-home-next-lesson">
      <CardContent class="tw:px-4">
        <p class="tw:text-xs tw:text-muted-foreground">下一堂課</p>
        <template v-if="home.nextLesson">
          <p class="tw:text-xl tw:font-bold">
            {{ home.nextLesson.dateLabel }}（{{ home.nextLesson.weekday }}）{{ home.nextLesson.time }}
          </p>
          <p class="tw:text-xs tw:text-muted-foreground">
            {{ [home.nextLesson.subject, home.nextLesson.teacher, home.nextLesson.daysLeft === 0 ? '今天' : `還有 ${home.nextLesson.daysLeft} 天`].filter(Boolean).join('・') }}
          </p>
          <Badge v-if="home.nextLesson.leaveRequested" variant="outline" class="tw:mt-2">請假確認中</Badge>
          <Button v-else-if="home.nextLesson.canLeave" variant="secondary" class="tw:mt-3 tw:h-11 tw:w-full" @click="$emit('leave', home.nextLesson.session)">請假</Button>
        </template>
        <p v-else class="tw:text-base">近期沒有排課</p>
      </CardContent>
    </Card>

    <Card class="tw:gap-2 tw:py-4" data-guide="parent-home-money">
      <CardContent class="tw:px-4">
        <p class="tw:text-xs tw:text-muted-foreground">要繳的錢</p>
        <template v-if="home.due">
          <div class="tw:flex tw:items-center tw:justify-between tw:gap-2">
            <p class="tw:text-xl tw:font-bold">{{ home.due.review ? '帳務確認中' : home.due.amount == null ? '有課程待繳' : `$${money(home.due.amount)}` }}</p>
            <Badge variant="outline">{{ moneyStatusLabel(home.due.review ? 'review_required' : 'unpaid') }}</Badge>
          </div>
          <p v-if="home.due.dueDateLabel" class="tw:text-xs tw:text-muted-foreground">{{ home.due.dueDateLabel }} 前</p>
          <Button class="tw:mt-3 tw:h-11 tw:w-full" @click="$emit('pay')">怎麼繳費</Button>
        </template>
        <p v-else class="tw:text-xl tw:font-bold">都繳清了</p>
      </CardContent>
    </Card>

    <Card class="tw:gap-2 tw:py-4" data-guide="parent-home-feedback">
      <CardContent class="tw:px-4">
        <div class="tw:flex tw:items-center tw:justify-between tw:gap-2">
          <p class="tw:text-xs tw:text-muted-foreground">老師的新回饋</p>
          <Badge v-if="home.feedback?.isNew">新</Badge>
        </div>
        <p v-if="home.feedback" class="tw:text-base">
          {{ home.feedback.dateLabel }} {{ home.feedback.subject }}{{ home.feedback.text ? `：${home.feedback.text}` : '' }}
        </p>
        <p v-else class="tw:text-base">還沒有回饋</p>
        <Button variant="secondary" class="tw:mt-3 tw:h-11 tw:w-full" @click="$emit('learning')">看全部・回覆老師</Button>
      </CardContent>
    </Card>
  </div>
</template>
