#!/usr/bin/env node
/**
 * Maps docs/CHANGELOG.md → frontend/src/lib/releaseNotes.generated.js
 * Run from repo root or via `npm run sync-release-notes` in frontend/.
 * Groups entries by date into user-facing release cards and filters internal-only headings.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const mdPath = path.join(root, 'docs', 'CHANGELOG.md');
const outPath = path.join(root, 'frontend', 'src', 'lib', 'releaseNotes.generated.js');

const headingRe = /^## (\d{4}-\d{2}-\d{2}) — (.+)$/;

function shouldSkipHeading(title) {
  const t = String(title || '').trim();
  return (
    /^(ops|chore|docs|ci|td|security|fix\(ops\)|feat\(ops\))[:(（]/i.test(t)
    || /（ops）|Added（ops）|fix\(ops\)|chore\(github|chore\(ci|docs\(|docs\(ai|ci\(security|ops\(ci|\btd\(|\bbi\(|Golden scenarios|Issue 模板|CODEOWNERS|CONTRIBUTING|PITR|binlog|nightly|Actions|presubmit|runner|SSH|Dependency Review/i.test(
      t,
    )
  );
}

function buildVersionMap(dates) {
  const sorted = [...new Set(dates)].sort();
  /** @type {Map<string, string>} */
  const versionByDate = new Map();

  for (let i = 0; i < sorted.length; i += 1) {
    const date = sorted[i];
    versionByDate.set(date, `1.0.${i + 1}`);
  }

  return versionByDate;
}

