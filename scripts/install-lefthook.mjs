// `prepare` hook: install lefthook git hooks on a dev machine only. CI / LEFTHOOK=0 / missing binary = no-op,
// so `npm ci` never fails because of it.
import { spawnSync } from 'node:child_process';
if (process.env.CI || process.env.LEFTHOOK === '0') process.exit(0);
const r = spawnSync('npx', ['--no-install', 'lefthook', 'install'], { stdio: 'inherit' });
if (r.status !== 0) console.warn('lefthook install skipped (run `npm run hooks:install` manually)');
