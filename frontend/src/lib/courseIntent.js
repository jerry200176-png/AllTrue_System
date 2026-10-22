/**
 * in-app #429 (GitHub #4331): one name for adding lessons to the same course.
 * Founder 2026-10-15 Q8=A: adding lessons is always 「續課」. Every call site (課程查找 row + ⋯ menu,
 * 學生管理 course card, the 新增課程 duplicate prompt, purchase dialogs) uses this constant;
 * courseRenewalWording.test.js fails if the old 「加購」 wording comes back in frontend/src.
 */
export const RENEW_LESSONS_LABEL = '續課';

/**
 * in-app #423 (GitHub #4303): 「換師複製」 read as "change teacher", so a director used it to move a
 * student to another teacher. It only copies the settings for a second (雙師) course; the original
 * course and its future lessons stay on the old teacher and keep holding that slot. The label and
 * confirm text say so and point to 轉課, which moves the remaining lessons and frees the old seat.
 */
export const DUAL_TEACHER_COPY_LABEL = '雙師複製';
export const DUAL_TEACHER_COPY_DRAWER_LABEL = '複製為雙師課程（原課程保留）';

export function dualTeacherCopyNotice(teacherName) {
  const teacher = teacherName || '目前老師';
  return `會複製這門課的設定，替同一位學生再開一門由另一位老師上的課（雙師課程）。原課程（${teacher}）和它之後已排的堂次都會保留，${teacher}這個時段的名額也不會釋放。要把學生剩下的課換給新老師，請改用「⋯ › 調動 › 轉課」。`;
}