function stripTechNoise(s) {
  let t = String(s)
    .replace(/`[^`]+`/g, '')
    .replace(/\([^)]*#\d+[^)]*\)/g, '')
    .replace(/#[0-9]+/g, '')
    .replace(/\b[A-Z][A-Za-z0-9_]*(Controller|Service|Command|Test|Factory|Model)::[A-Za-z0-9_]+\b/g, '')
    .replace(/\b(GET|POST|PUT|PATCH|DELETE)\s+\/[A-Za-z0-9_/?&=.-]+/g, '')
    .replace(/\bAPI\b/gi, '')
    .replace(/\binbox\b/gi, '待辦匣')
    .replace(/\bCI\b/gi, '')
    .replace(/\bHIGH\b/gi, '')
    .replace(/\bPhpSpreadsheet\b/gi, '匯入套件')
    .replace(/\b[a-zA-Z0-9_./-]+\.(vue|js|mjs|php|md|yml|json|cjs)\b/g, '')
    .replace(/\b[A-Za-z_][A-Za-z0-9_]*\(\)/g, '')
    .replace(/\bFR-\d+\b/g, '')
    .replace(/\bPR\s*\)?/gi, '')
    .replace(/^-+/g, '')
    .replace(/-style/gi, ' 介面')
    .replace(/-inspired/gi, ' 風格')
    .replace(/\s*[：:]\s*/g, '：')
    .replace(/\s+/g, ' ')
    .trim();
  t = t.replace(/^[A-Za-z]+(?:\([^)]+\))?[：:]?\s*/i, '').trim();
  t = t.replace(/^[^A-Za-z0-9\u4e00-\u9fff]+/, '').trim();
  t = t.replace(/\(\s*\)/g, '').trim();
  if (t.length > 240) {
    return `${t.slice(0, 237)}…`;
  }
  return t;
}

function isReadablePhrase(text) {
  const t = String(text || '').trim();
  if (!t) return false;
  if (t.length < 4) return false;
  if (/^[\W_]+$/.test(t)) return false;
  return true;
}

function plainTextFromEntry(rawTitle, itemLines) {
  const source = `${rawTitle} ${itemLines.join(' ')}`;
  const title = String(rawTitle || '');

  const rules = [
    [/Dashboard 欄位|系統待機動畫|白話版本公告/i, '主任總覽欄位重新分配，系統新增登入後與閒置 Logo 動畫，版本更新改成白話版本卡'],
    [/版本更新.*CHANGELOG|sync release notes|release notes/i, '版本更新會自動整理最近變更，顯示成更好讀的更新公告'],
    [/主任總覽.*緊湊|desktop/i, '主任總覽在電腦版重新整理版面，畫面更平均也更不需要一直往下滑'],
    [/Super Admin.*版本更新|super_admin/i, '修正超級管理員看不到版本更新內容的問題'],
    [/暫停課程.*評量/i, '修正暫停課程最後一堂的評量仍能被主任找到並處理'],
    [/代課老師.*衝堂|調課.*代課|effective 代課/i, '修正代課與調課時誤判老師撞課的問題，安排課程更順'],
    [/老師評量待辦|填寫率/i, '老師工作台新增評量待辦提醒，主任也能看到各老師評量填寫狀況'],
    [/系統內建.*版本更新|版本更新.*頁面/i, '新增系統內的版本更新頁，老師和主任可直接查看近期更新'],
    [/首次登入.*新版重點/i, '登入後會提示新版重點，讓現場同仁更快知道這版多了什麼'],
    [/學生名冊|CSV|XLSX|匯入/i, '改善學生名冊匯入，表格格式比較不容易造成匯入失敗'],
    [/行事曆.*occurrence|行事曆.*合約/i, '修正行事曆課程合併邏輯，避免課程重複出現或突然消失'],
    [/停用舊課程/i, '修正停用舊課程後，學生不會再同時出現在新舊老師課表上'],
    [/補課候選.*確認/i, '主任可以更快確認補課候選時段'],
    [/補課候選.*產生/i, '系統可以協助產生補課候選時段'],
    [/家長請假.*主任.*inbox|家長請假.*閉環/i, '家長請假申請可由主任集中追蹤處理'],
    [/請假補課.*資料層|請假補課.*核心/i, '請假補課流程的資料串接更完整'],
    [/多科共用堂數/i, '新增多科共用堂數與加購分流入口'],
  ];

  for (const [pattern, text] of rules) {
    if (pattern.test(source)) return text;
  }

  const cleanTitle = stripTechNoise(title.replace(/^([^:：]+)[：:]\s*/, ''));
  if (isReadablePhrase(cleanTitle)) return cleanTitle;
  const cleanItem = stripTechNoise(itemLines[0] || title);
  if (isReadablePhrase(cleanItem)) return cleanItem;
  return '優化系統使用體驗';
}

function categoryForTitle(rawTitle) {
  const t = String(rawTitle || '');
  if (/fix|Fixed|修正/i.test(t)) return '修正內容';
  if (/Changed|調整|ui|介面|體驗/i.test(t)) return '體驗調整';
  if (/feat|Added|新增/i.test(t)) return '新增內容';
  return '其他改善';
}

function buildSummary(sections) {
  const firstItems = sections.flatMap((section) => section.items).slice(0, 2);
  if (firstItems.length === 0) return '這版整理了近期系統改善，讓日常操作更順。';
  return firstItems.join('；');
}

function parseChangelog(md) {
  const lines = md.split('\n');
  /** @type {Map<string, Map<string, string[]>>} */
  const grouped = new Map();

  for (let i = 0; i < lines.length; i++) {
    const hm = lines[i].match(headingRe);
    if (!hm) {
      continue;
    }
    const date = hm[1];
    const rawTitle = hm[2].trim();
    if (shouldSkipHeading(rawTitle)) {
      continue;
    }

    const items = [];
    for (let j = i + 1; j < lines.length; j++) {
      if (headingRe.test(lines[j])) {
        break;
      }
      const bm = lines[j].match(/^\s*-\s+(.+)$/);
      if (bm) {
        items.push(bm[1]);
      }
    }

    const category = categoryForTitle(rawTitle);
    const text = plainTextFromEntry(rawTitle, items);
    if (!isReadablePhrase(text)) {
      continue;
    }
    if (!grouped.has(date)) grouped.set(date, new Map());
    const sections = grouped.get(date);
    if (!sections.has(category)) sections.set(category, []);
    const bucket = sections.get(category);
    if (!bucket.includes(text)) bucket.push(text);
  }

  const versionByDate = buildVersionMap([...grouped.keys()]);
  const notes = [];
  for (const [date, sectionMap] of grouped.entries()) {
    const version = versionByDate.get(date) || '1.1.1';
    const sectionOrder = ['新增內容', '修正內容', '體驗調整', '其他改善'];
    const sections = sectionOrder
      .map((title) => ({ title, items: (sectionMap.get(title) || []).slice(0, 6) }))
      .filter((section) => section.items.length > 0);

    notes.push({
      version,
      date,
      title: `${version} 版本更新`,
      summary: buildSummary(sections),
      audience: ['teacher', 'director'],
      sections,
      items: sections.flatMap((section) => section.items).slice(0, 8),
    });

    if (notes.length >= 18) {
      break;
    }
  }

  if (notes.length === 0) {
    notes.push({
      version: '1.1.1',
      date: new Date().toISOString().slice(0, 10),
      title: '系統更新紀錄',
      summary: '可在這裡查看最近的系統更新。',
      audience: ['teacher', 'director'],
      sections: [{ title: '其他改善', items: ['可查閱完整 CHANGELOG 了解近期更新'] }],
      items: ['可查閱技術紀錄 CHANGELOG'],
    });
  }

  return notes;
}

const md = fs.readFileSync(mdPath, 'utf8');
const data = parseChangelog(md);

const banner = `/**
 * AUTO-GENERATED — source: docs/CHANGELOG.md
 * Regenerate: (cd frontend && npm run sync-release-notes)
 */\n`;

fs.writeFileSync(outPath, `${banner}export const changelogReleaseNotes = ${JSON.stringify(data, null, 2)};\n`, 'utf8');
console.error(`changelog-to-release-notes: wrote ${data.length} cards → ${outPath}`);
