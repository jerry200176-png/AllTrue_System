#!/usr/bin/env node
/**
 * Pattern D (architecture report, 2026-07-28): low-cost contract check between
 * frontend API calls and backend routes. Diffs every `/api/v1/...` path
 * literal referenced in frontend source against `php artisan route:list`
 * output, so a frontend-ships-ahead-of-backend gap (TD-067 / R79 — #1197's
 * BatchInvoiceModal/OverdueBucketsPanel calling routes that never made it
 * into main) gets a signal before a director clicks it in production.
 *
 * Deliberately ADVISORY (never fails the build) for now, same graduation
 * path as PHPStan: TD-067's known drift needs cleaning up first, otherwise
 * this would immediately red every unrelated PR. Once clean, flip
 * FAIL_ON_DRIFT to fail CI on new drift the way PHPStan can later.
 */

import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const FAIL_ON_DRIFT = false;

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '../..');
const FRONTEND_SRC = path.join(REPO_ROOT, 'frontend/src');
const BACKEND_DIR = path.join(REPO_ROOT, 'backend');

const SKIP_DIR_NAMES = new Set(['__tests__', 'node_modules', 'dist', 'dist_build']);
const SOURCE_FILE_RE = /\.(vue|js|ts)$/;
const TEST_FILE_RE = /\.(test|spec)\.(js|ts)$/;
const API_PATH_RE = /['"`](\/api\/v1\/[a-zA-Z0-9_\-/${}.]*)['"`]/g;

function normalizeSegment(seg) {
    if (seg === '' ) return seg;
    if (seg.includes('${') || seg.includes('{') || /^\d+$/.test(seg) || seg.startsWith(':')) {
        return '*';
    }
    return seg;
}

function getBackendRoutePatterns() {
    let output;
    try {
        output = execSync('php artisan route:list', {
            cwd: BACKEND_DIR,
            encoding: 'utf8',
            maxBuffer: 20 * 1024 * 1024,
        });
    } catch (err) {
        console.log(`⚠️  frontend-api-routes: could not run "php artisan route:list" (${err.message}); skipping check.`);
        return null;
    }

    const patterns = [];
    for (const line of output.split('\n')) {
        // Laravel 8 text table: | METHOD | URI | Name |
        const m = line.match(/\|\s*([\w|]+)\s*\|\s*(\S+)\s*\|/);
        if (!m) continue;
        const uri = m[2];
        if (!uri.startsWith('api/')) continue;
        const segments = uri.split('/').filter((s) => s.length > 0).map(normalizeSegment);
        patterns.push(segments);
    }
    return patterns;
}

function collectFrontendFiles(dir) {
    const results = [];
    let entries;
    try {
        entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch {
        return results;
    }
    for (const entry of entries) {
        if (SKIP_DIR_NAMES.has(entry.name)) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            results.push(...collectFrontendFiles(full));
        } else if (SOURCE_FILE_RE.test(entry.name) && !TEST_FILE_RE.test(entry.name)) {
            results.push(full);
        }
    }
    return results;
}

function extractFrontendPaths(files) {
    const found = new Map();
    for (const file of files) {
        const content = fs.readFileSync(file, 'utf8');
        let match;
        API_PATH_RE.lastIndex = 0;
        while ((match = API_PATH_RE.exec(content))) {
            let raw = match[1].split('?')[0].replace(/\/+$/, '');
            const segments = raw.split('/').filter((s) => s.length > 0).map(normalizeSegment);
            const key = segments.join('/');
            if (!found.has(key)) {
                found.set(key, { raw, segments, file: path.relative(REPO_ROOT, file) });
            }
        }
    }
    return found;
}

function routeExists(frontendSegments, backendPatterns) {
    return backendPatterns.some((bp) => {
        if (bp.length !== frontendSegments.length) return false;
        return bp.every((seg, i) => seg === '*' || frontendSegments[i] === '*' || seg === frontendSegments[i]);
    });
}

function main() {
    const backendPatterns = getBackendRoutePatterns();
    if (backendPatterns === null) {
        return; // route:list unavailable in this environment (e.g. no vendor/) — not a failure.
    }

    const files = collectFrontendFiles(FRONTEND_SRC);
    const frontendPaths = extractFrontendPaths(files);

    const missing = [];
    for (const info of frontendPaths.values()) {
        if (!routeExists(info.segments, backendPatterns)) {
            missing.push(info);
        }
    }

    console.log(`frontend-api-routes: scanned ${files.length} frontend files, ${frontendPaths.size} distinct /api/v1/* paths, ${backendPatterns.length} backend routes.`);

    if (missing.length === 0) {
        console.log('✅ Every frontend /api/v1/* path matches a backend route.');
        return;
    }

    console.log(`⚠️  ${missing.length} frontend path(s) have no matching backend route (advisory — see docs/TECH_DEBT.md TD-067 for known drift):`);
    for (const m of missing) {
        console.log(`  - ${m.raw}  (${m.file})`);
    }

    if (FAIL_ON_DRIFT) {
        process.exitCode = 1;
    }
}

main();
