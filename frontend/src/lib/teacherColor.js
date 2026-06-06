// SmartCalendar 教師配色（#740 Step 3 — 自 SmartCalendar.vue 剝離）。
// ⚠️ 與純函式不同：getTeacherColor 帶 module-level memo 快取（teacherColorMap），
//    屬「有狀態 (stateful)」函式——同一 teacherId 第二次呼叫直接走 cache，顏色穩定。
//    行為等價：getTeacherColor 函式體一字未改，僅加 export。
// 快取改為 module-level singleton（SmartCalendar 單例頁面，語意與原本元件內 map 相同）。

// Teacher colors
const teacherColorMap = {};
const palette = [
  '#1E88E5', '#43A047', '#E53935', '#FB8C00', '#8E24AA',
  '#00ACC1', '#D81B60', '#6D4C41', '#546E7A', '#F4511E',
  '#3949AB', '#00897B', '#C0CA33', '#5E35B1', '#039BE5'
];
export const getTeacherColor = (teacherId) => {
  if (!teacherId) return '#90A4AE';
  if (!teacherColorMap[teacherId]) {
    const idx = Object.keys(teacherColorMap).length % palette.length;
    teacherColorMap[teacherId] = palette[idx];
  }
  return teacherColorMap[teacherId];
};

/** 僅供單元測試重置 memo 快取，正式程式碼請勿呼叫。 */
export const __resetTeacherColorCache = () => {
  for (const k in teacherColorMap) delete teacherColorMap[k];
};

/** 僅供測試：palette 長度（驗證輪替/耗盡行為）。 */
export const __paletteSize = palette.length;
