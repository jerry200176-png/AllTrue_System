<script setup>
// #4970 step 5: 「這堂上完的單元」 inside the lesson evaluation form. Ticks live here; the page calls save(recordId)
// right after the evaluation itself was saved, so the evaluation save path stays untouched.
import { computed, ref, watch } from 'vue';
import { authedFetch } from '../../lib/authedFetch';
import { dateRangeText } from '../../lib/learningPlanGantt';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Skeleton } from '../ui/skeleton';

const props = defineProps({
  classSessionId: { type: [Number, String], default: 0 },
  disabled: { type: Boolean, default: false },
  restoreIds: { type: Array, default: null }, // the selection kept in an unsaved draft; null = the draft holds none
});
const emit = defineEmits(['change']);

const state = ref(null); // the candidates payload; null = nothing to show (no plan, no right to tick, or not loaded)
const loading = ref(false);
const loadError = ref('');
const saving = ref(false); // ticks are locked while a save is in flight, so nothing can be lost when the form closes
const checked = ref(new Set());
const saved = ref(new Set()); // what the server already holds for this lesson
let seq = 0;

async function api(path, options = {}) {
  const res = await authedFetch(`/api/v1${path}`, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(body.message || `HTTP ${res.status}`), { status: res.status });
  return body;
}

let inflight = Promise.resolve(); // the latest list request, so a save can wait for it
function load(all = false) {
  inflight = doLoad(all);
  return inflight;
}

async function doLoad(all = false) {
  const my = ++seq;
  const id = Number(props.classSessionId) || 0;
  if (id <= 0) { state.value = null; return; }
  loading.value = true;
  loadError.value = '';
  try {
    const { data } = await api(`/learning-plan-units/candidates?class_session_id=${id}${all ? '&all=1' : ''}`);
    if (my !== seq) return;
    const serverChecked = new Set((data.units || []).filter((u) => u.checked).map((u) => u.id));
    if (!all || !state.value) { checked.value = new Set(serverChecked); saved.value = new Set(serverChecked); }
    else {
      // The full list replaces the short one: a tick whose unit is no longer offered must not be sent.
      const offered = new Set((data.units || []).map((u) => u.id));
      checked.value = new Set([...checked.value].filter((id) => offered.has(id)));
    }
    state.value = data.plan && data.can_tick ? data : null;
    if (!state.value) restored.value = true; // no plan, or no right to tick: a draft selection has nothing to go to
    applyRestore(all);
  } catch (e) {
    if (my !== seq) return;
    // A failed 「顯示全部」 keeps the list and the ticks already on screen.
    if (all && state.value) { loadError.value = restorePending.value ? '草稿裡的勾選還沒還原：完整單元清單載入失敗，請按「顯示全部」再試一次。' : '完整單元清單暫時載入失敗，請再試一次。'; return; }
    state.value = null;
    // No right to see the plan is not an error for the evaluation; anything else is worth a line.
    loadError.value = e.status === 403 || e.status === 404 ? '' : '單元清單暫時載入失敗，評量本身不受影響。';
    if (e.status === 403 || e.status === 404) restored.value = true;
  } finally {
    if (my === seq) loading.value = false;
  }
}

// A different lesson must never inherit the previous lesson's list or ticks, not even for the length of one request.
watch(() => props.classSessionId, () => {
  seq += 1; // drop any request still in flight for the old lesson
  state.value = null;
  checked.value = new Set();
  saved.value = new Set();
  loadError.value = '';
  load();
}, { immediate: true });

/** The selection kept in an unsaved draft replaces what the server holds, so a tick the teacher removed stays removed. */
// Only units the server still offers for this lesson are restored: a unit finished elsewhere or a replaced plan must
// not be sent. If some are not in the short list, the full list is loaded once to look for them.
const restored = ref(false); // false while a draft selection is given but not yet applied to the ticks
// Ticks stay locked until then, so an edit can never silently replace draft ticks that are not on screen yet.
const restorePending = computed(() => Array.isArray(props.restoreIds) && !restored.value);
function applyRestore(expanded = false) {
  // Applied once per draft: a later 「顯示全部」 must not put the draft back over the teacher's newer ticks.
  if (!state.value || !Array.isArray(props.restoreIds) || restored.value) return;
  const ids = props.restoreIds.map(Number).filter((n) => n > 0);
  const offered = new Set((state.value.units || []).map((u) => u.id));
  const known = ids.filter((id) => offered.has(id));
  const more = (state.value.total || 0) > (state.value.units || []).length;
  // Wait for the full list before applying, so a half-restored selection is never written back to the draft.
  if (!expanded && known.length < ids.length && more) { load(true); return; }
  checked.value = new Set(known);
  restored.value = true;
  if (dirty.value) emit('change');
}
watch(() => props.restoreIds, () => { restored.value = false; applyRestore(); });

