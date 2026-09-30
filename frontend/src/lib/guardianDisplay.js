import { formatLocalDate } from './calendarDateUtils.js';

/** 監護人關係代碼 → 中文（與編輯學生 modal 的新增下拉一致）。 */
const GUARDIAN_ROLE_LABELS = { father: '爸爸', mother: '媽媽', guardian: '監護人', other: '其他' };
export const guardianRoleLabel = (role) => GUARDIAN_ROLE_LABELS[role] || role || '';

/** LINE 綁定列：不顯示原始 LINE user id 與 ISO 時間戳。 */
export function lineBindingDisplay(binding) {
  const d = binding?.bound_at ? new Date(binding.bound_at) : null;
  const date = d && !Number.isNaN(d.getTime()) ? formatLocalDate(d).replace(/-/g, '/') : '';
  return { label: '已綁定 LINE', date: date ? `綁定日 ${date}` : '' };
}
