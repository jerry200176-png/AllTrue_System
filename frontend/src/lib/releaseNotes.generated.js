/**
 * AUTO-GENERATED — source: docs/CHANGELOG.md
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */
export const changelogReleaseNotes = [
  {
    "version": "1.0.8",
    "date": "2026-05-10",
    "title": "1.0.8 版本更新",
    "summary": "評量 409 bug 修復 + KPI bar + 快速語句擴充；教學工作台「待填／待修改」數改走 ，與側欄角標同源，避免把全時段 pending 與今日缺評量錯誤加總",
    "audience": [
      "teacher",
      "director",
      "parent"
    ],
    "sections": [
      {
        "title": "修正內容",
        "items": [
          "評量 409 bug 修復 + KPI bar + 快速語句擴充",
          "教學工作台「待填／待修改」數改走 ，與側欄角標同源，避免把全時段 pending 與今日缺評量錯誤加總"
        ]
      },
      {
        "title": "體驗調整",
        "items": [
          "移除 重複的「今日最重要 3 件事」與「每日任務清單」區塊，工作台子區塊從 5 降至 3"
        ]
      }
    ],
    "items": [
      "評量 409 bug 修復 + KPI bar + 快速語句擴充",
      "教學工作台「待填／待修改」數改走 ，與側欄角標同源，避免把全時段 pending 與今日缺評量錯誤加總",
      "移除 重複的「今日最重要 3 件事」與「每日任務清單」區塊，工作台子區塊從 5 降至 3"
    ]
  },
  {
    "version": "1.0.7",
    "date": "2026-05-09",
    "title": "1.0.7 版本更新",
    "summary": "一般請假新增 30 秒內撤銷；桌機登入動畫在 reduced-motion 設定下仍可見",
    "audience": [
      "teacher",
      "director",
      "parent"
    ],
    "sections": [
      {
        "title": "修正內容",
        "items": [
          "一般請假新增 30 秒內撤銷",
          "桌機登入動畫在 reduced-motion 設定下仍可見"
        ]
      },
      {
        "title": "體驗調整",
        "items": [
          "登入 Logo 動畫升級為爆發式縮放",
          "主任總覽欄位重新分配，系統新增登入後與閒置 Logo 動畫，版本更新改成白話版本卡"
        ]
      }
    ],
    "items": [
      "一般請假新增 30 秒內撤銷",
      "桌機登入動畫在 reduced-motion 設定下仍可見",
      "登入 Logo 動畫升級為爆發式縮放",
      "主任總覽欄位重新分配，系統新增登入後與閒置 Logo 動畫，版本更新改成白話版本卡"
    ]
  },
  {
    "version": "1.0.6",
    "date": "2026-05-08",
    "title": "1.0.6 版本更新",
    "summary": "例外堂補建 ClassSession 避免灰色堂次消失；教學工作台補填提醒點入時會同步切換至對應分校並定位堂次，且評量頁可顯示近期需補填的非 active 課程，避免提醒可見但無法填寫",
    "audience": [
      "teacher",
      "director",
      "parent"
    ],
    "sections": [
      {
        "title": "修正內容",
        "items": [
          "例外堂補建 ClassSession 避免灰色堂次消失",
          "教學工作台補填提醒點入時會同步切換至對應分校並定位堂次，且評量頁可顯示近期需補填的非 active 課程，避免提醒可見但無法填寫"
        ]
      }
    ],
    "items": [
      "例外堂補建 ClassSession 避免灰色堂次消失",
      "教學工作台補填提醒點入時會同步切換至對應分校並定位堂次，且評量頁可顯示近期需補填的非 active 課程，避免提醒可見但無法填寫"
    ]
  },
  {
    "version": "1.0.5",
    "date": "2026-04-29",
    "title": "1.0.5 版本更新",
    "summary": "課程管理例外堂被請假或取消後，日期 chip 外層標籤優先顯示請假/取消，例外堂改保留在提示資訊；同一學生課程同日多時段時，代課老師評量列表與儲存權限改以同日期同開始時間判定，避免看到非自己時段後儲存 Forbidden",
    "audience": [
      "teacher",
      "director",
      "parent"
    ],
    "sections": [
      {
        "title": "修正內容",
        "items": [
          "課程管理例外堂被請假或取消後，日期 chip 外層標籤優先顯示請假/取消，例外堂改保留在提示資訊",
          "同一學生課程同日多時段時，代課老師評量列表與儲存權限改以同日期同開始時間判定，避免看到非自己時段後儲存 Forbidden",
          "老師自行註冊、主任新增老師與主任申請頁的密碼提示/前端檢查同步為 8 碼，並以緊急前端靜態檔部署上線",
          "老師登入帳號診斷補充 User fallback",
          "read-only teacher sign-in diagnostic workflow to support exact lookup when the displayed teacher name is unknown",
          "read-only teacher sign-in diagnostic command and manual workflow to inspect missing sign-ins without modifying oduction data"
        ]
      }
    ],
    "items": [
      "課程管理例外堂被請假或取消後，日期 chip 外層標籤優先顯示請假/取消，例外堂改保留在提示資訊",
      "同一學生課程同日多時段時，代課老師評量列表與儲存權限改以同日期同開始時間判定，避免看到非自己時段後儲存 Forbidden",
      "老師自行註冊、主任新增老師與主任申請頁的密碼提示/前端檢查同步為 8 碼，並以緊急前端靜態檔部署上線",
      "老師登入帳號診斷補充 User fallback",
      "read-only teacher sign-in diagnostic workflow to support exact lookup when the displayed teacher name is unknown",
      "read-only teacher sign-in diagnostic command and manual workflow to inspect missing sign-ins without modifying oduction data"
    ]
  },
  {
    "version": "1.0.4",
    "date": "2026-04-28",
    "title": "1.0.4 版本更新",
    "summary": "主任總覽儀表板與近 7 天代課紀錄卡片的字體、間距與資訊層級，延續 Porsche 風格 light-first 視覺系統；學生帳務對帳視窗依 ledger 權限顯示一般作廢或沖銷作廢，避免未收款錯帳誤走沖銷流程",
    "audience": [
      "teacher",
      "director",
      "parent"
    ],
    "sections": [
      {
        "title": "新增內容",
        "items": [
          "主任總覽儀表板與近 7 天代課紀錄卡片的字體、間距與資訊層級，延續 Porsche 風格 light-first 視覺系統"
        ]
      },
      {
        "title": "修正內容",
        "items": [
          "學生帳務對帳視窗依 ledger 權限顯示一般作廢或沖銷作廢，避免未收款錯帳誤走沖銷流程",
          "錯帳沖銷作廢與 ledger 狀態",
          "總覽代課動態卡片在子元件內補齊 header/badge 樣式，貼齊今日課表排版並避免裸排版",
          "智慧行事曆同課程同日同時存在請假與 scheduled 例外時保留請假卡，避免張正樂 4/29 請假在課表消失",
          "課程管理月結帳單作廢入口，限制未收款帳單必填原因並保留稽核紀錄，同時修正總覽代課動態卡片排版與 CJK 字型 fallback",
          "同一張 RFID 同時命中大安老師分校卡與學生卡時，刷卡會優先建立老師打卡，避免老師出缺勤列表看不到本人刷卡紀錄"
        ]
      }
    ],
    "items": [
      "主任總覽儀表板與近 7 天代課紀錄卡片的字體、間距與資訊層級，延續 Porsche 風格 light-first 視覺系統",
      "學生帳務對帳視窗依 ledger 權限顯示一般作廢或沖銷作廢，避免未收款錯帳誤走沖銷流程",
      "錯帳沖銷作廢與 ledger 狀態",
      "總覽代課動態卡片在子元件內補齊 header/badge 樣式，貼齊今日課表排版並避免裸排版",
      "智慧行事曆同課程同日同時存在請假與 scheduled 例外時保留請假卡，避免張正樂 4/29 請假在課表消失",
      "課程管理月結帳單作廢入口，限制未收款帳單必填原因並保留稽核紀錄，同時修正總覽代課動態卡片排版與 CJK 字型 fallback",
      "同一張 RFID 同時命中大安老師分校卡與學生卡時，刷卡會優先建立老師打卡，避免老師出缺勤列表看不到本人刷卡紀錄"
    ]
  },
  {
    "version": "1.0.3",
    "date": "2026-04-27",
    "title": "1.0.3 版本更新",
    "summary": "主任儀表板今日課表、繳費提醒、待審評量、通知與 KPI 面板為 light-first Porsche 介面 霧面工作卡；主任儀表板首屏的高級營運指揮艙視覺，包含分校 hero、每日待辦任務列與 performance HUD 統計",
    "audience": [
      "teacher",
      "director",
      "parent"
    ],
    "sections": [
      {
        "title": "新增內容",
        "items": [
          "主任儀表板今日課表、繳費提醒、待審評量、通知與 KPI 面板為 light-first Porsche 介面 霧面工作卡",
          "主任儀表板首屏的高級營運指揮艙視覺，包含分校 hero、每日待辦任務列與 performance HUD 統計",
          "狀態與 Modal 補強",
          "課程管理學生群組、課程 row、操作按鈕與詳情面板的戰術資訊列視覺，提升掃描速度與高風險狀態辨識度",
          "風格 command center 首屏",
          "課程管理主列表、學生群組卡、課程狀態標籤、歷史課程卡與空狀態的 emium 視覺層級，並補上初次載入 skeleton"
        ]
      },
      {
        "title": "修正內容",
        "items": [
          "課程管理加購、月結續約、暫停/恢復、刪除在送出中會鎖定按鈕與 modal，降低雙擊或重送造成重複操作的風險",
          "課程管理月結課程「帳單」入口，主任可查看各期 billing period 的已繳、未繳、部分繳狀態",
          "0元課程可核帳結算",
          "課程管理補課與暫停 UI SaaS 化",
          "課程直接改為歷史狀態時也會取消未來 scheduled 堂次，避免結算後仍顯示未來排課"
        ]
      },
      {
        "title": "體驗調整",
        "items": [
          "AllTrue Porsche 風格 light-first 視覺系統規格與共用前端 token/class，作為後續頁面升級的單一設計依據",
          "課程管理與主任儀表板首屏為 light-first 高級霧面視覺，收斂過重 HUD/雷達感並統一 Porsche 風格 設計語言"
        ]
      }
    ],
    "items": [
      "主任儀表板今日課表、繳費提醒、待審評量、通知與 KPI 面板為 light-first Porsche 介面 霧面工作卡",
      "主任儀表板首屏的高級營運指揮艙視覺，包含分校 hero、每日待辦任務列與 performance HUD 統計",
      "狀態與 Modal 補強",
      "課程管理學生群組、課程 row、操作按鈕與詳情面板的戰術資訊列視覺，提升掃描速度與高風險狀態辨識度",
      "風格 command center 首屏",
      "課程管理主列表、學生群組卡、課程狀態標籤、歷史課程卡與空狀態的 emium 視覺層級，並補上初次載入 skeleton",
      "課程管理加購、月結續約、暫停/恢復、刪除在送出中會鎖定按鈕與 modal，降低雙擊或重送造成重複操作的風險",
      "課程管理月結課程「帳單」入口，主任可查看各期 billing period 的已繳、未繳、部分繳狀態"
    ]
  },
  {
    "version": "1.0.2",
    "date": "2026-04-26",
    "title": "1.0.2 版本更新",
    "summary": "學習評量「學習進度與家長溝通」快捷片語擴充為 10 個家長友善選項，預設顯示 6 個並可展開更多，降低老師撰寫評語負擔；通知中心「標記已繳費」可填繳費日期、方式、金額與備註，並同步建立 Payment / Invoice 核帳記錄",
    "audience": [
      "teacher",
      "director",
      "parent"
    ],
    "sections": [
      {
        "title": "新增內容",
        "items": [
          "學習評量「學習進度與家長溝通」快捷片語擴充為 10 個家長友善選項，預設顯示 6 個並可展開更多，降低老師撰寫評語負擔",
          "通知中心「標記已繳費」可填繳費日期、方式、金額與備註，並同步建立 Payment / Invoice 核帳記錄",
          "三 Tab 架構改版，學習優先（）",
          "通知中心 v2"
        ]
      },
      {
        "title": "修正內容",
        "items": [
          "家長回饋 mark-read 不再更新回饋內容時間，避免刷新後又變未讀；學習評量表新增有回饋／未讀回饋篩選",
          "處理 MIME type 錯誤 及 N+1 誤判 （）",
          "對月結制（payment_type=monthly）課程缺少保護，RemainingSessions=0 + 已繳費導致課程被過濾，補習科目/堂數欄顯示「尚未設定」",
          "密碼改完撤銷全部 token + CSP Report-Only + debug_mode 監測"
        ]
      }
    ],
    "items": [
      "學習評量「學習進度與家長溝通」快捷片語擴充為 10 個家長友善選項，預設顯示 6 個並可展開更多，降低老師撰寫評語負擔",
      "通知中心「標記已繳費」可填繳費日期、方式、金額與備註，並同步建立 Payment / Invoice 核帳記錄",
      "三 Tab 架構改版，學習優先（）",
      "通知中心 v2",
      "家長回饋 mark-read 不再更新回饋內容時間，避免刷新後又變未讀；學習評量表新增有回饋／未讀回饋篩選",
      "處理 MIME type 錯誤 及 N+1 誤判 （）",
      "對月結制（payment_type=monthly）課程缺少保護，RemainingSessions=0 + 已繳費導致課程被過濾，補習科目/堂數欄顯示「尚未設定」",
      "密碼改完撤銷全部 token + CSP Report-Only + debug_mode 監測"
    ]
  },
  {
    "version": "1.0.1",
    "date": "2026-04-25",
    "title": "1.0.1 版本更新",
    "summary": "家長入口跨家庭學生資料洩漏修復 [SECURITY]",
    "audience": [
      "teacher",
      "director",
      "parent"
    ],
    "sections": [
      {
        "title": "修正內容",
        "items": [
          "家長入口跨家庭學生資料洩漏修復 [SECURITY]"
        ]
      }
    ],
    "items": [
      "家長入口跨家庭學生資料洩漏修復 [SECURITY]"
    ]
  }
];
