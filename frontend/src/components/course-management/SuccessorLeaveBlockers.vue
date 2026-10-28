<template>
  <ul v-if="blockers.length" role="alert" aria-label="請假順延阻擋原因">
    <li v-for="(blocker, index) in blockers" :key="index">
      {{ blocker.message }}
      <button v-if="Number(blocker.course_id) > 0" type="button" class="ghost" @click="$emit('navigate', { target: 'calendar', courseId: Number(blocker.course_id), date: blocker.date || date, intent: '' })">
        {{ blocker.date || date }} 查看受影響課程
      </button>
    </li>
  </ul>
</template>
<script setup>
defineProps({ blockers: { type: Array, default: () => [] }, date: { type: String, default: '' } });
defineEmits(['navigate']);
</script>
