import { describe, it, expect } from 'vitest';
import { summaryGridColumns, summaryGridStyle } from '../../lib/summaryGrid.js';

// In-app #538: five summary cards in a fixed 4-column grid left one card alone on the second row.
describe('summaryGridColumns', () => {
  it.each([3, 4, 5, 6])('%i cards: no breakpoint ends a row with a single orphan card', (n) => {
    const { wide, mid } = summaryGridColumns(n);
    for (const cols of [wide, mid, 2]) {
      // Narrow is always 2 columns; the stylesheet spans a trailing odd card, so only wide/mid must divide cleanly.
      if (cols === 2) continue;
      expect(n % cols, `${n} cards in ${cols} columns`).not.toBe(1);
    }
  });

  it('5 cards: one row when wide, 3 + 2 when mid', () => {
    expect(summaryGridColumns(5)).toEqual({ wide: 5, mid: 3 });
  });

  it('never leaves an orphan for any card count 2..24', () => {
    for (let n = 2; n <= 24; n += 1) {
      const { wide, mid } = summaryGridColumns(n);
      expect(n % wide, `wide ${n}`).not.toBe(1);
      expect(n % mid, `mid ${n}`).not.toBe(1);
    }
  });

  it('exposes the counts as CSS variables the stylesheet reads', () => {
    expect(summaryGridStyle(4)).toEqual({ '--summary-cols-wide': 4, '--summary-cols-mid': 2 });
  });

  it('treats a missing or zero count as one card', () => {
    expect(summaryGridColumns(0)).toEqual({ wide: 1, mid: 1 });
    expect(summaryGridColumns(undefined)).toEqual({ wide: 1, mid: 1 });
  });
});
