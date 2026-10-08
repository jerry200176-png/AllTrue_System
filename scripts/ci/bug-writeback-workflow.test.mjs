import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const workflows = [
  '.github/workflows/bug-phase-a-triage.yml',
  '.github/workflows/bug-followup-comment.yml',
];

for (const workflow of workflows) {
  const source = fs.readFileSync(workflow, 'utf8');
  assert.match(source, /PUBLIC_REPLY_B64="\$\(printf '%s' "\$PUBLIC_REPLY" \| base64 \| tr -d '\\n'\)"/);
  assert.match(source, /PUBLIC_REPLY_B64='\$PUBLIC_REPLY_B64' bash -s/);
  assert.match(source, /PUBLIC_REPLY="\$\(printf '%s' "\$PUBLIC_REPLY_B64" \| base64 -d\)"/);
  assert.ok(
    source.includes('[[ "$GITHUB_ISSUE_URL" =~ ^https://github\\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[0-9]+$ ]]'),
    `${workflow} must strictly validate issue URLs`,
  );
  assert.ok(!source.includes('== https://github.com/*/issues/*'), `${workflow} must not use wildcard URL validation`);
  assert.ok(!source.includes('PUBLIC_REPLY=\\$(printf %q'), `${workflow} must not interpolate raw reply text`);
}

const phaseASource = fs.readFileSync('.github/workflows/bug-phase-a-triage.yml', 'utf8');
assert.ok(
  phaseASource.includes('DISPOSITION: ${{ inputs.disposition }}')
    && phaseASource.includes('"disposition" => $disposition')
    && phaseASource.includes('"github_issue_url" => $issueUrl'),
  'Phase-A must pass the selected disposition and GitHub issue to the existing service',
);
assert.match(phaseASource, /\[\[ "\$DISPOSITION" =~ \^\[a-z_\]\+\$ \]\]/,
  'Phase-A must constrain the disposition before passing it through SSH');
assert.ok(!phaseASource.includes('"disposition" => "bug"'), 'Phase-A must not force every report to bug');
assert.ok(!phaseASource.includes('"engineering_required" => true'),
  'Phase-A must use the service-owned engineering requirement default');
assert.ok(phaseASource.includes('$svc::normalizeDispositionOptions(['),
  'Phase-A must validate disposition against the deployed service even on an idempotent rerun');

const phaseCSource = fs.readFileSync('.github/workflows/bug-phase-c-allowlist.yml', 'utf8');
assert.match(phaseCSource, /workflow_dispatch:\s+inputs:\s+bug_id:\s+description:[^\n]+\s+required: true/,
  'Phase-C manual dispatch must require one target ID');
