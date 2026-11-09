// Bounded observations for the existing handoff; no action replay or policy gate.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execute = promisify(execFile);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const repo = { owner: 'jerry200176-png', name: 'AllTrue_System' };
const sha = /^[0-9a-f]{40}$/;

export function parseArgs(args) {
  const options = { name: '', prs: [], save: false };
  while (args.length) {
    const flag = args.shift();
    if (flag === '--checkpoint') options.name = args.shift() || '';
    else if (flag === '--prs') {
      const value = args.shift() || '';
      if (!/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/.test(value)) throw new Error('Use numeric PR IDs');
      options.prs = [...new Set(value.split(',').map(Number))];
      if (options.prs.some(number => !Number.isSafeInteger(number) || number > 2147483647)) throw new Error('PR ID outside supported range');
    } else if (flag === '--save') options.save = true;
    else throw new Error('Unknown option');
  }
  if (!/^[a-z0-9][a-z0-9_-]{0,63}$/.test(options.name) || !options.prs.length || options.prs.length > 20) {
    throw new Error('Usage: --checkpoint <task-name> --prs <IDs,comma-separated> [--save]');
  }
  return options;
}

function checkState(pr) {
  return JSON.stringify({
    pending: [...new Set(pr.pending || [])].sort(),
    failed: [...new Set(pr.failed || [])].sort(),
    checks: (pr.checks || []).map(check => JSON.stringify([check.name, check.status, check.conclusion])).sort(),
  });
}

export function compare(previous, current) {
  const changes = [];
  if (!previous) return ['No saved observation; verify the task context before acting'];
  if (previous.git.head !== current.git.head) changes.push('Local HEAD changed');
  if (JSON.stringify(previous.git.changed) !== JSON.stringify(current.git.changed)) changes.push('Workspace files changed');
  if (previous.main !== current.main) changes.push('Remote main changed');
  if (previous.production.backend !== current.production.backend) changes.push('Production backend changed');
  if (previous.production.frontend !== current.production.frontend) changes.push('Production frontend changed');
  for (const pr of current.prs) {
    const old = previous.prs.find(item => item.number === pr.number);
    if (!old || old.head !== pr.head || old.state !== pr.state) changes.push(`PR #${pr.number} head/state changed`);
    if (old && checkState(old) !== checkState(pr)) changes.push(`PR #${pr.number} checks changed`);
  }
  return changes;
}

function privateFile(base, name) {
  const directory = path.join(base, '.local/agent-checkpoints');
  for (const item of [path.join(base, '.local'), directory, path.join(directory, `${name}.json`)]) {
    if (fs.existsSync(item) && fs.lstatSync(item).isSymbolicLink()) throw new Error('Checkpoint paths must not be symlinks');
  }
  fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
  // Keep Linux directory ownership across awaited network reads. Anchored I/O
  // cannot follow a replacement parent symlink to an unrelated destination.
  const descriptor = fs.openSync(directory, fs.constants.O_RDONLY | fs.constants.O_DIRECTORY | fs.constants.O_NOFOLLOW);
  try {
    const identity = fs.fstatSync(descriptor);
    const display = path.join(directory, `${name}.json`);
    const validate = () => {
      for (const item of [path.join(base, '.local'), directory, display]) {
        if (fs.existsSync(item) && fs.lstatSync(item).isSymbolicLink()) throw new Error('Checkpoint paths must not be symlinks');
      }
      const current = fs.lstatSync(directory);
      if (!current.isDirectory() || current.dev !== identity.dev || current.ino !== identity.ino) throw new Error('Checkpoint directory changed');
    };
    validate();
    fs.fchmodSync(descriptor, 0o700);
    return { file: `/proc/self/fd/${descriptor}/${name}.json`, display, validate, close: () => fs.closeSync(descriptor) };
  } catch (error) { fs.closeSync(descriptor); throw error; }
}

function readExisting(file) {
  let descriptor;
  try {
    descriptor = fs.openSync(file, fs.constants.O_RDONLY | fs.constants.O_NOFOLLOW | fs.constants.O_NONBLOCK);
    const stat = fs.fstatSync(descriptor);
    if (!stat.isFile() || (stat.mode & 0o077)) throw new Error('Checkpoint must be a private regular file (0600)');
    return fs.readFileSync(descriptor, 'utf8');
  } catch (error) { if (error.code === 'ENOENT') return null; throw error; }
  finally { if (descriptor !== undefined) fs.closeSync(descriptor); }
}

