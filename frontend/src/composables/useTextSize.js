import { ref } from 'vue';

// 字級: 標準 (default) or 大. Per person, kept in localStorage; ui.css maps html[data-text-size] to the --tpl-text-* tokens.
export const TEXT_SIZES = Object.freeze([
  { key: 'standard', label: '標準' },
  { key: 'large', label: '大' },
]);
const STORAGE_KEY = 'alltrue_text_size';
const LEGACY_KEY = 'alltrue_text_scale'; // old A / A+ / A++ control on the director home

const safeGet = (k) => { try { return globalThis.localStorage?.getItem(k); } catch { return null; } };

const read = () => {
  const v = safeGet(STORAGE_KEY);
  if (TEXT_SIZES.some((s) => s.key === v)) return v;
  const old = safeGet(LEGACY_KEY);
  return old === 'A+' || old === 'A++' ? 'large' : 'standard';
};

const current = ref(read());

const apply = (key) => globalThis.document?.documentElement?.setAttribute('data-text-size', key);

/** Call once at startup so every page, not only the one with the toggle, gets the saved size. */
export function initTextSize() {
  current.value = read();
  apply(current.value);
}

export function useTextSize() {
  const setSize = (key) => {
    if (!TEXT_SIZES.some((s) => s.key === key)) return;
    current.value = key;
    apply(key);
    try { globalThis.localStorage?.setItem(STORAGE_KEY, key); } catch { /* storage blocked: keep in memory */ }
  };
  return { sizes: TEXT_SIZES, current, setSize };
}
