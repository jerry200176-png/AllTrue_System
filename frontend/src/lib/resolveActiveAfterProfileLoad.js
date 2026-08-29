/**
 * Decide whether a /me profile refresh may change the SPA active page.
 *
 * Landing pages are owned by login / password-change / deep-link handlers.
 * Profile refresh must not yank the user (or smoke tests) back to home after
 * they already navigated — that race was clobbering teacher「我的課表」and
 * director「課程查找」clicks when onAuthStateChange re-fetched /me.
 *
 * Bootstrap: App.vue seeds `active` as `'director'`. On teacher session restore
 * we only rewrite that bootstrap default to teacher-home.
 */

const BOOTSTRAP_ACTIVE = 'director';

/**
 * @param {{
 *   role?: string|null,
 *   mustChangePassword?: boolean,
 *   currentActive?: string|null,
 * }} input
 * @returns {string} next active page id
 */
export function resolveActiveAfterProfileLoad({
  role = null,
  mustChangePassword = false,
  currentActive = BOOTSTRAP_ACTIVE,
} = {}) {
  const current = String(currentActive || BOOTSTRAP_ACTIVE);
  if (mustChangePassword) return 'profile';

  const normalizedRole = String(role || '');
  if (normalizedRole === 'teacher') {
    if (current === BOOTSTRAP_ACTIVE) return 'teacher-home';
    return current;
  }

  if (normalizedRole === 'director' || normalizedRole === 'admin' || normalizedRole === 'super_admin') {
    // Role mismatch cleanup only (e.g. leftover teacher landing). Never reset
    // an intentional director page such as course-mgmt / calendar.
    if (current === 'teacher-home') return 'director';
    return current;
  }

  return current;
}
