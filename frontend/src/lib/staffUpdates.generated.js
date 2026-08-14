/**
 * AUTO-GENERATED — source: docs/STAFF_UPDATES.yml
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */
export const staffUpdates = [
  {
    "id": "staff-2026-08-14-teacher-home-campus-label",
    "publishedAt": "2026-08-14",
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
    "summary": "課堂缺分校時不再出現看不懂的編號，改顯示中文或隱藏。",
    "items": [
      "教師首頁待填評量與週課表會顯示分校名稱；缺資料時改為中文說明或隱藏。"
    ],
    "sections": [
      {
        "title": "我們修好了",
        "items": [
          "教師首頁待填評量與週課表會顯示分校名稱；缺資料時改為中文說明或隱藏。"
        ]
      }
    ],
    "sourceRefs": [
      "changelog:2026-08-14:teacher-home-campus-label"
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
