import fs from 'node:fs';

const MARKER = 'ALLTRUE_SESSION_PAYLOAD_V1:';
const MAX_TTL_MS = 30 * 60 * 1000;

function fail(message) {
  throw new Error(message);
}

export function normalizeSessionOutput(output, requestedBranch = 0, now = Date.now()) {
  const markerLines = String(output).split(/\r?\n/).filter((line) => line.startsWith(MARKER));
  if (markerLines.length !== 1) fail(`expected exactly one session marker, found ${markerLines.length}`);
  const encoded = markerLines[0].slice(MARKER.length);
  if (!encoded || !/^[A-Za-z0-9+/]+={0,2}$/.test(encoded) || encoded.length % 4 !== 0) fail('session marker is not valid base64');
  let input;
  try { input = JSON.parse(Buffer.from(encoded, 'base64').toString('utf8')); } catch { fail('session marker payload is not valid JSON'); }
  const token = typeof input?.access_token === 'string' ? input.access_token.trim() : '';
  const expiresMs = Date.parse(typeof input?.expires_at === 'string' ? input.expires_at : '');
  const campuses = Array.isArray(input?.user?.campuses)
    ? input.user.campuses.map(Number).filter((id) => Number.isInteger(id) && id > 0)
    : [];
  if (!token) fail('director session access_token is missing');
  if (!Number.isFinite(expiresMs) || expiresMs <= now || expiresMs - now > MAX_TTL_MS) fail('director session expiry is outside the bounded 30 minute window');
  if (!Number.isInteger(input?.user?.id) || input.user.id <= 0) fail('director session user id is invalid');
  if (!campuses.length) fail('director session has no authorized campus');
  if (requestedBranch > 0 && !campuses.includes(requestedBranch)) fail('requested campus is not authorized');
  if (input?.user?.must_change_password !== false) fail('director session requires password change');
  if (!['director', 'super_admin'].includes(input?.user?.role)) fail('director session role is invalid');
  const normalized = {
    access_token: token,
    token_type: 'Bearer',
    expires_at: new Date(expiresMs).toISOString(),
    user: {
      id: input.user.id,
      role: input.user.role,
      campuses: [...new Set(campuses)].sort((a, b) => a - b),
      must_change_password: false,
    },
  };
  const effectiveBranch = requestedBranch || normalized.user.campuses[0];
  return { normalized, effectiveBranch };
}

function fixture(overrides = {}) {
  const input = {
    access_token: 'fixture-token',
    expires_at: new Date(Date.now() + 10 * 60 * 1000).toISOString(),
    user: { id: 7, role: 'director', campuses: [16, 9], must_change_password: false },
    ...overrides,
  };
  return `${'ssh diagnostic banner\n'}${MARKER}${Buffer.from(JSON.stringify(input)).toString('base64')}\nremote footer`;
}

function expectReject(label, callback) {
  let rejected = false;
  try { callback(); } catch { rejected = true; }
  if (!rejected) fail(`self-test accepted invalid ${label}`);
}

export function selfTest() {
  const valid = normalizeSessionOutput(fixture(), 16);
  if (valid.effectiveBranch !== 16 || valid.normalized.user.campuses[0] !== 9) fail('self-test valid marker normalization failed');
  expectReject('missing marker', () => normalizeSessionOutput('noise only', 16));
  expectReject('duplicate marker', () => normalizeSessionOutput(`${fixture()}\n${fixture()}`, 16));
  expectReject('invalid base64', () => normalizeSessionOutput(`prefix\n${MARKER}%%%\n`, 16));
  expectReject('invalid JSON', () => normalizeSessionOutput(`prefix\n${MARKER}${Buffer.from('not-json').toString('base64')}\n`, 16));
  expectReject('missing token', () => normalizeSessionOutput(fixture({ access_token: '' }), 16));
  expectReject('unauthorized branch', () => normalizeSessionOutput(fixture(), 99));
  expectReject('expired session', () => normalizeSessionOutput(fixture({ expires_at: new Date(Date.now() - 1).toISOString() }), 16));
  expectReject('ttl over 30 minutes', () => normalizeSessionOutput(fixture({ expires_at: new Date(Date.now() + 31 * 60 * 1000).toISOString() }), 16));
  expectReject('invalid role', () => normalizeSessionOutput(fixture({ user: { id: 7, role: 'teacher', campuses: [16], must_change_password: false } }), 16));
  expectReject('invalid user', () => normalizeSessionOutput(fixture({ user: { id: 0, role: 'director', campuses: [16], must_change_password: false } }), 16));
  expectReject('empty campus', () => normalizeSessionOutput(fixture({ user: { id: 7, role: 'director', campuses: [], must_change_password: false } }), 16));
  expectReject('password change', () => normalizeSessionOutput(fixture({ user: { id: 7, role: 'director', campuses: [16], must_change_password: true } }), 16));
  console.log('normalize-smoke-session self-test: ok');
}

if (process.argv.includes('--self-test')) {
  selfTest();
} else {
  const outputPath = process.env.SESSION_OUTPUT_PATH;
  const normalizedPath = process.env.SESSION_NORMALIZED_PATH;
  const requestedBranch = Number(process.env.REQUESTED_BRANCH_ID || 0);
  if (!outputPath || !normalizedPath) fail('session normalizer paths are required');
  const { normalized, effectiveBranch } = normalizeSessionOutput(fs.readFileSync(outputPath, 'utf8'), requestedBranch);
  fs.writeFileSync(normalizedPath, JSON.stringify(normalized));
  fs.appendFileSync(process.env.GITHUB_ENV, `EFFECTIVE_BRANCH_ID=${effectiveBranch}\nSMOKE_BRANCH_ID=${effectiveBranch}\n`);
}
