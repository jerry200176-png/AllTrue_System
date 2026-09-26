import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('tuition collection viewport containment', () => {
  it('allows the page and table wrapper to shrink inside the app shell', () => {
    expect(source).toContain('min-width: 0;');
    expect(source).toContain('max-width: 100%;');
    expect(source).toContain('overflow-x: auto;');
  });

  it('reduces filter density before tablet content can be clipped', () => {
    expect(source).toContain('@media (max-width: 1100px)');
    expect(source).toContain('@media (max-width: 900px) and (min-width: 769px)');
    expect(source).toContain('grid-template-columns: repeat(2, minmax(0, 1fr));');
  });

  it('wraps accounting actions and bulk controls on touch layouts', () => {
    expect(source).toContain('.tc-table.acct-table .tc-actions {\n    flex-wrap: wrap;');
    expect(source).toContain('.acct-bulkbar { flex-wrap: wrap; }');
    expect(source).toContain('flex: 1 1 9rem;');
  });

  it('keeps dense-table actions visible while the table scrolls horizontally', () => {
    expect(source).toContain('@media (min-width: 769px)');
    expect(source).toContain('.tc-table td:last-child');
    expect(source).toContain('position: sticky;');
    expect(source).toContain('right: 0;');
  });
});
