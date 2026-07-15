import { describe, expect, it } from 'vitest';
import { buildDirectorPriorityRisks } from '../../lib/directorPriorityRisks';

describe('buildDirectorPriorityRisks', () => {
  it('orders urgent operational risks and returns only the top three', () => {
    const risks = buildDirectorPriorityRisks({
      breachedTasks: 2,
      unpaidPayments: 4,
      pendingAttendance: 3,
      exceptionWorkflows: 5,
      pendingEvaluations: 6,
      unreadFeedback: 7,
      renewalAttention: 8,
    });

    expect(risks).toHaveLength(3);
    expect(risks.map((risk) => risk.key)).toEqual([
      'breached-tasks',
      'unpaid-payments',
      'pending-attendance',
    ]);
    expect(risks[0]).toMatchObject({
      count: 2,
      target: 'director-tasks',
      actionLabel: '查看逾期待辦',
    });
  });

  it('keeps renewal attention separate from unpaid tuition', () => {
    const risks = buildDirectorPriorityRisks({ renewalAttention: 2 });

    expect(risks).toEqual([
      expect.objectContaining({
        key: 'renewal-attention',
        count: 2,
        title: '2 筆課程需要續課確認',
        target: 'tuition',
      }),
    ]);
  });

  it('ignores zero, negative, and invalid counts', () => {
    expect(buildDirectorPriorityRisks({
      breachedTasks: 0,
      unpaidPayments: -1,
      pendingAttendance: Number.NaN,
      unreadFeedback: null,
    })).toEqual([]);
  });
});
