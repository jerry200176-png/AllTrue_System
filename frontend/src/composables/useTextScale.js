import { ref } from 'vue';

// Text-size control (A / A+ / A++): scales the --tpl-* text tokens in ui.css. Remembered per viewer.
export const TEXT_SCALES = Object.freeze([
  { key: 'A', label: 'A', scale: 1 },
  { key: 'A+', label: 'A+', scale: 1.15 },
  { key: 'A++', label: 'A++', scale: 1.3 },
]);
const STORAGE_KEY = 'alltrue_text_scale';
const DEFAULT_KEY = 'A';

const read = () => {
  try {
    const v = globalThis.localStorage?.getItem(STORAGE_KEY);
    return TEXT_SCALES.some((s) => s.key === v) ? v : DEFAULT_KEY;
  } catch {
    return DEFAULT_KEY;
  }
};

const current = ref(read());

const apply = (key) => {
  const scale = (TEXT_SCALES.find((s) => s.key === key) || TEXT_SCALES[0]).scale;
  globalThis.document?.documentElement?.style.setProperty('--tpl-scale', String(scale));
};

export function useTextScale() {
  apply(current.value);
  const setScale = (key) => {
    if (!TEXT_SCALES.some((s) => s.key === key)) return;
    current.value = key;
    apply(key);
    try { globalThis.localStorage?.setItem(STORAGE_KEY, key); } catch { /* storage blocked: keep in memory */ }
  };
  return { steps: TEXT_SCALES, current, setScale };
}