const units = computed(() => state.value?.units || []);
const hiddenCount = computed(() => Math.max(0, (state.value?.total || 0) - units.value.length));
const dirty = computed(() => checked.value.size !== saved.value.size || [...checked.value].some((id) => !saved.value.has(id)));

function toggle(id, on) {
  if (restorePending.value) return;
  const next = new Set(checked.value);
  if (on) next.add(id); else next.delete(id);
  checked.value = next;
  emit('change');
}

/** Called by the page after the evaluation was saved. Resolves { ok, message }; nothing to send is ok. */
async function save(recordId) {
  // A draft selection that is not on the form yet must not be reported as saved: the page would clear the draft.
  if (restorePending.value) {
    await inflight;
    if (restorePending.value && !loading.value) await load();
    await inflight;
    if (restorePending.value) return { ok: false, message: '草稿裡勾選的單元還沒還原，請再儲存一次。' };
  }
  if (!state.value || !dirty.value) return { ok: true };
  saving.value = true;
  try {
    const sent = [...checked.value];
    const { data } = await api(`/learning-records/${recordId}/plan-units`, { method: 'PUT', body: JSON.stringify({ unit_ids: sent }) });
    saved.value = new Set(data.unit_ids || []);
    // Ticks changed while the request was in flight stay as the user left them (still dirty, sent on the next save).
    const untouched = checked.value.size === sent.length && sent.every((id) => checked.value.has(id));
    if (untouched) checked.value = new Set(saved.value);
    return { ok: true };
  } catch (e) {
    return { ok: false, message: e.message || '請稍後再試' };
  } finally {
    saving.value = false;
  }
}

/** Ticks worth keeping in the draft: nothing when they equal what the server already holds. */
/** The selection to keep in a draft, or null when it equals what the server holds (nothing to keep). */
// Until a draft selection has been applied (list still loading, or the full list failed), the draft keeps it as it is.
const draftIds = () => {
  if (!state.value || restorePending.value) return props.restoreIds;
  return dirty.value ? [...checked.value] : null;
};

defineExpose({ save, dirty, draftIds });
</script>

<template>
  <section v-if="loading || state || loadError" class="form-group tw:space-y-2" data-testid="lesson-plan-units" aria-labelledby="lesson-plan-units-title">
    <h4 id="lesson-plan-units-title" class="tw:m-0 tw:text-tpl-body tw:font-semibold">這堂上完的單元</h4>
    <Skeleton v-if="loading && !state" class="tw:h-16 tw:w-full" />
    <p v-else-if="loadError && !state" class="tw:m-0 tw:text-tpl-caption tw:text-muted-foreground" role="status">{{ loadError }}</p>
    <template v-else>
      <p v-if="loadError" class="tw:m-0 tw:text-tpl-caption tw:text-muted-foreground" role="status">{{ loadError }}</p>
      <p class="tw:m-0 tw:text-tpl-caption tw:text-muted-foreground">依「{{ state.plan.title }}」學習計畫，勾選這堂課實際上完的單元；儲存評量時一併記錄。</p>
      <ul class="tw:m-0 tw:list-none tw:space-y-2 tw:p-0">
        <li v-for="u in units" :key="u.id" class="tw:rounded-md tw:border tw:border-border">
          <label class="tw:flex tw:min-h-11 tw:items-start tw:gap-3 tw:p-3">
            <input type="checkbox" class="tw:mt-0.5 tw:size-5 tw:shrink-0" :checked="checked.has(u.id)" :disabled="disabled || saving || loading || restorePending"
              :aria-label="`${u.title} 這堂上完`" @change="toggle(u.id, $event.target.checked)" />
            <span class="tw:min-w-0 tw:flex-1">
              <span class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                <strong class="tw:text-tpl-body tw:break-words">{{ u.title }}</strong>
                <Badge v-if="u.behind && !checked.has(u.id)" variant="outline" class="tw:border-destructive tw:text-destructive">落後</Badge>
              </span>
              <span class="tw:block tw:text-tpl-caption tw:text-muted-foreground tw:tabular-nums">預計 {{ dateRangeText(u) }}</span>
            </span>
          </label>
        </li>
      </ul>
      <Button v-if="hiddenCount > 0" type="button" variant="outline" class="tw:mt-3 tw:min-h-11" :disabled="disabled" @click="load(true)">顯示全部（還有 {{ hiddenCount }} 個單元）</Button>
    </template>
  </section>
</template>
