/**
 * AUTO-GENERATED — source: docs/STAFF_UPDATES.yml
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */
export const staffUpdates = [
  {
    "id": "staff-2026-09-01-unpaid-settlement-reconciliation",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "未繳課程可結案並保留待對帳",
    "summary": "課程需要先停止排課時，不必等付款完成；未完成收款的課程會留在帳務中心待對帳。",
    "items": [
      "未繳費課程現在可以結案，結案不會把課程誤標成已繳費。",
      "未完成收款的結案課程會標示「結案待對帳」，可從帳務中心登記回報、確認入帳；確認後才轉為一般已結算。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "未繳費課程現在可以結案，結案不會把課程誤標成已繳費。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "未完成收款的結案課程會標示「結案待對帳」，可從帳務中心登記回報、確認入帳；確認後才轉為一般已結算。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:unpaid-settlement-reconciliation"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-09-01-history-usage-balance-visibility",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "歷史課程也會顯示堂數待對帳",
    "summary": "已結案或完課的歷史課程若堂數需要核對，課程卡會直接顯示提醒與原因。",
    "items": [
      "歷史課程卡補上「堂數待對帳」醒目標籤，不會因被收進歷史區而看不到異常。",
      "提醒可直接查看課堂狀態與扣堂紀錄的差異原因；不會自動修改堂數或帳務資料。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "歷史課程卡補上「堂數待對帳」醒目標籤，不會因被收進歷史區而看不到異常。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "提醒可直接查看課堂狀態與扣堂紀錄的差異原因；不會自動修改堂數或帳務資料。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:history-usage-balance-visibility"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-09-01-calendar-reschedule-authority",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "action_required",
    "title": "行事曆調課不再被畫面誤擋",
    "summary": "請假資料尚在同步時，調課仍可送出由系統做最後確認；真正衝堂仍會被擋下。",
    "items": [
      "行事曆的送出前提示不會再因資料尚未完整載入而直接禁止調課。",
      "確認後仍由系統做最後衝堂檢查；若確實衝堂，資料不會被變更。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "行事曆的送出前提示不會再因資料尚未完整載入而直接禁止調課。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "確認後仍由系統做最後衝堂檢查；若確實衝堂，資料不會被變更。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:calendar-reschedule-authority"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-09-01-calendar-leave-precedence",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "調課時會正確辨識已請假的時段",
    "summary": "請假狀態同步中也不會把已空出的老師時段誤判為滿段。",
    "items": [
      "行事曆調課現在優先採用當日請假狀態，不會因畫面資料同步時間差而誤擋調課。",
      "真正仍在上課的學生依然會列入容量檢查，避免誤放行衝堂。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "行事曆調課現在優先採用當日請假狀態，不會因畫面資料同步時間差而誤擋調課。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "真正仍在上課的學生依然會列入容量檢查，避免誤放行衝堂。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:calendar-leave-precedence"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-09-01-bug-detail-target-correctness",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "Bug 詳情證據不再混用其他個案",
    "summary": "分診用的 Bug 詳情資料會標明目標、適用 probe 與是否達到可判定標準。",
    "items": [
      "Bug 詳情 dump 只執行對應 probe；未配置則標示不適用。",
      "需要目標證據但未配置時，會標示 decision-grade=false。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "Bug 詳情 dump 只執行對應 probe；未配置則標示不適用。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "需要目標證據但未配置時，會標示 decision-grade=false。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:bug-detail-target-correctness"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-09-01-teacher-week-disclosure",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "teacher"
    ],
    "audience": [
      "teacher"
    ],
    "importance": "digest",
    "title": "老師首頁先看今天的課表",
    "summary": "本週課表預設先展開今天，其餘日期需要時再查看。",
    "items": [
      "老師首頁的本週課表只會預設展開今天，其他有課日期仍可點開查看。",
      "跨分校課表、日期切換、課堂內容與評量／回報操作維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "老師首頁的本週課表只會預設展開今天，其他有課日期仍可點開查看。",
          "跨分校課表、日期切換、課堂內容與評量／回報操作維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:teacher-week-disclosure"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-09-01-scheduling-intersection-helper",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "排課先找可行時段",
    "summary": "排課可先核對老師未來四次固定日期的空檔與容量。",
    "items": [
      "勾選學生可配合的星期與時間窗口，即可查看老師可服務分校與未來四次（至課程結束日）固定日期皆可排的時段。",
      "點選候選時段會帶入固定排課欄位；若有日期資料取不到，系統不顯示該星期建議，送出時仍由後端再次檢查衝堂與教室容量。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "勾選學生可配合的星期與時間窗口，即可查看老師可服務分校與未來四次（至課程結束日）固定日期皆可排的時段。",
          "點選候選時段會帶入固定排課欄位；若有日期資料取不到，系統不顯示該星期建議，送出時仍由後端再次檢查衝堂與教室容量。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:scheduling-intersection-helper"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-09-01-calendar-secondary-controls",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "行事曆工具列更清爽",
    "summary": "主要日期控制常駐，其他篩選與操作需要時再展開。",
    "items": [
      "月份、週次、跳至日期與日／週檢視維持直接可用，其他工具集中到「篩選與更多操作」。",
      "收合時會顯示目前啟用的篩選數；展開後原有篩選、老師請假、教室管理與快速排課維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "月份、週次、跳至日期與日／週檢視維持直接可用，其他工具集中到「篩選與更多操作」。",
          "收合時會顯示目前啟用的篩選數；展開後原有篩選、老師請假、教室管理與快速排課維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:calendar-secondary-controls"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-09-01-bug-report-tracking",
    "publishedAt": "2026-09-01",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "Bug 回報送出後可直接追蹤",
    "summary": "送出回報後會保留編號，並可直接前往 Bug 回報查看處理進度。",
    "items": [
      "Bug 回報成功後會保留回報編號與確認訊息，不會短暫顯示後自動消失。",
      "可直接點選「查看回報進度」前往 Bug 回報頁；回報狀態與資料內容維持原規則。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "Bug 回報成功後會保留回報編號與確認訊息，不會短暫顯示後自動消失。",
          "可直接點選「查看回報進度」前往 Bug 回報頁；回報狀態與資料內容維持原規則。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-09-01:bug-report-tracking"
    ],
    "date": "2026-09-01",
    "version": "2026.09.01"
  },
  {
    "id": "staff-2026-08-31-weekly-16-segments",
    "publishedAt": "2026-08-31",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "每週16段課達標可直接查看",
    "summary": "主任可看到每位正職老師的正課、試聽與總段數，並追溯到實際課程。",
    "items": [
      "正職薪資要件頁新增每週課段欄位，正課依實際課程時長換算、試聽每堂固定一段、輔導不計入，並標示是否達到十六段。",
      "可展開查看構成課程的日期、時間、類型與段數；取消、請假、作廢或沒有有效點名的課程不會計入，也不要求先有核准的學習紀錄。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "正職薪資要件頁新增每週課段欄位，正課依實際課程時長換算、試聽每堂固定一段、輔導不計入，並標示是否達到十六段。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "可展開查看構成課程的日期、時間、類型與段數；取消、請假、作廢或沒有有效點名的課程不會計入，也不要求先有核准的學習紀錄。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-31:weekly-16-segments"
    ],
    "date": "2026-08-31",
    "version": "2026.08.31"
  },
  {
    "id": "staff-2026-08-31-usage-balance-visibility",
    "publishedAt": "2026-08-31",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "課程查找會直接顯示堂數待對帳",
    "summary": "堂數資料需要人工核對時，課程名稱旁會直接出現醒目提醒，頁面摘要也會統計筆數。",
    "items": [
      "課堂狀態與扣堂紀錄不一致時，課程名稱旁會顯示「堂數待對帳」，不必再從上課時段欄或滑過摘要才發現。",
      "頁面摘要會顯示待對帳課程數與原因提示；不會自動修改堂數、帳務、出勤或扣堂資料。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "課堂狀態與扣堂紀錄不一致時，課程名稱旁會顯示「堂數待對帳」，不必再從上課時段欄或滑過摘要才發現。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "頁面摘要會顯示待對帳課程數與原因提示；不會自動修改堂數、帳務、出勤或扣堂資料。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-31:usage-balance-visibility"
    ],
    "date": "2026-08-31",
    "version": "2026.08.31"
  },
  {
    "id": "staff-2026-08-31-calendar-leave-capacity-preview",
    "publishedAt": "2026-08-31",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "行事曆調課不再誤判請假時段已滿",
    "summary": "調課預覽會正確排除已請假或取消的課程，和課程查找看到相同的可用時段。",
    "items": [
      "行事曆調課的送出前檢查現在會排除同日期已請假、已調整請假、核准請假與取消的課程，不會再把已空出的老師時段誤判為滿段。",
      "仍會保留有效課程的老師容量檢查，送出時也會由後端再次確認，避免只因畫面快取而放行衝堂。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "行事曆調課的送出前檢查現在會排除同日期已請假、已調整請假、核准請假與取消的課程，不會再把已空出的老師時段誤判為滿段。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "仍會保留有效課程的老師容量檢查，送出時也會由後端再次確認，避免只因畫面快取而放行衝堂。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-31:calendar-leave-capacity-preview"
    ],
    "date": "2026-08-31",
    "version": "2026.08.31"
  },
  {
    "id": "staff-2026-08-31-monthly-opening-date",
    "publishedAt": "2026-08-31",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "月結開課日會正確顯示首堂",
    "summary": "開課日與固定上課星期不同時，月結排課仍會建立開課日首堂，後續固定星期照常排課。",
    "items": [
      "月結單科與多科方案現在都會保留開課日首堂，不會因開課日跨星期而整堂消失。",
      "排課預覽會標示「含開課日首堂」；付款、結算、既有出勤與扣堂歷史不變。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "月結單科與多科方案現在都會保留開課日首堂，不會因開課日跨星期而整堂消失。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "排課預覽會標示「含開課日首堂」；付款、結算、既有出勤與扣堂歷史不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-31:monthly-opening-date"
    ],
    "date": "2026-08-31",
    "version": "2026.08.31"
  },
  {
    "id": "staff-2026-08-31-bug-triage-result-contract",
    "publishedAt": "2026-08-31",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "Bug 回報分診結果更準確",
    "summary": "Bug 回報完成分診後，系統會正確記錄處理結果，避免重複操作。",
    "items": [
      "Bug 回報分診完成後，公開回覆會被正確記錄為已完成，避免畫面已更新卻被誤判失敗而重複處理。",
      "不改 Bug 狀態規則、回覆權限或帳務資料；只有真正成功保存的分診結果才會被視為完成。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "Bug 回報分診完成後，公開回覆會被正確記錄為已完成，避免畫面已更新卻被誤判失敗而重複處理。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "不改 Bug 狀態規則、回覆權限或帳務資料；只有真正成功保存的分診結果才會被視為完成。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-31:bug-triage-result-contract"
    ],
    "date": "2026-08-31",
    "version": "2026.08.31"
  },
  {
    "id": "staff-2026-08-30-teacher-home-single-surface",
    "publishedAt": "2026-08-30",
    "effectiveAt": null,
    "audiences": [
      "teacher"
    ],
    "audience": [
      "teacher"
    ],
    "importance": "digest",
    "title": "老師工作台更聚焦下一步",
    "summary": "今天的待辦集中在一個工作佇列，本週課表直接可見，減少在多個區塊間猜下一步。",
    "items": [
      "老師工作台現在以「今天要完成」作為唯一主要待辦入口，舊的隱藏待辦、提示音與重複捷徑不再干擾畫面。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "老師工作台現在以「今天要完成」作為唯一主要待辦入口，舊的隱藏待辦、提示音與重複捷徑不再干擾畫面。"
        ]
      }
    ],
    "sourceRefs": [],
    "date": "2026-08-30",
    "version": "2026.08.30"
  },
  {
    "id": "staff-2026-08-29-cross-contract-makeup-conflict",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "補課候選會先避開跨合約衝堂",
    "summary": "安排補課前會先檢查同一學生其他合約的已排時段。",
    "items": [
      "搜尋補課時段會排除同一學生在其他合約已有的正式堂次，不會先顯示確認後才被擋的時段。",
      "尚未物化但已預約的排課也會納入檢查；若搜尋後時段被占用，確認時會清楚提示並保留原案件。",
      "付款、出席、扣堂歷史與合約日期不會因補課衝堂檢查被自動改寫。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "搜尋補課時段會排除同一學生在其他合約已有的正式堂次，不會先顯示確認後才被擋的時段。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "尚未物化但已預約的排課也會納入檢查；若搜尋後時段被占用，確認時會清楚提示並保留原案件。",
          "付款、出席、扣堂歷史與合約日期不會因補課衝堂檢查被自動改寫。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:cross-contract-makeup-conflict"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-teachers-modal-semantics",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "老師管理彈窗上下文更清楚",
    "summary": "新增、編輯與批次新增老師時，鍵盤與螢幕閱讀器能辨識目前工作階段。",
    "items": [
      "老師管理的新增、編輯與批次新增彈窗清楚標示工作階段與標題，鍵盤與螢幕閱讀器更容易掌握上下文。",
      "老師管理彈窗的取消、儲存與批次結果操作按鈕補上正確型別，表單操作更不容易被誤判為送出。",
      "老師管理的搜尋、狀態與科目篩選補上欄位標籤關聯，鍵盤操作時更容易知道目前控制項用途。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "老師管理的新增、編輯與批次新增彈窗清楚標示工作階段與標題，鍵盤與螢幕閱讀器更容易掌握上下文。",
          "老師管理彈窗的取消、儲存與批次結果操作按鈕補上正確型別，表單操作更不容易被誤判為送出。",
          "老師管理的搜尋、狀態與科目篩選補上欄位標籤關聯，鍵盤操作時更容易知道目前控制項用途。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:teachers-modal-semantics"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-teachers-list-status",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "老師狀態與識別資訊更清楚",
    "summary": "老師管理的狀態切換、提醒色與 RFID 識別碼更容易閱讀。",
    "items": [
      "「正式老師／待審核／停用」分頁會清楚連到目前內容區，主任切換後能直接聚焦該狀態的老師。",
      "待審核與停用的數字提醒色，以及老師卡片上的狀態標籤，現在使用一致的語意顏色與文字。",
      "RFID 識別碼使用穩定的等寬數字呈現；老師資料、帳號、綁定、權限與既有操作行為維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "「正式老師／待審核／停用」分頁會清楚連到目前內容區，主任切換後能直接聚焦該狀態的老師。",
          "待審核與停用的數字提醒色，以及老師卡片上的狀態標籤，現在使用一致的語意顏色與文字。",
          "RFID 識別碼使用穩定的等寬數字呈現；老師資料、帳號、綁定、權限與既有操作行為維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:teachers-list-status"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-teacher-secondary-cta",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "teacher"
    ],
    "audience": [
      "teacher"
    ],
    "importance": "digest",
    "title": "今天先做的那一件事更清楚",
    "summary": "今日工作佇列只保留一顆主行動，其餘待辦改為次要按鈕。",
    "items": [
      "「現在先做」仍是清楚的主按鈕；「接著處理」改為次要樣式，比較不會搶走視線。",
      "任務排序、點名／評量導頁、資料與權限維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "「現在先做」仍是清楚的主按鈕；「接著處理」改為次要樣式，比較不會搶走視線。",
          "任務排序、點名／評量導頁、資料與權限維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:teacher-secondary-cta"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-teacher-queue-focus",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "teacher"
    ],
    "audience": [
      "teacher"
    ],
    "importance": "digest",
    "title": "查看今日任務會直接進入工作區",
    "summary": "老師從工作台捷徑進入今日任務時，焦點會跟著移到「今天要完成」。",
    "items": [
      "點「查看今日任務」後會捲到今日工作佇列，鍵盤焦點也會落在工作區標題。",
      "任務排序、點名／評量內容、導頁、資料與權限維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "點「查看今日任務」後會捲到今日工作佇列，鍵盤焦點也會落在工作區標題。",
          "任務排序、點名／評量內容、導頁、資料與權限維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:teacher-queue-focus"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-teacher-partial-queue-error",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "teacher"
    ],
    "audience": [
      "teacher"
    ],
    "importance": "digest",
    "title": "工作台不會被單一資料失敗卡住",
    "summary": "家長回覆暫時無法載入時，已載入的點名與評量工作仍可繼續處理。",
    "items": [
      "家長回覆資料失敗會顯示部分待辦提示，不會隱藏仍可處理的工作。",
      "點名／評量等關鍵資料失敗時仍會防止誤判為全部完成。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "家長回覆資料失敗會顯示部分待辦提示，不會隱藏仍可處理的工作。",
          "點名／評量等關鍵資料失敗時仍會防止誤判為全部完成。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:teacher-partial-queue-error"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-teacher-next-action",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "teacher"
    ],
    "audience": [
      "teacher"
    ],
    "importance": "digest",
    "title": "老師工作台先做一件事",
    "summary": "第一個待辦會清楚標示現在先做，其餘工作保留在接著處理。",
    "items": [
      "工作台依照既有期限與影響排序，將第一個待辦獨立標示為「現在先做」，並直接提供對應的行動按鈕。",
      "其他待辦仍會保留在「接著處理」清單；請假待審堂次不會被誤列為老師要處理的工作。",
      "手機版主行動會在同一張卡片內完整呈現，原有導頁、權限與資料行為不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "工作台依照既有期限與影響排序，將第一個待辦獨立標示為「現在先做」，並直接提供對應的行動按鈕。",
          "其他待辦仍會保留在「接著處理」清單；請假待審堂次不會被誤列為老師要處理的工作。",
          "手機版主行動會在同一張卡片內完整呈現，原有導頁、權限與資料行為不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:teacher-next-action"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-teacher-home-notification-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "teacher"
    ],
    "audience": [
      "teacher"
    ],
    "importance": "digest",
    "title": "待辦提示音狀態更清楚",
    "summary": "教師可清楚知道待辦提示音是否開啟，也能辨識今日靜音操作。",
    "items": [
      "提示音開關會標示目前開啟／關閉狀態，鍵盤與螢幕閱讀器也能取得相同資訊。",
      "今日靜音按鈕補上明確用途名稱；待辦排序、點名導頁與資料流程不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "提示音開關會標示目前開啟／關閉狀態，鍵盤與螢幕閱讀器也能取得相同資訊。",
          "今日靜音按鈕補上明確用途名稱；待辦排序、點名導頁與資料流程不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:teacher-home-notification-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-teacher-card-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "teacher"
    ],
    "audience": [
      "teacher"
    ],
    "importance": "digest",
    "title": "老師工作台的控制項更穩定",
    "summary": "今日打卡與本週課表的操作更容易用鍵盤、觸控與螢幕閱讀器理解。",
    "items": [
      "今日打卡狀態卡片現在是清楚的原生按鈕，會說明目前狀態並保留前往出缺勤管理的行為。",
      "本週課表的上一週／下一週與課表圖示操作都有明確名稱，不必只依賴圖示或滑鼠提示。",
      "出缺勤、課表、評量資料、API、權限與既有導頁行為維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "今日打卡狀態卡片現在是清楚的原生按鈕，會說明目前狀態並保留前往出缺勤管理的行為。",
          "本週課表的上一週／下一週與課表圖示操作都有明確名稱，不必只依賴圖示或滑鼠提示。",
          "出缺勤、課表、評量資料、API、權限與既有導頁行為維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:teacher-card-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-students-row-disclosure",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "學生列表展開更容易操作",
    "summary": "學生資料列可用鍵盤展開，並清楚連到課程工作區。",
    "items": [
      "在學生列表將焦點放到資料列後，可以使用 Enter 或 Space 展開／收合課程工作區。",
      "學生資料列會明確告知目前展開狀態與下方課程工作區的關係，螢幕閱讀器更容易理解。",
      "勾選、編輯與刪除仍是獨立操作，不會被資料列的鍵盤展開行為誤觸。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "在學生列表將焦點放到資料列後，可以使用 Enter 或 Space 展開／收合課程工作區。",
          "學生資料列會明確告知目前展開狀態與下方課程工作區的關係，螢幕閱讀器更容易理解。",
          "勾選、編輯與刪除仍是獨立操作，不會被資料列的鍵盤展開行為誤觸。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:students-row-disclosure"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-students-modal-semantics",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "學生管理視窗更容易辨識",
    "summary": "新增／編輯學生與課程等視窗現在會清楚告訴鍵盤與螢幕閱讀器目前工作區。",
    "items": [
      "學生管理的新增／編輯學生、課程、帳單、加購、年級升級與跨分校身份視窗補上清楚的對話框標題。",
      "既有資料、操作流程、帳務規則與權限維持不變；本次只改善視窗語意。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "學生管理的新增／編輯學生、課程、帳單、加購、年級升級與跨分校身份視窗補上清楚的對話框標題。",
          "既有資料、操作流程、帳務規則與權限維持不變；本次只改善視窗語意。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:students-modal-semantics"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-student-course-overview",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "學生課程總覽更容易判斷下一步",
    "summary": "展開學生後先看到課程摘要，並優先選出需要處理的課程。",
    "items": [
      "課程總覽會摘要顯示進行中、需要注意與歷史課程數量，減少在多門課程間反覆尋找。",
      "課程選擇器會優先帶出需要處理的課程；切換課程時保留既有課程操作與資料行為。",
      "手機版主要課程工作區維持可讀寬度，學生列表的次要欄位仍可水平查閱。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "課程總覽會摘要顯示進行中、需要注意與歷史課程數量，減少在多門課程間反覆尋找。",
          "課程選擇器會優先帶出需要處理的課程；切換課程時保留既有課程操作與資料行為。",
          "手機版主要課程工作區維持可讀寬度，學生列表的次要欄位仍可水平查閱。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:student-course-overview"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-student-course-next-action",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "學生課程下一步更明確",
    "summary": "課程工作區會清楚說明現在該處理什麼，主行動也會對應目前狀態。",
    "items": [
      "「現在先處理」會說明續報、付款待確認、資料待補或一般課程的下一步，不必只靠顏色猜測。",
      "主行動會依既有課程狀態前往續報加購、繳費資訊或編輯課程，並保留原有操作行為。",
      "課程資料、付款、排課、權限、API 與手機版可讀布局維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "「現在先處理」會說明續報、付款待確認、資料待補或一般課程的下一步，不必只靠顏色猜測。",
          "主行動會依既有課程狀態前往續報加購、繳費資訊或編輯課程，並保留原有操作行為。",
          "課程資料、付款、排課、權限、API 與手機版可讀布局維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:student-course-next-action"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-student-course-disclosure",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "學生課程細節分層更清楚",
    "summary": "目前課程與歷史課程分開，先處理選定課程再查看舊資料。",
    "items": [
      "學生課程頁會明確標示目前課程工作區，選定課程的完整資料與下一步集中在同一區域。",
      "歷史課程維持在獨立的按需展開區，不會和目前課程混在同一個工作層級。",
      "歷史區補上鍵盤與螢幕閱讀器需要的展開狀態，原有編輯與刪除操作不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "學生課程頁會明確標示目前課程工作區，選定課程的完整資料與下一步集中在同一區域。",
          "歷史課程維持在獨立的按需展開區，不會和目前課程混在同一個工作層級。",
          "歷史區補上鍵盤與螢幕閱讀器需要的展開狀態，原有編輯與刪除操作不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:student-course-disclosure"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-schedule-orphan-prevention",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "調課錯誤更容易處理",
    "summary": "找不到原堂次時不會留下日曆無法操作的調課目標。",
    "items": [
      "跨日調課若缺少原堂次，會在寫入前明確提示，不再留下孤兒排程。",
      "已有原堂次的合法調課流程維持不變；本次不改既有資料。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "跨日調課若缺少原堂次，會在寫入前明確提示，不再留下孤兒排程。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "已有原堂次的合法調課流程維持不變；本次不改既有資料。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:schedule-orphan-prevention"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-reflow-duplicate-target",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "重整排課遇到重複時段會清楚提示",
    "summary": "固定排課設定產生相同目標時段時，不會再顯示不明確的伺服器錯誤。",
    "items": [
      "系統會在移動堂次前攔截重複的日期／時間目標，並回傳時段衝突提示。",
      "原子交易與唯一時段防線維持不變，錯誤時不會留下半套重整結果。",
      "既有堂次、扣堂、評量與排課資料不會因這次防線被自動改寫。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "系統會在移動堂次前攔截重複的日期／時間目標，並回傳時段衝突提示。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "原子交易與唯一時段防線維持不變，錯誤時不會留下半套重整結果。",
          "既有堂次、扣堂、評量與排課資料不會因這次防線被自動改寫。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:reflow-duplicate-target"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-profile-nav-clobber",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "登入後側欄切頁更穩定",
    "summary": "登入後立刻點側欄，不會再被系統拉回首頁。",
    "items": [
      "個人資料載入完成後，不會把你剛切好的頁面（例如我的課表、課程查找）強制拉回工作台／總覽。",
      "強制改密與角色首頁冷啟動行為維持不變；權限、分校與各頁業務邏輯也不變。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "個人資料載入完成後，不會把你剛切好的頁面（例如我的課表、課程查找）強制拉回工作台／總覽。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "強制改密與角色首頁冷啟動行為維持不變；權限、分校與各頁業務邏輯也不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:profile-nav-clobber"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-notifications-dialog-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "主任收件匣工作區更穩定",
    "summary": "收件匣分頁與核帳登記更容易理解，也更適合鍵盤操作。",
    "items": [
      "「待辦案件／營運通知」分頁會清楚連到目前內容區，切換後能直接聚焦正在處理的工作區。",
      "核帳登記使用一致的對話框操作，支援關閉按鈕、Escape 與初始鍵盤焦點；通知操作不會意外送出表單。",
      "付款資料、核帳規則、API、權限與既有導頁行為維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "「待辦案件／營運通知」分頁會清楚連到目前內容區，切換後能直接聚焦正在處理的工作區。",
          "核帳登記使用一致的對話框操作，支援關閉按鈕、Escape 與初始鍵盤焦點；通知操作不會意外送出表單。",
          "付款資料、核帳規則、API、權限與既有導頁行為維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:notifications-dialog-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-logging-facade-runtime",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "錯誤處理更穩定",
    "summary": "分校選擇與工作台的例外處理不會因記錄錯誤而變成第二個系統錯誤。",
    "items": [
      "公開分校清單遇到暫時性結構／資料庫問題時，會保留清楚的空清單回應，不再出現額外的伺服器錯誤。",
      "排課與薪資的例外記錄會正確寫入，方便追查問題並保留原本的安全備援。",
      "本次不改學生資料、帳務、排課規則、權限或既有成功流程。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "公開分校清單遇到暫時性結構／資料庫問題時，會保留清楚的空清單回應，不再出現額外的伺服器錯誤。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "排課與薪資的例外記錄會正確寫入，方便追查問題並保留原本的安全備援。",
          "本次不改學生資料、帳務、排課規則、權限或既有成功流程。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:logging-facade-runtime"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-learning-view-actions-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "評量檢視與操作狀態更清楚",
    "summary": "切換檢視模式與執行評量操作時，控制項的目前狀態與用途更明確。",
    "items": [
      "列表／卡片與內容預覽會標示目前選取狀態，鍵盤與螢幕閱讀器更容易辨識檢視模式。",
      "批次審核、單筆操作、匯出、草稿與彈窗按鈕補上正確語意；既有資料與權限流程不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "列表／卡片與內容預覽會標示目前選取狀態，鍵盤與螢幕閱讀器更容易辨識檢視模式。",
          "批次審核、單筆操作、匯出、草稿與彈窗按鈕補上正確語意；既有資料與權限流程不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:learning-view-actions-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-learning-schedule-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "評量課表檢視控制更清楚",
    "summary": "今日／本週、週次切換與填寫操作會以清楚的按鈕語意呈現。",
    "items": [
      "評量頁課表的今日／本週切換與週次前後按鈕補上明確名稱，鍵盤與螢幕閱讀器更容易操作。",
      "原有課表資料、填寫導向、評量內容、權限與 API 維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "評量頁課表的今日／本週切換與週次前後按鈕補上明確名稱，鍵盤與螢幕閱讀器更容易操作。",
          "原有課表資料、填寫導向、評量內容、權限與 API 維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:learning-schedule-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-learning-review-tabs-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "評量審核佇列更容易辨識",
    "summary": "評量分頁現在會清楚連到目前清單，切換後只聚焦該審核狀態。",
    "items": [
      "主任與老師的審核狀態分頁補上清楚的選取狀態，並連到評量清單工作區。",
      "審核規則、核准同步點名／扣堂、API、權限與批次操作維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "主任與老師的審核狀態分頁補上清楚的選取狀態，並連到評量清單工作區。",
          "審核規則、核准同步點名／扣堂、API、權限與批次操作維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:learning-review-tabs-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-learning-filter-chips-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "評量快捷篩選狀態更清楚",
    "summary": "未填、需修改與家長留言篩選會清楚說明目前是否選取。",
    "items": [
      "評量頁快捷篩選改用明確的按鈕與選取狀態，鍵盤與螢幕閱讀器更容易辨識目前篩選。",
      "原有篩選條件、排序、資料、權限與審核流程維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "評量頁快捷篩選改用明確的按鈕與選取狀態，鍵盤與螢幕閱讀器更容易辨識目前篩選。",
          "原有篩選條件、排序、資料、權限與審核流程維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:learning-filter-chips-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-goto-purchase",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "新增課程可直接加購",
    "summary": "學生已有課程時，點「去加購」會打開加購堂數。",
    "items": [
      "學生管理新增課程後，點「去加購」會打開該課的加購視窗。",
      "系統對不到課程時會提示重新整理，不再沒有反應。",
      "課程資料、加購規則、權限與既有課程列「加購」維持不變。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "學生管理新增課程後，點「去加購」會打開該課的加購視窗。",
          "系統對不到課程時會提示重新整理，不再沒有反應。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "課程資料、加購規則、權限與既有課程列「加購」維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:goto-purchase"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-director-view-switcher-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "主任總覽檢視切換更清楚",
    "summary": "今天與完整營運兩個工作區的切換關係更容易理解。",
    "items": [
      "主任總覽的檢視分頁會清楚連到對應內容區，鍵盤與螢幕閱讀器能辨識目前工作區。",
      "今日待辦優先、完整營運按需載入與原有導頁、資料、權限行為維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "主任總覽的檢視分頁會清楚連到對應內容區，鍵盤與螢幕閱讀器能辨識目前工作區。",
          "今日待辦優先、完整營運按需載入與原有導頁、資料、權限行為維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:director-view-switcher-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-course-tabs-keyboard",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程查找分頁更適合鍵盤操作",
    "summary": "每位學生的課程／帳務分頁可用方向鍵切換，焦點會跟著目前工作區移動。",
    "items": [
      "在課程查找展開學生後，可用左右鍵或上下鍵切換「課程資料／帳務資料」，不用逐一 Tab 經過每個選項。",
      "目前分頁保留單一鍵盤焦點入口，切換後會聚焦新的工作區；課程、帳務資料與權限維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "在課程查找展開學生後，可用左右鍵或上下鍵切換「課程資料／帳務資料」，不用逐一 Tab 經過每個選項。",
          "目前分頁保留單一鍵盤焦點入口，切換後會聚焦新的工作區；課程、帳務資料與權限維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:course-tabs-keyboard"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-course-management-hierarchy",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程查找操作層級更清楚",
    "summary": "學生群組、課程／帳務分頁與歷史資料的操作關係更明確。",
    "items": [
      "學生群組的展開與「專注此學生」改成兩個清楚分開的操作，鍵盤可以直接使用 Enter 或 Space 展開與收合。",
      "課程資料與帳務資料分頁補上明確的控制關係；切換分頁會自動保留原有資料與操作流程。",
      "歷史課程維持按需展開，並補上展開狀態與可控制區域，讓螢幕閱讀器能理解目前看到的內容。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "學生群組的展開與「專注此學生」改成兩個清楚分開的操作，鍵盤可以直接使用 Enter 或 Space 展開與收合。",
          "課程資料與帳務資料分頁補上明確的控制關係；切換分頁會自動保留原有資料與操作流程。",
          "歷史課程維持按需展開，並補上展開狀態與可控制區域，讓螢幕閱讀器能理解目前看到的內容。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:course-management-hierarchy"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-bug-report-triage-context",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "回報問題時更容易提供線索",
    "summary": "可補充發生時間與相關資料，幫助更快找到問題。",
    "items": [
      "回報視窗可選填發生時間，以及學生、課程、課堂或發票編號等相關資料。",
      "描述欄提供簡短提示；欄位不會要求密碼，也不改既有操作或資料。",
      "處理人員查看詳情時會直接看到這些補充線索，送出成功也會顯示回報編號，減少另外查找回報內容的步驟。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "回報視窗可選填發生時間，以及學生、課程、課堂或發票編號等相關資料。",
          "描述欄提供簡短提示；欄位不會要求密碼，也不改既有操作或資料。",
          "處理人員查看詳情時會直接看到這些補充線索，送出成功也會顯示回報編號，減少另外查找回報內容的步驟。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:bug-report-triage-context"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-bug-list-keyboard",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "Bug 回報可以用鍵盤快速查看",
    "summary": "在回報列表中可直接用鍵盤選取問題並開啟詳情。",
    "items": [
      "聚焦回報項目後按 Enter 或 Space，即可查看問題詳情。",
      "目前選取的回報會有清楚的輔助標示；原有滑鼠操作維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "聚焦回報項目後按 Enter 或 Space，即可查看問題詳情。",
          "目前選取的回報會有清楚的輔助標示；原有滑鼠操作維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:bug-list-keyboard"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-billing-tab-panels",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "帳務分頁只顯示目前工作區",
    "summary": "帳務中心切換分頁後，畫面會只保留目前要處理的帳務內容。",
    "items": [
      "「待處理／已結清課程彙總／收據紀錄」不會再同時顯示彼此的工作區，主任切換後能直接聚焦目前任務。",
      "三個帳務分頁與內容區補上鍵盤與螢幕閱讀器的控制關係，降低誤判目前工作區的機會。",
      "付款規則、收據資料、API、權限與既有手機版布局維持不變。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "「待處理／已結清課程彙總／收據紀錄」不會再同時顯示彼此的工作區，主任切換後能直接聚焦目前任務。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "三個帳務分頁與內容區補上鍵盤與螢幕閱讀器的控制關係，降低誤判目前工作區的機會。",
          "付款規則、收據資料、API、權限與既有手機版布局維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:billing-tab-panels"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-attendance-tab-status-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "出缺勤工作區切換更清楚",
    "summary": "點名與打卡工作區，以及每堂到班狀態，都更容易用鍵盤與螢幕閱讀器理解。",
    "items": [
      "主任的「學生點名／老師打卡」分頁會清楚連到目前內容區，切換後能直接知道正在處理哪一個工作區。",
      "待點名堂次的到班狀態按鈕會公告目前選取狀態，鍵盤焦點也更明顯，不再只依賴顏色判斷。",
      "點名資料、扣堂、RFID、API、權限與既有送出行為維持不變；老師仍使用原有學生點名工作區。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "主任的「學生點名／老師打卡」分頁會清楚連到目前內容區，切換後能直接知道正在處理哪一個工作區。",
          "待點名堂次的到班狀態按鈕會公告目前選取狀態，鍵盤焦點也更明顯，不再只依賴顏色判斷。",
          "點名資料、扣堂、RFID、API、權限與既有送出行為維持不變；老師仍使用原有學生點名工作區。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:attendance-tab-status-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-29-assessment-actions-a11y",
    "publishedAt": "2026-08-29",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "學習檢測操作更不容易誤觸",
    "summary": "建立、結果、作答與補強操作的按鈕用途更明確。",
    "items": [
      "檢測建立、發布、結果、作答與補強按鈕補上正確語意，表單情境不會因隱含型別誤送出。",
      "建立檢測與查看結果彈窗會清楚標示工作階段與標題，鍵盤與螢幕閱讀器更容易掌握上下文。",
      "原有檢測資料、主任複核、補強追蹤、權限與 API 流程維持不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "檢測建立、發布、結果、作答與補強按鈕補上正確語意，表單情境不會因隱含型別誤送出。",
          "建立檢測與查看結果彈窗會清楚標示工作階段與標題，鍵盤與螢幕閱讀器更容易掌握上下文。",
          "原有檢測資料、主任複核、補強追蹤、權限與 API 流程維持不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-29:assessment-actions-a11y"
    ],
    "date": "2026-08-29",
    "version": "2026.08.29"
  },
  {
    "id": "staff-2026-08-28-schedule-safe-recovery",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "取消課堂可安全復原",
    "summary": "誤取消單堂課後，可在同一堂次檢視視窗復原上一個狀態。",
    "items": [
      "取消堂次重新開啟後，只有系統確認是最近一次主任操作且沒有衝堂時才會顯示復原按鈕。",
      "復原前請確認日期與時段並填寫原因；系統會同步排課、必要評量／點名與堂數。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "取消堂次重新開啟後，只有系統確認是最近一次主任操作且沒有衝堂時才會顯示復原按鈕。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "復原前請確認日期與時段並填寫原因；系統會同步排課、必要評量／點名與堂數。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:schedule-safe-recovery"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-leave-trial-conversion",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "請假可安全撤銷，試聽可直接轉正式",
    "summary": "請假不必再用已上再改回未上的繞路；試聽轉正式也不必手動搬堂次。",
    "items": [
      "請假堂次請使用「取消請假」；系統會同步復原該堂與請假紀錄，禁止直接改成已上或未上造成資料不同步。",
      "試聽確定入班時，請按「轉為正式課程」並選擇正式堂數與開始日；試聽堂與評量會保留，未來試聽排課會取消，不會重複計堂。",
      "若看到缺少順延尾堂的提示，請依畫面到合約／堂次調整做對帳；系統不會自行刪除其他堂次。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "請假堂次請使用「取消請假」；系統會同步復原該堂與請假紀錄，禁止直接改成已上或未上造成資料不同步。"
        ]
      },
      {
        "title": "你現在可以",
        "items": [
          "試聽確定入班時，請按「轉為正式課程」並選擇正式堂數與開始日；試聽堂與評量會保留，未來試聽排課會取消，不會重複計堂。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "若看到缺少順延尾堂的提示，請依畫面到合約／堂次調整做對帳；系統不會自行刪除其他堂次。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:leave-trial-conversion"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-ops-ui-sweep",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "側欄與工作頁更清楚",
    "summary": "常用工作先顯示，低頻功能仍可展開使用。",
    "items": [
      "側欄將每日工作、教學現場、學生課程與財務人事分組；進階工具、報表薪資、訊息回報改為需要時再展開。",
      "出缺勤、教學工作台、老師管理與帳務中心統一頁首與摘要，重新整理時會顯示一致的載入狀態。",
      "找不到低頻功能時，請展開對應區段；原有功能入口與資料操作沒有刪除。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "側欄將每日工作、教學現場、學生課程與財務人事分組；進階工具、報表薪資、訊息回報改為需要時再展開。",
          "出缺勤、教學工作台、老師管理與帳務中心統一頁首與摘要，重新整理時會顯示一致的載入狀態。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "找不到低頻功能時，請展開對應區段；原有功能入口與資料操作沒有刪除。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:ops-ui-sweep"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-attendance-learning-record-integrity",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "出缺勤與學習評量表一致性修正",
    "summary": "已上／遲到一定會有可填的學習評量表；請假、取消與未上不會再出現幽靈評量表。",
    "items": [
      "出缺勤狀態與學習評量表改由同一個後端一致性流程處理，失敗會回滾，不再留下半完成資料。",
      "系統會掃描並修復已上／遲到缺評量，以及請假／取消／未上的幽靈評量，並保留稽核紀錄。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "出缺勤狀態與學習評量表改由同一個後端一致性流程處理，失敗會回滾，不再留下半完成資料。",
          "系統會掃描並修復已上／遲到缺評量，以及請假／取消／未上的幽靈評量，並保留稽核紀錄。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:attendance-learning-record-integrity"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-subject-units-summary",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "科目數統計摘要顯示修正",
    "summary": "上方摘要卡會正確顯示科目數，不再因資料格式造成顯示異常或載入失敗。",
    "items": [
      "科目數統計遇到 API 數字以文字格式回傳時，現在仍會正確轉換並顯示，和下方老師明細保持一致。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "科目數統計遇到 API 數字以文字格式回傳時，現在仍會正確轉換並顯示，和下方老師明細保持一致。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:subject-units-summary"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-student-course-summary",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "學生課程資訊更清楚",
    "summary": "一眼看到課程進度與下一步。",
    "items": [
      "學生課程會先顯示剩餘堂數、上課安排與下一步操作。",
      "付款、帳單、結案與刪除等較少使用的操作集中在更多操作。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "學生課程會先顯示剩餘堂數、上課安排與下一步操作。",
          "付款、帳單、結案與刪除等較少使用的操作集中在更多操作。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:student-course-summary"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-sidebar-focus",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "側欄功能更清楚",
    "summary": "常用工作先顯示，其他功能集中在更多功能。",
    "items": [
      "每日工作、教學現場、學生與課程、財務人事保留在側欄主畫面，主任與老師可更快找到常用入口。",
      "報表、進階工具、訊息回報與設定仍可使用，統一收進「更多功能」面板，不刪除原有頁面。",
      "「更多功能」支援目前頁面提示、待辦徽章、Escape 關閉與鍵盤操作；手機版使用方式不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "每日工作、教學現場、學生與課程、財務人事保留在側欄主畫面，主任與老師可更快找到常用入口。",
          "報表、進階工具、訊息回報與設定仍可使用，統一收進「更多功能」面板，不刪除原有頁面。",
          "「更多功能」支援目前頁面提示、待辦徽章、Escape 關閉與鍵盤操作；手機版使用方式不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:sidebar-focus"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-learning-review-queues",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "評量審核佇列更清楚",
    "summary": "待核准與需修改分開顯示，主任一眼知道下一步。",
    "items": [
      "學習評量頁將「待主任核准」與「老師需修改」拆開，不必在同一個待審清單中判斷兩種工作。",
      "分頁數字、狀態篩選與空白提示同步對齊；目前工作佇列下方會直接說明該頁要做什麼。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "學習評量頁將「待主任核准」與「老師需修改」拆開，不必在同一個待審清單中判斷兩種工作。",
          "分頁數字、狀態篩選與空白提示同步對齊；目前工作佇列下方會直接說明該頁要做什麼。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:learning-review-queues"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-in-app-bug-report",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "in-app 問題回報更穩定",
    "summary": "回報問題、看處理進度與遇到錯誤時，都能在系統內完成。",
    "items": [
      "回報視窗支援 Esc、手機底部抽屜與背景捲動鎖定；截圖貼上、拖曳、選檔與送出方式不變。",
      "狀態更新、留言、留言可見性與回報者驗收失敗時，會在 Bug 詳情原位置顯示原因，不再跳瀏覽器 alert。",
      "UI／營運改善清單移到 GitHub Issue、PR 與設計文件追蹤，系統側欄只保留實際業務功能。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "回報視窗支援 Esc、手機底部抽屜與背景捲動鎖定；截圖貼上、拖曳、選檔與送出方式不變。",
          "UI／營運改善清單移到 GitHub Issue、PR 與設計文件追蹤，系統側欄只保留實際業務功能。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "狀態更新、留言、留言可見性與回報者驗收失敗時，會在 Bug 詳情原位置顯示原因，不再跳瀏覽器 alert。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:in-app-bug-report"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-28-attendance-workspace-focus",
    "publishedAt": "2026-08-28",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "出缺勤先看要處理的工作",
    "summary": "學生點名與老師打卡分開整理，摘要和完整紀錄需要時再展開。",
    "items": [
      "主任在「學生點名」先看到待點名堂次，在「老師打卡」先看到遲到、漏刷與需要補卡的項目。",
      "到班統計、行政出勤、系統待比對與完整打卡匯出仍可使用，但不再和主要處理工作同時搶畫面。",
      "需要查完整紀錄或匯出月報時，請展開「查看完整打卡紀錄與匯出」；既有點名、補卡與補登流程不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "主任在「學生點名」先看到待點名堂次，在「老師打卡」先看到遲到、漏刷與需要補卡的項目。",
          "到班統計、行政出勤、系統待比對與完整打卡匯出仍可使用，但不再和主要處理工作同時搶畫面。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "需要查完整紀錄或匯出月報時，請展開「查看完整打卡紀錄與匯出」；既有點名、補卡與補登流程不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-28:attendance-workspace-focus"
    ],
    "date": "2026-08-28",
    "version": "2026.08.28"
  },
  {
    "id": "staff-2026-08-27-transfer-ledger-reconciliation",
    "publishedAt": "2026-08-27",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "堂次轉移會同步扣堂",
    "summary": "已上課堂次轉到新合約後，扣堂台帳與堂數會一起更新。",
    "items": [
      "轉移已上課堂次時，點名、評量、扣堂台帳與來源／目標堂數會同步更新。",
      "若看到既有轉移造成的待對帳，請重新整理後依畫面提示處理，不要自行修改堂數欄位。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "轉移已上課堂次時，點名、評量、扣堂台帳與來源／目標堂數會同步更新。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "若看到既有轉移造成的待對帳，請重新整理後依畫面提示處理，不要自行修改堂數欄位。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-27:transfer-ledger-reconciliation"
    ],
    "date": "2026-08-27",
    "version": "2026.08.27"
  },
  {
    "id": "staff-2026-08-27-session-evaluation-integrity",
    "publishedAt": "2026-08-27",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "評量與課堂狀態一致",
    "summary": "取消或請假的堂次不再要求填評量；主任總覽可直接看出哪位老師需要跟進。",
    "items": [
      "評量只列入已上課、完成或遲到；取消、請假與停課不留待填評量，夜間對帳只顯示仍有差異的項目。",
      "主任總覽上方新增教學品質追蹤，直接顯示分校整體填寫率、待填堂數、需要跟進的老師與每位老師的已填／應填數。",
      "看到「需要跟進」時，請從「前往評量審核」確認未填堂次與內容品質；填寫率是提醒指標，不是公開排名。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "評量只列入已上課、完成或遲到；取消、請假與停課不留待填評量，夜間對帳只顯示仍有差異的項目。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "主任總覽上方新增教學品質追蹤，直接顯示分校整體填寫率、待填堂數、需要跟進的老師與每位老師的已填／應填數。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "看到「需要跟進」時，請從「前往評量審核」確認未填堂次與內容品質；填寫率是提醒指標，不是公開排名。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-27:session-evaluation-integrity"
    ],
    "date": "2026-08-27",
    "version": "2026.08.27"
  },
  {
    "id": "staff-2026-08-27-paid-course-settlement",
    "publishedAt": "2026-08-27",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "已繳費課程可明確結案",
    "summary": "已確認收款的堂數制與月結課程，都能直接選擇結案不續報；排月結失敗也會顯示原因。",
    "items": [
      "三個入口都可直接「結案（不續報）」，保留付款與已上課紀錄。",
      "有剩餘堂數會先提示；確認後取消未來排課。月結也可結案。",
      "若這些堂數是請假順延或仍要上課，請先排完再結案；只有確定不再使用時才確認放棄餘額。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "三個入口都可直接「結案（不續報）」，保留付款與已上課紀錄。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "有剩餘堂數會先提示；確認後取消未來排課。月結也可結案。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "若這些堂數是請假順延或仍要上課，請先排完再結案；只有確定不再使用時才確認放棄餘額。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-27:paid-course-settlement"
    ],
    "date": "2026-08-27",
    "version": "2026.08.27"
  },
  {
    "id": "staff-2026-08-27-course-management-inline-scheduling",
    "publishedAt": "2026-08-27",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "課程查找可同頁編輯與新增堂次",
    "summary": "編輯月結結束日與新增今天堂次都留在課程查找，不再在學生管理與課程查找之間來回切換。",
    "items": [
      "課程查找的「編輯」會開啟同頁課程編輯視窗；月結課程可直接設定開始日與結束日並儲存。",
      "月結可同頁設定起訖日與新增日期時間；缺少或逾期會提供設定入口。",
      "新增下午五點到七點前，請確認課程時長與月結結束日；已過時段或衝堂時請改選時間。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "課程查找的「編輯」會開啟同頁課程編輯視窗；月結課程可直接設定開始日與結束日並儲存。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "月結可同頁設定起訖日與新增日期時間；缺少或逾期會提供設定入口。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "新增下午五點到七點前，請確認課程時長與月結結束日；已過時段或衝堂時請改選時間。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-27:course-management-inline-scheduling"
    ],
    "date": "2026-08-27",
    "version": "2026.08.27"
  },
  {
    "id": "staff-2026-08-27-teacher-assessment-engagement",
    "publishedAt": "2026-08-27",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "評量完成率可追蹤",
    "summary": "主任可看待跟進評量，老師不以公開排名比較。",
    "items": [
      "完整營運的近期紀錄與分析新增評量完成率，可切換近 7／14／30 天查看已填、應填與待跟進狀態。",
      "少於五堂會標示資料累積中，不直接判定表現；既有分校權限、代課歸屬與請假排除規則維持不變。",
      "老師仍請以完整且有用的課後進度回饋為準，填寫率只代表是否有有效進度文字，不等於內容品質分數。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "完整營運的近期紀錄與分析新增評量完成率，可切換近 7／14／30 天查看已填、應填與待跟進狀態。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "少於五堂會標示資料累積中，不直接判定表現；既有分校權限、代課歸屬與請假排除規則維持不變。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "老師仍請以完整且有用的課後進度回饋為準，填寫率只代表是否有有效進度文字，不等於內容品質分數。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-27:teacher-assessment-engagement"
    ],
    "date": "2026-08-27",
    "version": "2026.08.27"
  },
  {
    "id": "staff-2026-08-27-course-payment-slip",
    "publishedAt": "2026-08-27",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程管理直達繳費通知",
    "summary": "未繳課程可直接開通知單。",
    "items": [
      "課程管理的帳務資料與更多選單可直接開啟繳費通知單，預覽後即可複製給家長。",
      "只對未繳、部分繳與待對帳課程顯示；已繳費課程不顯示，且不改付款或收據流程。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "課程管理的帳務資料與更多選單可直接開啟繳費通知單，預覽後即可複製給家長。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "只對未繳、部分繳與待對帳課程顯示；已繳費課程不顯示，且不改付款或收據流程。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-27:course-payment-slip"
    ],
    "date": "2026-08-27",
    "version": "2026.08.27"
  },
  {
    "id": "staff-2026-08-26-reschedule-preflight",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "調課前先看衝堂結果",
    "summary": "選好新日期與時間後，系統會先提示目前課表是否可能衝堂。",
    "items": [
      "調課視窗會依目前已載入的老師、日期、時段與班型，先顯示可安排或已達上限的結果，已知衝堂時不能誤按確認。",
      "這是送出前提示，最後仍以系統送出時的權限、房間與衝堂檢查為準；若其他人同時改課，請依最新訊息重新選擇。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "調課視窗會依目前已載入的老師、日期、時段與班型，先顯示可安排或已達上限的結果，已知衝堂時不能誤按確認。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "這是送出前提示，最後仍以系統送出時的權限、房間與衝堂檢查為準；若其他人同時改課，請依最新訊息重新選擇。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:reschedule-preflight"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-payment-shortcuts",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "繳費通知與明細直達",
    "summary": "主任提醒可直接開通知單與繳費明細。",
    "items": [
      "主任總覽每筆繳費提醒可直接開啟繳費通知單、查看繳費明細，並複製通知給家長。",
      "帳務中心的對帳入口改名為繳費明細，帳單、收款與收據時間線仍沿用原本流程。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "主任總覽每筆繳費提醒可直接開啟繳費通知單、查看繳費明細，並複製通知給家長。",
          "帳務中心的對帳入口改名為繳費明細，帳單、收款與收據時間線仍沿用原本流程。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:payment-shortcuts"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-ops-workflow-quick-start",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "主任常用流程更好找",
    "summary": "收款核帳、排課與調課可從主任首頁直接開始。",
    "items": [
      "主任首頁新增收款與核帳、新增排課、調課／代課三個工作入口，帳務中心與班級行事曆內也會顯示下一步。",
      "帳務仍要先送出繳費回報，再由主任確認入帳；排課與調課仍會保留原有權限、衝堂與堂次安全檢查。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "主任首頁新增收款與核帳、新增排課、調課／代課三個工作入口，帳務中心與班級行事曆內也會顯示下一步。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "帳務仍要先送出繳費回報，再由主任確認入帳；排課與調課仍會保留原有權限、衝堂與堂次安全檢查。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:ops-workflow-quick-start"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-navigation-registry",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "導覽入口統一",
    "summary": "桌面、手機與更多功能選單現在使用同一份角色導覽。",
    "items": [
      "桌面側欄、手機底部導覽與更多功能會依身分顯示相同的頁面入口，減少找不到功能或看到錯誤入口的情況。",
      "帳務、排課、點名與資料權限仍依原本後端規則；本次只改善導覽與目前位置提示。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "桌面側欄、手機底部導覽與更多功能會依身分顯示相同的頁面入口，減少找不到功能或看到錯誤入口的情況。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "帳務、排課、點名與資料權限仍依原本後端規則；本次只改善導覽與目前位置提示。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:navigation-registry"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-director-contextual-actions",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "待辦直接定位帳務與課表",
    "summary": "從通知中心進入帳務或排課時，會帶入對應學生／課程脈絡。",
    "items": [
      "帳務通知會自動定位並高亮對應課程，排課／代課通知會切到通知日期並高亮對應課卡，主任不必再重新搜尋。",
      "只在目前分校已載入且可驗證的資料中定位；找不到時顯示提示，不跨分校猜測、不自動寫入，付款、堂次與權限規則不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "帳務通知會自動定位並高亮對應課程，排課／代課通知會切到通知日期並高亮對應課卡，主任不必再重新搜尋。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "只在目前分校已載入且可驗證的資料中定位；找不到時顯示提示，不跨分校猜測、不自動寫入，付款、堂次與權限規則不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:director-contextual-actions"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-dashboard-return-context",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "待辦處理後可返回主任工作台",
    "summary": "從主任待辦進入工作頁後，可一鍵回到今日待辦。",
    "items": [
      "從今日工作進入帳務、點名、評量或課表處理後，頂端會保留「回到主任今日工作」入口，減少重新找待辦的操作。",
      "這是暫時的導覽脈絡提示；帳務、排課、點名與權限規則維持原流程。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "從今日工作進入帳務、點名、評量或課表處理後，頂端會保留「回到主任今日工作」入口，減少重新找待辦的操作。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "這是暫時的導覽脈絡提示；帳務、排課、點名與權限規則維持原流程。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:dashboard-return-context"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-course-payment-summary",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "課程卡顯示最近繳費備註",
    "summary": "課程管理可直接看到最近繳費回報內容。",
    "items": [
      "課程管理學生課程卡會顯示最近繳費的日期、金額、備註與主任可見的匯款後五碼。",
      "待對帳資料會標示「待對帳」；付款狀態與收據仍依帳務中心流程處理。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "課程管理學生課程卡會顯示最近繳費的日期、金額、備註與主任可見的匯款後五碼。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "待對帳資料會標示「待對帳」；付款狀態與收據仍依帳務中心流程處理。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:course-payment-summary"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-bug-report-image-paste",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "Bug 回報可直接貼圖",
    "summary": "回報問題可直接貼截圖，不必先另存檔案。",
    "items": [
      "Bug 回報視窗現在可直接貼上截圖、拖曳圖片或點擊選檔，加入後會顯示預覽並可逐張移除。",
      "附件數量與大小有限制；若貼上後格式或大小不符，請改用 JPEG、PNG、GIF 或 WebP 圖片。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "Bug 回報視窗現在可直接貼上截圖、拖曳圖片或點擊選檔，加入後會顯示預覽並可逐張移除。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "附件數量與大小有限制；若貼上後格式或大小不符，請改用 JPEG、PNG、GIF 或 WebP 圖片。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:bug-report-image-paste"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-billing-batch-preview",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "批次帳務先看摘要",
    "summary": "批次回報與確認入帳前，先核對選取資料。",
    "items": [
      "批次操作會先顯示筆數、金額、付款方式與逐筆課程摘要，確認後才送出。",
      "批次回報送出後仍是待對帳；只有確認入帳後才會成為已繳費並開收據。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "批次操作會先顯示筆數、金額、付款方式與逐筆課程摘要，確認後才送出。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "批次回報送出後仍是待對帳；只有確認入帳後才會成為已繳費並開收據。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:billing-batch-preview"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-billing-action-queue",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "帳務中心先顯示待處理",
    "summary": "開啟帳務中心後，會先看到未繳、部分付款與待對帳工作。",
    "items": [
      "預設待處理佇列集中主任現在要做的工作；完整提醒、逾期與續課仍可從其他分類查看。",
      "待處理中請分開勾選未繳費回報或待對帳確認；已回報仍須確認入帳後才算已繳費並開收據。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "預設待處理佇列集中主任現在要做的工作；完整提醒、逾期與續課仍可從其他分類查看。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "待處理中請分開勾選未繳費回報或待對帳確認；已回報仍須確認入帳後才算已繳費並開收據。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:billing-action-queue"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-attendance-session-trust",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "出缺勤只顯示有效堂次",
    "summary": "已取消或對不上堂次的舊點名不會再混進作業清單。",
    "items": [
      "出缺勤清單會先確認點名對應的堂次仍有效；已取消堂次或合約不一致的殘留資料不再顯示成今天的有效出勤。",
      "自修與舊式臨時點名沒有堂次編號時仍照常保留；付款、扣堂與權限規則不變。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "出缺勤清單會先確認點名對應的堂次仍有效；已取消堂次或合約不一致的殘留資料不再顯示成今天的有效出勤。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "自修與舊式臨時點名沒有堂次編號時仍照常保留；付款、扣堂與權限規則不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:attendance-session-trust"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-ops-workflow-telemetry",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "報帳與排課流程開始量測",
    "summary": "系統會用不含個資的方式記錄流程完成與錯誤，作為下一輪簡化依據。",
    "items": [
      "帳務回報、確認入帳、新增排課與調課會記錄開始、完成、返回工作區、錯誤類型與耗時。",
      "只記錄固定流程欄位，不記錄姓名、學號、課程 ID、金額、備註、電話或錯誤原文；紀錄服務異常不會阻塞日常操作。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "帳務回報、確認入帳、新增排課與調課會記錄開始、完成、返回工作區、錯誤類型與耗時。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "只記錄固定流程欄位，不記錄姓名、學號、課程 ID、金額、備註、電話或錯誤原文；紀錄服務異常不會阻塞日常操作。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:ops-workflow-telemetry"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-course-student-focus",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "從課程管理直接定位學生",
    "summary": "從學生群組前往學生管理時，會直接展開同一位學生。",
    "items": [
      "在課程管理展開學生群組後選擇新增課程，系統會帶到學生管理並定位該學生，接著即可在學生主檔處理課程。",
      "定位只會在目前分校已載入的學生清單中生效；一般前往學生管理仍維持完整清單，不改付款、堂數或權限規則。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "在課程管理展開學生群組後選擇新增課程，系統會帶到學生管理並定位該學生，接著即可在學生主檔處理課程。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "定位只會在目前分校已載入的學生清單中生效；一般前往學生管理仍維持完整清單，不改付款、堂數或權限規則。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:course-student-focus"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-course-edit-master-record",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程編輯集中到學生主檔",
    "summary": "從課程管理編輯課程時，會直接開啟學生管理中同一門課的編輯表單。",
    "items": [
      "課程管理的編輯入口會帶到同一位學生與同一門課，主任不必再重新找學生或判斷要用哪個編輯畫面。",
      "只有目前分校清單中能驗證的學生與課程才會開啟；付款、堂數、出缺勤、資料與權限規則不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "課程管理的編輯入口會帶到同一位學生與同一門課，主任不必再重新找學生或判斷要用哪個編輯畫面。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "只有目前分校清單中能驗證的學生與課程才會開啟；付款、堂數、出缺勤、資料與權限規則不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:course-edit-master-record"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-course-create-entry",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程建立回到學生管理",
    "summary": "建立新課程請從學生管理的學生主檔進入，課程管理專心做查找與分流。",
    "items": [
      "課程管理展開學生資料後，新增課程會帶到學生管理；建立、編輯與合約操作集中在同一個學生主檔。",
      "課程查找、排課、調課與換師複製維持原流程，資料、堂數、付款與權限規則不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "課程管理展開學生資料後，新增課程會帶到學生管理；建立、編輯與合約操作集中在同一個學生主檔。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "課程查找、排課、調課與換師複製維持原流程，資料、堂數、付款與權限規則不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:course-create-entry"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-26-course-action-dedup",
    "publishedAt": "2026-08-26",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程管理排課入口更清楚",
    "summary": "每列只保留一個主要排課入口，其他課堂操作集中在更多選單。",
    "items": [
      "「排課／新增下一堂」不再同時出現在主要操作與更多選單；「補課／補登」仍可從更多選單使用。",
      "這次只整理畫面入口，既有排課、堂數、付款、資料與權限規則不變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "「排課／新增下一堂」不再同時出現在主要操作與更多選單；「補課／補登」仍可從更多選單使用。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "這次只整理畫面入口，既有排課、堂數、付款、資料與權限規則不變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-26:course-action-dedup"
    ],
    "date": "2026-08-26",
    "version": "2026.08.26"
  },
  {
    "id": "staff-2026-08-25-transfer-slot-conflict-preflight",
    "publishedAt": "2026-08-25",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "堂次轉移先檢查衝突",
    "summary": "相同日期時段會先提示，不再只顯示 Server Error。",
    "items": [
      "轉移堂次前會先檢查目標課程是否已有相同日期／時段；有衝突會列出日期，且不會搬動任何來源資料。",
      "若出現衝突，請先處理目標課程的重複堂次，再選擇沒有相同時段的目標課程重新操作。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "轉移堂次前會先檢查目標課程是否已有相同日期／時段；有衝突會列出日期，且不會搬動任何來源資料。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "若出現衝突，請先處理目標課程的重複堂次，再選擇沒有相同時段的目標課程重新操作。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-25:transfer-slot-conflict-preflight",
      "github:pull/2042"
    ],
    "date": "2026-08-25",
    "version": "2026.08.25"
  },
  {
    "id": "staff-2026-08-25-cancelled-session-recovery-transfer",
    "publishedAt": "2026-08-25",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "已取消堂次可受控恢復",
    "summary": "有評量或點名紀錄的取消堂次可填原因後恢復並移轉。",
    "items": [
      "合約／堂次調整會辨識仍有歷史證據的取消堂次；送出原因後同步恢復評量、點名與扣堂資料，沒有證據的取消堂次仍不可移轉。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "合約／堂次調整會辨識仍有歷史證據的取消堂次；送出原因後同步恢復評量、點名與扣堂資料，沒有證據的取消堂次仍不可移轉。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-25:cancelled-session-recovery-transfer",
      "github:pull/2050"
    ],
    "date": "2026-08-25",
    "version": "2026.08.25"
  },
  {
    "id": "staff-2026-08-25-receipt-image-copy",
    "publishedAt": "2026-08-25",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "電子收據可複製圖片",
    "summary": "收據可直接複製圖片貼給家長。",
    "items": [
      "電子收據新增「複製圖片」與「下載圖片」，可直接貼到 LINE 或下載後傳給家長；文字複製仍保留。",
      "若瀏覽器不支援圖片剪貼簿，請按「下載圖片」，再把 PNG 圖檔傳給家長。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "電子收據新增「複製圖片」與「下載圖片」，可直接貼到 LINE 或下載後傳給家長；文字複製仍保留。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "若瀏覽器不支援圖片剪貼簿，請按「下載圖片」，再把 PNG 圖檔傳給家長。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-25:receipt-image-copy",
      "github:pull/2046"
    ],
    "date": "2026-08-25",
    "version": "2026.08.25"
  },
  {
    "id": "staff-2026-08-25-payment-report-reconciliation",
    "publishedAt": "2026-08-25",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "帳務回報流程更清楚",
    "summary": "現金回報先待對帳，課程與備註資訊會一路保留。",
    "items": [
      "現金或匯款送出後會先進「待對帳」；確認入帳後才變成已繳費並開立收據，重複送出會直接帶到待對帳。",
      "同一學生同科目有多筆課程時，帳務列會顯示課程編號與日期，避免把已繳與未繳課程看成同一筆。",
      "回報備註會自動保留在對帳、電子收據與學生編輯頁的最近入帳備註，不必重複輸入；收據也可直接複製。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "現金或匯款送出後會先進「待對帳」；確認入帳後才變成已繳費並開立收據，重複送出會直接帶到待對帳。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "同一學生同科目有多筆課程時，帳務列會顯示課程編號與日期，避免把已繳與未繳課程看成同一筆。",
          "回報備註會自動保留在對帳、電子收據與學生編輯頁的最近入帳備註，不必重複輸入；收據也可直接複製。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-25:payment-report-reconciliation",
      "github:pull/2039"
    ],
    "date": "2026-08-25",
    "version": "2026.08.25"
  },
  {
    "id": "staff-2026-08-25-course-list-pending-report",
    "publishedAt": "2026-08-25",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "課程列表顯示待對帳",
    "summary": "已回報未核帳不再誤顯示未繳費。",
    "items": [
      "課程管理與學生課程列表會把尚未核帳的回報顯示為「待對帳」，避免主任重複送出。",
      "主任仍需在帳務中心確認入帳；確認後才會變成「已繳費」並開立電子收據。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "課程管理與學生課程列表會把尚未核帳的回報顯示為「待對帳」，避免主任重複送出。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "主任仍需在帳務中心確認入帳；確認後才會變成「已繳費」並開立電子收據。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-25:course-list-pending-report",
      "github:pull/2045"
    ],
    "date": "2026-08-25",
    "version": "2026.08.25"
  },
  {
    "id": "staff-2026-08-24-course-editability-preflight",
    "publishedAt": "2026-08-24",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "編輯先說明限制與下一步",
    "summary": "開啟課程編輯時，系統先說明可改欄位、受保護欄位與安全處理方式。",
    "items": [
      "編輯視窗先顯示扣堂、付款、共用方案與對帳狀態；受保護欄位會標明原因。",
      "更正堂數、處理付款或調整方案時，畫面會提供下一步；一般資料仍可直接編輯。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "編輯視窗先顯示扣堂、付款、共用方案與對帳狀態；受保護欄位會標明原因。",
          "更正堂數、處理付款或調整方案時，畫面會提供下一步；一般資料仍可直接編輯。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-24:course-editability-preflight"
    ],
    "date": "2026-08-24",
    "version": "2026.08.24"
  },
  {
    "id": "staff-2026-08-24-course-editability-guidance",
    "publishedAt": "2026-08-24",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "編輯受限有下一步",
    "summary": "共用方案、堂次對帳與新課程情境，會直接帶到既有處理入口。",
    "items": [
      "共用方案堂數不能直接改時，可從編輯視窗開啟方案總堂數調整並預填目前數字。",
      "堂次對帳與需要另開課程時，畫面會提供前往既有審核或學生管理的按鈕。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "共用方案堂數不能直接改時，可從編輯視窗開啟方案總堂數調整並預填目前數字。",
          "堂次對帳與需要另開課程時，畫面會提供前往既有審核或學生管理的按鈕。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-24:course-editability-guidance"
    ],
    "date": "2026-08-24",
    "version": "2026.08.24"
  },
  {
    "id": "staff-2026-08-24-course-action-hierarchy",
    "publishedAt": "2026-08-24",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程操作更清楚",
    "summary": "課程列只保留最常用入口，其他操作按情境收在「更多」。",
    "items": [
      "第一層只保留編輯、排課與查看詳情，主任先處理最常見工作。",
      "帳單、合約調整、補課與狀態管理改按情境分組，功能仍保留。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "第一層只保留編輯、排課與查看詳情，主任先處理最常見工作。",
          "帳單、合約調整、補課與狀態管理改按情境分組，功能仍保留。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-24:course-action-hierarchy"
    ],
    "date": "2026-08-24",
    "version": "2026.08.24"
  },
  {
    "id": "staff-2026-08-24-branch-health-v1",
    "publishedAt": "2026-08-24",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "總部可查看分校健康訊號",
    "summary": "超級管理員可在「分校健康」看板查看各校目前可驗證的營運訊號與下一步。",
    "items": [
      "看板以學生、教學、家長、教師、營運五個維度顯示紅黃綠或待接資料，不用單一總分排名分校。",
      "點入分校可查看訊號來源、資料期間與建議下一步；本頁只讀，不會自動修改排課、堂數或帳務。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "看板以學生、教學、家長、教師、營運五個維度顯示紅黃綠或待接資料，不用單一總分排名分校。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "點入分校可查看訊號來源、資料期間與建議下一步；本頁只讀，不會自動修改排課、堂數或帳務。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-24:branch-health-v1",
      "architecture:RFC_BRANCH_HEALTH_V1"
    ],
    "date": "2026-08-24",
    "version": "2026.08.24"
  },
  {
    "id": "staff-2026-08-23-split-contract-wizard",
    "publishedAt": "2026-08-23",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "未收款課程可用合約拆分精靈",
    "summary": "選取已上課堂次後，系統會先搬移紀錄，再自動試算並更正兩份未收款合約。",
    "items": [
      "合約拆分會保留評量／點名紀錄，並在送出前顯示舊約與新約的堂數及金額。",
      "已收款、待對帳或不符合條件的課程會維持鎖定，不會被精靈改寫。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "合約拆分會保留評量／點名紀錄，並在送出前顯示舊約與新約的堂數及金額。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "已收款、待對帳或不符合條件的課程會維持鎖定，不會被精靈改寫。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-23:split-contract-wizard",
      "github:#1901"
    ],
    "date": "2026-08-23",
    "version": "2026.08.23"
  },
  {
    "id": "staff-2026-08-23-overlap-entitlement-root-guard",
    "publishedAt": "2026-08-23",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "新增排課防重疊與堂數對齊",
    "summary": "系統會在未來課堂建立前攔截同一學生的時間重疊，並讓課程展開的已上堂數與剩餘堂數對齊。",
    "items": [
      "同一學生已有其他課程或排課時，未來重疊時段會在寫入前提醒，避免事後才到重疊課程頁面處理。",
      "同一共用方案的平行科目仍可正常排課；主任明確建立獨立平行課時會留下原因紀錄。",
      "課程展開的已上堂數、購買堂數與剩餘堂數改用同一套帳務口徑。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "同一學生已有其他課程或排課時，未來重疊時段會在寫入前提醒，避免事後才到重疊課程頁面處理。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "同一共用方案的平行科目仍可正常排課；主任明確建立獨立平行課時會留下原因紀錄。",
          "課程展開的已上堂數、購買堂數與剩餘堂數改用同一套帳務口徑。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-23:overlap-entitlement-root-guard"
    ],
    "date": "2026-08-23",
    "version": "2026.08.23"
  },
  {
    "id": "staff-2026-08-23-duplicate-usage-reconciliation",
    "publishedAt": "2026-08-23",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "重複堂次會同步沖回扣堂",
    "summary": "審核跨合約重複課程時，取消的非保留堂次會一併清理使用證據；數字不一致時會先提醒對帳。",
    "items": [
      "重複課程審核取消非保留堂次時，會同步作廢簽到／評量、沖回扣堂並重算剩餘堂數。",
      "已先被取消但仍殘留扣堂證據的重複堂次，也會回到審核清單供主任完成清理。",
      "課程管理發現課堂狀態與扣堂紀錄不一致時，會顯示「堂數待對帳」，請先完成對帳再作為收費依據。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "重複課程審核取消非保留堂次時，會同步作廢簽到／評量、沖回扣堂並重算剩餘堂數。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "已先被取消但仍殘留扣堂證據的重複堂次，也會回到審核清單供主任完成清理。",
          "課程管理發現課堂狀態與扣堂紀錄不一致時，會顯示「堂數待對帳」，請先完成對帳再作為收費依據。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-23:duplicate-usage-reconciliation"
    ],
    "date": "2026-08-23",
    "version": "2026.08.23"
  },
  {
    "id": "staff-2026-08-23-contract-correction-transfer-safety",
    "publishedAt": "2026-08-23",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "未收款合約更正與堂次轉移安全性",
    "summary": "未收款合約可更正實際堂數，堂次轉移也會清楚限制在可安全搬移的已上課紀錄。",
    "items": [
      "課程編輯儲存失敗會固定顯示在編輯視窗內，不會再被錯誤提示或底層畫面遮住。",
      "原本五堂、尚未收款但實際只上四堂時，可從「操作 → 更正未收款堂數」改為四堂；已上課紀錄不會刪除。",
      "轉移堂次只列出同一學生、同一科目且已上課的堂次，未上課、請假與缺席堂次會留在原合約。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "課程編輯儲存失敗會固定顯示在編輯視窗內，不會再被錯誤提示或底層畫面遮住。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "原本五堂、尚未收款但實際只上四堂時，可從「操作 → 更正未收款堂數」改為四堂；已上課紀錄不會刪除。",
          "轉移堂次只列出同一學生、同一科目且已上課的堂次，未上課、請假與缺席堂次會留在原合約。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-23:contract-correction-transfer-safety"
    ],
    "date": "2026-08-23",
    "version": "2026.08.23"
  },
  {
    "id": "staff-2026-08-23-performance-first-batch",
    "publishedAt": "2026-08-23",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "頁面載入更順暢",
    "summary": "登入、主任首頁與行事曆減少等待，常用資產也會更有效率地快取。",
    "items": [
      "主任首頁的獨立資料區塊會同時載入，不會因單一區塊較慢而拖住整頁。",
      "登入與行事曆資料減少連續等待；已下載過的內容切換檢視時更快回應。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "主任首頁的獨立資料區塊會同時載入，不會因單一區塊較慢而拖住整頁。",
          "登入與行事曆資料減少連續等待；已下載過的內容切換檢視時更快回應。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-23:performance-first-batch"
    ],
    "date": "2026-08-23",
    "version": "2026.08.23"
  },
  {
    "id": "staff-2026-08-23-performance-backend-query",
    "publishedAt": "2026-08-23",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "主任頁面查詢加速",
    "summary": "主任頁面的次要資料與營運摘要減少資料庫等待，資料多時也更穩定。",
    "items": [
      "老師評量填寫率改用較有效率的日期／時段查詢，主任開啟完整頁面時等待更少。",
      "營運摘要合併重複統計，維持原本數字口徑但減少資料庫往返。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "老師評量填寫率改用較有效率的日期／時段查詢，主任開啟完整頁面時等待更少。",
          "營運摘要合併重複統計，維持原本數字口徑但減少資料庫往返。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-23:performance-backend-query"
    ],
    "date": "2026-08-23",
    "version": "2026.08.23"
  },
  {
    "id": "staff-2026-08-23-director-adjustment-entry",
    "publishedAt": "2026-08-23",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "合約與堂次調整入口簡化",
    "summary": "課程管理把兩個容易混淆的調整功能收斂到同一個入口，主任先選情境，再進入正確流程。",
    "items": [
      "「操作」選單只顯示「合約／堂次調整」，不再同時放置「更正未收款堂數」與「轉移堂次紀錄」兩個容易混淆的按鈕。",
      "未付款課程會先詢問是要把堂數改少，還是把已上課紀錄轉到另一份合約；兩個流程的帳務規則不會混在一起。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "「操作」選單只顯示「合約／堂次調整」，不再同時放置「更正未收款堂數」與「轉移堂次紀錄」兩個容易混淆的按鈕。",
          "未付款課程會先詢問是要把堂數改少，還是把已上課紀錄轉到另一份合約；兩個流程的帳務規則不會混在一起。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-23:director-adjustment-entry"
    ],
    "date": "2026-08-23",
    "version": "2026.08.23"
  },
  {
    "id": "staff-2026-08-22-manual-session-booking",
    "publishedAt": "2026-08-22",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "一般課程合約新增「排課」按鈕",
    "summary": "一般（非進階）課程合約現在可以直接排指定日期時段的課，不用再誤用補課功能。",
    "items": [
      "課程管理頁面對一般合約新增「排課」按鈕，可直接指定日期時段建立課堂。",
      "排課前會先檢查師資／教室是否衝堂，衝堂原因會顯示在對話框內。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "課程管理頁面對一般合約新增「排課」按鈕，可直接指定日期時段建立課堂。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "排課前會先檢查師資／教室是否衝堂，衝堂原因會顯示在對話框內。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-22:manual-session-booking",
      "github:#1956"
    ],
    "date": "2026-08-22",
    "version": "2026.08.22"
  },
  {
    "id": "staff-2026-08-21-question-bank-provenance",
    "publishedAt": "2026-08-21",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "題庫匯入保留授權來源",
    "summary": "匯入題目現在能追溯來源與授權資訊。",
    "items": [
      "可保存來源名稱、版本、外部題號、年級、科目與匯出批次。",
      "已授權素材缺少授權參考時會整批拒絕，所有匯入題目仍先待主任審核。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "可保存來源名稱、版本、外部題號、年級、科目與匯出批次。",
          "已授權素材缺少授權參考時會整批拒絕，所有匯入題目仍先待主任審核。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-21:question-bank-provenance",
      "issue:1934"
    ],
    "date": "2026-08-21",
    "version": "2026.08.21"
  },
  {
    "id": "staff-2026-08-21-question-bank-management",
    "publishedAt": "2026-08-21",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "題庫管理支援版本與審核",
    "summary": "題目可依分校管理、匯入、送審與保留版本歷史。",
    "items": [
      "可建立題庫、設定知識標籤與 1–5 級難度，題目可用 CSV 批次匯入。",
      "匯入與老師建立的題目會先進入待審核；主任核准後才列為正式題目。",
      "修改題目會建立新版本，舊版本保留供追溯，退休題目不會被直接刪除。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "可建立題庫、設定知識標籤與 1–5 級難度，題目可用 CSV 批次匯入。",
          "匯入與老師建立的題目會先進入待審核；主任核准後才列為正式題目。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "修改題目會建立新版本，舊版本保留供追溯，退休題目不會被直接刪除。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-21:question-bank-management",
      "issue:1934"
    ],
    "date": "2026-08-21",
    "version": "2026.08.21"
  },
  {
    "id": "staff-2026-08-21-learning-assessment-mvp",
    "publishedAt": "2026-08-21",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "學習檢測可追蹤補強",
    "summary": "建立檢測、登錄結果，直接追蹤學生補強行動。",
    "items": [
      "主任與老師可建立檢測、發布並登錄學生多次結果。",
      "從學生結果建立知識缺口、補強計畫與預計完成日。",
      "可查看待補強與逾期數，將行動標記為進行中或已完成。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "主任與老師可建立檢測、發布並登錄學生多次結果。",
          "從學生結果建立知識缺口、補強計畫與預計完成日。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "可查看待補強與逾期數，將行動標記為進行中或已完成。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-21:learning-assessment-mvp",
      "issue:1934"
    ],
    "date": "2026-08-21",
    "version": "2026.08.21"
  },
  {
    "id": "staff-2026-08-21-parent-assessment-progress",
    "publishedAt": "2026-08-21",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "家長端可查看已複核檢測進度",
    "summary": "家長可看到檢測結果與補強狀態，內部備註仍留在教職員端。",
    "items": [
      "家長端只顯示已完成複核的檢測分數與達標／再練習提示。",
      "補強狀態與練習方向以白話摘要呈現，不會公開老師內部計畫與備註。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "家長端只顯示已完成複核的檢測分數與達標／再練習提示。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "補強狀態與練習方向以白話摘要呈現，不會公開老師內部計畫與備註。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-21:parent-assessment-progress",
      "issue:1934"
    ],
    "date": "2026-08-21",
    "version": "2026.08.21"
  },
  {
    "id": "staff-2026-08-21-dunhua-campus-retired",
    "publishedAt": "2026-08-21",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "敦化分校已停用",
    "summary": "敦化分校不再出現在可選分校清單，歷史資料仍保留。",
    "items": [
      "敦化分校已從公開分校清單與離線後備清單移除。",
      "學生、課程、出勤、帳務、評量與稽核歷史資料不會被刪除。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "敦化分校已從公開分校清單與離線後備清單移除。",
          "學生、課程、出勤、帳務、評量與稽核歷史資料不會被刪除。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-21:dunhua-campus-retired"
    ],
    "date": "2026-08-21",
    "version": "2026.08.21"
  },
  {
    "id": "staff-2026-08-20-sidebar-workspaces",
    "publishedAt": "2026-08-20",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "側欄改成工作情境",
    "summary": "找事情先看工作區；學生、課程、行事曆的入口更清楚。",
    "items": [
      "側欄分成今日工作、教學現場、學生與課程、財務與人事、設定與資源。",
      "「課程管理」改名為「課程查找」，建立與續報仍從學生管理進入。",
      "桌面收合與手機更多功能會保留目前頁面標示，找功能不必重新猜。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "側欄分成今日工作、教學現場、學生與課程、財務與人事、設定與資源。",
          "「課程管理」改名為「課程查找」，建立與續報仍從學生管理進入。",
          "桌面收合與手機更多功能會保留目前頁面標示，找功能不必重新猜。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-20:sidebar-workspaces"
    ],
    "date": "2026-08-20",
    "version": "2026.08.20"
  },
  {
    "id": "staff-2026-08-20-nightly-session-check",
    "publishedAt": "2026-08-20",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "夜間堂數檢查改用白話說明",
    "summary": "看得出它檢查什麼、什麼不會改，以及異常後該走哪個流程。",
    "items": [
      "頁面明確區分課程已用堂數、權威扣堂計算與實際出席證據。",
      "異常只提供診斷與人工確認，不會自動改寫堂數，也不是銀行或學費入帳對帳。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "頁面明確區分課程已用堂數、權威扣堂計算與實際出席證據。",
          "異常只提供診斷與人工確認，不會自動改寫堂數，也不是銀行或學費入帳對帳。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-20:nightly-session-check"
    ],
    "date": "2026-08-20",
    "version": "2026.08.20"
  },
  {
    "id": "staff-2026-08-20-course-triage-lens",
    "publishedAt": "2026-08-20",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "課程管理更容易找課",
    "summary": "先篩選與找提醒，再到學生管理修改課程。",
    "items": [
      "課程管理新增唯讀提示、摘要與清除篩選，找課更容易。",
      "建立、編輯、續報與加購請從學生管理的學生主檔進入。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "課程管理新增唯讀提示、摘要與清除篩選，找課更容易。",
          "建立、編輯、續報與加購請從學生管理的學生主檔進入。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-20:course-triage-lens"
    ],
    "date": "2026-08-20",
    "version": "2026.08.20"
  },
  {
    "id": "staff-2026-08-20-course-transfer-picker",
    "publishedAt": "2026-08-20",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "轉移堂次不用再背課程 ID",
    "summary": "轉移評量與點名紀錄時，可以直接選同一學生的新課程。",
    "items": [
      "轉移堂次會列出同一學生的其他課程，點選目標課程即可。",
      "已完成的評量與點名紀錄仍會一起搬過去，堂數與金額不會改變。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "轉移堂次會列出同一學生的其他課程，點選目標課程即可。",
          "已完成的評量與點名紀錄仍會一起搬過去，堂數與金額不會改變。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-20:course-transfer-picker"
    ],
    "date": "2026-08-20",
    "version": "2026.08.20"
  },
  {
    "id": "staff-2026-08-19-receipt-line-clarity",
    "publishedAt": "2026-08-19",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "收據紀錄看得到班型",
    "summary": "同科兩筆會標班型；0元看得出試聽或輔導。",
    "items": [
      "科目會寫一對一、輔導、試聽；歷史課首堂有說明。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "科目會寫一對一、輔導、試聽；歷史課首堂有說明。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-19:receipt-line-clarity"
    ],
    "date": "2026-08-19",
    "version": "2026.08.19"
  },
  {
    "id": "staff-2026-08-19-accounting-receipt-pin-gap",
    "publishedAt": "2026-08-19",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "收據紀錄不必再卡 PIN",
    "summary": "收據流水登入即可看，不再卡 PIN 沒輸入欄。",
    "items": [
      "收據紀錄不再要求 PIN；薪資、當月學收、老師管理仍要 PIN。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "收據紀錄不再要求 PIN；薪資、當月學收、老師管理仍要 PIN。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-19:accounting-receipt-pin-gap"
    ],
    "date": "2026-08-19",
    "version": "2026.08.19"
  },
  {
    "id": "staff-2026-08-17-ui-jargon-w2",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "LINE 設定與錯誤提示更白話",
    "summary": "頻道授權碼改中文；操作失敗時較少出現英文 Forbidden。",
    "items": [
      "家長 LINE 設定改看「頻道授權碼」「頻道密鑰」。",
      "沒有權限、找不到資料等錯誤改中文。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "家長 LINE 設定改看「頻道授權碼」「頻道密鑰」。",
          "沒有權限、找不到資料等錯誤改中文。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:ui-jargon-w2"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-ui-human-copy-sweep",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "畫面用語再白話一點",
    "summary": "匯入老師、收據編號、分校授權碼改成比較好懂的中文。",
    "items": [
      "批次新增老師改用「帳號／主分校／可授科目」說明，不再寫 branch_id。",
      "收據與帳單編號改顯示「收據／帳單／舊資料」，不再出現 LEGACY。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "批次新增老師改用「帳號／主分校／可授科目」說明，不再寫 branch_id。",
          "收據與帳單編號改顯示「收據／帳單／舊資料」，不再出現 LEGACY。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:ui-human-copy-sweep"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-tuition-collect-tdz",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "帳務中心可正常開啟",
    "summary": "進入帳務中心不再白屏，可直接看催繳與待對帳名單。",
    "items": [
      "帳務中心一進去就能載入名單，不再空白。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "帳務中心一進去就能載入名單，不再空白。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:tuition-collect-tdz"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-same-day-reschedule-occupancy-1885",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "同一天調課後舊時段可再排",
    "summary": "課堂改到別的時段後，原來那個時段不再顯示已滿。",
    "items": [
      "同一天把課調走後，舊時段可以再排其他學生，不會一直顯示已滿。",
      "還沒建成正式課堂的補課，以及請假後另約的時段，仍會佔用老師時間。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "同一天把課調走後，舊時段可以再排其他學生，不會一直顯示已滿。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "還沒建成正式課堂的補課，以及請假後另約的時段，仍會佔用老師時間。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:same-day-reschedule-occupancy-1885"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-projected-ordinal",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "預排不再佔堂次序號",
    "summary": "還沒排成課堂的預排日期，不會再插進已上課堂的編號。",
    "items": [
      "預排只顯示日期與預排，不佔已上課堂的編號。",
      "已經排進課表、還沒點名的堂次，仍照順序編號。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "預排只顯示日期與預排，不佔已上課堂的編號。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "已經排進課表、還沒點名的堂次，仍照順序編號。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:projected-ordinal"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-mixed-class-type-occupancy-1889",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "混班時段一對三還能加",
    "summary": "同一時段已有一對二時，一對三仍可再收，不會整格顯示已滿。",
    "items": [
      "一對二與一對三同一時段時，一對三還有位子不會顯示已滿。",
      "容量會分開寫一對二和一對三；本分校上課不會寫成其他分校。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "一對二與一對三同一時段時，一對三還有位子不會顯示已滿。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "容量會分開寫一對二和一對三；本分校上課不會寫成其他分校。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:mixed-class-type-occupancy-1889"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-lr-batch-approve-perf",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "學習評量批次核准與進頁速度",
    "summary": "已停用課程裡已上過的待審評量可以批次核准；進頁會先出列表，比較不會轉很久。",
    "items": [
      "勾選已停用課程、但堂次已上的待審評量時，批次核准不再整批失敗。",
      "學習評量表會先顯示列表，補建與課表在背景載入。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "勾選已停用課程、但堂次已上的待審評量時，批次核准不再整批失敗。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "學習評量表會先顯示列表，補建與課表在背景載入。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:lr-batch-approve-perf"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-fillrate-completed",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "已完成堂次也算進評量填寫率",
    "summary": "課堂狀態是「已完成」時，也會跟已到班、遲到一樣算進老師評量填寫率。",
    "items": [
      "主任報表不再漏掉已完成的堂次。",
      "代課老師填了評量的已完成堂次，會算在代課老師名下。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "主任報表不再漏掉已完成的堂次。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "代課老師填了評量的已完成堂次，會算在代課老師名下。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:fillrate-completed"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-course-memo-length-1732",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程備註可存更長",
    "summary": "備註可貼上給家長看的繳費說明，不再存檔失敗。",
    "items": [
      "課程備註加長，貼上繳費說明也能存檔。",
      "字數超過上限會提示請刪短，不會再存檔失敗。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "課程備註加長，貼上繳費說明也能存檔。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "字數超過上限會提示請刪短，不會再存檔失敗。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:course-memo-length-1732"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-course-attended-count-1834",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "課程「已上堂數」跟明細一致",
    "summary": "卡片上的已上堂數會跟展開的上課日期對齊；剛點名的堂次也會算進去。",
    "items": [
      "課程管理顯示的已上堂數，改跟日期列表同一套計算。",
      "月結課不會再出現「購買多少堂」這種容易誤會的字。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "課程管理顯示的已上堂數，改跟日期列表同一套計算。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "月結課不會再出現「購買多少堂」這種容易誤會的字。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:course-attended-count-1834"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-count-settle-makeup-1839",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "還有堂數時不能直接結案",
    "summary": "學生還有未上的堂次時，結案會被擋住，避免請假補課被系統取消。",
    "items": [
      "堂數制課程還有未上堂次時，催繳名單不能結案；請先把請假順延的課排好。",
      "若課程已被結案但還有餘額，仍可把剩下的堂次排進去。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "堂數制課程還有未上堂次時，催繳名單不能結案；請先把請假順延的課排好。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "若課程已被結案但還有餘額，仍可把剩下的堂次排進去。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:count-settle-makeup-1839"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-billing-scan-density",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "對帳畫面比較好掃",
    "summary": "帳單與收款分開看；摘要變精簡，勾選後批次列會跟著畫面。",
    "items": [
      "學生對帳先看帳單列，點開才看收款明細。",
      "帳務中心勾選多筆後，操作列會貼在上方方便確認。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "學生對帳先看帳單列，點開才看收款明細。",
          "帳務中心勾選多筆後，操作列會貼在上方方便確認。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:billing-scan-density"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-billing-mode-convert-archive",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "改計費方式會清掉未入帳帳單",
    "summary": "堂數制改月結（或反向）時，還沒入帳的帳單與待確認回報會自動作廢；已確認收款不會動金額。",
    "items": [
      "改計費方式後，舊的未繳帳單與待確認回報不再繼續出現。",
      "已經確認的收據金額不變，開收據時會提醒計費方式已改過。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "改計費方式後，舊的未繳帳單與待確認回報不再繼續出現。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "已經確認的收據金額不變，開收據時會提醒計費方式已改過。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:billing-mode-convert-archive"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-17-billing-human-copy",
    "publishedAt": "2026-08-17",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "帳務用語比較好懂",
    "summary": "對帳畫面改成白話；不再出現英文 Invoice 或帳單／課程斜線。",
    "items": [
      "學生對帳改看「帳單（科目）」「已記入」「多收待處理」。",
      "課程管理改「帳單與對帳」，統計標籤改中文。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "學生對帳改看「帳單（科目）」「已記入」「多收待處理」。",
          "課程管理改「帳單與對帳」，統計標籤改中文。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-17:billing-human-copy"
    ],
    "date": "2026-08-17",
    "version": "2026.08.17"
  },
  {
    "id": "staff-2026-08-16-reported-paid-pending",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "action_required",
    "title": "繳費改先回報再入帳",
    "summary": "行政先登記家長已繳；對到帳後主任按確認入帳才開收據。",
    "items": [
      "看官方 LINE 請用「登記已回報」，不必等人也能先登錄。",
      "對到帳後按確認入帳才開電子收據；對不到款可退回。"
    ],
    "sections": [
      {
        "title": "需要你注意",
        "items": [
          "看官方 LINE 請用「登記已回報」，不必等人也能先登錄。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "對到帳後按確認入帳才開電子收據；對不到款可退回。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:reported-paid-pending"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-fulltime-settlement-table",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "正職結算表上線",
    "summary": "正職薪資頁改為底薪與獎金結算欄",
    "items": [
      "可見底薪、科目數拆分、倍率後獎金算式與總發放。",
      "假日假可補足16小時倍率；小數科目數依附件表內插。",
      "全勤、勞健保、行政加給仍未自動列入，請人工核對。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "可見底薪、科目數拆分、倍率後獎金算式與總發放。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "假日假可補足16小時倍率；小數科目數依附件表內插。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "全勤、勞健保、行政加給仍未自動列入，請人工核對。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:fulltime-settlement-table"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-fulltime-payroll-lock",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "正職結算可鎖定與匯出",
    "summary": "正職薪資可鎖定本月並匯出 Excel；底薪核准後才計入，鎖定月份不能改。",
    "items": [
      "確認無試算列後可鎖定本月，匯出 CSV 給 Excel 開；總部可填原因重開。",
      "已鎖定月份不能改底薪。現金加扣款調整下一包才上。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "確認無試算列後可鎖定本月，匯出 CSV 給 Excel 開；總部可填原因重開。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "已鎖定月份不能改底薪。現金加扣款調整下一包才上。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:fulltime-payroll-lock"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-fulltime-cash-adj",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "正職現金加扣款",
    "summary": "可登錄獨立現金加或扣，不進倍率；主任確認、總部核准後才進總發放。",
    "items": [
      "未鎖定月份可登錄現金加扣款，核准後顯示在結算加扣款欄。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "未鎖定月份可登錄現金加扣款，核准後顯示在結算加扣款欄。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:fulltime-cash-adj"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-fulltime-admin-allowance",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "正職行政加給與底薪核准",
    "summary": "行政加給要主任確認、總部核准才進倍率。主任改底薪先待核准；總部改了立刻計入。",
    "items": [
      "可登錄行政協助／總導師／副主任加給 0–10%，核准後才計入。",
      "主任改底薪會看到待核准金額；總部可核准，或自己改立即生效。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "可登錄行政協助／總導師／副主任加給 0–10%，核准後才計入。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "主任改底薪會看到待核准金額；總部可核准，或自己改立即生效。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:fulltime-admin-allowance"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-reported-paid-phase2",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "課程頁可批次帳務",
    "summary": "同一學生切帳務；收費頁勾選批次回報，確認後才開收據。",
    "items": [
      "課程管理同一學生可切帳務資料，不必再搜一次。",
      "收費頁可一次勾選多筆已回報或確認入帳。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "課程管理同一學生可切帳務資料，不必再搜一次。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "收費頁可一次勾選多筆已回報或確認入帳。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:reported-paid-phase2"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-reported-paid-notif",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "通知中心先回報",
    "summary": "通知中心登記已繳後仍待對帳，確認入帳後才開收據。",
    "items": [
      "通知中心學費改「送出已回報」，不會當場變已繳或開收據。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "通知中心學費改「送出已回報」，不會當場變已繳或開收據。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:reported-paid-notif"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-lr-resurrect-status-adjust",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "重點名後評量會自動恢復待填",
    "summary": "已到班的堂次若被改回未點名再標到班，老師端評量會恢復成待填，不必再找主任重開。",
    "items": [
      "出缺勤狀態來回調整後，系統作廢的評量會自動還原。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "出缺勤狀態來回調整後，系統作廢的評量會自動還原。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:lr-resurrect-status-adjust"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-hai-sen-director-copy",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "對帳由主任完成",
    "summary": "已回報後請到帳務中心確認入帳；沒有會計角色。",
    "items": [
      "同一課程已有待對帳時，會請你到帳務中心確認入帳或退回。",
      "對到帳後按確認入帳就是完成，系統不會再開會計帳號。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "同一課程已有待對帳時，會請你到帳務中心確認入帳或退回。",
          "對到帳後按確認入帳就是完成，系統不會再開會計帳號。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:hai-sen-director-copy"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-fillrate-substitute-absent-copy",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "代課填報率與缺席不扣堂說明",
    "summary": "代課堂次的評量填寫率改算在代課老師；點名標缺席時改為不扣堂、也不順延。",
    "items": [
      "主任填報率報表與課表一樣，把代課堂次算給代課老師。",
      "點名確認缺席時改顯示不扣堂、不順延；請假仍會順延。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "主任填報率報表與課表一樣，把代課堂次算給代課老師。",
          "點名確認缺席時改顯示不扣堂、不順延；請假仍會順延。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:fillrate-substitute-absent-copy"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-director-confirms",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "帳務中心直接開",
    "summary": "帳務中心不再擋 PIN；對到帳後由主任按確認入帳。",
    "items": [
      "帳務中心一進去就有名單，不必先解 PIN。",
      "沒有會計角色；主任按確認入帳就完成。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "帳務中心一進去就有名單，不必先解 PIN。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "沒有會計角色；主任按確認入帳就完成。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:director-confirms"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-billing-ux-find",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "帳務入口比較好找",
    "summary": "課程列上可切帳務；帳務中心解鎖前會說明要 PIN。",
    "items": [
      "課程管理學生列上有「課程／帳務」分頁，收合也能點進去。",
      "帳務中心若要 PIN，畫面會說明，不再一片空白。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "課程管理學生列上有「課程／帳務」分頁，收合也能點進去。",
          "帳務中心若要 PIN，畫面會說明，不再一片空白。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:billing-ux-find"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-16-billing-tabs-visible",
    "publishedAt": "2026-08-16",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "帳務資料在學生列",
    "summary": "課程管理每位學生姓名下方有課程資料與帳務資料。",
    "items": [
      "點學生列下面的「帳務資料」即可對帳，不必先展開。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "點學生列下面的「帳務資料」即可對帳，不必先展開。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-16:billing-tabs-visible"
    ],
    "date": "2026-08-16",
    "version": "2026.08.16"
  },
  {
    "id": "staff-2026-08-15-tuition-charge-display-1734",
    "publishedAt": "2026-08-15",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "收帳金額顯示修正",
    "summary": "已收款課程會顯示實際合約總額，不再出現舊的錯誤數字。",
    "items": [
      "收帳頁會跟已開立帳單同一金額，不再顯示過期的錯誤總額。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "收帳頁會跟已開立帳單同一金額，不再顯示過期的錯誤總額。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-15:tuition-charge-display-1734"
    ],
    "date": "2026-08-15",
    "version": "2026.08.15"
  },
  {
    "id": "staff-2026-08-15-teacher-home-projected-campus",
    "publishedAt": "2026-08-15",
    "effectiveAt": null,
    "audiences": [
      "teacher",
      "director"
    ],
    "audience": [
      "teacher",
      "director"
    ],
    "importance": "digest",
    "title": "教師首頁分校顯示",
    "summary": "還沒上課的堂次會顯示正確分校；缺資料時改中文或隱藏。",
    "items": [
      "週課表未來堂次不再出現看不懂的編號，會顯示分校名稱。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "週課表未來堂次不再出現看不懂的編號，會顯示分校名稱。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-15:teacher-home-projected-campus"
    ],
    "date": "2026-08-15",
    "version": "2026.08.15"
  },
  {
    "id": "staff-2026-08-15-stale-receipt-badge-934",
    "publishedAt": "2026-08-15",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "收據新增「可能已被取代」提醒",
    "summary": "課程計費模式（堂數制/月結）事後變更時，舊收據會顯示提醒，避免家長拿舊收據截圖產生誤會。",
    "items": [
      "收據頁面：若課程計費模式已變更，顯示黃色提醒訊息。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "收據頁面：若課程計費模式已變更，顯示黃色提醒訊息。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-15:stale-receipt-badge-934"
    ],
    "date": "2026-08-15",
    "version": "2026.08.15"
  },
  {
    "id": "staff-2026-08-15-eligibility-approved-revert",
    "publishedAt": "2026-08-15",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "誤核薪資可退回",
    "summary": "已核准可退回；全校放假改看課程管理連假。",
    "items": [
      "已核准的補登可按退回，該筆不再進入薪資。",
      "全校放假以課程管理連假為準，不必再手動登假日曆。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "已核准的補登可按退回，該筆不再進入薪資。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "全校放假以課程管理連假為準，不必再手動登假日曆。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-15:eligibility-approved-revert"
    ],
    "date": "2026-08-15",
    "version": "2026.08.15"
  },
  {
    "id": "staff-2026-08-14-monthly-copy-teacher-list",
    "publishedAt": "2026-08-14",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "月結課表顯示修正",
    "summary": "月結課程不再寫成購買堂數；老師清單同一堂不重複。",
    "items": [
      "月結課程的上課日期改顯示已上堂數，不再出現購買堂數。",
      "老師清單同一學生同時段只列一筆，不再重複同一門課。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "月結課程的上課日期改顯示已上堂數，不再出現購買堂數。",
          "老師清單同一學生同時段只列一筆，不再重複同一門課。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-14:monthly-copy-teacher-list"
    ],
    "date": "2026-08-14",
    "version": "2026.08.14"
  },
  {
    "id": "staff-2026-08-14-eligibility-pending-edit",
    "publishedAt": "2026-08-14",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "薪資補登可先改再核",
    "summary": "全校放假請走課程管理；這裡預設幫老師補請假。",
    "items": [
      "全校放假用課程管理「連假批次請假」；這裡預設是幫老師補請假。",
      "待審核資料可在右側清單修改或撤回，核准後才會算進薪資。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "全校放假用課程管理「連假批次請假」；這裡預設是幫老師補請假。"
        ]
      },
      {
        "title": "你現在可以",
        "items": [
          "待審核資料可在右側清單修改或撤回，核准後才會算進薪資。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-14:eligibility-pending-edit"
    ],
    "date": "2026-08-14",
    "version": "2026.08.14"
  },
  {
    "id": "staff-2026-08-13-teacher-home-projection-integrity",
    "publishedAt": "2026-08-13",
    "effectiveAt": null,
    "audiences": [
      "teacher",
      "director"
    ],
    "audience": [
      "teacher",
      "director"
    ],
    "importance": "major",
    "title": "教師首頁課表與評量顯示更穩定",
    "summary": "重新整理或切換分校時，課表會保留已載入的內容並避免重複的同堂評量卡。",
    "items": [
      "同一學生、日期與時段的重複課堂投影會合併為一筆，不會顯示成兩張評量卡。",
      "週課表載入新資料時不會先清空舊畫面；較早完成的舊請求也不會覆蓋最新課表。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "同一學生、日期與時段的重複課堂投影會合併為一筆，不會顯示成兩張評量卡。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "週課表載入新資料時不會先清空舊畫面；較早完成的舊請求也不會覆蓋最新課表。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-13:teacher-home-projection-integrity"
    ],
    "date": "2026-08-13",
    "version": "2026.08.13"
  },
  {
    "id": "staff-2026-08-13-session-entitlement-transfer-command",
    "publishedAt": "2026-08-13",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "超額堂次可安全轉到續購批次",
    "summary": "已提供具稽核、驗證與回滾保護的管理修復流程。",
    "items": [
      "已確認誤扣在舊一期的已上課堂次，可保留原點名與評量並轉至同一學生的續購批次。",
      "系統會攔截跨學生、跨科目、目標已滿、重複時段及已結算批次。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "已確認誤扣在舊一期的已上課堂次，可保留原點名與評量並轉至同一學生的續購批次。",
          "系統會攔截跨學生、跨科目、目標已滿、重複時段及已結算批次。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-13:session-entitlement-transfer-command"
    ],
    "date": "2026-08-13",
    "version": "2026.08.13"
  },
  {
    "id": "staff-2026-08-13-monthly-projection-exception",
    "publishedAt": "2026-08-13",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "月例外投影修正",
    "summary": "修正例外堂次無法關閉與誤建堂次",
    "items": [
      "月排課在例外時間不同於合約時間時，仍可由投影堂次正常開啟與關閉。",
      "補課與跨日改課目的列不會再被誤當成月例外堂次 materialize 或連帶取消。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "月排課在例外時間不同於合約時間時，仍可由投影堂次正常開啟與關閉。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "補課與跨日改課目的列不會再被誤當成月例外堂次 materialize 或連帶取消。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-13:monthly-projection-exception"
    ],
    "date": "2026-08-13",
    "version": "2026.08.13"
  },
  {
    "id": "staff-2026-08-13-fulltime-settlement-total-payout",
    "publishedAt": "2026-08-13",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "正職薪資總發放金額",
    "summary": "新增底薪欄位，自動組成教師倍率與總發放金額",
    "items": [
      "「正職老師薪資要件」頁面新增可編輯底薪欄位，自動算出教師倍率與總發放金額。",
      "行政加給倍率（行政協助／總導師／副主任）尚未計入，主任需自行加算，之後會補上。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "「正職老師薪資要件」頁面新增可編輯底薪欄位，自動算出教師倍率與總發放金額。"
        ]
      },
      {
        "title": "需要你注意",
        "items": [
          "行政加給倍率（行政協助／總導師／副主任）尚未計入，主任需自行加算，之後會補上。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-13:fulltime-settlement-total-payout"
    ],
    "date": "2026-08-13",
    "version": "2026.08.13"
  },
  {
    "id": "staff-2026-08-12-in-app-bug-fixes",
    "publishedAt": "2026-08-12",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "排課與收費顯示更準",
    "summary": "共用方案、收帳金額、收據日期與課表缺漏已修正。",
    "items": [
      "共用方案可跨課程正確新增與預約堂次，不會誤判單一課程已滿。",
      "收帳金額與收據期間會按照實際合約與堂次資料顯示。",
      "課表缺漏的正常改期堂次會補回，修改備註不會新增預排。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "共用方案可跨課程正確新增與預約堂次，不會誤判單一課程已滿。",
          "收帳金額與收據期間會按照實際合約與堂次資料顯示。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "課表缺漏的正常改期堂次會補回，修改備註不會新增預排。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-12:in-app-bug-fixes"
    ],
    "date": "2026-08-12",
    "version": "2026.08.12"
  },
  {
    "id": "staff-2026-08-09-payroll-director-rules-v2",
    "publishedAt": "2026-08-09",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "正職薪資假日假規則更精準",
    "summary": "假日假維持資格但不會創造假日倍率，平日下午只計常態排課。",
    "items": [
      "常態假日滿16小時時，假日請假不扣假日倍率與每週16段獎金。",
      "常態假日不足16小時時，假日假不會補成10%倍率。",
      "平日下午倍率排除補課與臨時加課，常態5.5小時換算0.75段。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "常態假日滿16小時時，假日請假不扣假日倍率與每週16段獎金。",
          "常態假日不足16小時時，假日假不會補成10%倍率。",
          "平日下午倍率排除補課與臨時加課，常態5.5小時換算0.75段。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-09:payroll-director-rules-v2"
    ],
    "date": "2026-08-09",
    "version": "2026.08.09"
  },
  {
    "id": "staff-2026-08-08-payroll-eligibility",
    "publishedAt": "2026-08-08",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "major",
    "title": "正職薪資條件更清楚",
    "summary": "主任可分項查看正職老師薪資要件。",
    "items": [
      "可依週、月、年度查看正職老師薪資要件報表。",
      "各項符合、不符合與待確認條件會分開顯示。"
    ],
    "sections": [
      {
        "title": "你現在可以",
        "items": [
          "可依週、月、年度查看正職老師薪資要件報表。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "各項符合、不符合與待確認條件會分開顯示。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-08:payroll-eligibility"
    ],
    "date": "2026-08-08",
    "version": "2026.08.08"
  },
  {
    "id": "staff-2026-08-08-makeup-candidate-date-fix",
    "publishedAt": "2026-08-08",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "請假補課日期更合理",
    "summary": "原堂是 8/20 時，補課候選會從原堂之後開始，不會出現 8/9 這類錯誤日期。",
    "items": [
      "補課候選會參考原堂日期，原堂 8/20 的案件只會提供 8/21 之後的時段。",
      "主任畫面會顯示補課候選範圍，確認日期依據更清楚。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "補課候選會參考原堂日期，原堂 8/20 的案件只會提供 8/21 之後的時段。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "主任畫面會顯示補課候選範圍，確認日期依據更清楚。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-08:makeup-candidate-date-fix"
    ],
    "date": "2026-08-08",
    "version": "2026.08.08"
  },
  {
    "id": "staff-2026-08-08-leave-review-integrity",
    "publishedAt": "2026-08-08",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "請假評量狀態更完整",
    "summary": "請假補課確認後，原堂評量會正確清理。",
    "items": [
      "主任確認請假補課後，原堂待審評量不會殘留。",
      "夜間檢查會自動清理既有的請假殘留狀態。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "主任確認請假補課後，原堂待審評量不會殘留。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "夜間檢查會自動清理既有的請假殘留狀態。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-08:leave-stale-review"
    ],
    "date": "2026-08-08",
    "version": "2026.08.08"
  },
  {
    "id": "staff-2026-08-08-calendar-stability",
    "publishedAt": "2026-08-08",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "行事曆改期更可靠",
    "summary": "改期、重複時段與月結課判斷更準。",
    "items": [
      "不存在的改期堂次不會被行事曆畫出來。",
      "連續調課或同時段重排只保留最新有效時段。",
      "月結課不會被誤判為超排。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "不存在的改期堂次不會被行事曆畫出來。",
          "連續調課或同時段重排只保留最新有效時段。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "月結課不會被誤判為超排。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-08:calendar-orphan-rescheduled",
      "changelog:2026-08-08:calendar-reschedule-chain",
      "changelog:2026-08-08:calendar-duplicate-monthly"
    ],
    "date": "2026-08-08",
    "version": "2026.08.08"
  },
  {
    "id": "staff-2026-08-07-scheduling-billing-improvements",
    "publishedAt": "2026-08-07",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "課表與繳費更穩定",
    "summary": "手動排課、暫停恢復與繳費提醒更順。",
    "items": [
      "手動排課偏離預設時段時，取消與評量操作仍可使用。",
      "課程恢復後，被取消的未來堂次會一併還原。",
      "大量繳費提醒的查詢效率提升。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "手動排課偏離預設時段時，取消與評量操作仍可使用。",
          "課程恢復後，被取消的未來堂次會一併還原。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "大量繳費提醒的查詢效率提升。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-07:manual-scheduling-session-time",
      "changelog:2026-08-05:course-pause-restore",
      "changelog:2026-08-07:billing-alert-n-plus-one"
    ],
    "date": "2026-08-07",
    "version": "2026.08.07"
  },
  {
    "id": "staff-2026-08-06-scheduling-fixes",
    "publishedAt": "2026-08-06",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "課表與繳費頁面修復",
    "summary": "重複課程審核、請假撤銷、暫停恢復、行事曆換週都修好了。",
    "items": [
      "重複課程審核頁面選分校後，只會顯示該分校資料。",
      "請假撤銷後可以正常填寫評量，不再跳出錯誤訊息。",
      "行事曆換週不會弄丟已上堂次；課程暫停後恢復，取消的堂次會一併還原。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "重複課程審核頁面選分校後，只會顯示該分校資料。",
          "請假撤銷後可以正常填寫評量，不再跳出錯誤訊息。",
          "行事曆換週不會弄丟已上堂次；課程暫停後恢復，取消的堂次會一併還原。"
        ]
      }
    ],
    "sourceRefs": [
      "2026-08-06"
    ],
    "date": "2026-08-06",
    "version": "2026.08.06"
  },
  {
    "id": "staff-2026-08-06-leave-makeup-count-fix",
    "publishedAt": "2026-08-06",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "請假補堂不再超額",
    "summary": "請假或取消後自動補的堂次，會正確對齊已購買堂數，不會越補越多。",
    "items": [
      "請假或取消課程後，系統自動補的堂次會正確對齊已購買堂數，不會超過實際購買的堂數。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "請假或取消課程後，系統自動補的堂次會正確對齊已購買堂數，不會超過實際購買的堂數。"
        ]
      }
    ],
    "sourceRefs": [
      "2026-08-06"
    ],
    "date": "2026-08-06",
    "version": "2026.08.06"
  },
  {
    "id": "staff-2026-08-05-calendar-data-stability",
    "publishedAt": "2026-08-05",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "行事曆資料更完整",
    "summary": "編輯合約後，舊課程堂次仍會正常保留。",
    "items": [
      "編輯新合約後，已完成的舊堂次不會從行事曆消失。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "編輯新合約後，已完成的舊堂次不會從行事曆消失。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-05:calendar-history-cache"
    ],
    "date": "2026-08-05",
    "version": "2026.08.05"
  },
  {
    "id": "staff-2026-07-week-30",
    "publishedAt": "2026-07-29",
    "effectiveAt": "2026-07-28",
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "本週排課與家長留言改善",
    "summary": "調課後課表更穩定；老師也更容易找到並回覆家長留言。",
    "items": [
      "單堂調課後，課程不會再被系統自動拉回原本時段。",
      "老師工作台可看到「待回覆家長留言」，並直接處理。",
      "評量列表的家長留言預覽可一鍵開啟回覆。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "單堂調課後，課程不會再被系統自動拉回原本時段。"
        ]
      },
      {
        "title": "你現在可以",
        "items": [
          "老師工作台可看到「待回覆家長留言」，並直接處理。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "評量列表的家長留言預覽可一鍵開啟回覆。"
        ]
      }
    ],
    "sourceRefs": [
      "2026-07-28"
    ],
    "date": "2026-07-29",
    "version": "2026.07.29"
  },
  {
    "id": "staff-2026-07-29-tuition-alert-payment-truth-959",
    "publishedAt": "2026-07-29",
    "effectiveAt": null,
    "audiences": [
      "director"
    ],
    "audience": [
      "director"
    ],
    "importance": "digest",
    "title": "繳費提醒付款狀態修正",
    "summary": "帳單已結清但課程繳費狀態未同步更新時，繳費提醒頁面不再誤顯示「未繳費」與欠款金額。",
    "items": [
      "繳費提醒頁的已繳/未繳狀態改為跟課程列表同一套判斷，不再兩邊不一致。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "繳費提醒頁的已繳/未繳狀態改為跟課程列表同一套判斷，不再兩邊不一致。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-07-29:tuition-alert-payment-truth-959"
    ],
    "date": "2026-07-29",
    "version": "2026.07.29"
  },
  {
    "id": "staff-2026-07-28-reschedule-stability",
    "publishedAt": "2026-07-28",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "major",
    "title": "調課後課表不再跳回",
    "summary": "單堂調到新時段後會固定下來，重整或儲存也不會被拉回原時段。",
    "items": [
      "單堂調課會當成該堂例外，不再被固定週期排課覆蓋回去。",
      "課程管理預設只顯示有效堂次，已取消的內部紀錄改為可展開摘要。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "單堂調課會當成該堂例外，不再被固定週期排課覆蓋回去。"
        ]
      },
      {
        "title": "操作更順手",
        "items": [
          "課程管理預設只顯示有效堂次，已取消的內部紀錄改為可展開摘要。"
        ]
      }
    ],
    "sourceRefs": [
      "2026-07-28",
      "2026-07-24"
    ],
    "date": "2026-07-28",
    "version": "2026.07.28"
  },
  {
    "id": "staff-2026-07-week-29",
    "publishedAt": "2026-07-24",
    "effectiveAt": null,
    "audiences": [
      "director",
      "teacher"
    ],
    "audience": [
      "director",
      "teacher"
    ],
    "importance": "digest",
    "title": "課表與調課操作更清楚",
    "summary": "有效堂次與幽靈取消分開顯示；調課失敗原因會留在對話框內。",
    "items": [
      "調課失敗（含衝堂名單）改顯示在對話框內，送出中會停用按鈕。",
      "已取消或內部調課紀錄不會再像正常課一樣搶版面。",
      "暫停課程時可勾選是否取消剩餘排課（預設勾選）。"
    ],
    "sections": [
      {
        "title": "操作更順手",
        "items": [
          "調課失敗（含衝堂名單）改顯示在對話框內，送出中會停用按鈕。",
          "暫停課程時可勾選是否取消剩餘排課（預設勾選）。"
        ]
      },
      {
        "title": "我們修好了",
        "items": [
          "已取消或內部調課紀錄不會再像正常課一樣搶版面。"
        ]
      }
    ],
    "sourceRefs": [
      "2026-07-24"
    ],
    "date": "2026-07-24",
    "version": "2026.07.24"
  }
];
