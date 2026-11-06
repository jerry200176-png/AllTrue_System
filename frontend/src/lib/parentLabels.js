/**
 * The one plain-language map for everything a parent sees (#4986).
 * Unknown codes get a neutral fallback, never the raw code.
 */
export const PARENT_FALLBACK_LABEL = '狀態確認中';

// Internal terms that must never reach a parent screen.
export const PARENT_BANNED_TERMS = ['月結', '共用方案', '已核帳', '堂次', '堂數用完'];

// Every code the parent dashboard can return (session Status, attendance Status, money status).
export const LESSON_STATUS_LABELS = {
  scheduled: '已排課',
  rescheduled: '已排課',
  leave_requested: '請假確認中',
  completed: '已完成',
  makeup: '補課',
  trial: '試聽',
  tutoring: '輔導',
  present: '到',
  late: '遲到',
  absent: '缺課',
  leave: '請假',
  excused: '請假',
  cancelled: '已取消',
};

export const MONEY_STATUS_LABELS = {
  unpaid: '未繳',
  pending: '未繳',
  partial: '部分已繳',
  paid: '已繳',
  free: '不用繳',
  waived: '不用繳',
  review_required: '確認中',
};

export const COURSE_TYPE_LABELS = {
  monthly: '每月方案',
  session: '堂數方案',
  package: '多科方案',
};

const lookup = (table, code) => table[String(code ?? '').trim().toLowerCase()] ?? PARENT_FALLBACK_LABEL;

export const lessonStatusLabel = (code) => lookup(LESSON_STATUS_LABELS, code);
export const moneyStatusLabel = (code) => lookup(MONEY_STATUS_LABELS, code);
export const courseTypeLabel = (code) => lookup(COURSE_TYPE_LABELS, code);
export const outOfLessonsLabel = () => '已上完';

export const findBannedTerms = (text) => PARENT_BANNED_TERMS.filter((t) => String(text ?? '').includes(t));
