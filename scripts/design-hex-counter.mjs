#!/usr/bin/env node

import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

const RAW_HEX_PATTERN = /#[0-9a-fA-F]{8}(?![0-9a-fA-F])|#[0-9a-fA-F]{6}(?![0-9a-fA-F])|#[0-9a-fA-F]{4}(?![0-9a-fA-F])|#[0-9a-fA-F]{3}(?![0-9a-fA-F])/g;

/**
 * Remove Vue/JS/CSS comments without changing strings or line structure.
 * Comment contents become spaces so tokens on either side cannot join.
 */
export function stripVueComments(source) {
  let output = '';
  let state = 'normal';

  for (let i = 0; i < source.length; i += 1) {
    const char = source[i];
    const next = source[i + 1] ?? '';

    if (state === 'line-comment') {
      if (char === '\n' || char === '\r') {
        output += char;
        state = 'normal';
      } else {
        output += ' ';
      }
      continue;
    }

    if (state === 'block-comment') {
      if (char === '*' && next === '/') {
        output += '  ';
        i += 1;
        state = 'normal';
      } else {
        output += char === '\n' || char === '\r' ? char : ' ';
      }
      continue;
    }

    if (state === 'html-comment') {
      if (source.startsWith('-->', i)) {
        output += '   ';
        i += 2;
        state = 'normal';
      } else {
        output += char === '\n' || char === '\r' ? char : ' ';
      }
      continue;
    }

    if (state === 'single-quote' || state === 'double-quote' || state === 'template') {
      output += char;
      if (char === '\\' && i + 1 < source.length) {
        output += source[i + 1];
        i += 1;
        continue;
      }
      if (
        (state === 'single-quote' && char === "'")
        || (state === 'double-quote' && char === '"')
        || (state === 'template' && char === '`')
      ) {
        state = 'normal';
      }
      continue;
    }

    if (source.startsWith('<!--', i)) {
      output += '    ';
      i += 3;
      state = 'html-comment';
    } else if (char === '/' && next === '/') {
      output += '  ';
      i += 1;
      state = 'line-comment';
    } else if (char === '/' && next === '*') {
      output += '  ';
      i += 1;
      state = 'block-comment';
    } else {
      output += char;
      if (char === "'") state = 'single-quote';
      if (char === '"') state = 'double-quote';
      if (char === '`') state = 'template';
    }
  }

  return output;
}

export function countRawHex(source) {
  return stripVueComments(source).match(RAW_HEX_PATTERN)?.length ?? 0;
}

const invokedPath = process.argv[1] ? pathToFileURL(process.argv[1]).href : '';
if (invokedPath === import.meta.url) {
  const file = process.argv[2];
  if (!file) {
    console.error('Usage: node scripts/design-hex-counter.mjs <vue-file>');
    process.exit(2);
  }
  process.stdout.write(`${countRawHex(fs.readFileSync(file, 'utf8'))}\n`);
}
