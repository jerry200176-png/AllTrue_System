#!/usr/bin/env node
/**
 * 打包 deploy 檔案，供在伺服器上解壓（繞過 EPERM）
 * 輸出: frontend/deploy.tar.gz
 * 伺服器上執行: sudo tar -xzf deploy.tar.gz -C /path/to/backend/public
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const distDir = path.join(__dirname, '..', 'dist');
const packFile = path.join(__dirname, '..', 'deploy.tar.gz');

if (!fs.existsSync(distDir)) {
  console.error('Run npm run build first.');
  process.exit(1);
}

process.chdir(distDir);
execSync('tar -czf ../deploy.tar.gz index.html assets/', { stdio: 'inherit' });

console.log('');
console.log('已產生 frontend/deploy.tar.gz');
console.log('');
console.log('在伺服器上執行（替換為實際路徑）：');
console.log('  sudo tar -xzf frontend/deploy.tar.gz -C backend/public');
console.log('');
