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

function copyWithFsCp() {
  const indexFrom = path.join(fromDir, 'index.html');
  const assetsFrom = path.join(fromDir, 'assets');
  const assetsTo = path.join(toDir, 'assets');
  resetAssetsDir();
  fs.cpSync(indexFrom, path.join(toDir, 'index.html'), { force: true });
  for (const f of fs.readdirSync(assetsFrom)) {
    const src = path.join(assetsFrom, f);
    const dest = path.join(assetsTo, f);
    if (fs.statSync(src).isFile()) fs.cpSync(src, dest, { force: true });
  }
  ensureBranchesJson();
}

function copyWithNode() {
  const indexFrom = path.join(fromDir, 'index.html');
  const indexTo = path.join(toDir, 'index.html');
  fs.writeFileSync(indexTo, fs.readFileSync(indexFrom));
  const assetsFrom = path.join(fromDir, 'assets');
  const assetsTo = path.join(toDir, 'assets');
  resetAssetsDir();
  for (const f of fs.readdirSync(assetsFrom)) {
    const src = path.join(assetsFrom, f);
    const dest = path.join(assetsTo, f);
    if (fs.statSync(src).isFile()) fs.writeFileSync(dest, fs.readFileSync(src));
  }
  ensureBranchesJson();
}

function copyWithCp() {
  const fromAssets = path.join(fromDir, 'assets');
  const toAssets = path.join(toDir, 'assets');
  resetAssetsDir();
  execSync(`mkdir -p "${toAssets}" && cp -f "${path.join(fromDir, 'index.html')}" "${toDir}/" && cp -rf "${fromAssets}"/* "${toAssets}/"`, { stdio: 'pipe', shell: true });
  ensureBranchesJson();
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
    process.exit(0);
  } catch (e) {
    lastErr = e;
  }
}
fail(lastErr);
