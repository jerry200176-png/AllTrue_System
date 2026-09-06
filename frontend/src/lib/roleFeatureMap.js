import { getNavigationGroups } from './navigationRegistry.js';

const FEATURE_USAGES = {
  director: {
    'director': '每天開工第一站，掌握校區今日課程堂數、出勤異常、待審假單與營運提醒。',
    'notifications': '集中查看待跟進事項、審核家長回饋、確認學費待繳與課堂異常通知。',
    'calendar': '查看全校每週課表與教室排程，處理臨時調課、代課或教室異動。',
    'attendance': '監控各班點名進度，確認學生實到、請假狀況，或替未點名班級補登出席。',
    'schedule-discrepancy': '排解排課衝突、未預期授課時段或課表與實際不符之異常回報。',
    'learning': '查閱與審核老師送出的學生課堂評語、學習表現及家長雙向回饋。',
    'assessments': '建立與查看學生段考、模擬考測驗成績，分析學習進步曲線與弱點。',
    'question-banks': '管理分校測驗題庫、上傳練習題題目與建立測驗卷資源。',
    'duplicate-review': '當學生同時段有跨班選課或連續上課衝堂時，進行特殊審核確認。',
    'students': '查詢學生基本資料、家長聯絡電話、剩餘堂數與學員合約狀態。',
    'course-mgmt': '搜尋全校常態開課清單、班級學生名單與各科授課進度。',
    'admission-inquiries': '登記家長來電諮詢、安排新生體驗試聽與追蹤後續報名轉化。',
    'tuition-collect': '開立學費繳費單、記錄家長繳費（現金/轉帳）與收據明細查詢。',
    'teachers': '維護分校師資名單、基本資料、授課科目與聯絡方式。',
    'tuition-report': '月底或週對帳時，查看分校學費應收、實收總額與帳務對帳報表。',
    'subject-units': '統計各班與全校科目堂數，核對每週、每月的授課量是否正確。',
    'parttime-payroll': '每月發薪日前核算兼職時薪老師的總授課時數與應發鐘點費。',
    'teacher-eligibility': '審查正職老師每月授課指標、底薪達標門檻與業績獎金計算要件。',
    'chat': '與校區老師或同仁即時線上傳訊，討論班務或交代臨時事項。',
    'bugs': '操作系統發現錯誤、資料異常或顯示問題時，直接提交工程團隊修復。',
    'classroom': '設定分校各間教室名稱、容納人數與可用設備，利於排課防衝堂。',
    'subject-settings': '新增或維護分校開設之科目名稱、年級分類與課綱設定。',
    'line-integration': '設定 LINE 官方帳號推播、訊息範本與學生到離班即時推播狀態。',
    'binding-management': '查詢家長是否已綁定學生帳號，發送綁定驗證碼或協助重新綁定。',
    'binding-conflicts': '當多位家長綁定同一電話或資料衝突時，核對身分並排除衝突。',
    'binding-health': '查看全校學生家長 LINE 綁定覆蓋率與推播送達成效指標。',
    'director-accounts': '總部專用：審核各分校新註冊之主任帳號權限。',
    'branch-management': '總部專用：維護各校區據點資料與營業狀態。',
    'branch-health-board': '總部專用：監控各分校營運健康度與系統運作指標。',
    'nightly-reconcile': '總部專用：檢視每日夜間自動對帳紀錄與堂數差異報表。',
  },
  teacher: {
    'teacher-home': '每天上班必看第一站，確認今日自己的班級課表、學生名單與教室。',
    'calendar': '查閱本週個人授課行程、上課教室與學生名單，規劃教學進度。',
    'attendance': '每堂課上課後 10 分鐘內，完成學生實到、請假、遲到點名標記。',
    'learning': '下課前記錄今日教學進度單元、學生學習表現標籤與家長聯絡回饋。',
    'assessments': '輸入學生段考或模擬測驗分數，追蹤班級與個別學生學習進步趨勢。',
    'question-banks': '查閱題庫考題與範例題目，為課堂練習或小考出題做準備。',
    'subject-units': '查詢自己當月累計授課科目堂數，確認授課時數統計是否無誤。',
    'chat': '與主任或行政人員即時溝通學生特殊狀況或協調代課交接。',
    'bugs': '使用系統遇到畫面卡頓、點名失敗等異常時，隨時點擊回報問題。',
  },
};

export function getRoleFeatureMap(role, options = {}) {
  const isTeacher = role === 'teacher';
  const isDirectorRole = role === 'director' || role === 'admin' || role === 'super_admin';
  if (!isTeacher && !isDirectorRole) {
    return { role, roleLabel: '未授權', highFrequencyCount: 0, advancedCount: 0, totalCount: 0, groups: [], allItems: [], highFrequencyItems: [], advancedItems: [] };
  }
  const roleKey = isTeacher ? 'teacher' : 'director';
  const roleLabel = isTeacher ? '老師' : (role === 'super_admin' ? '總部管理員' : '主任');
  const usages = FEATURE_USAGES[roleKey] || {};
  const navGroups = getNavigationGroups(role, options);
  const groups = [];
  const allItems = [];
  const highFrequencyItems = [];
  const advancedItems = [];

  for (const group of navGroups) {
    const isPrimary = Boolean(group.primary);
    const mappedItems = group.items.map(item => {
      const frequency = isPrimary ? 'high' : 'advanced';
      const usage = usages[item.page] || (isTeacher ? '日常教學操作頁面。' : '校務營運操作頁面。');
      const feat = { page: item.page, label: item.label, icon: item.icon, categoryKey: group.key, categoryTitle: group.title, frequency, frequencyLabel: frequency === 'high' ? '常用高頻' : '進階功能', usage, badgeTypes: item.badgeTypes ? [...item.badgeTypes] : [] };
      allItems.push(feat);
      if (frequency === 'high') highFrequencyItems.push(feat); else advancedItems.push(feat);
      return feat;
    });
    if (mappedItems.length > 0) groups.push({ key: group.key, title: group.title, primary: isPrimary, items: mappedItems });
  }

  return { role, roleLabel, highFrequencyCount: highFrequencyItems.length, advancedCount: advancedItems.length, totalCount: allItems.length, groups, allItems, highFrequencyItems, advancedItems };
}
