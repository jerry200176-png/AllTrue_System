/**
 * AUTO-GENERATED — source: docs/CHANGELOG.md
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */
export const changelogReleaseNotes = [
  {
    "version": "2026-05-09",
    "title": "feat(ui): 版本更新由 CHANGELOG 同步、主任總覽桌面版更緊湊",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Added 「scripts/changelog-to-release-notes.mjs」＋「npm run sync-release-notes」：「build」 與 CI 先從 「docs/CHANGELOG.md」 產生課程向更新卡（略過純維運／chore／docs 類標題）",
      "Changed 主任總覽 「≥1100px」 縮短上下節奏；長列表區塊改為區內捲動以降低整頁長度",
      "Added「版本更新」頁底 GitHub CHANGELOG 連結"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "Fixed（ui）: Super Admin 「版本更新」空白",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 「notesForRole」：super_admin／admin 對齊可看主任／老師向發布備註；CI 增加 「npm run test:release-notes」"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "fix(learning): 暫停課程最後堂 scheduled 未回寫仍可見待審評量",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 「LearningRecord::excludePausedCoursePendingReview」：課程已 Stop 且堂次結束時間已過但仍為 「scheduled」 時，保留 「pending」／「changes_requested」 於列表（避免最後一堂評量永遠載不出、主任無法退回）"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "fix(substitute): 調課時代課老師被合約老師時段誤阻修正",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 「ScheduleController::store」 FR-003：建立調課目標排程列時，若合約老師(A)已有代課老師(B)指派，改以B為基準做衝堂檢查，避免A的其他課程錯誤阻擋有效的調課操作"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "fix(substitute): 代課老師衝堂誤報修正（#275）",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 「SubstituteService::collectTeacherBusySlots」 / 「collectTeacherBusySlotsWithCapacity」：合約老師的課若已有代課安排，不再誤標為忙碌；修正代課選人 modal 錯誤顯示「在其他分校有課」的 false positive"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "fix(schedule): 代課後調課寫 schedules 自動採 effective 代課老師",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 「POST /schedules」 對 「scheduled」 + 「original_schedule_id」（調課目標列）先做 anchor 鏈結代課老師消解，避免請求沿用 「StudentClass.TeacherID」 觸發假性撞課；行事曆 「submitReschedule」 同步將 「teacher_id」 對齊已存在的代課列"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "feat(learning): 老師評量待辦角標與一鍵開填、主任填寫率報表",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Added 教學工作台優先開下一筆待填／「GET me/learning-pending-summary」 角標；主任儀表板近 14 天各老師已到班堂次之評量進度填寫率（「GET reports/teacher-learning-fill-rates」）"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "feat(ui): 系統內建「版本更新」頁面（老師/主任）",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Added 老師與主任側欄新增「版本更新」入口，集中顯示近期版本新增功能與修正重點，降低口頭公告成本"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "feat(ui): 首次登入顯示「新版重點」導覽卡",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Added 老師與主任登入後首次會看到簡短新版提醒，支援「立即查看 / 稍後再看」；文案改為非技術語言，讓現場同仁更容易理解"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "fix(import): 學生名冊 CSV/XLSX 標題列與 0 列匯入",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 「ImportController」：標題列掃描加深至前 30 列、「normalizeHeader」 支援全形空白；若僅有表頭無資料列回 **422** 並寫入可讀 「ErrorLog」；補 「StudentImportTest」 迴歸案例（#205）"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "fix(calendar): 行事曆改用 occurrence 合約合併",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 智慧行事曆週檢視以單一 occurrence resolver 合併 「StudentClass」、「ClassSession」 與 「schedules」，避免同一堂課重複掛兩位老師或被 scheduled 例外互相抵消而消失"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "fix(calendar): 停用舊課程不再重複掛兩位老師",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 智慧行事曆載入課程時排除 「status=inactive」 或 「Stop=1」 的舊課，避免同學生在舊/新老師欄位同時出現"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "ops(db): PITR/binlog 評估決策（先 defer）",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Ops/DBA 在 runbook 明確記錄 「#207」 決策：目前先不啟用 production binlog，補齊觸發條件與 pre-enable checklist（限 drill DB 驗證）後再啟動"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "ops(backups): nightly 移除 Pi 端 git push/tag",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Ops 調整 「scripts/nightly-backup.sh」 僅保留 DB 備份與保留策略，不再從 Pi 嘗試 「git-sync」 或 nightly tag push，與 protected-main 治理一致"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "ops(actions): 降低排程 Actions 依賴並補 fallback",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Ops 將 「pi-health.yml」 降為每日、「branch-hygiene.yml」 降為每週，並在 runbook 補齊「minutes 耗盡時由 Pi 本機 monitor-alert + UptimeRobot 承接」與恢復後 rerun 清單"
    ]
  },
  {
    "version": "2026-05-09",
    "title": "ops(backups): 新增一鍵備份稽核腳本",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Ops 新增 「scripts/backup-audit.sh」，集中檢查本機備份、manifest、Google Drive 異地同步、restore drill 結果與只讀 row-count sanity，並以 GREEN/YELLOW/RED 輸出總結"
    ]
  },
  {
    "version": "2026-05-08",
    "title": "security(ops): 維運 SSH 改為 host key pinning",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Security 將 「pi-health.yml」、「backup-restore-test.yml」、「slow-query-report.yml」 移除 「StrictHostKeyChecking no」，改用 「PI_HOST_KEY」 + 「known_hosts」 pinning，並在 Presubmit 增加禁止回歸檢查"
    ]
  },
  {
    "version": "2026-05-08",
    "title": "fix(schedule): 代課堂次顯示在代課老師欄",
    "audience": [
      "teacher",
      "director"
    ],
    "items": [
      "Fixed 同一堂代課若殘留原老師與代課老師的重複排程紀錄，行事曆會優先顯示在代課老師欄位"
    ]
  }
];
