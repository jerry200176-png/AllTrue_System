/**
 * Performance feature flags for gradual rollout and instant rollback.
 * Toggle values here to revert to old behavior without code changes.
 *
 * For remote control, these could be fetched from a backend endpoint;
 * for now they are compile-time constants.
 */

const flags = {
  /** Polling interval for unread notifications (ms). Old: 60000, keep same but visibility-gated */
  NOTIFICATION_POLL_INTERVAL: 60000,

  /** Polling interval for chat/bug/director badges (ms). Old: 25000, new: 60000 */
  BADGE_POLL_INTERVAL: 60000,

  /** Polling interval for parent feedback unread badge (ms). */
  FEEDBACK_BADGE_POLL_INTERVAL: 5 * 60 * 1000,

  /** Default per_page for learning records fetch. 200 gives a near-complete view per student without manual pagination */
  LR_DEFAULT_PER_PAGE: 200,

  /**
   * Default rolling time window (in days) applied to LearningRecords initial fetch when the user
   * has not set a date range. 90 ≈ one typical cram-school course cycle and matches the backend
   * config/perfflags.php `learning_records_default_window_days`. 0 = disabled.
   * Overridden at runtime by `/api/v1/health`'s `perf_flags.lr_default_window_days`.
   */
  LR_DEFAULT_WINDOW_DAYS: 90,

  /** Max per_page for class sessions in teacher schedule. Old: 2000, new: 500 */
  SESSION_MAX_PER_PAGE: 500,

  /** Pause polling when page is not visible. Old: false, new: true */
  PAUSE_POLLING_ON_HIDDEN: true,

  /** Students per_page. Old: 500, new: 200 */
  STUDENTS_PER_PAGE: 200,
};

export default flags;

export function getFlag(name) {
  return flags[name];
}

let _remoteLoaded = false;
let _remotePromise = null;

/**
 * Load perf_flags overrides from /api/v1/health once per session and patch `flags` in place.
 * The server is authoritative for numeric thresholds like LR_DEFAULT_WINDOW_DAYS so that
 * operators can tune the experience via `.env` without a frontend rebuild (GitHub / Stripe
 * live-config pattern). Safe to call repeatedly — subsequent calls reuse the first fetch.
 */
export async function loadRemoteFlags() {
  if (_remoteLoaded) return flags;
  if (_remotePromise) return _remotePromise;
  _remotePromise = (async () => {
    try {
      const res = await fetch('/api/v1/health', { credentials: 'same-origin' });
      if (!res.ok) return flags;
      const body = await res.json();
      const pf = body && body.perf_flags ? body.perf_flags : {};
      if (typeof pf.lr_default_window_days === 'number' && pf.lr_default_window_days >= 0) {
        flags.LR_DEFAULT_WINDOW_DAYS = pf.lr_default_window_days;
      }
      if (typeof pf.lr_default_per_page === 'number' && pf.lr_default_per_page > 0) {
        // Respect backend default if set; keep existing client value otherwise.
        flags.LR_DEFAULT_PER_PAGE = Math.min(pf.lr_default_per_page, pf.lr_max_per_page || pf.lr_default_per_page);
      }
      _remoteLoaded = true;
    } catch (_e) {
      // Offline or health endpoint down — fall back to compile-time defaults, do not throw.
    }
    return flags;
  })();
  return _remotePromise;
}
