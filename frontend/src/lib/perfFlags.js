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

  /** Default per_page for learning records fetch. Old: 200, new: 50 */
  LR_DEFAULT_PER_PAGE: 50,

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
