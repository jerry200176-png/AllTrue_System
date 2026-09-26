import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const discrepancySource = readFileSync(resolve(__dirname, '../../pages/ScheduleDiscrepancyPage.vue'), 'utf8');
const notificationsSource = readFileSync(resolve(__dirname, '../../pages/NotificationsCenter.vue'), 'utf8');
const tuitionReportSource = readFileSync(resolve(__dirname, '../../pages/TuitionReportPage.vue'), 'utf8');

describe('Product clarity UX improvements', () => {
  describe('ScheduleDiscrepancyPage', () => {
    it('uses progressive disclosure for the SOP guide banner', () => {
      expect(discrepancySource).toContain('<details class="sdp-sop-card">');
      expect(discrepancySource).toContain('<summary>');
      expect(discrepancySource).toContain('快速處理 SOP（建議流程）');
      expect(discrepancySource).toContain('sdp-sop-arrow');
    });

    it('avoids raw ID interpolation for missing reporter and branch names', () => {
      expect(discrepancySource).not.toContain('`#${row.reporter_id}`');
      expect(discrepancySource).not.toContain('`#${row.branch_id}`');
      expect(discrepancySource).toContain("'未提供老師姓名'");
      expect(discrepancySource).toContain("'未指定分校'");
    });

    it('uses user-friendly domain terminology instead of engineering terms', () => {
      expect(discrepancySource).toContain('堂次編號');
      expect(discrepancySource).not.toContain('堂次 ID');
    });
  });

  describe('NotificationsCenter', () => {
    it('removes redundant repeated leave-case instructions from loop items', () => {
      expect(notificationsSource).not.toContain('class="leave-case-step"');
      expect(notificationsSource).not.toContain('開啟後可選補課、核准不補課，或填寫原因退回請假。');
      // Panel intro retains high-level guidance
      expect(notificationsSource).toContain('家長請假待主任處理');
      expect(notificationsSource).toContain('先選補課時段，再核准請假；所有決策都在主任處理頁完成。');
    });

    it('translates backend PascalCase SourceType into user-friendly Chinese labels', () => {
      expect(notificationsSource).toContain('sourceTypeLabel');
      expect(notificationsSource).toContain('SOURCE_TYPE_LABELS');
      expect(notificationsSource).toContain('課程合約');
      expect(notificationsSource).toContain('學費帳單');
      expect(notificationsSource).toContain('sourceTypeLabel(item.SourceType)');
    });
  });

  describe('TuitionReportPage', () => {
    it('provides an actionable error state with a retry button', () => {
      expect(tuitionReportSource).toContain('class="tr-error" role="alert"');
      expect(tuitionReportSource).toContain('class="tr-retry-btn" @click="loadData"');
      expect(tuitionReportSource).toContain('再試一次');
    });

    it('keeps report requests and export contracts while making controls accessible', () => {
      expect(tuitionReportSource).toContain('/api/v1/finance/branch-monthly-tuition?${params}');
      expect(tuitionReportSource).toContain('/api/v1/finance/branch-monthly-tuition/export?${params}');
      expect(tuitionReportSource).toContain('AtIconButton icon="chevron_left" label="上一月"');
      expect(tuitionReportSource).toContain('AtIconButton icon="chevron_right" label="下一月"');
      expect(tuitionReportSource).toContain('class="tr-loading" role="status" aria-live="polite"');
      expect(tuitionReportSource).toContain('class="tr-empty" role="status"');
      expect(tuitionReportSource).toContain('aria-label="學收報表分頁"');
      expect(tuitionReportSource).toContain('--ds-control-height-touch, 44px');
    });
  });
});
