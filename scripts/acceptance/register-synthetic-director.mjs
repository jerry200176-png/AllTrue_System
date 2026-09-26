#!/usr/bin/env node

/**
 * Register (once) a bounded synthetic director through the public
 * director registration route.  This helper deliberately has no administrator
 * or database access.  It leaves a pending application in place for the
 * Founder to approve in AllTrue, then can be rerun to resume from its receipt.
 *
 * Secrets are generated and kept in an owner-only file.  They are never
 * accepted as argv, printed, or written to a GitHub artifact/repository.
 */

import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const canonicalRef = process.env.ALLTRUE_SYNTHETIC_CANONICAL_REF || 'alltrue-smoke-director-campus16-v1';
const account = canonicalRef;
const campusId = Number(process.env.ALLTRUE_SYNTHETIC_CAMPUS_ID || 16);
const name = process.env.ALLTRUE_SYNTHETIC_NAME || `AllTrue Smoke Director C${campusId}`;

if (!/^alltrue-smoke-director-campus[0-9]+-v[0-9]+$/.test(canonicalRef)
  || !Number.isSafeInteger(campusId) || campusId < 1 || campusId > 1_000_000
  || typeof name !== 'string' || name.length < 1 || name.length > 32) {
  throw new Error('synthetic_identity_configuration_invalid');
}

function defaultDir() {
  return path.join(os.homedir(), '.config', 'alltrue');
}

const dir = process.env.ALLTRUE_SYNTHETIC_STATE_DIR || defaultDir();
const credentialsPath = process.env.ALLTRUE_SYNTHETIC_CREDENTIALS_PATH
  || path.join(dir, `synthetic-director-campus${campusId}.credentials.json`);
const statePath = process.env.ALLTRUE_SYNTHETIC_STATE_PATH
  || path.join(dir, `synthetic-director-campus${campusId}.state.json`);
const baseUrl = (process.env.ALLTRUE_SYNTHETIC_BASE_URL || 'https://daan.lifenet.com.tw').replace(/\/$/, '');
const registrationToken = process.env.ALLTRUE_DIRECTOR_REGISTRATION_TOKEN || '';

function ensurePrivateFile(filePath) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true, mode: 0o700 });
  try { fs.chmodSync(path.dirname(filePath), 0o700); } catch {}
  if (fs.existsSync(filePath)) fs.chmodSync(filePath, 0o600);
}

function writePrivateJson(filePath, value) {
  ensurePrivateFile(filePath);
  const temporary = `${filePath}.tmp-${process.pid}`;
  fs.writeFileSync(temporary, `${JSON.stringify(value)}\n`, { mode: 0o600, flag: 'wx' });
  fs.chmodSync(temporary, 0o600);
  fs.renameSync(temporary, filePath);
  fs.chmodSync(filePath, 0o600);
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function safeCredentials() {
  if (fs.existsSync(credentialsPath)) {
    ensurePrivateFile(credentialsPath);
    const existing = readJson(credentialsPath);
    if (existing.account !== account || Number(existing.campus_id) !== campusId || typeof existing.password !== 'string' || existing.password.length < 32) {
      throw new Error('credential_file_identity_mismatch');
    }
    return existing;
  }

  const credentials = {
    canonical_ref: canonicalRef,
    account,
    name,
    campus_id: campusId,
    password: crypto.randomBytes(32).toString('base64url'),
  };
  writePrivateJson(credentialsPath, credentials);
  return credentials;
}

function publicState(credentials, extra = {}) {
  return {
    canonical_ref: canonicalRef,
    account: credentials.account,
    name: credentials.name,
    campus_id: campusId,
    ...extra,
  };
}

function output(state) {
  // Account/campus are the non-sensitive receipt reference the operator needs;
  // never include a password, response body, token, or registration token.
  process.stdout.write(`synthetic_director=${state.canonical_ref} account=${state.account} campus_id=${state.campus_id}`);
  if (state.campus_name) process.stdout.write(` campus_name=${state.campus_name}`);
  if (Number.isInteger(state.account_id)) process.stdout.write(` account_id=${state.account_id}`);
  process.stdout.write(` status=${state.status}\n`);
}

async function campusName() {
  try {
    const response = await fetch(`${baseUrl}/api/v1/branches`, { headers: { accept: 'application/json' } });
    if (!response.ok) return null;
    const rows = await response.json();
    const campus = Array.isArray(rows) ? rows.find((row) => Number(row?.id) === campusId) : null;
    return typeof campus?.name === 'string' && campus.name.length > 0 ? campus.name : null;
  } catch {
    return null;
  }
}

async function main() {
  const existingState = fs.existsSync(statePath) ? readJson(statePath) : null;
  if (existingState && !fs.existsSync(credentialsPath)) {
    throw new Error('receipt_credentials_missing');
  }
  if (existingState) ensurePrivateFile(statePath);
  const credentials = safeCredentials();
  if (existingState) {
    if (existingState.account !== account || Number(existingState.campus_id) !== campusId) throw new Error('state_identity_mismatch');
    output(existingState);
    return;
  }

  const body = {
    name: credentials.name,
    account: credentials.account,
    password: credentials.password,
    campus_id: campusId,
  };
  if (registrationToken) body.registration_token = registrationToken;

  let response;
  try {
    response = await fetch(`${baseUrl}/api/v1/directors/register`, {
      method: 'POST',
      headers: { accept: 'application/json', 'content-type': 'application/json' },
      body: JSON.stringify(body),
    });
  } catch {
    output(publicState(credentials, { status: 'registration_transport_failed' }));
    process.exitCode = 2;
    return;
  }

  let payload = null;
  try { payload = await response.json(); } catch {}

  if (response.status === 403) {
    output(publicState(credentials, {
      status: registrationToken ? 'registration_forbidden_with_supplied_token' : 'registration_requires_invite_token',
    }));
    process.exitCode = 3;
    return;
  }
  if (response.status === 422) {
    // A same-name row is not proof of ownership.  Never take it over or reset
    // it; leave our generated credentials intact for an explicit collision
    // decision.
    output(publicState(credentials, { status: 'identity_collision_refused' }));
    process.exitCode = 4;
    return;
  }
  if (response.status !== 201) {
    output(publicState(credentials, { status: `registration_failed_http_${response.status}` }));
    process.exitCode = 5;
    return;
  }

  const id = payload?.pending_application_id;
  if (!Number.isInteger(id) || id <= 0) {
    output(publicState(credentials, { status: 'registration_receipt_missing' }));
    process.exitCode = 6;
    return;
  }

  const state = publicState(credentials, {
    account_id: id,
    campus_name: await campusName(),
    status: 'pending_approval',
  });
  writePrivateJson(statePath, state);
  output(state);
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.message : 'registration_helper_failed'}\n`);
  process.exitCode = 1;
});
