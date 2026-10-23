// Exact source reader, not a router, summary, index or permission gate.
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';

export function headings(text) {
  const result = [];
  let fence = null;
  for (const [i, line] of text.split('\n').entries()) {
    const f = line.match(/^ {0,3}(`{3,}|~{3,})(.*)$/);
    if (f) {
      if (!fence) fence = f[1];
      else if (f[1][0] === fence[0] && f[1].length >= fence.length && !f[2].trim()) fence = null;
      continue;
    }
    if (fence) continue;
    const h = line.match(/^(#{1,6})\s+(.+?)(?:\s+#+)?\s*$/);
    if (h) result.push({ line: i + 1, level: h[1].length, title: h[2] });
  }
  return result;
}

export function section(text, title) {
  const hs = headings(text);
  const matches = hs.filter(h => h.title === title);
  if (matches.length !== 1) throw new Error(`Heading must match exactly once: ${title}; use --outline or read the source directly`);
  const h = matches[0];
  const next = hs.find(n => n.line > h.line && n.level <= h.level);
  const lines = text.split('\n');
  const end = next ? next.line - 1 : lines.length;
  return { start: h.line, end, text: lines.slice(h.line - 1, end).join('\n') };
}

export function readSection(root, args) {
  const [mode, file, title, expectedHash] = args;
  if (!['--section', '--outline'].includes(mode) || !file ||
      (mode === '--section' ? !title || args.length > 4 : args.length !== 2)) {
    throw new Error('Usage: --outline <file> | --section <file> <exact heading> [source-sha256]');
  }
  // Read only tracked instruction/docs sources. Never walk the machine or .local.
  const allowed = /^(AGENTS\.md|CLAUDE\.md|\.cursorrules|(?:docs|governance|\.cursor\/rules|\.(?:agents|claude|cursor)\/skills)\/.+\.(?:md|mdc))$/;
  if (!allowed.test(file) || file.split('/').some(p => p === '..' || p === '.local' || /^(?:\.env|secrets?$)/i.test(p))) {
    throw new Error('Only tracked, public instruction/docs paths are supported; read authorized sources directly');
  }
  execFileSync('git', ['ls-files', '--error-unmatch', '--', file], { cwd: root, stdio: 'pipe' });
  let component = root;
  for (const part of file.split('/')) {
    component = path.join(component, part);
    if (fs.lstatSync(component).isSymbolicLink()) throw new Error('Use the canonical source path, not a symlink');
  }
  const absolute = fs.realpathSync(path.join(root, file));
  if (!absolute.startsWith(`${fs.realpathSync(root)}${path.sep}`)) throw new Error('Source resolves outside the repository');
  const source = fs.readFileSync(absolute, 'utf8');
  const hash = createHash('sha256').update(source).digest('hex');
  const head = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
  const changed = execFileSync('git', ['status', '--porcelain', '--', file], { cwd: root, encoding: 'utf8' }).trim();
  const notice = expectedHash && expectedHash !== hash ? 'STALE source reference: ignore the old excerpt; current source follows.\n' : '';
  const provenance = `${file} HEAD=${head} source-sha256=${hash} tree=${changed ? 'modified' : 'clean'} (source identity, not authority or verification)`;
  if (mode === '--outline') return `${provenance}\n${headings(source).map(h => `${h.line}: ${'#'.repeat(h.level)} ${h.title}`).join('\n')}\n`;
  const s = section(source, title);
  return `${notice}${provenance} lines=${s.start}-${s.end}\n${s.text}\n`;
}
