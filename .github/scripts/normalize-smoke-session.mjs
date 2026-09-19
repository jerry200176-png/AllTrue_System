import fs from 'node:fs';

const MARKER = 'ALLTRUE_SESSION_PAYLOAD_V1:';
const MAX_TTL_MS = 30 * 60 * 1000;
const ISO_WITH_OFFSET = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/;
const EPOCH_SECONDS = /^\d{10}(?:\.\d+)?$/;
const EPOCH_MILLIS = /^\d{13}$/;

function fail(message) {
  throw new Error(message);
}

function extractEncodedMarker(output) {
  const text = String(output);
  const occurrences = [...text.matchAll(new RegExp(MARKER, 'g'))];
  if (occurrences.length !== 1) fail(`expected exactly one session marker, found ${occurrences.length}`);
  const start = occurrences[0].index + MARKER.length;
  const remainder = text.slice(start);
  const match = remainder.match(/^[A-Za-z0-9+/]+={0,2}/);
  if (!match || !match[0]) fail('session marker is not valid base64');
  const encoded = match[0];
  const boundary = remainder[encoded.length];
  if (boundary === '=' || /[A-Za-z0-9+/]/.test(boundary || '')) fail('session marker has ambiguous trailing base64');
  if (encoded.length % 4 !== 0) fail('session marker is not valid base64');
  return encoded;
}

export function normalizeSessionOutput(output, requestedBranch = 0, now = Date.now()) {
  if (!Number.isInteger(requestedBranch) || requestedBranch < 0) fail('requested campus is not a canonical positive integer');
  const encoded = extractEncodedMarker(output);
  let input;
  try { input = JSON.parse(Buffer.from(encoded, 'base64').toString('utf8')); } catch { fail('session marker payload is not valid JSON'); }
  if (!input || typeof input !== 'object' || Array.isArray(input)) fail('session marker payload is not an object');
  const token = typeof input.access_token === 'string' ? input.access_token : '';
  const expiresValue = input.expires_at;
  let expiresMs = 0;
  if (typeof expiresValue === 'number' && Number.isFinite(expiresValue) && expiresValue > 0) {
    expiresMs = expiresValue > 1e12 ? expiresValue : expiresValue * 1000;
  } else if (typeof expiresValue === 'string' && ISO_WITH_OFFSET.test(expiresValue)) {
    expiresMs = Date.parse(expiresValue);
  } else if (typeof expiresValue === 'string' && EPOCH_SECONDS.test(expiresValue)) {
    expiresMs = Number(expiresValue) * 1000;
  } else if (typeof expiresValue === 'string' && EPOCH_MILLIS.test(expiresValue)) {
    expiresMs = Number(expiresValue);
  }
  const campuses = Array.isArray(input?.user?.campuses)
    ? input.user.campuses
    : [];
  if (!token || token.trim() !== token || /[\r\n]/.test(token)) fail('director session access_token is missing or padded');
  if (input?.token_type !== 'Bearer') fail('director session token_type is invalid');
  if (!Array.isArray(input?.user?.campuses)
    || campuses.some((id) => !Number.isInteger(id) || id <= 0)) fail('director session campuses are invalid');
  if (!Number.isFinite(expiresMs) || expiresMs <= now || expiresMs - now > MAX_TTL_MS) fail('director session expiry is outside the bounded 30 minute window');
  if (!Number.isInteger(input?.user?.id) || input.user.id <= 0) fail('director session user id is invalid');
  if (!campuses.length) fail('director session has no authorized campus');
  if (requestedBranch > 0 && !campuses.includes(requestedBranch)) fail('requested campus is not authorized');
  if (input?.user?.must_change_password !== false) fail('director session requires password change');
  if (input?.user?.role !== 'director') fail('director session role is invalid');
  const normalized = {
    access_token: token,
    token_type: input.token_type,
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
    token_type: 'Bearer',
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
  if (valid.effectiveBranch !== 16 || valid.normalized.token_type !== 'Bearer' || valid.normalized.user.campuses[0] !== 9) fail('self-test valid marker normalization failed');
  const fixtureEncoded = fixture().split(MARKER)[1].split('\n')[0];
  if (normalizeSessionOutput(`shell-before ${MARKER}${fixtureEncoded}\nshell-after`, 16).effectiveBranch !== 16) fail('self-test shell-owned marker framing failed');
  const sameLineNoise = `tinker: warning ${fixture().replace(/\nremote footer$/, ' tinker: footer')}`;
  const multilineNoise = `noise before\n${fixture()}\nnoise after`;
  if (normalizeSessionOutput(sameLineNoise, 16).effectiveBranch !== 16) fail('self-test same-line noise normalization failed');
  if (normalizeSessionOutput(multilineNoise, 16).effectiveBranch !== 16) fail('self-test multiline noise normalization failed');
  expectReject('missing marker', () => normalizeSessionOutput('noise only', 16));
  expectReject('duplicate embedded marker', () => normalizeSessionOutput(`${fixture().replace(/\nremote footer$/, '')}${MARKER}ignored`, 16));
  expectReject('invalid base64', () => normalizeSessionOutput(`prefix\n${MARKER}%%%\n`, 16));
  expectReject('ambiguous trailing base64', () => normalizeSessionOutput(`${MARKER}${fixtureEncoded}A`, 16));
  expectReject('invalid JSON', () => normalizeSessionOutput(`prefix\n${MARKER}${Buffer.from('not-json').toString('base64')}\n`, 16));
  expectReject('missing token', () => normalizeSessionOutput(fixture({ access_token: '' }), 16));
  expectReject('wrong token type', () => normalizeSessionOutput(fixture({ token_type: 'bearer' }), 16));
  expectReject('line-break token', () => normalizeSessionOutput(fixture({ access_token: 'fixture\ntoken' }), 16));
  expectReject('unauthorized branch', () => normalizeSessionOutput(fixture(), 99));
  expectReject('malformed requested branch', () => normalizeSessionOutput(fixture(), '16x'));
  expectReject('expired session', () => normalizeSessionOutput(fixture({ expires_at: new Date(Date.now() - 1).toISOString() }), 16));
  expectReject('ttl over 30 minutes', () => normalizeSessionOutput(fixture({ expires_at: new Date(Date.now() + 31 * 60 * 1000).toISOString() }), 16));
  expectReject('invalid role', () => normalizeSessionOutput(fixture({ user: { id: 7, role: 'teacher', campuses: [16], must_change_password: false } }), 16));
  expectReject('super admin role', () => normalizeSessionOutput(fixture({ user: { id: 7, role: 'super_admin', campuses: [16], must_change_password: false } }), 16));
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
  const requestedRaw = process.env.REQUESTED_BRANCH_ID || '';
  if (requestedRaw && !/^[1-9]\d*$/.test(requestedRaw)) fail('requested campus is not a canonical positive integer');
  const requestedBranch = requestedRaw ? Number(requestedRaw) : 0;
  if (!outputPath || !normalizedPath) fail('session normalizer paths are required');
  const { normalized, effectiveBranch } = normalizeSessionOutput(fs.readFileSync(outputPath, 'utf8'), requestedBranch);
  fs.writeFileSync(normalizedPath, JSON.stringify(normalized));
  fs.appendFileSync(process.env.GITHUB_ENV, `EFFECTIVE_BRANCH_ID=${effectiveBranch}\nSMOKE_BRANCH_ID=${effectiveBranch}\n`);
}
