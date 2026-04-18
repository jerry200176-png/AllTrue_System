<template>
  <section class="rsc wp" id="recent-subs-sec" data-guide="director-recent-subs">
    <header class="wp__head rsc-head">
      <span class="material-symbols-outlined wp__hi">swap_horiz</span>
      <h3>近 7 天代課記錄</h3>
      <span v-if="items.length" class="wp__badge">{{ items.length }}</span>
    </header>
    <p class="wp__hint">僅顯示當前分校，資料來自代課送出時建立的家長通知。</p>
    <div v-if="loading" class="rsc-skel" aria-hidden="true">
      <div v-for="n in 3" :key="n" class="rsc-skel__row">
        <div class="rsc-skel__line rsc-skel__line--w60"></div>
        <div class="rsc-skel__line rsc-skel__line--w40"></div>
      </div>
    </div>
    <template v-else>
      <div v-if="items.length === 0" class="rsc-empty">
        <div class="rsc-empty__emoji" aria-hidden="true">🌤️</div>
        <div class="rsc-empty__title">近 7 天無代課記錄</div>
        <div class="rsc-empty__desc">老師出勤穩定，辛苦您！</div>
      </div>
      <ul v-else class="rsc-list">
        <li v-for="row in displayedItems" :key="row.id" class="rsc-row">
          <div class="rsc-row__meta">
            <span class="rsc-row__date">{{ row.session_date }} {{ row.start_time }}~{{ row.end_time }}</span>
            <span class="rsc-row__student">{{ row.student_name }} · {{ row.subject || '課程' }}</span>
          </div>
          <div class="rsc-row__flow">
            <span>{{ row.old_teacher_name || '—' }}</span>
            <span class="rsc-row__arrow">→</span>
            <span>{{ row.new_teacher_name || '—' }}</span>
            <span v-if="row.cross_campus" class="rsc-row__badge">跨分校</span>
          </div>
          <div v-if="row.reason" class="rsc-row__reason" :title="row.reason">{{ row.reason }}</div>
        </li>
      </ul>
      <footer v-if="items.length > limit && !expanded" class="wp__foot">
        <button class="btn-o btn-xs" @click="expanded = true">查看全部 ({{ items.length }})</button>
      </footer>
      <footer v-else-if="expanded && items.length > limit" class="wp__foot">
        <button class="btn-o btn-xs" @click="expanded = false">收合</button>
      </footer>
    </template>
  </section>
</template>

<script setup>
// RecentSubstitutesCard — 近 7 天代課記錄（PRD 9c058f19 FR-012 / US-05）
import { computed, ref, watch } from 'vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  // async ({ branch_id }) => { items: [...] }
  fetchRecent: { type: Function, required: true },
});

const items = ref([]);
const loading = ref(false);
const expanded = ref(false);
const limit = 5;

const displayedItems = computed(() =>
  expanded.value ? items.value : items.value.slice(0, limit)
);

async function load() {
  if (!props.branchId) {
    items.value = [];
    return;
  }
  loading.value = true;
  try {
    const r = await props.fetchRecent({ branch_id: props.branchId });
    items.value = Array.isArray(r?.items) ? r.items : [];
  } catch (e) {
    items.value = [];
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.branchId,
  () => load(),
  { immediate: true }
);

defineExpose({ reload: load });
</script>

<style scoped>
.rsc {
  /* Reuses existing DirectorDashboard .wp token. */
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.rsc-head { display: flex; align-items: center; gap: 8px; }
.rsc-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
.rsc-row {
  padding: 10px 12px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.rsc-row__meta { display: flex; justify-content: space-between; font-size: 12px; color: #6b7280; }
.rsc-row__date { font-weight: 600; color: #111827; }
.rsc-row__flow { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #111827; }
.rsc-row__arrow { color: #6b7280; }
.rsc-row__badge { margin-left: 6px; background: #fef3c7; color: #92400e; font-size: 11px; padding: 2px 8px; border-radius: 999px; }
.rsc-row__reason { font-size: 12px; color: #4b5563; font-style: italic; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.rsc-empty { text-align: center; padding: 24px 8px; color: #6b7280; }
.rsc-empty__emoji { font-size: 28px; }
.rsc-empty__title { font-weight: 600; color: #111827; margin-top: 6px; }
.rsc-empty__desc { font-size: 12px; margin-top: 2px; }

.rsc-skel { display: flex; flex-direction: column; gap: 8px; padding: 6px 0; }
.rsc-skel__row { padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 10px; display: flex; flex-direction: column; gap: 6px; background: #fff; }
.rsc-skel__line { height: 10px; background: #e5e7eb; border-radius: 4px; }
.rsc-skel__line--w60 { width: 60%; }
.rsc-skel__line--w40 { width: 40%; }
</style>
