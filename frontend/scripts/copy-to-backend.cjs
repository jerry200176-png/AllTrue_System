#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const fromDir = path.resolve(__dirname, '..', 'dist_build');
const toDir = path.resolve(__dirname, '..', '..', 'backend', 'public');

function ensureBranchesJson() {
  const p = path.join(toDir, 'branches.json');
  if (!fs.existsSync(p)) fs.writeFileSync(p, '[{"id":1,"name":"大安分校","code":"daan"}]');
}

function resetAssetsDir() {
  const assetsTo = path.join(toDir, 'assets');
  try {
    fs.rmSync(assetsTo, { recursive: true, force: true });
  } catch (_) {}
  fs.mkdirSync(assetsTo, { recursive: true });
}

if (!fs.existsSync(fromDir)) {
  console.error('Run npm run build first.');
  process.exit(1);
}
if (!fs.existsSync(toDir)) {
  console.error('backend/public 不存在');
  process.exit(1);
}

// Files in dist_build root (from public/) that should always be deployed
const ROOT_ASSETS = ['manifest.json', 'logo.png', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'version.json'];

function copyRootAssets() {
  for (const f of ROOT_ASSETS) {
    const src = path.join(fromDir, f);
    const dest = path.join(toDir, f);
    if (fs.existsSync(src)) {
      try { fs.cpSync(src, dest, { force: true }); } catch (_) {
        try { fs.writeFileSync(dest, fs.readFileSync(src)); } catch (_) {}
      }
    }
  }
}

/** index.html 與 assets hash 必須一致，否則 SPA fallback 會把 .js 當 HTML 回傳 → 整站 MIME 白屏。 */
function verifyIndexHtmlReferencesAssets() {
  const indexPath = path.join(toDir, 'index.html');
  if (!fs.existsSync(indexPath)) {
    throw new Error('verify: index.html missing under backend/public');
  }
  const html = fs.readFileSync(indexPath, 'utf8');
  const refs = new Set();
  for (const re of [/src="\.\/assets\/([^"]+)"/g, /href="\.\/assets\/([^"]+)"/g]) {
    let m;
    while ((m = re.exec(html)) !== null) refs.add(m[1]);
  }
  const missing = [...refs].filter((f) => !fs.existsSync(path.join(toDir, 'assets', f)));
  if (missing.length) {
    throw new Error(
      `verify: index.html references missing asset(s): ${missing.join(', ')} — run full deploy, do not copy only assets/`
    );
  }
}

function writeIndexHtmlAtomic(indexFrom) {
  const buf = fs.readFileSync(indexFrom);
  fs.writeFileSync(path.join(toDir, 'index.html'), buf);
}

function copyWithFsCp() {
  const indexFrom = path.join(fromDir, 'index.html');
  const assetsFrom = path.join(fromDir, 'assets');
  const assetsTo = path.join(toDir, 'assets');
  resetAssetsDir();
  writeIndexHtmlAtomic(indexFrom);
  for (const f of fs.readdirSync(assetsFrom)) {
    const src = path.join(assetsFrom, f);
    const dest = path.join(assetsTo, f);
    if (fs.statSync(src).isFile()) fs.cpSync(src, dest, { force: true });
  }
  copyRootAssets();
  ensureBranchesJson();
  verifyIndexHtmlReferencesAssets();
}

function copyWithNode() {
  const indexFrom = path.join(fromDir, 'index.html');
  const assetsFrom = path.join(fromDir, 'assets');
  const assetsTo = path.join(toDir, 'assets');
  resetAssetsDir();
  writeIndexHtmlAtomic(indexFrom);
  for (const f of fs.readdirSync(assetsFrom)) {
    const src = path.join(assetsFrom, f);
    const dest = path.join(assetsTo, f);
    if (fs.statSync(src).isFile()) fs.writeFileSync(dest, fs.readFileSync(src));
  }
  copyRootAssets();
  ensureBranchesJson();
  verifyIndexHtmlReferencesAssets();
}

function copyWithCp() {
  const fromAssets = path.join(fromDir, 'assets');
  const toAssets = path.join(toDir, 'assets');
  resetAssetsDir();
  execSync(`mkdir -p "${toAssets}" && cp -f "${path.join(fromDir, 'index.html')}" "${toDir}/" && cp -rf "${fromAssets}"/* "${toAssets}/"`, { stdio: 'pipe', shell: true });
  copyRootAssets();
  ensureBranchesJson();
  verifyIndexHtmlReferencesAssets();
}

function createPack() {
  const outFile = path.join(path.dirname(fromDir), 'deploy.tar.gz');
  execSync(`tar -czf "${outFile}" index.html assets/`, { cwd: fromDir, stdio: 'pipe', shell: true });
  console.log('已產生 frontend/deploy.tar.gz');
  console.log('');
  console.log('請在專案根目錄執行：');
  console.log('  sudo tar -xzf frontend/deploy.tar.gz -C backend/public');
  console.log('');
}

function fail(err) {
  console.error('\n無法寫入 backend/public:', err?.code || err?.message || '');
  console.error('');
  try {
    createPack();
  } catch (e) {
    console.error('打包失敗');
  }
  console.error('或執行: ./scripts/fix-deploy-permissions.sh');
  console.error('或執行: cd frontend && ./scripts/deploy-sudo.sh');
  process.exit(1);
}

const methods = [
  ['fs.cpSync', copyWithFsCp],
  ['cp', copyWithCp],
  ['Node', copyWithNode],
];

let lastErr;
for (const [name, fn] of methods) {
  try {
    fn();
    console.log('Done (' + name + ').');
    console.log('Refresh the app in the browser.');
    flushOpcache();
    process.exit(0);
  } catch (e) {
    lastErr = e;
  }
}
fail(lastErr);

/** FR-003: 呼叫保護端點讓 PHP-FPM 自行清除 OPcache bytecode cache */
function flushOpcache() {
  try {
    const envPath = path.resolve(__dirname, '..', '..', 'backend', '.env');
    if (!fs.existsSync(envPath)) return;
    const secret = fs
      .readFileSync(envPath, 'utf8')
      .split('\n')
      .find((l) => l.startsWith('DEPLOY_SECRET='))
      ?.split('=')[1]
      ?.trim();
    if (!secret) return;
    execSync(
      `curl -sf -X POST https://daan.lifenet.com.tw/api/internal/opcache-reset -H "X-Deploy-Secret: ${secret}" -H "Content-Type: application/json"`,
      { stdio: 'pipe', shell: true }
    );
    console.log('OPcache flushed.');
  } catch (_) {
    console.warn('[warn] OPcache flush failed (non-fatal). Run: sudo service php8.2-fpm reload');
  }
}
