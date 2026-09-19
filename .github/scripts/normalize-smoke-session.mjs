import fs from 'node:fs';

const BEGIN = 'ALLTRUE_SESSION_PAYLOAD_BEGIN_V1';
const END = 'ALLTRUE_SESSION_PAYLOAD_END_V1';
const MAX_TTL_MS = 30 * 60 * 1000;

function fail(message) {
  throw new Error(message);
}

function extractEncodedPayload(output) {
  const text = String(output);
  const begins = [...text.matchAll(new RegExp(BEGIN, 'g'))];
  const ends = [...text.matchAll(new RegExp(END, 'g'))];
  if (begins.length !== 1) fail(`expected exactly one session begin sentinel, found ${begins.length}`);
  if (ends.length !== 1) fail(`expected exactly one session end sentinel, found ${ends.length}`);
  const start = begins[0].index + BEGIN.length;
  const end = ends[0].index;
  if (start >= end) fail('session sentinels are misordered');
  const encoded = text.slice(start, end).replace(/^[\x09-\x0D\x20]+|[\x09-\x0D\x20]+$/g, '');
  if (!encoded || !/^[A-Za-z0-9+/]+={0,2}$/.test(encoded)) fail('session payload is not valid base64');
  if (encoded.length % 4 !== 0) fail('session marker is not valid base64');
  return encoded;
}

export function normalizeSessionOutput(output, requestedBranch = 0, now = Date.now()) {
  const encoded = extractEncodedPayload(output);
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
  return `${'ssh diagnostic banner\n'}${BEGIN}\n${Buffer.from(JSON.stringify(input)).toString('base64')}\n${END}\nremote footer`;
}

function expectReject(label, callback) {
  let rejected = false;
  try { callback(); } catch { rejected = true; }
  if (!rejected) fail(`self-test accepted invalid ${label}`);
}

export function selfTest() {
  const valid = normalizeSessionOutput(fixture(), 16);
  if (valid.effectiveBranch !== 16 || valid.normalized.user.campuses[0] !== 9) fail('self-test valid marker normalization failed');
  const fixtureEncoded = fixture().split(`${BEGIN}\n`)[1].split(`\n${END}`)[0];
  if (normalizeSessionOutput(`shell-before\n${BEGIN}\r\n ${fixtureEncoded} \r\n${END}\nshell-after`, 16).effectiveBranch !== 16) fail('self-test shell-owned sentinel framing failed');
  expectReject('missing sentinels', () => normalizeSessionOutput('noise only', 16));
  expectReject('duplicate begin sentinel', () => normalizeSessionOutput(`${BEGIN}\n${BEGIN}\n${fixtureEncoded}\n${END}`, 16));
  expectReject('duplicate end sentinel', () => normalizeSessionOutput(`${BEGIN}\n${fixtureEncoded}\n${END}\n${END}`, 16));
  expectReject('misordered sentinels', () => normalizeSessionOutput(`${END}\n${fixtureEncoded}\n${BEGIN}`, 16));
  expectReject('banner between sentinel and payload', () => normalizeSessionOutput(`${BEGIN}\ntinker banner\n${fixtureEncoded}\n${END}`, 16));
  expectReject('payload with trailing junk', () => normalizeSessionOutput(`${BEGIN}\n${fixtureEncoded} junk\n${END}`, 16));
  expectReject('invalid base64', () => normalizeSessionOutput(`${BEGIN}\n%%%\n${END}`, 16));
  expectReject('invalid JSON', () => normalizeSessionOutput(`${BEGIN}\n${Buffer.from('not-json').toString('base64')}\n${END}`, 16));
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
