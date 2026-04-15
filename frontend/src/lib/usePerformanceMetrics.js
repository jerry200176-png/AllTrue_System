const PERF_LOG_KEY = '__alltrue_perf_logs';

function getStoredLogs() {
  try {
    return JSON.parse(sessionStorage.getItem(PERF_LOG_KEY) || '[]');
  } catch { return []; }
}

function storeLogs(logs) {
  try {
    const trimmed = logs.slice(-200);
    sessionStorage.setItem(PERF_LOG_KEY, JSON.stringify(trimmed));
  } catch { /* quota exceeded — ignore */ }
}

export function createPerfTracker(pageName) {
  const pageLoadStart = performance.now();
  const timings = {};

  function markStart(label) {
    timings[label] = { start: performance.now(), end: null, duration: null };
  }

  function markEnd(label) {
    const entry = timings[label];
    if (!entry) return 0;
    entry.end = performance.now();
    entry.duration = Math.round(entry.end - entry.start);
    return entry.duration;
  }

  async function trackAsync(label, fn) {
    markStart(label);
    try {
      const result = await fn();
      return result;
    } finally {
      const ms = markEnd(label);
      if (ms > 300) {
        console.warn(`[perf:${pageName}] ${label} took ${ms}ms`);
      }
    }
  }

  function markTTI() {
    const tti = Math.round(performance.now() - pageLoadStart);
    timings._tti = { duration: tti };
    return tti;
  }

  function flushReport() {
    const report = {
      page: pageName,
      timestamp: new Date().toISOString(),
      tti: timings._tti?.duration ?? null,
      entries: {},
    };
    for (const [key, val] of Object.entries(timings)) {
      if (key === '_tti') continue;
      report.entries[key] = val.duration ?? null;
    }

    const logs = getStoredLogs();
    logs.push(report);
    storeLogs(logs);

    if (typeof window !== 'undefined' && window.__ALLTRUE_PERF_DEBUG) {
      console.table(report.entries);
    }
    console.log(`[perf:${pageName}] TTI=${report.tti}ms`, report.entries);

    return report;
  }

  function trackComputed(label, computeFn) {
    const start = performance.now();
    const result = computeFn();
    const ms = Math.round(performance.now() - start);
    if (ms > 16) {
      console.warn(`[perf:${pageName}] computed "${label}" took ${ms}ms`);
    }
    return result;
  }

  return { markStart, markEnd, trackAsync, markTTI, flushReport, trackComputed, timings };
}

export function getPerfHistory() {
  return getStoredLogs();
}

export function clearPerfHistory() {
  sessionStorage.removeItem(PERF_LOG_KEY);
}
