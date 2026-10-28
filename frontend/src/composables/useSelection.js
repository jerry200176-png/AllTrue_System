import { computed, reactive, ref, watch } from 'vue';

/**
 * #4488 勾選聯動: row selection scoped to the list the user can see.
 * Pass the filtered/visible rows. Rows failing `canSelect` (disabled, wrong tab/mode) are never
 * selected; when a key leaves that scope (filter, refresh, tab change) it is dropped, so a batch
 * action never touches a row the user cannot see. `all` / `mixed` drive a tri-state header box.
 */
export function useSelection(rows, { key = 'id', canSelect = () => true } = {}) {
  const selected = ref(new Set());
  const selectableKeys = computed(() => (rows.value || []).filter(canSelect).map((row) => row[key]));
  const keys = computed(() => selectableKeys.value.filter((k) => selected.value.has(k)));
  const all = computed(() => selectableKeys.value.length > 0 && keys.value.length === selectableKeys.value.length);

  watch(keys, (kept) => {
    if (kept.length !== selected.value.size) selected.value = new Set(kept);
  });

  return reactive({
    keys,
    count: computed(() => keys.value.length),
    all,
    mixed: computed(() => keys.value.length > 0 && !all.value),
    empty: computed(() => selectableKeys.value.length === 0),
    has: (k) => selected.value.has(k),
    toggle(k, on = !selected.value.has(k)) {
      if (on && !selectableKeys.value.includes(k)) return;
      const next = new Set(selected.value);
      if (on) next.add(k);
      else next.delete(k);
      selected.value = next;
    },
    toggleAll(on = !all.value) {
      selected.value = new Set(on ? selectableKeys.value : []);
    },
    clear() {
      selected.value = new Set();
    },
  });
}
