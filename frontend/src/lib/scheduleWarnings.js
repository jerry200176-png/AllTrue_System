// Past lessons that clash with another campus are saved anyway (#5588); the server sends the reminder in the
// X-Schedule-Warnings header (URL-encoded, one message per line) and every successful write shows it here.
import { toast } from 'vue-sonner';

export const SCHEDULE_WARNINGS_HEADER = 'X-Schedule-Warnings';

export function scheduleWarningsFrom(res) {
  try {
    const raw = res?.ok ? res.headers?.get?.(SCHEDULE_WARNINGS_HEADER) : null;
    return raw ? decodeURIComponent(raw).split('\n').filter(Boolean) : [];
  } catch {
    return [];
  }
}

let installed = false;

export function installScheduleWarnings(target = typeof window === 'undefined' ? null : window, show = (m) => toast.warning(m, { duration: 12000 })) {
  if (installed || typeof target?.fetch !== 'function') return;
  installed = true;
  const orig = target.fetch.bind(target);
  target.fetch = async (input, init) => {
    const res = await orig(input, init);
    scheduleWarningsFrom(res).forEach(show);
    return res;
  };
}
