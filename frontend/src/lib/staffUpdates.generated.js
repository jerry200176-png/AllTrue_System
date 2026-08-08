/**
 * AUTO-GENERATED — source: docs/STAFF_UPDATES.yml
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */
export const staffUpdates = [
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
      "2026-08-08"
    ],
    "date": "2026-08-08",
    "version": "2026.08.08"
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
