const pageGuideConfig = {
  director: [
    {
      target: '[data-guide="director-summary"]',
      icon: '📊',
      title: '今日關鍵數據',
      description: '每次開啟系統先看這裡：未點名堂數、財務／續課需留意筆數、待審核評量，點擊卡片可直接跳到對應功能處理。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="director-alerts"]',
      icon: '🔔',
      title: '繳費／續課提醒與通知',
      description: '列出本分校待繳費、月結將届，以及「已繳費但剩 0～2 堂」需聯繫加購的課程；另可從此區掌握未讀通知，或前往通知中心查看細節。',
      placement: 'right',
    },
    {
      target: '[data-guide="director-pending-evals"]',
      icon: '✅',
      title: '待審核評量',
      description: '老師提交的評量會在此等待主任核准。可直接在這裡點「核准」或「退回」，核准後會自動影響老師的科目數統計。',
      placement: 'left',
    },
    {
      target: '[data-guide="director-teacher-stats"]',
      icon: '👩‍🏫',
      title: '老師科目數摘要',
      description: '快速掌握本月各老師的科目數總覽。若需逐堂明細或匯出報表，請切換到左側「科目數統計」頁面。',
      placement: 'left',
    },
  ],

  notifications: [
    {
      target: '[data-guide="notifications-header"]',
      icon: '📬',
      title: '通知中心',
      description: '所有需要主任處理的事件都集中在這裡：繳費異常、待審評量、未識別刷卡等，建議每天至少查看一次。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="notifications-controls"]',
      icon: '🔧',
      title: '篩選與批次操作',
      description: '可依「狀態」（未讀 / 已讀）或「類型」篩選，也支援「全部標記已讀」或手動同步最新通知。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="notifications-list"]',
      icon: '📋',
      title: '通知清單',
      description: '急件會置頂顯示（紅色標籤）。點通知可展開詳情；部分通知提供「跳轉」按鈕，可直接前往對應功能頁面處理。',
      placement: 'top',
    },
  ],

  calendar: ({ role }) => {
    const base = [
      {
        target: '[data-guide="calendar-header"]',
        icon: '📅',
        title: role === 'teacher' ? '我的課表' : '班級行事曆',
        description: role === 'teacher'
          ? '顯示你本週的課堂安排與學生清單。點課程方塊可查看學生列表、登記出缺勤與填寫評量。'
          : '以週為單位掌握全分校排課狀況，可同時管理排課、調課、請假與教室容量。',
        placement: 'bottom',
      },
      {
        target: '[data-guide="calendar-toolbar"]',
        icon: '🔍',
        title: '日期與篩選工具',
        description: '先用月份選擇器跳到目標週次，再用老師 / 教室 / 科目篩選縮小視圖範圍，讓課表更清晰易讀。',
        placement: 'bottom',
      },
      {
        target: '[data-guide="calendar-grid"]',
        icon: '🗓️',
        title: '課表主視圖',
        description: '點空白時段可新增排課；點課程方塊可查看詳情或進行調課、請假等操作。',
        placement: 'top',
      },
    ];
    if (role !== 'teacher') {
      base.push({
        target: '[data-guide="calendar-grid"]',
        icon: '🖱️',
        title: '右鍵快捷選單',
        description: '在課程卡片上按右鍵（手機長按），可快速建立請假、開啟課程詳情，不需要離開課表頁面。',
        placement: 'top',
      });
      base.push({
        target: '[data-guide="calendar-grid"]',
        icon: '↕️',
        title: '拖曳調課',
        description: '直接拖曳課程卡片到新的日期或時段，放開後系統會自動帶入新時間並完成調課，非常快速。',
        placement: 'top',
      });
      base.push({
        target: '[data-guide="calendar-quick-add"]',
        icon: '➕',
        title: '快速排課',
        description: '點此按鈕開啟快速排課表單，會自動帶入目前視圖的日期與篩選條件，減少重複填寫。',
        placement: 'left',
      });
    }
    return base;
  },

  students: [
    {
      target: '[data-guide="students-header"]',
      icon: '🎓',
      title: '學生管理',
      description: '可在這裡新增單一學生、批次匯入 CSV/Excel，或切換到「已封存」學生清單重新啟用。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="students-filters"]',
      icon: '🔍',
      title: '篩選條件',
      description: '依姓名、年級或狀態快速縮小學生名單，搜尋欄支援模糊匹配，輸入部分名字即可找到。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="students-table"]',
      icon: '📋',
      title: '學生清單與課程展開',
      description: '點學生列可展開其所有在修課程；在展開列可新增課程、購買堂數，或直接點選繳費狀態進行更新。',
      placement: 'top',
    },
  ],

  teachers: [
    {
      target: '[data-guide="teachers-header"]',
      icon: '👩‍🏫',
      title: '老師管理',
      description: '可新增老師帳號（系統會產生初始密碼）、批次匯入，或切換到「待審核」清單處理新申請。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="teachers-filters"]',
      icon: '🔍',
      title: '篩選條件',
      description: '可依姓名、電話、帳號狀態（正式 / 暫停）或教授科目篩選，快速定位目標老師。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="teachers-table"]',
      icon: '📋',
      title: '老師清單',
      description: '可直接編輯老師的分校設定、重設密碼，或點「課表」「課程」按鈕快速跳到對應頁面查看該老師的資料。',
      placement: 'top',
    },
  ],

  'course-mgmt': [
    {
      target: '[data-guide="course-mgmt-header"]',
      icon: '📚',
      title: '課程管理',
      description: '集中查看所有學生課程的排課情形、堂數餘額與繳費狀態，是處理課程異動的主要入口。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="course-mgmt-filters"]',
      icon: '🔍',
      title: '課程篩選',
      description: '可依學生姓名、課程類型（個人 / 群體）、老師或繳費狀態快速縮小清單範圍。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="course-mgmt-table"]',
      icon: '📋',
      title: '課程明細表',
      description: '每列課程可執行請假、調課、購買堂數、編輯課程設定與刪除。堂數欄位會即時反映核准後的剩餘堂數。',
      placement: 'top',
    },
  ],

  classroom: [
    {
      target: '[data-guide="classroom-header"]',
      icon: '🏫',
      title: '教室管理',
      description: '新增或調整教室名稱、最大容納人數與啟用狀態。停用的教室不會出現在排課時的教室選單。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="classroom-table"]',
      icon: '📋',
      title: '教室列表',
      description: '每間教室可直接點擊名稱或容量進行行內編輯，也可停用或永久刪除。刪除前請確認該教室無未來排課。',
      placement: 'top',
    },
  ],

  'subject-units': [
    {
      target: '[data-guide="subject-units-header"]',
      icon: '📊',
      title: '科目數統計',
      description: '選擇年月後，系統會計算該期間所有老師的加權科目數（考量時長與課型），用於薪資計算依據。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="subject-units-summary"]',
      icon: '🔢',
      title: '全校統計摘要',
      description: '顯示本分校本期的總教學時數，以及含輔導課 / 不含輔導課的科目數總計。',
      placement: 'top',
    },
    {
      target: '[data-guide="subject-units-table"]',
      icon: '👩‍🏫',
      title: '老師明細',
      description: '逐位老師列出時數、科目數與佔全校比例，可點擊老師名稱展開課堂明細，或匯出 Excel 進行核算。',
      placement: 'top',
    },
  ],

  attendance: [
    {
      target: '[data-guide="attendance-header"]',
      icon: '✋',
      title: '出缺勤管理',
      description: '支援兩種登記方式：依今日課堂點名（推薦），或手動選擇學生與狀態直接登記（補登適用）。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="attendance-manual"]',
      icon: '✍️',
      title: '手動登記',
      description: '在現場補登時使用：選擇學生與出缺勤狀態後送出，記錄會即時出現在今日紀錄區。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="attendance-overview"]',
      icon: '📋',
      title: '今日出缺勤紀錄',
      description: '可即時查看所有到離班紀錄，用姓名或狀態篩選可快速定位特定學生，點記錄可修改狀態。',
      placement: 'top',
    },
    {
      target: '[data-guide="attendance-pending"]',
      icon: '❓',
      title: '未識別刷卡',
      description: '讀卡機掃到的 RFID 若尚未對應到學生，會暫存在這裡。請點「對應」將卡號綁定到正確的學生帳號。',
      placement: 'top',
    },
  ],

  learning: ({ role }) => {
    const base = [
      {
        target: '[data-guide="learning-header"]',
        icon: role === 'teacher' ? '📖' : '🗂️',
        title: role === 'teacher' ? '課表與學習評量' : '學習評量管理',
        description: role === 'teacher'
          ? '這裡整合你的課表與評量填寫入口。上方是課堂列表，點課程可直接開啟評量表單，填完後送出等待主任審核。'
          : '集中管理所有老師提交的評量記錄。可查看、編輯內容，並執行核准或退回操作。',
        placement: 'bottom',
      },
      {
        target: '[data-guide="learning-filters"]',
        icon: '🔍',
        title: '評量篩選',
        description: '可依學生姓名、老師或審核狀態（待審 / 已核准 / 已退回）快速找到目標記錄。',
        placement: 'bottom',
      },
      {
        target: '[data-guide="learning-table"]',
        icon: '📝',
        title: '評量清單',
        description: role === 'teacher'
          ? '這裡列出你的所有評量記錄，已退回的評量可重新編輯後再次送出。'
          : '主任可在此查看評量內容、批次核准，或退回並附上說明。核准後會同步計算老師科目數。',
        placement: 'top',
      },
    ];
    if (role === 'teacher') {
      base.splice(1, 0, {
        target: '[data-guide="learning-teacher-schedule"]',
        icon: '🗓️',
        title: '今日 / 近期課表',
        description: '從這裡快速找到你剛上完的課堂，點「填寫評量」按鈕即可直接開啟對應的評量表單，不用另外搜尋。',
        placement: 'bottom',
      });
    }
    return base;
  },

  profile: [
    {
      target: '[data-guide="profile-header"]',
      icon: '👤',
      title: '個人管理中心',
      description: '管理你的帳號資料、安全性設定與通知偏好。第一次登入建議先修改密碼，並上傳個人頭像。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="profile-tabs"]',
      icon: '🗂️',
      title: '功能分頁',
      description: '「基本資料」可修改姓名、電話、頭像；「安全性」可變更密碼；「通知偏好」可設定要接收哪些 LINE 推播。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="profile-active-panel"]',
      icon: '💾',
      title: '目前分頁內容',
      description: '在各分頁完成修改後，請記得點「儲存」按鈕。密碼變更後需要重新登入才會生效。',
      placement: 'top',
    },
  ],

  parent: [
    {
      target: '[data-guide="parent-portal-root"]',
      icon: '👨‍👩‍👧',
      title: '家長查詢入口',
      description: '家長可在這裡查詢孩子的課程堂數、學習評量內容與近期出缺勤紀錄，不需要帳號直接用手機號碼驗證。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="parent-login-card"], [data-guide="parent-student-card"]',
      icon: '🔑',
      title: '驗證身份',
      description: '請輸入學生姓名與家長手機號碼進行驗證；若有多位孩子，登入後可切換不同學生查看各自的資料。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="parent-learning-card"], [data-guide="parent-classes-card"]',
      icon: '📊',
      title: '課程與評量摘要',
      description: '可查看孩子的在修課程剩餘堂數、已核准的學習評量回饋，以及繳費狀態提醒。',
      placement: 'top',
    },
  ],

  'director-accounts': [
    {
      target: '[data-guide="director-accounts-header"]',
      icon: '🔐',
      title: '主任帳號審核',
      description: '此頁僅限 Super Admin 使用，用於審核新主任帳號的申請。通過後該帳號才能登入系統管理分校。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="director-accounts-table"]',
      icon: '📋',
      title: '待審清單',
      description: '逐筆查看申請者資料後點「通過」或「拒絕」；通過後系統會寄送通知；拒絕時可附上說明原因。',
      placement: 'top',
    },
  ],

  chat: [
    {
      target: '[data-guide="chat-header"]',
      icon: '💬',
      title: '內部聊天',
      description: '供校區人員即時溝通的訊息系統。對話記錄依左上角選擇的分校篩選，切換分校可查看其他校區的對話。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="chat-thread-list"]',
      icon: '📋',
      title: '對話列表',
      description: '列出你參與的所有對話（一對一或群組）。點「新對話」可開啟私訊或建立群組；長按對話可釘選或刪除。',
      placement: 'right',
    },
    {
      target: '[data-guide="chat-message-area"]',
      icon: '✉️',
      title: '訊息區',
      description: '在下方輸入框打字後按 Enter 或送出按鈕發送訊息。長按訊息可引用回覆或查看已讀狀態。',
      placement: 'top',
    },
  ],

  bugs: [
    {
      target: '[data-guide="bugs-header"]',
      icon: '🐛',
      title: 'Bug 回報追蹤',
      description: '記錄與追蹤系統問題。一般使用者可用右下角的「回報問題」按鈕新增；主任可在這裡查看所有回報並更新狀態。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="bugs-filters"]',
      icon: '🔍',
      title: '篩選條件',
      description: '可依「狀態」（新提交 / 處理中 / 已解決等）與「嚴重度」（嚴重 / 高 / 中 / 低）篩選，優先處理嚴重問題。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="bugs-list"]',
      icon: '📝',
      title: 'Bug 清單',
      description: '每筆 Bug 顯示嚴重度色點、標題、狀態標籤與提交時間。點進去可查看完整描述、截圖，並更新處理狀態。',
      placement: 'top',
    },
  ],

  'line-integration': [
    {
      target: '[data-guide="line-status"]',
      icon: '🟢',
      title: '整合狀態總覽',
      description: '三個燈號代表整合進度：LINE 官方帳號連線、手機 LIFF 一鍵開啟，以及已綁定的家長數量。全部亮綠才算完整設定。',
      placement: 'bottom',
    },
    {
      target: '[data-guide="line-config"]',
      icon: '🔑',
      title: 'LINE 設定填寫',
      description: '填入 Channel Access Token、Channel Secret（從 LINE Developers 後台取得）與 LIFF ID（選填）。儲存後請重新整理確認燈號變綠。',
      placement: 'top',
    },
    {
      target: '[data-guide="line-bound-parents"]',
      icon: '👨‍👩‍👧',
      title: '已綁定家長數',
      description: '家長加入 LINE 官方帳號後即自動綁定。這個數字代表目前可透過 LINE 接收推播的家長總數，設定完成後通知家長掃碼加入。',
      placement: 'bottom',
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
