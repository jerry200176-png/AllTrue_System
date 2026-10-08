/**
 * 堂數 column text, per billing mode (課程查找 C-PR5): count mode shows what is left (剩 N 堂),
 * monthly shows what has been taught (已上 N 堂), so a monthly row never sits under a 「剩餘」 header.
 */
export function courseLessonCountLabel({ isSession, remaining, completed }) {
  if (isSession) return remaining == null ? '—' : `剩 ${remaining} 堂`;
  return `已上 ${Number(completed) || 0} 堂`;
}
