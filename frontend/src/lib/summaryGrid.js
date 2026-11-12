// Column counts for a row of summary cards so no row ends with a single orphan card.
// Wide: one row up to 6 cards, otherwise two balanced rows. Mid: three or fewer in one row, otherwise two balanced rows.
// Narrow (<= 640px) is always 2 columns; the stylesheet spans a trailing odd card across the row.
// Why not CSS auto-fit alone: it cannot know the card count, so 5 cards at 4 columns leave one alone (in-app #538).
export function summaryGridColumns(count) {
  const n = Math.max(1, Math.floor(Number(count)) || 1);
  return {
    wide: n <= 6 ? n : Math.ceil(n / 2),
    mid: n <= 3 ? n : Math.ceil(n / 2),
  };
}

/** Inline CSS variables read by `.course-lens-summary` (and any grid using `--summary-cols-*`). */
export function summaryGridStyle(count) {
  const { wide, mid } = summaryGridColumns(count);
  return { '--summary-cols-wide': wide, '--summary-cols-mid': mid };
}
