import { ref } from 'vue';
import { fetchMonthlyAttendanceSummary } from '../../lib/monthlyAttendanceSummary.js';

/** Track only this view's monthly summaries; newer requests always win. */
export function useMonthlyAttendanceSummary({ getToken, fetchSummary = fetchMonthlyAttendanceSummary }) {
  const states = ref({});
  const generations = new Map();
  const controllers = new Map();
  let sequence = 0;
  let disposed = false;
  const keyFor = (branchId, courseId) => `${branchId}:${courseId}`;
  const cancel = (key) => {
    generations.set(key, ++sequence);
    controllers.get(key)?.abort();
    controllers.delete(key);
  };
  const clear = () => {
    sequence++;
    for (const controller of controllers.values()) controller.abort();
    controllers.clear();
    generations.clear();
    states.value = {};
  };
  const dispose = () => { disposed = true; clear(); };

  async function refresh(branchId, courseId) {
    const key = keyFor(branchId, courseId);
    controllers.get(key)?.abort();
    const controller = new AbortController();
    controllers.set(key, controller);
    const generation = ++sequence;
    generations.set(key, generation);
    const old = states.value[key];
    states.value = { ...states.value, [key]: { status: 'loading', count: old?.count ?? null, cancelled: old?.cancelled ?? 0 } };
    try {
      const token = await getToken();
      if (disposed || generations.get(key) !== generation || controller.signal.aborted) return;
      const result = await fetchSummary({ branchId, courseId, token, signal: controller.signal });
      if (disposed || generations.get(key) !== generation) return;
      states.value = { ...states.value, [key]: { status: 'ready', count: result.count, cancelled: result.cancelled } };
    } catch {
      if (disposed || generations.get(key) !== generation) return;
      states.value = { ...states.value, [key]: { status: 'error', count: old?.count ?? null, cancelled: old?.cancelled ?? 0 } };
    } finally {
      if (controllers.get(key) === controller) controllers.delete(key);
    }
  }
  return { states, keyFor, refresh, cancel, clear, dispose };
}
