/**
 * Curated findings from the non-dashboard UX audit.
 * Presentation metadata only; it never drives permissions or business rules.
 */
export const UI_IMPROVEMENTS = Object.freeze([
  { id: 'course-context', page: 'course-mgmt', pageLabel: '課程管理', severity: 'P0', category: '操作流程', title: '課程操作需要在學生管理與課程管理間切換', impact: '主任容易失去學生、合約與堂次的上下文。', action: '建立學生課程工作區，讓查找、加堂與續報保留同一個操作脈絡。' },
  { id: 'attendance-focus', page: 'attendance', pageLabel: '出缺勤管理', severity: 'P0', category: '資訊層級', title: '學生出缺勤與老師打卡同頁，工作目標不夠明確', impact: '主任進頁後需要先理解頁面，而不是直接處理異常。', action: '拆成清楚的工作分頁，預設先顯示待處理異常。' },
  { id: 'learning-status', page: 'learning', pageLabel: '學習評量表', severity: 'P0', category: '狀態模型', title: '填寫狀態與審核狀態同時出現', impact: '主任不容易判斷下一步是補填、退回，還是核准。', action: '預設顯示待處理佇列，其他狀態收進篩選器。' },
  { id: 'calendar-toolbar', page: 'calendar', pageLabel: '班級行事曆／課表', severity: 'P0', category: '資訊層級', title: '日期、老師、教室與排課操作集中在同一工具列', impact: '高頻操作與低頻設定互相搶注意力。', action: '保留日期與檢視為主要操作，其餘移到篩選與更多操作。' },
  { id: 'billing-queue', page: 'tuition-collect', pageLabel: '帳務中心', severity: 'P0', category: '操作流程', title: '對帳、結算與繳費通知沒有形成單一路徑', impact: '主任需要反覆查找同一位學生，容易漏處理。', action: '改為待處理帳務列表，勾選後顯示固定批次操作列。' },
  { id: 'feedback', page: 'teachers', pageLabel: '老師管理', severity: 'P1', category: '回饋方式', title: '多個作業頁仍使用瀏覽器 alert／confirm', impact: '提示會阻斷流程，也沒有在原位置說明如何修正。', action: '統一使用頁內錯誤提示、Toast 與帶影響筆數的確認視窗。' },
  { id: 'navigation-language', page: 'course-mgmt', pageLabel: '導覽與命名', severity: 'P1', category: '導覽語意', title: '學習評量、學習檢測與課表評量名稱相近', impact: '使用者需要靠猜測分辨不同功能。', action: '以主任／老師的實際任務重新命名，並維持舊 page key 相容。' },
  { id: 'design-convergence', page: 'students', pageLabel: '全站頁面', severity: 'P1', category: '視覺一致性', title: '頁首、篩選列與空狀態尚未全面使用共用元件', impact: '不同頁面有不同的操作密度、間距與按鈕層級。', action: '依 rollout 順序逐頁導入 AtPageHeader、AtFilterBar 與 AtEmpty。' },
  { id: 'mobile-density', page: 'calendar', pageLabel: '手機版頁面', severity: 'P1', category: '響應式', title: '高密度工具列需要重新安排手機操作順序', impact: '窄螢幕上主要操作容易被推到畫面下方。', action: '保留 3–5 個高頻入口，其他控制放進同一個 More drawer。' },
  { id: 'copy-icons', page: 'subject-units', pageLabel: '科目數統計', severity: 'P2', category: '文案與圖示', title: '部分頁面仍有裝飾性 emoji 與不一致文案', impact: '視覺語言不像同一套營運系統。', action: '改用 Material Symbols 與白話、動詞開頭的 UI 文案。' },
]);

export function getUiImprovementSummary(items = UI_IMPROVEMENTS) {
  return items.reduce((summary, item) => {
    summary.total += 1;
    summary[item.severity] = (summary[item.severity] || 0) + 1;
    return summary;
  }, { total: 0, P0: 0, P1: 0, P2: 0 });
}
