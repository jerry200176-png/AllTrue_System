import assert from 'node:assert/strict';
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
  /if \(in_array\(\$status, \["resolved", "closed"\], true\)\) \{\s+if \(\$reuseNotice\) \\Illuminate\\Support\\Facades\\DB::rollBack\(\);\s+\$results\[\] = \["id" => \$bugId, "action" => "skip_already", "status" => \$status\];\s+continue;/,
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
assert.match(phaseCSource, /if \(!\$reuseNotice\) \$svc::addComment/, 'existing notice must not be duplicated');
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
  {
    const entry = phaseCSource.match(/\n            296 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 296 must exist');
    assert.ok(entry[1].includes('"rev" => "55c2b64196712e1bb7b7aa2b4da9f5b59f652485"'), '296 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "35441352677"'), '296 deploy binding');
    assert.ok(entry[1].includes('issues/2905'), '296 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '296 must not claim reporter acceptance');
  }
  {
    const entry = phaseCSource.match(/\n            318 => \[([\s\S]*?)\n            \],/);
    assert.ok(entry, 'scoped Phase-C entry 318 must exist');
    assert.ok(entry[1].includes('"rev" => "08038b8acc62b35af0b9d8bdf0901ec13c597db6"'), '318 requires the exact containing merge');
    assert.ok(entry[1].includes('"deploy" => "35443369856"'), '318 deploy binding');
    assert.ok(entry[1].includes('issues/3068'), '318 must notify its canonical issue');
    assert.ok(entry[1].includes('仍等待您實際確認'), '318 must not claim reporter acceptance');
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