export function save(file, context, observation, expected, validate = () => {}) {
  const temporary = `${file}.${process.pid}.tmp`;
  let lock;
  let ownsTemporary = false;
  try {
    validate();
    lock = fs.openSync(`${file}.lock`, 'wx', 0o600);
    validate();
    const current = readExisting(file);
    if (expected !== undefined && current !== expected) throw new Error('Checkpoint changed during observation');
    fs.writeFileSync(temporary, JSON.stringify({ version: 1, context, observation }, null, 2) + '\n', { mode: 0o600, flag: 'wx' });
    ownsTemporary = true;
    fs.renameSync(temporary, file);
  } finally {
    if (ownsTemporary && fs.existsSync(temporary)) fs.unlinkSync(temporary);
    if (lock !== undefined) { fs.closeSync(lock); fs.unlinkSync(`${file}.lock`); }
  }
}

async function command(binary, args) {
  return (await execute(binary, args, { cwd: root, timeout: 25000, maxBuffer: 256 * 1024 })).stdout.trim();
}

export async function observe(prs) {
  const fields = prs.map(number => `p${number}:pullRequest(number:${number}){number state headRefOid mergeCommit{oid} statusCheckRollup{contexts(first:100){pageInfo{hasNextPage} nodes{__typename ... on CheckRun{name status conclusion} ... on StatusContext{context state}}}}}`).join(' ');
  const query = `query{repository(owner:"${repo.owner}",name:"${repo.name}"){defaultBranchRef{target{oid}} ${fields}}}`;
  const [head, changed, github, manifest] = await Promise.all([
    command('git', ['rev-parse', 'HEAD']),
    command('git', ['status', '--porcelain', '--untracked-files=normal']),
    command('gh', ['api', 'graphql', '-f', `query=${query}`]),
    command('curl', ['--fail', '--silent', '--show-error', '--max-time', '20', '--max-redirs', '0', 'https://daan.lifenet.com.tw/deployment.json']),
  ]);
  const payload = JSON.parse(github);
  const repository = payload.data?.repository;
  const production = JSON.parse(manifest);
  if (payload.errors || !sha.test(head) || !sha.test(repository?.defaultBranchRef?.target?.oid)
      || !sha.test(production.backend_sha) || !sha.test(production.frontend_sha)) throw new Error('Incomplete status response');
  return {
    observed_at: new Date().toISOString(),
    git: { head, changed: changed ? changed.split('\n') : [] }, main: repository.defaultBranchRef.target.oid,
    production: { backend: production.backend_sha, frontend: production.frontend_sha, deployed_at: production.deployed_at },
    prs: prs.map(number => {
      const pr = repository[`p${number}`];
      if (!pr || !sha.test(pr.headRefOid) || pr.statusCheckRollup?.contexts.pageInfo.hasNextPage) throw new Error('Missing or truncated PR status');
      const checks = pr.statusCheckRollup?.contexts.nodes || [];
      return { number, head: pr.headRefOid, state: pr.state, merge: pr.mergeCommit?.oid || null,
        pending: checks.filter(c => c.__typename === 'CheckRun' ? c.status !== 'COMPLETED' : c.state === 'PENDING').map(c => c.name || c.context),
        failed: checks.filter(c => ['FAILURE', 'ERROR', 'TIMED_OUT', 'ACTION_REQUIRED', 'CANCELLED', 'STARTUP_FAILURE', 'STALE'].includes(c.conclusion || c.state)).map(c => c.name || c.context),
        checks: checks.map(c => ({ name: c.name || c.context, status: c.status || c.state, conclusion: c.conclusion || null })) };
    }),
  };
}

export async function main(args, { base = root, observer = observe, output = value => process.stdout.write(value + '\n') } = {}) {
  const options = parseArgs([...args]);
  const location = privateFile(base, options.name);
  try {
    const { file } = location;
    const original = readExisting(file);
    const previous = original ? JSON.parse(original) : { version: 1, context: {} };
    if (previous.version !== 1 || typeof previous.context !== 'object' || previous.context === null || Array.isArray(previous.context)) throw new Error('Invalid checkpoint');
    const current = await observer(options.prs);
    location.validate();
    const changes = compare(previous.observation, current);
    if (options.save) save(file, previous.context, current, original, location.validate);
    const compact = { ...current, prs: current.prs.map(({ checks, ...pr }) => ({ ...pr, observed_check_count: checks?.length ?? 0 })) };
    output(JSON.stringify({ checkpoint: location.display, saved: options.save, changes, observation: compact,
      note: 'Observations only; dirty file contents are not fingerprinted. Verify actual diff, current reviews, required checks and execution receipts before any action' }));
  } finally { location.close(); }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main(process.argv.slice(2)).catch(() => { console.error('task-reconcile: observation failed; saved checkpoint was not advanced'); process.exitCode = 2; });
}