assert.match(phaseCSource, /re\.fullmatch\(r"bug_id:\[ \\t\]\*\(\[1-9\]\[0-9\]\*\)/,
  'Phase-C request-file push must parse an exact positive target ID line');
assert.match(phaseCSource, /if len\(target_lines\) != 1:/,
  'Phase-C request-file push must reject absent or ambiguous target IDs');
assert.match(phaseCSource, /TARGET_BUG_ID: \$\{\{ steps\.target\.outputs\.bug_id \}\}/,
  'Phase-C must pass the validated target into the write step');
assert.match(phaseCSource, /TARGET_BUG_ID='\$TARGET_BUG_ID' bash/,
  'Phase-C must pass only the numeric target to the remote write step');
assert.match(phaseCSource, /!isset\(\$items\[\$targetBugId\]\)/,
  'Phase-C must reject targets absent from the allowlist before writes');
assert.match(phaseCSource, /git merge-base --is-ancestor/,
  'Phase-C must prove the allowlisted revision is an ancestor of production');
assert.match(phaseCSource, /\$items = \[\$targetBugId => \$target\];[\s\S]*?foreach \(\$items as \$bugId => \$cfg\)/,
  'Phase-C must loop only over the selected target after preflight');
const targetStep = phaseCSource.match(/      - name: Select one target\n[\s\S]*?        run: \|\n([\s\S]*?)\n      - name: Configure pinned production SSH trust/);
assert.ok(targetStep, 'Phase-C target selection step must exist');
const targetScript = targetStep[1].split('\n').map((line) => line.replace(/^          /, '')).join('\n');
const targetFixture = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-phase-c-target-'));
try {
  const requestPath = path.join(targetFixture, 'operations/closeout/bug-phase-c-allowlist.request.md');
  const outputPath = path.join(targetFixture, 'github-output');
  fs.mkdirSync(path.dirname(requestPath), { recursive: true });
  const selectTarget = (eventName, dispatchId, requestText) => {
    fs.writeFileSync(requestPath, requestText);
    fs.writeFileSync(outputPath, '');
    execFileSync('bash', ['-c', targetScript], {
      cwd: targetFixture,
      env: { ...process.env, EVENT_NAME: eventName, DISPATCH_BUG_ID: dispatchId, GITHUB_OUTPUT: outputPath },
    });
    return fs.readFileSync(outputPath, 'utf8').trim();
  };
  assert.throws(() => selectTarget('push', '', '# old unscoped request\n'),
    'an old request without an explicit target must fail closed');
  assert.throws(() => selectTarget('push', '', 'bug_id: 329\nbug_id: 323\n'),
    'a request with two targets must fail closed');
  assert.throws(() => selectTarget('push', '', 'bug_id: 329\nbug_id: 323 # duplicate request\n'),
    'a malformed second target must not be ignored');
  assert.throws(() => selectTarget('push', '', 'bug_id:\n329\n'),
    'a target split across lines must fail closed');
  assert.throws(() => selectTarget('push', '', 'bug_id: 329 # comment\n'),
    'trailing content on the target line must fail closed');
  assert.equal(selectTarget('push', '', 'bug_id: 329\n'), 'bug_id=329');
  assert.throws(() => selectTarget('workflow_dispatch', '0', ''),
    'a nonpositive manual target must fail closed');
  assert.equal(selectTarget('workflow_dispatch', '329', ''), 'bug_id=329');
} finally {
  fs.rmSync(targetFixture, { recursive: true, force: true });
}
assert.match(
  phaseCSource,
  /280 => \[[\s\S]*?"rev" => "16e38fb969cf73a227a86c4dfe9918078b00a459",[\s\S]*?"deploy" => "35183308316",/,
  'in-app #280 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /297 => \[[\s\S]*?"rev" => "16e38fb969cf73a227a86c4dfe9918078b00a459",[\s\S]*?"deploy" => "35183308316",/,
  'in-app #297 must resolve only against its exact verified production revision and deploy run',
);
for (const bugId of [281, 283]) {
  assert.match(
    phaseCSource,
    new RegExp(`${bugId} => \\[[\\s\\S]*?"rev" => "86602e4ddaf8c03b73c78ee99d745507d884e958",[\\s\\S]*?"deploy" => "34670532157",`),
    `in-app #${bugId} must resolve only against its exact verified production revision and deploy run`,
  );
}
assert.match(
  phaseCSource,
  /284 => \[[\s\S]*?"rev" => "921fe606766172e63252f03805504ae08c8ac8d8",[\s\S]*?"deploy" => "34675463987",/,
  'in-app #284 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /287 => \[[\s\S]*?"rev" => "025f4e6ee3657c702178ca3ef8a8753b8002fe3e",[\s\S]*?"deploy" => "34705892308",/,
  'in-app #287 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /289 => \[[\s\S]*?"rev" => "09be02bf8f6ca559d8173091c7171b5a8e2b0189",[\s\S]*?"deploy" => "34927408130",/,
  'in-app #289 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /294 => \[[\s\S]*?"rev" => "09be02bf8f6ca559d8173091c7171b5a8e2b0189",[\s\S]*?"deploy" => "34927408130",/,
  'in-app #294 must resolve only against its exact verified production revision and repair run',
);
assert.match(
  phaseCSource,
  /298 => \[[\s\S]*?"rev" => "b4a5f64b1549a0ca8c154e7a7253948597f99f09",[\s\S]*?"deploy" => "34954544684",/,
  'in-app #298 must resolve only against its exact verified production revision and deploy run',
);
assert.match(
  phaseCSource,
  /291 => \[[\s\S]*?"rev" => "b4a5f64b1549a0ca8c154e7a7253948597f99f09",[\s\S]*?"deploy" => "34954544684",/,
  'in-app #291 must resolve only against its exact verified production revision and deploy run',
);
assert.ok(
  !phaseCSource.includes('repair_resolved'),
  'Phase-C allowlist entries must not replay already-resolved reports during unrelated runs',
);
assert.match(
  phaseCSource,
  /if \(in_array\(\$status, \["resolved", "closed"\], true\)\) \{\s+\\Illuminate\\Support\\Facades\\DB::rollBack\(\);\s+\$results\[\] = \["id" => \$bugId, "action" => "skip_already", "status" => \$status\];\s+continue;/,
  'Phase-C must skip every already-resolved or closed report',
);

// Execute the actual PHP predicate against fresh notice/reopen fixtures.
const guard = phaseCSource.match(/\$canReuseNotice = static function \([\s\S]*?\n          \};/)[0];
const notice = { id: 808, body: 'already public', is_internal_note: false };
const cfg = { reply: notice.body, existing_notice_id: notice.id, expected_log_ids: [1094] };
const cases = [
  ['triaged', [1094], [notice], cfg, true],
  ['in_progress', [1094], [notice], cfg, false],
  ['triaged', [1094, 1200, 1201], [notice], cfg, false],
  ['triaged', [1094], [notice, { ...notice, id: 809 }], cfg, false],
  ['triaged', [1094], [{ ...notice, body: 'different' }], cfg, false],
  ['triaged', [1094], [{ ...notice, is_internal_note: true }], cfg, false],
  ['triaged', [1094], [], cfg, false],
];
const phpCases = Buffer.from(JSON.stringify(cases)).toString('base64');
const phpGuardTest = `${guard}\n$cases = json_decode(base64_decode("${phpCases}"), true);\nforeach ($cases as $case) { $expected = array_pop($case); if ($canReuseNotice(...$case) !== $expected) { exit(1); } }`;
execFileSync('php', ['-r', phpGuardTest]);
// #326 reuses its existing public deployment notice without sending it again.
const entry326 = phaseCSource.match(/\n            326 => \[([\s\S]*?)\n            \],/);
assert.ok(entry326, 'single-target #326 closeout metadata must exist');
const cfg326 = JSON.parse(execFileSync('php', ['-r', `echo json_encode([${entry326[1]}]);`], { encoding: 'utf8' }));
assert.deepEqual(Object.keys(cfg326).sort(), ['deploy', 'existing_notice_id', 'expected_log_ids', 'reply', 'rev']);
assert.equal(cfg326.rev, '449931d6bf82d8b77f2a944a9a9fc58ed69e9e9a');
assert.equal(cfg326.deploy, '35539903948');
assert.equal(cfg326.existing_notice_id, 791);
assert.deepEqual(cfg326.expected_log_ids, [1108]);
assert.ok(cfg326.reply.includes('https://github.com/jerry200176-png/AllTrue_System/issues/3083'));
const notice326 = { id: 791, body: cfg326.reply, is_internal_note: false };
const history326 = [{ id: 790, body: 'existing intake', is_internal_note: false }, notice326];
const cases326 = [
  ['triaged', [1108], history326, cfg326, true],
  ['in_progress', [1108], history326, cfg326, false],
  ['triaged', [1108, 1109], history326, cfg326, false],
  ['triaged', [1108], [...history326, { id: 792, body: 'still broken', is_internal_note: false }], cfg326, false],
  ['triaged', [1108], [{ ...notice326, body: 'changed' }], cfg326, false],
  ['triaged', [1108], [{ ...notice326, is_internal_note: true }], cfg326, false],
  ['triaged', [1108], [], cfg326, false],
];
const encoded326 = Buffer.from(JSON.stringify(cases326)).toString('base64');
execFileSync('php', ['-r', `${guard}\n$cases = json_decode(base64_decode("${encoded326}"), true);\nforeach ($cases as $case) { $expected = array_pop($case); if ($canReuseNotice(...$case) !== $expected) { exit(1); } }`]);
assert.match(phaseCSource, /lockForUpdate\(\)->first\(\)/, 'notice reconciliation must lock the report across writes');
assert.match(phaseCSource, /if \(!\$reuseNotice && !\$alreadyPosted\) \$svc::addComment/, 'existing notice or an identical prior reply must not be duplicated');
// #3742: every closeout (not only reuse-notice ones) runs in one locked transaction, and a reply
// already on the report (e.g. from a run whose job failed after commit) is never posted twice.
{
  const loop = phaseCSource.slice(phaseCSource.indexOf('foreach ($items as $bugId => $cfg) {'), phaseCSource.indexOf('// F14: every allowlisted target carries its issue'));
  assert.ok(/\$reuseNotice = isset\(\$cfg\["existing_notice_id"\]\);[\s\S]*?\n\s+\\Illuminate\\Support\\Facades\\DB::beginTransaction\(\);/.test(loop), 'every closeout opens a transaction');
  assert.ok(!loop.includes('if ($reuseNotice) \\Illuminate\\Support\\Facades\\DB::beginTransaction()'), 'transaction must not be limited to reuse-notice entries');
  assert.ok(loop.includes('$bug = \\App\\Models\\BugReport::where("id", $bugId)->lockForUpdate()->first();'), 'every closeout locks the report');
  assert.ok(loop.includes('->where("is_internal_note", false)->where("body", $cfg["reply"])'), 'identical public reply is detected');
  assert.ok(loop.includes('->whereIn("from_status", ["resolved", "closed"])->max("created_at")'), 'a reopened report gets the reply again');
  const begins = (loop.match(/DB::beginTransaction\(\)/g) || []).length;
  const exits = (loop.match(/DB::rollBack\(\)|DB::commit\(\)/g) || []).length;
  assert.equal(begins, 1);
  assert.ok(exits >= 5, 'every early exit and the final branch close the transaction');
}
assert.match(phaseCSource, /if \(\$ok\).*DB::commit\(\);\s+else .*DB::rollBack\(\);/, 'failed reconciliation must rollback');

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-bug-reply-'));
const marker = path.join(tempDir, 'executed');
const payload = `literal $(touch ${marker}) \`echo SHOULD_NOT_RUN\` ; quoted 'reply'`;
const encoded = Buffer.from(payload, 'utf8').toString('base64');
const script = [
  'set -euo pipefail',
  `PUBLIC_REPLY_B64='${encoded}'`,
  'PUBLIC_REPLY="$(printf \'%s\' "$PUBLIC_REPLY_B64" | base64 -d)"',
  'printf \'%s\' "$PUBLIC_REPLY"',
].join('\n');

try {
  const decoded = execFileSync('bash', ['-c', script], { encoding: 'utf8' });
  assert.equal(decoded, payload, 'encoded reply must round-trip literally');
  assert.equal(fs.existsSync(marker), false, 'reply metacharacters must not execute');
} finally {
  fs.rmSync(tempDir, { recursive: true, force: true });
}

// Immutable per-report closeout metadata must retain exact evidence and bounded claims.
for (const [id, revision, issue] of [
  [339, '345b0f4cbf2cbdd23d76ecb350f96c1a7096aafc', 3268],
  [348, 'ad2f90260d4914611ce24f4778aafd8f4742b101', 3213],
  [367, '44662351fa33c2052900781f674737f74ef00402', 3267],
]) {
  const entry = phaseCSource.match(new RegExp(`\\n            ${id} => \\[([\\s\\S]*?)\\n            \\],`));
  assert.ok(entry, `scoped Phase-C entry ${id} must exist`);
  assert.ok(entry[1].includes(`"rev" => "${revision}"`), `${id} requires the exact containing product merge`);
  assert.match(entry[1], /"deploy" => "[0-9]+"/, `${id} requires a concrete successful deploy run`);
  assert.ok(entry[1].includes(`issues/${issue}`), `${id} must notify its canonical issue`);
  assert.ok(entry[1].includes('仍等待您實際確認'), `${id} must not claim reporter acceptance`);
}

// Shipped-batch closeout 2026-09-29 adds one immutable per-report metadata record.
{
  const entry = phaseCSource.match(/\n            353 => \[([\s\S]*?)\n            \],/);
  assert.ok(entry, 'scoped Phase-C entry 353 must exist');
  assert.ok(entry[1].includes('"rev" => "3056e8ccd9240b6e7086565308516f7832765f8c"'), '353 requires the exact containing product merge');
  assert.ok(entry[1].includes('"deploy" => "36527079538"'), '353 requires a concrete successful deploy run');
  assert.ok(entry[1].includes('issues/3201'), '353 must notify its canonical issue');
  assert.ok(entry[1].includes('仍等待您實際確認'), '353 must not claim reporter acceptance');
}

// Verified/decided batch closeout 2026-09-30 (production-checked or Founder-decided).
  {
    const entry = phaseCSource.match(/\n            350 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 350 must exist');
    assert.ok(entry[1].includes('"rev" => "7ae45b00d2933bc0dffd2b6be19ead180a1719ad"'), '350 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36527079538"'), '350 deploy binding');
    assert.ok(entry[1].includes('issues/3212'), '350 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '350 must not claim reporter acceptance');
  }
// Scoped Phase-C for in-app 338 (#3148), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            338 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 338 must exist');
    assert.ok(entry[1].includes('確認已修好'), '338 must tell the reporter how to confirm a successful retest');
    assert.ok(entry[1].includes('"rev" => "59daf9a39f1b66d6b6063f185f203955ec882a8f"'), '338 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37563232975"'), '338 deploy binding');
    assert.ok(entry[1].includes('issues/3148'), '338 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '338 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '338 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '338 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 363 (#3227), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            363 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 363 must exist');
    assert.ok(entry[1].includes('"rev" => "59daf9a39f1b66d6b6063f185f203955ec882a8f"'), '363 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37563232975"'), '363 deploy binding');
    assert.ok(entry[1].includes('issues/3227'), '363 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '363 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '363 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '363 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            325 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 325 must exist');
    assert.ok(entry[1].includes('"rev" => "b7250094c679baa99a10c09281b478bd4955a950"'), '325 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "35444435960"'), '325 deploy binding');
    assert.ok(entry[1].includes('issues/3075'), '325 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '325 must not claim reporter acceptance');
  }
// Scoped Phase-C for in-app 327 (#3084), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            327 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 327 must exist');
    assert.ok(entry[1].includes('"rev" => "59daf9a39f1b66d6b6063f185f203955ec882a8f"'), '327 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37563232975"'), '327 deploy binding');
    assert.ok(entry[1].includes('issues/3084'), '327 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '327 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '327 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '327 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            351 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 351 must exist');
    assert.ok(entry[1].includes('"rev" => "2b6a52f82b1ee0427e26d79db50f4b34b5cc4642"'), '351 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36527079538"'), '351 deploy binding');
    assert.ok(entry[1].includes('issues/3206'), '351 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '351 must not claim reporter acceptance');
  }
// Scoped Phase-C for in-app 347 (#3197), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            347 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 347 must exist');
    assert.ok(entry[1].includes('"rev" => "2bd3de08cef0b87cf5ae9e3ac9f3cca85f7d9bf9"'), '347 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37563232975"'), '347 deploy binding');
    assert.ok(entry[1].includes('issues/3197'), '347 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '347 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '347 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '347 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            296 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 296 must exist');
    assert.ok(entry[1].includes('"rev" => "55c2b64196712e1bb7b7aa2b4da9f5b59f652485"'), '296 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "35441352677"'), '296 deploy binding');
    assert.ok(entry[1].includes('issues/2905'), '296 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '296 must not claim reporter acceptance');
  }
// Scoped Phase-C for in-app 334 (#3139), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            334 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 334 must exist');
    assert.ok(entry[1].includes('"rev" => "f3aa9efca6b235d0ddf049a29a0fd396eea1bd97"'), '334 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37563232975"'), '334 deploy binding');
    assert.ok(entry[1].includes('issues/3139'), '334 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '334 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '334 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '334 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            318 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 318 must exist');
    assert.ok(entry[1].includes('"rev" => "08038b8acc62b35af0b9d8bdf0901ec13c597db6"'), '318 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "35443369856"'), '318 deploy binding');
    assert.ok(entry[1].includes('issues/3068'), '318 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '318 must not claim reporter acceptance');
  }
// Scoped Phase-C for in-app 319 (#3069), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            319 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 319 must exist');
    assert.ok(entry[1].includes('"rev" => "5b90abf6305aa051fd5a5fa4e0e6c04fe8e0c48b"'), '319 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37563232975"'), '319 deploy binding');
    assert.ok(entry[1].includes('issues/3069'), '319 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '319 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '319 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '319 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            328 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 328 must exist');
    assert.ok(entry[1].includes('"rev" => "99022e290ea742f85ebad3a943076fd01c3e9735"'), '328 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "35441352677"'), '328 deploy binding');
    assert.ok(entry[1].includes('issues/3102'), '328 must notify its canonical issue');
    assert.ok(!entry[1].includes('仍等待您實際確認') && entry[1].includes('若'), '328 is a decision closure, not a fix acceptance claim');
  }
  {
    const entry = phaseCSource.match(/\n            292 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 292 must exist');
    assert.ok(entry[1].includes('"rev" => "d18e26b91a1e1ab0f1c175c4b728c34ba80cc97f"'), '292 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => ""'), '292 deploy binding');
    assert.ok(entry[1].includes('issues/2808'), '292 must notify its canonical issue');
    assert.ok(!entry[1].includes('仍等待您實際確認') && entry[1].includes('若'), '292 is a decision closure, not a fix acceptance claim');
  }

// Shipped batch closeout 2026-10-02 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            316 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 316 must exist');
    assert.ok(entry[1].includes('"rev" => "c2356c3ac0b7c681e74c1f7c17d72f28985c073a"'), '316 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36862070318"'), '316 deploy binding');
    assert.ok(entry[1].includes('issues/3066'), '316 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '316 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '316 must disclose no production UI check');
  }
  {
    const entry = phaseCSource.match(/\n            331 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 331 must exist');
    assert.ok(entry[1].includes('"rev" => "6cc2213a49c48bd432b5d221aa6b74bcd4a8c2df"'), '331 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36862070318"'), '331 deploy binding');
    assert.ok(entry[1].includes('issues/3104'), '331 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '331 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '331 must disclose no production UI check');
  }
