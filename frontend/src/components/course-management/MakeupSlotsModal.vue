<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal" style="max-width: 520px;">
      <h3 class="modal-title">老師可補課時段</h3>
      <p class="modal-desc">
        {{ studentName }} — {{ subjectLabel }}
        ｜老師空檔（未來 {{ dateRange }} 天）
      </p>
      <div class="makeup-range-bar">
        <label>查詢範圍</label>
        <select :value="dateRange" @change="$emit('update:dateRange', Number($event.target.value)); $emit('refresh')">
          <option :value="7">未來 7 天</option>
          <option :value="14">未來 14 天</option>
          <option :value="30">未來 30 天</option>
          <option :value="60">未來 60 天</option>
        </select>
      </div>
      <div v-if="loading" class="makeup-status">查詢中…</div>
      <div v-else-if="slotsGrouped.length === 0" class="makeup-status">
        查無可補課空檔，請嘗試放寬查詢範圍。
      </div>
      <div v-else class="makeup-slots-list">
        <div v-for="group in slotsGrouped" :key="group.date" class="makeup-date-group">
          <div class="makeup-date-header">{{ group.date }} {{ dayLabel(group.day_of_week) }}</div>
          <div v-for="slot in group.slots" :key="slot.start_time"
            class="makeup-slot-row" :class="{ 'slot-has-students': slot.currentStudentCount > 0 }">
            <div class="slot-info">
              <span class="slot-time">{{ slot.start_time }} ~ {{ slot.end_time }}</span>
              <span class="slot-capacity" :class="slot.currentStudentCount > 0 ? 'cap-partial' : 'cap-free'">
                {{ slot.currentStudentCount }} / {{ slot.capacity }} 人
              </span>
              <span v-if="slot.existingStudents && slot.existingStudents.length" class="slot-students">
                {{ slot.existingStudents.join('、') }}
              </span>
            </div>
            <button class="small primary" @click="$emit('select', slot)">選擇</button>
          </div>
        </div>
      </div>
      <div class="actions">
        <button class="ghost" @click="$emit('close')">關閉</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  studentName: String,
  subject: String,
  dateRange: Number,
  loading: Boolean,
  slotsGrouped: Array,
  dayLabel: Function,
});
defineEmits(['close', 'select', 'refresh', 'update:dateRange']);
const subjectLabel = computed(() => getSubjectLabel(props.subject));
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
.makeup-range-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.makeup-range-bar label { font-size: 13px; font-weight: 600; color: var(--text-light); white-space: nowrap; }
.makeup-range-bar select { padding: 6px 10px; border-radius: 6px; font-size: 13px; flex: 1; }
.makeup-status { text-align: center; padding: 28px 16px; color: var(--text-light); font-size: 14px; }
.makeup-slots-list { max-height: 380px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 16px; }
.makeup-date-group + .makeup-date-group { border-top: 1px solid var(--border); }
.makeup-date-header { padding: 8px 12px; font-size: 13px; font-weight: 700; background: var(--primary-bg); color: var(--text); position: sticky; top: 0; z-index: 1; }
.makeup-slot-row { display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; border-top: 1px solid #f0f0f0; font-size: 13px; }
.makeup-slot-row:first-child { border-top: none; }
.makeup-slot-row:hover { background: #fafafa; }
.slot-info { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; min-width: 0; flex: 1; }
.slot-time { color: var(--text); font-weight: 500; }
.slot-capacity { font-size: 12px; font-weight: 600; padding: 1px 8px; border-radius: 10px; }
.slot-capacity.cap-free { background: #e8f5e9; color: #2e7d32; }
.slot-capacity.cap-partial { background: #fff3e0; color: #e65100; }
.slot-students { font-size: 11px; color: var(--text-light); flex-basis: 100%; }
.slot-has-students { background: #fffde7; }
</style>
