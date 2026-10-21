/**
 * in-app #429 (GitHub #4331): one name for adding lessons to the same course.
 * Founder 2026-10-15 Q8=A: adding lessons is always 「續課」. Every call site (課程查找 row + ⋯ menu,
 * 學生管理 course card, the 新增課程 duplicate prompt, purchase dialogs) uses this constant;
 * courseRenewalWording.test.js fails if the old 「加購」 wording comes back in frontend/src.
 */
export const RENEW_LESSONS_LABEL = '續課';
