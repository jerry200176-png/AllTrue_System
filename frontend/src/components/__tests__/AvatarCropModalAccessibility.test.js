import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(here, '../AvatarCropModal.vue'), 'utf8');

describe('avatar crop modal accessibility contract', () => {
  it('exposes a labelled modal dialog', () => {
    expect(source).toMatch(/role="dialog"/);
    expect(source).toMatch(/aria-modal="true"/);
    expect(source).toMatch(/aria-labelledby="avatar-crop-title"/);
    expect(source).toMatch(/<h3 id="avatar-crop-title">/);
  });

  it('associates the zoom label with the range control', () => {
    expect(source).toMatch(/<label for="avatar-crop-zoom">/);
    expect(source).toMatch(/<input id="avatar-crop-zoom"[^>]*type="range"/);
  });

  it('keeps crop actions explicit buttons', () => {
    expect(source).toMatch(/<button type="button" class="btn-cancel"/);
    expect(source).toMatch(/<button type="button" class="btn-ok"/);
  });
});
