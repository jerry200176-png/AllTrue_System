const pageGuideConfig = {
  director: [
    {
      target: '[data-guide="director-summary"]',
      title: '儀表板總覽',
      description: '先看本分校今日關鍵數據，快速掌握未繳費、排課與待審評量。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="director-alerts"]',
      title: '繳費與通知',
      description: '這裡可以快速檢查未繳費提醒與未讀通知，必要時直接前往通知中心。',
      placement: 'right',
    },
    {
      target: '[data-guide="director-pending-evals"]',
      title: '待審核評量',
      description: '主任可在此直接核准或退回評量，核准後會同步影響老師科目數統計。',
      placement: 'left',
    },
    {
      target: '[data-guide="director-teacher-stats"]',
      title: '老師科目數摘要',
      description: '可快速查看本月各老師科目數，完整明細可切到「科目數統計」頁。',
      placement: 'left',
    },
  ],
  notifications: [
    {
      target: '[data-guide="notifications-header"]',
      title: '通知中心',
      description: '集中處理繳費、待審評量與未識別刷卡等事件。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="notifications-controls"]',
      title: '篩選與批次操作',
      description: '可依狀態、類型篩選通知，並支援同步或全部標記已讀。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="notifications-list"]',
      title: '通知清單',
      description: '從急件置頂區開始處理，必要時可直接跳轉到對應功能頁。',
      placement: 'top',
    },
  ],
  calendar: ({ role }) => {
    const base = [
      {
        target: '[data-guide="calendar-header"]',
        title: role === 'teacher' ? '班級行事曆 / 課表' : '班級行事曆 / 課表',
        description: role === 'teacher'
          ? '老師可快速檢視今日/本週課表與課堂內容。'
          : '行政人員可在此以週檢視掌握全校課表，並管理排課、調課、請假與教室容量。',
        placement: 'bottom',
      },
      {
        target: '[data-guide="calendar-toolbar"]',
        title: '日期與篩選工具',
        description: '先設定月份、週次與篩選條件，再開始查看或調整課表。',
        placement: 'bottom',
      },
      {
        target: '[data-guide="calendar-grid"]',
        title: '課表主視圖',
        description: '可點空白時段新增排課，點課程方塊可檢視或操作單堂課。',
        placement: 'top',
      },
    ];
    if (role !== 'teacher') {
      base.push({
        target: '[data-guide="calendar-grid"]',
        title: '右鍵請假',
        description: '在課表中的課程卡片按右鍵可開啟快捷選單，直接建立該堂請假，不用先回到課程編輯。',
        placement: 'top',
      });
      base.push({
        target: '[data-guide="calendar-grid"]',
        title: '拖曳調課',
        description: '直接拖曳課程卡片到新日期/時段即可快速調課，放開滑鼠後會帶入新時間並完成調整。',
        placement: 'top',
      });
      base.push({
        target: '[data-guide="calendar-quick-add"]',
        title: '快速排課',
        description: '常用入口，會自動帶入目前視圖的日期與條件。',
        placement: 'left',
      });
    }
    return base;
  },
  students: [
    {
      target: '[data-guide="students-header"]',
      title: '學生管理入口',
      description: '可新增學生、批次匯入 CSV，並管理課程與家長資訊。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="students-filters"]',
      title: '學生篩選',
      description: '可依姓名、年級、狀態快速縮小名單。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="students-table"]',
      title: '學生與課程明細',
      description: '點學生列可展開課程，進一步新增課程、加購或調整繳費狀態。',
      placement: 'top',
    },
  ],
  teachers: [
    {
      target: '[data-guide="teachers-header"]',
      title: '老師管理',
      description: '可新增老師、批次匯入與切換正式/待審核清單。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="teachers-filters"]',
      title: '篩選條件',
      description: '依姓名、電話、狀態或科目篩選老師資料。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="teachers-table"]',
      title: '老師清單',
      description: '可編輯分校配置、重設密碼，並快速跳到課程或日曆頁。',
      placement: 'top',
    },
  ],
  'course-mgmt': [
    {
      target: '[data-guide="course-mgmt-header"]',
      title: '課程管理總覽',
      description: '集中查看所有學生課程與繳費狀態。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="course-mgmt-filters"]',
      title: '課程篩選',
      description: '可依學生、課型、老師快速縮小清單。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="course-mgmt-table"]',
      title: '課程明細表',
      description: '可執行請假、調課、編輯與刪除，並檢視堂數與排課日期。',
      placement: 'top',
    },
  ],
  classroom: [
    {
      target: '[data-guide="classroom-header"]',
      title: '教室管理',
      description: '新增或調整教室名稱、容量與啟用狀態。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="classroom-table"]',
      title: '教室列表',
      description: '每間教室都可直接編輯、停用或刪除。',
      placement: 'top',
    },
  ],
  'subject-units': [
    {
      target: '[data-guide="subject-units-header"]',
      title: '科目數統計',
      description: '切換月份後可查看該期間的加權科目數。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="subject-units-summary"]',
      title: '統計摘要',
      description: '先看本校總時數與含/不含輔導的科目數。',
      placement: 'top',
    },
    {
      target: '[data-guide="subject-units-table"]',
      title: '老師明細',
      description: '逐位老師檢視時數、科目數與貢獻佔比。',
      placement: 'top',
    },
  ],
  attendance: [
    {
      target: '[data-guide="attendance-header"]',
      title: '出缺勤管理',
      description: '可切換「依節次點名」與「手動登記」兩種處理方式。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="attendance-manual"]',
      title: '手動登記',
      description: '當現場需要補登時，可指定人員與狀態直接建檔。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="attendance-overview"]',
      title: '今日紀錄',
      description: '可即時查看到離班紀錄並用姓名與狀態快速篩選。',
      placement: 'top',
    },
    {
      target: '[data-guide="attendance-pending"]',
      title: '未識別刷卡',
      description: '處理讀卡機尚未對應成功的刷卡資料。',
      placement: 'top',
    },
  ],
  learning: ({ role }) => {
    const base = [
      {
        target: '[data-guide="learning-header"]',
        title: role === 'teacher' ? '課表與評量' : '學習評量管理',
        description: role === 'teacher'
          ? '老師可先看課表，再快速補上當堂評量。'
          : '主任可統一管理、審核與追蹤評量品質。',
        placement: 'bottom',
      },
      {
        target: '[data-guide="learning-filters"]',
        title: '評量篩選',
        description: '可依學生、老師與審核狀態快速查找記錄。',
        placement: 'bottom',
      },
      {
        target: '[data-guide="learning-table"]',
        title: '評量清單',
        description: '在此檢視、編輯與執行核准/退回等審核操作。',
        placement: 'top',
      },
    ];
    if (role === 'teacher') {
      base.splice(1, 0, {
        target: '[data-guide="learning-teacher-schedule"]',
        title: '老師課表區',
        description: '可從課表直接開啟該堂課的評量填寫畫面。',
        placement: 'bottom',
      });
    }
    return base;
  },
  profile: [
    {
      target: '[data-guide="profile-header"]',
      title: '個人管理',
      description: '集中管理個人資料、安全性與通知偏好。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="profile-tabs"]',
      title: '功能分頁',
      description: '可在基本資料、安全性與通知偏好之間切換。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="profile-active-panel"]',
      title: '目前分頁內容',
      description: '依你所在分頁調整對應設定，修改後請記得儲存。',
      placement: 'top',
    },
  ],
  parent: [
    {
      target: '[data-guide="parent-portal-root"]',
      title: '家長入口',
      description: '可查詢孩子近期課程、堂數、評量與出缺勤紀錄。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="parent-login-card"], [data-guide="parent-student-card"]',
      title: '登入與身份資訊',
      description: '未登入時請輸入學生姓名與手機；登入後會顯示學生摘要。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="parent-learning-card"], [data-guide="parent-classes-card"]',
      title: '主要查詢內容',
      description: '可查看已核准學習評量、課程堂數與繳費提醒。',
      placement: 'top',
    },
  ],
  'director-accounts': [
    {
      target: '[data-guide="director-accounts-header"]',
      title: '主任審核',
      description: '此頁僅限 super admin，處理主任帳號的申請審核。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="director-accounts-table"]',
      title: '待審清單',
      description: '可逐筆通過或拒絕申請，完成後名單會自動更新。',
      placement: 'top',
    },
  ],
};

export function resolvePageGuideSteps(pageKey, context = {}) {
  if (!pageKey) return [];
  const config = pageGuideConfig[pageKey];
  if (!config) return [];
  if (typeof config === 'function') {
    const result = config(context);
    return Array.isArray(result) ? result : [];
  }
  return Array.isArray(config) ? config : [];
}