// Shipped batch 2 closeout 2026-10-02 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            370 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 370 must exist');
    assert.ok(entry[1].includes('"rev" => "2eae2c608c6f1252d03db9668bb5e469ebc48935"'), '370 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36955378940"'), '370 deploy binding');
    assert.ok(entry[1].includes('issues/3357'), '370 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '370 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '370 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '370 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 386 (#3801), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            386 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 386 must exist');
    assert.ok(entry[1].includes('"rev" => "b4fdfe2066dd28f16ff6d7ae0fa9a854ae377cdb"'), '386 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37734404763"'), '386 deploy binding');
    assert.ok(entry[1].includes('issues/3801'), '386 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '386 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '386 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '386 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 385 (#3800), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            385 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 385 must exist');
    assert.ok(entry[1].includes('"rev" => "288b574ed3ddb4546c6ced2d3f63dcad53deff90"'), '385 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37739161930"'), '385 deploy binding');
    assert.ok(entry[1].includes('issues/3800'), '385 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '385 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '385 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '385 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 340 (#3215), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            340 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 340 must exist');
    assert.ok(entry[1].includes('"rev" => "9abfd377fe4e92654f48554438dc7a6108ae5356"'), '340 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37740344626"'), '340 deploy binding');
    assert.ok(entry[1].includes('issues/3215'), '340 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '340 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '340 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '340 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 379 (#3798), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            379 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 379 must exist');
    assert.ok(entry[1].includes('"rev" => "ac49ad2b5bb32af2240dfee3220b5fb7202b2d43"'), '379 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37741223255"'), '379 deploy binding');
    assert.ok(entry[1].includes('issues/3798'), '379 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '379 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '379 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '379 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 376 (#3779), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            376 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 376 must exist');
    assert.ok(entry[1].includes('"rev" => "4826eecc63d6995d0f70e5727366720e2257a015"'), '376 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37733225699"'), '376 deploy binding');
    assert.ok(entry[1].includes('issues/3779'), '376 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '376 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '376 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '376 must give a no-names reopen path');
  }
  // Scoped Phase-C for in-app 360 (#3207), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            360 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 360 must exist');
    assert.ok(entry[1].includes('"rev" => "8812d63b18556627e40b1c57207fe3ef6b34fd2d"'), '360 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37605663527"'), '360 deploy binding');
    assert.ok(entry[1].includes('issues/3207'), '360 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '360 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '360 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '360 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 361 (#3203), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            361 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 361 must exist');
    assert.ok(entry[1].includes('"rev" => "3f21fbd597ee664d6a90485fb7f80ae8fecb08c0"'), '361 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37630503805"'), '361 deploy binding');
    assert.ok(entry[1].includes('issues/3203'), '361 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '361 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '361 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '361 must give a no-names reopen path');
  }
  // Scoped Phase-C for in-app 356 (#3208), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            356 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 356 must exist');
    assert.ok(entry[1].includes('"rev" => "f66b526fefd0ec5b4aaea17e165c6b08448214e7"'), '356 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37512531504"'), '356 deploy binding');
    assert.ok(entry[1].includes('issues/3208'), '356 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '356 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '356 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '356 must give a no-names reopen path');
  }
  // Scoped Phase-C for in-app 381 (#3799), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            381 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 381 must exist');
    assert.ok(entry[1].includes('"rev" => "f009fce12c6ff63cb1b79d0e3e9014c7d4d6efd8"'), '381 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37726665054"'), '381 deploy binding');
    assert.ok(entry[1].includes('issues/3799'), '381 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '381 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '381 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '381 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 377 (#3771), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            377 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 377 must exist');
    assert.ok(entry[1].includes('"rev" => "fa3d8eedc3c26881279add1a695a9e846bd4208d"'), '377 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37581142543"'), '377 deploy binding');
    assert.ok(entry[1].includes('issues/3771'), '377 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '377 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '377 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '377 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 378 (#3772), 2026-10-08 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            378 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 378 must exist');
    assert.ok(entry[1].includes('"rev" => "96e97505aebf9abfc85c19ad45d681b7cf56fdc7"'), '378 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37607736238"'), '378 deploy binding');
    assert.ok(entry[1].includes('issues/3772'), '378 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '378 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '378 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '378 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 380 (#3720), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            380 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 380 must exist');
    assert.ok(entry[1].includes('"rev" => "f662fb7fcc17abb71ede2ffa8d4450da6ccf1a2d"'), '380 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37581142543"'), '380 deploy binding');
    assert.ok(entry[1].includes('issues/3720'), '380 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '380 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '380 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '380 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            371 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 371 must exist');
    assert.ok(entry[1].includes('"rev" => "f369738a53f8152b1c061b13f866e82b80de025f"'), '371 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36955378940"'), '371 deploy binding');
    assert.ok(entry[1].includes('issues/3421'), '371 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '371 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '371 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '371 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 382 (#3721), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            382 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 382 must exist');
    assert.ok(entry[1].includes('"rev" => "6b0c3dd2671062859dfe397cdb261541ccf499a7"'), '382 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37581142543"'), '382 deploy binding');
    assert.ok(entry[1].includes('issues/3721'), '382 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '382 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '382 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '382 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            372 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 372 must exist');
    assert.ok(entry[1].includes('"rev" => "a49f307f3b46a5a9794d5e96feb5182f790921e6"'), '372 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36955378940"'), '372 deploy binding');
    assert.ok(entry[1].includes('issues/3423'), '372 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '372 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '372 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '372 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            373 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 373 must exist');
    assert.ok(entry[1].includes('"rev" => "a49f307f3b46a5a9794d5e96feb5182f790921e6"'), '373 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36955378940"'), '373 deploy binding');
    assert.ok(entry[1].includes('issues/3423'), '373 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '373 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '373 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '373 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 300 (#2909), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            300 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 300 must exist');
    assert.ok(entry[1].includes('"rev" => "54330988218646a4707e8af3a3f4cc53c1c1ecd4"'), '300 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37630119259"'), '300 deploy binding');
    assert.ok(entry[1].includes('issues/2909'), '300 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '300 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '300 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '300 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 345 (#3198), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            345 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 345 must exist');
    assert.ok(entry[1].includes('"rev" => "3056e8ccd9240b6e7086565308516f7832765f8c"'), '345 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37631583480"'), '345 deploy binding');
    assert.ok(entry[1].includes('issues/3198'), '345 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '345 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '345 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '345 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            368 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 368 must exist');
    assert.ok(entry[1].includes('"rev" => "868d3865fe858c3cd5820e74e34e2aafa27a2106"'), '368 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36955378940"'), '368 deploy binding');
    assert.ok(entry[1].includes('issues/3355'), '368 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '368 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '368 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '368 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            330 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 330 must exist');
    assert.ok(entry[1].includes('"rev" => "0ab77b41b6346fcb1beeacb2609b03b5a9fd4244"'), '330 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "35530419241"'), '330 deploy binding');
    assert.ok(entry[1].includes('issues/3103'), '330 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '330 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '330 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '330 must give a no-names reopen path');
  }
// Shipped batch 3 closeout 2026-10-02 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            293 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 293 must exist');
    assert.ok(entry[1].includes('"rev" => "9eb132c0e6193db31c28edc7b191553821a485ee"'), '293 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36961012794"'), '293 deploy binding');
    assert.ok(entry[1].includes('issues/2809'), '293 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '293 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '293 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '293 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 359 (#3204), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            359 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 359 must exist');
    assert.ok(entry[1].includes('"rev" => "59daf9a39f1b66d6b6063f185f203955ec882a8f"'), '359 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37465434964"'), '359 deploy binding');
    assert.ok(entry[1].includes('issues/3204'), '359 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '359 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '359 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '359 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            358 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 358 must exist');
    assert.ok(entry[1].includes('"rev" => "ace82171977af109cdecd357d3ccbf812be68396"'), '358 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36961012794"'), '358 deploy binding');
    assert.ok(entry[1].includes('issues/3216'), '358 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '358 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '358 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '358 must give a no-names reopen path');
    assert.ok(entry[1].includes('電話與備註'), '358 must state phone and notes are not added');
  }
// Scoped Phase-C for in-app 365 (#3229), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            365 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 365 must exist');
    assert.ok(entry[1].includes('"rev" => "59daf9a39f1b66d6b6063f185f203955ec882a8f"'), '365 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37465434964"'), '365 deploy binding');
    assert.ok(entry[1].includes('issues/3229'), '365 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '365 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '365 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '365 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            352 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 352 must exist');
    assert.ok(entry[1].includes('"rev" => "89a6c52051a95d3591afa30c2ee40526b3413f6b"'), '352 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36961012794"'), '352 deploy binding');
    assert.ok(entry[1].includes('issues/3234'), '352 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '352 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '352 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '352 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 333 (#3138), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            333 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 333 must exist');
    assert.ok(entry[1].includes('"rev" => "f3aa9efca6b235d0ddf049a29a0fd396eea1bd97"'), '333 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37563232975"'), '333 deploy binding');
    assert.ok(entry[1].includes('issues/3138'), '333 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '333 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '333 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '333 must give a no-names reopen path');
  }
// Shipped 2026-10-03 closeout (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            295 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 295 must exist');
    assert.ok(entry[1].includes('"rev" => "a3cd1d2a8a4292ff35b942d56b61d4be48784449"'), '295 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37098271478"'), '295 deploy binding');
    assert.ok(entry[1].includes('issues/2904'), '295 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '295 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '295 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '295 must give a no-names reopen path');
  }

// Shipped 2026-10-03 closeout 374/375 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            374 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 374 must exist');
    assert.ok(entry[1].includes('"rev" => "aab8dbcb8f966f4f72e813695c76c8bb1edec55b"'), '374 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37116877962"'), '374 deploy binding');
    assert.ok(entry[1].includes('issues/3464'), '374 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '374 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '374 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '374 must give a no-names reopen path');
  }
  {
    const entry = phaseCSource.match(/\n            375 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 375 must exist');
    assert.ok(entry[1].includes('"rev" => "2c6839e31953343ad9ed2be2b5d067ed4f69f090"'), '375 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37116877962"'), '375 deploy binding');
    assert.ok(entry[1].includes('issues/3467'), '375 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '375 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '375 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '375 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 364 (#3228), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            364 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 364 must exist');
    assert.ok(entry[1].includes('"rev" => "44ab1b3698cccfa1d12f367c65e12d3e53f1d3fe"'), '364 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "36961012794"'), '364 deploy binding');
    assert.ok(entry[1].includes('issues/3228'), '364 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '364 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境'), '364 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '364 must give a no-names reopen path');
  }
// Scoped Phase-C for in-app 343 (#3196), 2026-10-07 (engineering tests + production version check only).
  {
    const entry = phaseCSource.match(/\n            343 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 343 must exist');
    assert.ok(entry[1].includes('"rev" => "afce6ff6f6b0f38396a2e872fe855eb53610375c"'), '343 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "37505389591"'), '343 deploy binding');
    assert.ok(entry[1].includes('issues/3196'), '343 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '343 must not claim reporter acceptance');
    assert.ok(entry[1].includes('沒有在正式環境實際操作畫面'), '343 must disclose no production UI check');
    assert.ok(entry[1].includes('問題仍存在') && !/[學生]姓名[:：]/.test(entry[1]), '343 must give a no-names reopen path');
  }
// Merged != written (2026-10-07): newly allowlisted IDs are dispatched automatically, serially, verified.
{
  const src = fs.readFileSync('.github/workflows/bug-phase-c-autodispatch.yml', 'utf8');
  assert.ok(src.includes("- '.github/workflows/bug-phase-c-allowlist.yml'") && src.includes('branches: [main]'), 'fires when the allowlist lands on main');
  assert.ok(src.includes('actions: write') && /^permissions:\n  contents: read$/m.test(src), 'only the dispatch job can dispatch');
  assert.ok(src.includes('gh run watch "$run" --exit-status'), 'waits for each run before the next (concurrency group cancels rapid dispatches)');
  assert.ok(/"\\"id\\":\$\{id\},\\"action\\":\\"\(resolved\|skip_already\)\\""/.test(src), 'verifies the writer reported the report resolved');
  // Execute the real before/after diff against fixtures.
  const py = src.split("python3 - /tmp/before.yml \"$f\" <<'PY'\n")[1].split('\n          PY')[0].replace(/^ {10}/gm, '');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'phasec-diff-'));
  fs.writeFileSync(path.join(dir, 'b'), '          $items = [\n            364 => [\n            ],\n            343 => [\n            ],\n');
  fs.writeFileSync(path.join(dir, 'a'), '          $items = [\n            364 => [\n            ],\n            343 => [\n            ],\n            "380" => [\n            ],\n            333 => [\n            ],\n');
  const out = execFileSync('python3', ['-c', py, path.join(dir, 'b'), path.join(dir, 'a')], { encoding: 'utf8' }).trim();
  assert.equal(out, '333 380', 'dispatches exactly the newly added IDs, sorted, including quoted keys');
  assert.ok(src.includes('cannot read the previous allowlist') && !src.includes('|| : > /tmp/before.yml'), 'a missing base fails closed instead of dispatching everything');
  assert.ok(src.includes("git log --diff-filter=A") && src.includes('known="$(gh run list'), 'durable first-run baseline and a pre-dispatch run snapshot (#3754 review)');
  assert.ok(src.includes('{ grep -vx "$id" || true; }') && src.includes('<<<"$log"') && !/gh run view "\$run" --log[^\n]*\| grep/.test(src), 'no pipefail/grep exit traps (#3754 review)');
}
// F17 auto-intake (#Founder GO 6A, 2026-10-07): hourly, IDs-only, deduped, dry-run on manual dispatch.
{
  const src = fs.readFileSync('.github/workflows/bug-auto-intake.yml', 'utf8');
  assert.ok(src.includes("cron: '23 * * * *'"), 'auto-intake runs hourly');
  assert.match(src, /^permissions:\n  contents: read$/m, 'top-level permissions stay read-only');
  assert.equal((src.match(/issues: write/g) || []).length, 1, 'issues: write only on the issue job');
  assert.ok(src.includes('SourceRef: alltrue:bug_report:${id}') && src.includes('.login == $owner or .login == "app/github-actions"')
    && src.includes('[.comments[] | select('), 'issue dedupe by SourceRef in body or comments, trusted authors only');
  assert.equal((src.match(/if: github\.ref == 'refs\/heads\/main'/g) || []).length, 3, 'every job is main-only');
  assert.ok(!src.includes('page_key'), 'client-controlled page_key is never published');
  assert.ok(src.includes("!(github.event_name == 'workflow_dispatch' && inputs.dry_run)"), 'manual dry run never writes production');
  assert.ok(src.includes('bugs:auto-intake --candidates') && src.includes('bugs:auto-intake --ack'), 'uses the tested command');
  assert.ok(!/\.title|\.description|client_info/.test(src), 'no report free text in the workflow');
  assert.ok(fs.readFileSync('.github/pii-log-workflows.txt', 'utf8').split('\n').includes('bug-auto-intake.yml'), 'runs are purged hourly');
}

// Reply template rule (2026-10-07): every new Phase-C reply offers BOTH outcomes, so a
// successful retest is confirmed instead of waiting for the reporter timeout. IDs below were
// written before the rule (already sent; not re-sent by decision) and stay as-is.
{
  // Frozen pre-rule replies: exempt only while the reply text is byte-identical (hash-bound).
  const legacyWithoutConfirm = new Map(Object.entries({"242":"3de8fe10b10dfece","243":"c84c4cdb8d6b8b25","208":"dcaeb29a08af17ea","211":"5e27c429f1620119","210":"2c635c5995524d4b","207":"c1d6049255f23f5b","205":"f2c1b9a08b9afb39","198":"50044c13374ee796","212":"c4351113e140c08e","214":"24c201b8dc1875bd","213":"a2b191c3d3be561b","216":"6b8f4c9180d7d3e2","217":"396a3816b0042a1b","218":"b08b97188e9501c6","219":"e774741000d3cb7a","220":"45d71a40a8824ace","221":"e076e2e58013d0cb","224":"d2f357f0b9d290db","225":"472d4ff162524ff9","226":"472d4ff162524ff9","227":"472d4ff162524ff9","228":"897e6dfd0581c5fe","229":"897e6dfd0581c5fe","230":"faeebf5be96e6924","231":"6049d0b63f5e3c1e","232":"02a0ca26c5ec6c7d","233":"b7359f6eac8b868a","234":"6c3f287f5a3ebc52","236":"4a572a28b37a7078","239":"b23054172cd2bb7e","244":"8cd0ed573f35c4f6","245":"a0158aac819797d5","246":"6bd9945da0691e67","247":"ada832d541f84b57","249":"7cf1e45eb5272825","250":"042cb612b03c5e4d","252":"ece88acdaaf1c44f","253":"43ed04d204d72361","255":"c6a9e9fcdfbbf866","256":"13a5d72622af03f5","259":"48688bbfb583059f","270":"2fcadf168f7310ea","257":"da14bea52447261b","258":"97fb22934bab3b20","260":"39b2141a6f812782","261":"ab6728189913f4fe","264":"7fe19c51ba59e6da","265":"c3491f6920b42375","266":"198879c4989c9ac8","267":"6d36f32794384d74","272":"4a65e2aebd66f5fa","275":"f7cec5965fdabf17","276":"c5cca863dee4d743","273":"9aa14ad095260290","274":"98bccd2627c417de","271":"a398e11f7b0a3e62","269":"df1a0036b2b3e6a7","262":"91985b4fa46ca181","263":"91011f72fc5757e4","277":"29cbf466091db4a4","279":"f04c6e5528ed377b","280":"a901fb216b848cf3","281":"2fc667f13748d998","282":"2ad5bcb1542e9a22","283":"dabbe698d9df793e","284":"e7ab35783f0f8f3c","287":"e8b4606f9d62e103","289":"0dc8c90648827677","294":"0dc8c90648827677","298":"903954fa3fcac2eb","291":"2741f9fb4a383419","301":"8805c582ffb128ea","307":"acca79bb45d7dca6","309":"97f82848d99413f3","310":"bb7e5fbb0983e57a","312":"7bf2b8920b469310","304":"66dd70766a8b2ce5","305":"e53b398c7ab2eb57","306":"3fcb9a88c10c2f45","297":"33f456c7a2ce875d","314":"9dda72fdf91ff83c","313":"bda8b2c0dedb8da2","321":"7d850bc1b9ee57be","320":"5268af9219b08414","324":"88f4b78ba408a1dd","332":"3c380acac44b7a18","337":"0bdb9ce7e5f9c6e5","323":"ab4835b03d9412bf","317":"4f98553a6341afaf","326":"3946296a1971aebf","335":"c6983aabc15ba3ac","336":"1aaf5588567f3969","329":"04aa556b48e71a9f","339":"59a6f4c6f8a971bb","348":"7edb1dfe87a25f7d","367":"971d63fdd8bb5186","362":"9880120ffd82ea86","366":"252c62b0801d74e7","315":"657f0bbf016a0d45","353":"ecd919947d59c7c1","350":"6044f7f75d9a64cc","363":"6e57382c34329f5b","325":"4a7f64d57d9efc11","327":"e001db6e088ce8b2","351":"8d50faa91fa6be23","347":"570555aa85cb0a45","296":"8d455244c32253f4","334":"c25e50936226955e","318":"241fea1591ef8e6d","319":"a5048442095fb2b0","328":"43ef94789c7fd38f","292":"7d0443c5732d5245","316":"37a4f01f52426c99","331":"dd8df84411f62548","370":"631f156cf7799ef6","371":"07e496d1e329d664","372":"2c9915fe33b94b76","373":"ae75485dd6603186","368":"5288ab0d7c9b4839","330":"a5aceb22e979e2bd","293":"4d540a20b180522c","359":"df9843d91d38f88e","358":"7e5c787a4feb2392","365":"0225cdc087af4367","352":"4a1a662d6f8b2510","295":"ac1fbefb838b9022","374":"1bd5e5ef55e5770d","375":"f5e113860a7bbabb","364":"fb03a00605c3f4c4","343":"79db508b9876f7a6"}));
  // Parse every numeric entry block regardless of indentation, field order or wrapping, and
  // prove each discovered block has a reply before checking it.
  // PHP also accepts quoted decimal keys ("376" => [...]) and normalizes them to int.
  const keys = [...phaseCSource.matchAll(/^\s*"?(\d+)"?\s*=>\s*\[/gm)].map((m) => Number(m[1]));
  const blocks = [...phaseCSource.matchAll(/^\s*"?(\d+)"?\s*=>\s*\[([\s\S]*?)^\s*\],?\s*$/gm)];
  assert.equal(new Set(keys).size, keys.length, 'allowlist IDs must be unique (PHP keeps only the last duplicate)');
  assert.equal(blocks.length, keys.length, 'every allowlist entry must parse as one block');
  for (const [, id, body] of blocks) {
    // PHP keeps the LAST duplicate key, so exactly one reply field is allowed.
    assert.equal((body.match(/"reply"\s*=>/g) || []).length, 1, `Phase-C entry ${id} must have exactly one reply field`);
    const reply = body.match(/"reply"\s*=>\s*"([\s\S]*?)",\s*$/m);
    assert.ok(reply, `Phase-C entry ${id} must have a reply`);
    const frozenHash = legacyWithoutConfirm.get(String(id));
    if (frozenHash && createHash('sha256').update(reply[1], 'utf8').digest('hex').slice(0, 16) === frozenHash) continue;
    assert.ok(reply[1].includes('確認已修好') && reply[1].includes('問題仍存在'), `Phase-C reply ${id} must offer 「確認已修好」 and 「問題仍存在」`);
  }
}
// F14 gap (2026-10-07): close-issue and reconcile share ONE ownership rule (line-start SourceRef), so a
// cross-reference ("Related earlier SourceRef: …") never blocks auto-close.
{
  const rule = '^[ \\t>*_#.)0-9`-]*SourceRef\\b.*$';
  assert.ok(phaseCSource.includes(rule), 'close-issue uses the line-start ownership rule');
  assert.ok(fs.readFileSync('scripts/inapp-issue-reconcile.py', 'utf8').includes(rule), 'reconcile uses the same rule');
  assert.ok(!phaseCSource.includes('re.findall(r"alltrue:bug_report:(\\d+)", t)'), 'no bare any-mention ownership left');
}
console.log('bug-writeback-workflow.test.mjs: ok');

assert.match(phaseCSource,
  /329 => \[[\s\S]*?"rev" => "ad2f90260d4914611ce24f4778aafd8f4742b101",[\s\S]*?"deploy" => "36218370051",/,
  'in-app329 closeout requires its exact confirmed containing revision and successful deployment');

// F14: the linked GitHub issue closes when Phase-C ships, in a separate least-privilege job.
{
  const top = phaseCSource.split('\njobs:')[0];
  assert.match(top, /permissions:\s+contents: read/, 'Phase-C top-level token stays read-only');
  const closeJob = phaseCSource.split('\n  close-issue:')[1];
  assert.ok(closeJob, 'Phase-C must have a close-issue job');
  assert.match(closeJob, /needs: resolve/, 'close-issue runs only after resolve succeeds');
  assert.match(closeJob, /permissions:\s+issues: write/, 'close-issue has only issues: write');
  assert.match(closeJob, /type:epic/, 'epics are never auto-closed');
  assert.match(closeJob, /lifecycle:frozen/, 'reviewed frozen issues are never auto-closed');
  assert.match(phaseCSource, /issue_pending_siblings/, 'a shared issue waits for every linked report');
  assert.match(closeJob, /also maps unchecked in-app/, 'issues mapping unchecked reports are never closed');
  assert.ok(closeJob.indexOf('gh issue close') < closeJob.indexOf('gh issue comment'), 'close before comment so retries never duplicate the notice');
  const labelJob = phaseASource.split('\n  label-logged:')[1];
  assert.ok(labelJob && /needs: triage/.test(labelJob) && /issues: write/.test(labelJob) && /in-app:logged/.test(labelJob),
    'close_as_logged labels the backlog issue in-app:logged after triage succeeds');
  const followSource = fs.readFileSync('.github/workflows/bug-followup-comment.yml', 'utf8');
  const shipJob = followSource.split('\n  close-shipped-issue:')[1];
  assert.ok(shipJob && /needs: comment/.test(shipJob) && /issues: write/.test(shipJob) && /--remove-label in-app:logged/.test(shipJob),
    'a shipped logged suggestion closes its backlog issue after the notice succeeds');
  assert.ok(/type:epic/.test(shipJob) && /lifecycle:frozen/.test(shipJob) && /also maps in-app/.test(shipJob),
    'the logged ship path uses the same never-close guards as Phase-C');
  assert.match(phaseASource, /must be in this repository/, 'issue URLs from other repositories are rejected before any write');
  assert.match(phaseCSource, /"action" => \$ok \? "resolved" : "failed"/);
  assert.match(phaseCSource, /r\.get\("action"\) in \("resolved", "skip_already"\)/, 'resolved and already-resolved (retry) targets close issues; failed ones never');
}
