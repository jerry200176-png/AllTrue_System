import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

// Founder 2026-10-21: Phase-A triage and follow-up replies run as validated batches (<=20 rows)
// without a per-run Founder approval. Still: dispatch-only, main only, secrets only on the
// main-only production-ssh environment, one queued write lock, an ID/hash-only preview first.
const validator = '.github/scripts/inapp-writeback-rows.py';
const workflows = [
  ['.github/workflows/bug-phase-a-triage.yml', 'phase-a', 'triage', 'label-logged'],
  ['.github/workflows/bug-followup-comment.yml', 'followup', 'comment', 'close-shipped-issue'],
];
const jobBlock = (source, job) => {
  const m = source.match(new RegExp(`\\n  ${job}:\\n([\\s\\S]*?)(?=\\n  (?:# [^\\n]*\\n  )?[a-z-]+:\\n|$)`));
  assert.ok(m, `job ${job} must exist`);
  return m[1];
};
for (const [workflow, kind, writeJob, nextJob] of workflows) {
  const source = fs.readFileSync(workflow, 'utf8');
  assert.match(source, /\non:\n  workflow_dispatch:\n    inputs:\n      rows_json:\n[\s\S]*?\n\npermissions:/,
    `${workflow} must be workflow_dispatch-only with a rows_json input`);
  assert.ok(!/\n  (push|pull_request|schedule|repository_dispatch|workflow_run):/.test(source.split('\njobs:')[0]),
    `${workflow} must have no other trigger`);
  assert.ok(!source.includes('production-activation') && !/\n  approve:\n/.test(source),
    `${workflow} must not wait for a per-run Founder approval (Founder 2026-10-21)`);
  assert.ok(source.includes('\nconcurrency:\n  group: inapp-production-writeback\n  cancel-in-progress: false\n  queue: max\n'),
    `${workflow} must queue every in-app write on the shared lock`);
  const preview = jobBlock(source, 'preview');
  assert.ok(!/secrets\./.test(preview) && !/\n    environment:/.test(preview),
    `${workflow} preview job must hold no secrets or environment`);
  assert.ok(preview.includes(`run: python3 ${validator} ${kind} preview`), `${workflow} preview must validate every row`);
  const write = jobBlock(source, writeJob);
  assert.ok(write.startsWith('    needs: preview\n    runs-on: ubuntu-latest\n    environment: production-ssh\n'),
    `${workflow} write job must run after the preview on the main-only production-ssh environment`);
  assert.ok(write.includes(`ROWS="$(python3 ${validator} ${kind} write)"`), `${workflow} writer must re-validate rows`);
  assert.ok(write.includes("ROWS_B64='$ROWS_B64' bash -s"), `${workflow} must pass rows only base64-encoded`);
  assert.ok(write.includes(`PUBLIC_REPLY="$(printf '%s' "$PUBLIC_REPLY_B64" | base64 -d)"`), `${workflow} must decode each reply remotely`);
  assert.ok(write.includes('[[ "$GITHUB_ISSUE_URL" =~ ^https://github\\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[0-9]+$ ]]'),
    `${workflow} must strictly validate issue URLs on the remote too`);
  assert.ok(write.includes("          ' </dev/null 2>&1)\"\n"), `${workflow} tinker must never read the script's stdin`);
  assert.ok(write.includes('grep -qx "WRITEBACK_OK $BUG_ID" <<<"$OUT" || {'), `${workflow} counts a row only on its success marker (tinker exits 0 on a throw)`);
  assert.ok(write.includes('[[ "$applied" == "$ROW_COUNT" ]]'), `${workflow} must prove every row ran`);
  assert.ok(jobBlock(source, nextJob).includes(`needs: [preview, ${writeJob}]`), `${nextJob} runs only after every write succeeded`);
  assert.ok(!source.includes('inputs.public_reply') && !source.includes('inputs.rows_json') && !/^\s+(PUBLIC_REPLY|ROWS_JSON):/m.test(source),
    `${workflow} must never expose reply text through env or an expression (step env is printed in public logs)`);
  assert.ok(!source.includes('== https://github.com/*/issues/*') && !source.includes('PUBLIC_REPLY=\\$(printf %q'),
    `${workflow} must not use wildcard URL validation or interpolate raw reply text`);
  assert.ok(!/`\$[A-Z_]+`/.test(source), `${workflow} must not use backtick command substitution on inputs`);
}

// Behaviour: the validator is all-or-nothing, prints only IDs/hashes, and the remote loop applies
// every row with the exact reply bytes, never executing them.
{
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-rows-'));
  try {
    const eventPath = path.join(dir, 'event.json');
    const summary = path.join(dir, 'summary.md');
    const output = path.join(dir, 'output');
    const run = (kind, mode, rows, ref = 'refs/heads/main', repo = 'o/r') => {
      fs.writeFileSync(eventPath, JSON.stringify({ inputs: { rows_json: typeof rows === 'string' ? rows : JSON.stringify(rows) } }));
      fs.writeFileSync(summary, '');
      fs.writeFileSync(output, '');
      try {
        const out = execFileSync('python3', [validator, kind, mode], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
          env: { ...process.env, GITHUB_EVENT_PATH: eventPath, GITHUB_STEP_SUMMARY: summary, GITHUB_OUTPUT: output,
            GITHUB_REPOSITORY: repo, GITHUB_REF: ref } });
        return { ok: true, out, summary: fs.readFileSync(summary, 'utf8'), output: fs.readFileSync(output, 'utf8') };
      } catch (e) {
        return { ok: false, out: `${e.stdout}${e.stderr}`, summary: fs.readFileSync(summary, 'utf8'), output: fs.readFileSync(output, 'utf8') };
      }
    };
    const url = (n) => `https://github.com/o/r/issues/${n}`;
    const marker = path.join(dir, 'executed');  // the remote step runs with cwd = dir
    const tricky = `第一行 $(touch executed) \`id\` 'q' ; ${url(7)}\n second line\n`;
    const a = [
      { bug_id: '41', github_issue_url: url(7), public_reply: tricky },
      { bug_id: 42, github_issue_url: url(8), public_reply: `已記錄 ${url(8)}`, disposition: 'suggestion', close_as_logged: true },
    ];
    const good = run('phase-a', 'preview', a);
    assert.ok(good.ok, `valid batch must pass: ${good.out}`);
    assert.ok(good.summary.includes(`| 1 | 41 | ${url(7)} | bug | false | ${[...tricky].length} | ${createHash('sha256').update(tricky, 'utf8').digest('hex')} |`)
      && good.summary.includes(`| 2 | 42 | ${url(8)} | suggestion | true |`), 'preview table must list every row id, issue, disposition, close, len and sha256');
    assert.ok(!good.summary.includes('第一行') && !good.summary.includes('已記錄') && !good.out.includes('第一行'), 'reply text never reaches the summary or log');
    assert.equal(good.output, `logged_issues=${JSON.stringify([['42', url(8)]])}\n`);
    const f = [
      { bug_id: '50', expected_status: 'resolved', github_issue_url: url(9), public_reply: `更新 ${url(9)}` },
      { bug_id: '51', expected_status: 'closed', github_issue_url: url(10), public_reply: `上線 ${url(10)}`, production_revision: 'a'.repeat(40), deploy_run_id: '123' },
    ];
    const fgood = run('followup', 'preview', f);
    assert.ok(fgood.ok, `valid follow-up batch must pass: ${fgood.out}`);
    assert.equal(fgood.output, `shipped=${JSON.stringify([['51', url(10), 'a'.repeat(40)]])}\n`);
    const secret = '王小明 0912345678';
    const max = `${url(7)}${'字'.repeat(600 - url(7).length)}`;
    assert.ok(run('phase-a', 'preview', [{ ...a[0], public_reply: max }]).ok, 'a 600-character reply is allowed');

    // Founder 2026-10-21 reply content guard (OWASP LLM02 / LLM01, "lethal trifecta"): a reply that would
    // leak personal data or credentials, or link anywhere but this repo's issues / the app, fails the batch.
    const clean = `您好，已收到並開立追蹤：${url(7)}。上線後可到 https://daan.lifenet.com.tw/admin/bugs 查看，版本 abc1234（2026-10-21）。`;
    assert.ok(run('phase-a', 'preview', [{ ...a[0], public_reply: clean }]).ok, 'a clean reply with the issue and app links passes');
    assert.ok(run('phase-a', 'preview', [{ ...a[0], public_reply: `${url(7)} https://daan.lifenet.com.tw/learning-records/student-12345/session-67890?tab=open` }]).ok,
      'an ordinary app deep link with ids and a query passes');
    assert.ok(run('phase-a', 'preview', [{ ...a[0], public_reply: `${url(7)} 已在 daan.lifenet.com.tw 修正，版本 2026.10.21，下午 3:30，金額 1,200 元，共 8 堂、v1.2.3` }]).ok,
      'bare allowed hosts, dates, times, amounts and versions pass');
    // #4351 r4338359749: punctuation must not truncate an authority before @.
    // Both workflows and both modes use the same guard, with no output for a rejected batch.
    const authorityAttacks = [
      "https://daan.lifenet.com.tw'@evil.com/private-name",
      'https://daan.lifenet.com.tw"@evil.com/x',
      'https://daan.lifenet.com.tw`@evil.com/x',
      'https://daan.lifenet.com.tw)@evil.com/x',
      'https://daan.lifenet.com.tw]@evil.com/x',
      'https://daan.lifenet.com.tw\\@evil.com/x',
      'https://daan.lifenet.com.tw:443@evil.com/x',
      'https://daan.lifenet.com.tw%2F@evil.com/x',
      'https://daan.lifenet.com.tw。evil.com/x',
      'https://daan.lifenet.com.tw:/x',
      'https://daan.lifenet.com.tw:bad/x',
      'https://daan.lifenet.com.tw:443/x',
      'https://daan.lifenet.com.tw./x',
      "https://github.com'@evil.com/o/r/issues/7",
      "https://daan.lifenet.com.tw/redirect?to=https://daan.lifenet.com.tw'@evil.com/x",
      `https://daan.lifenet.com.tw/redirect?to=${encodeURIComponent("https://daan.lifenet.com.tw'@evil.com/x")}`,
      `https://daan.lifenet.com.tw/redirect?to=${encodeURIComponent(`https://daan.lifenet.com.tw/redirect?to=${encodeURIComponent("https://daan.lifenet.com.tw'@evil.com/x")}`)}`,
      "123https://daan.lifenet.com.tw'@evil.com/x",
      "aaaaaaaaaaaaaaaaaaaaahttps://daan.lifenet.com.tw'@evil.com/x",
      ...['\t', '\n', '\r'].flatMap((control) => [
        `https://daan.lifenet.com.tw${control}@evil.com/synthetic`,
        `https://daan.lifenet.com.tw${control}@[2001:db8::1]/synthetic`,
        `https://daan.lifenet.com.tw/help?to=${encodeURIComponent(`https://daan.lifenet.com.tw${control}@evil.com/synthetic`)}`,
        `https://daan.lifenet.com.tw/help?to=${encodeURIComponent(`https://daan.lifenet.com.tw${control}@[2001:db8::1]/synthetic`)}`,
        `https${control}://[2001:db8::1]/synthetic`,
        `https://daan.lifenet.com.tw/help?to=${encodeURIComponent(`https${control}://[2001:db8::1]/synthetic`)}`,
        `https:/${control}/2130706433/synthetic`,
        `https://daan.lifenet.com.tw/help?to=${encodeURIComponent(`/${control}/2130706433/synthetic`)}`,
      ]),
      ...['//[2001:db8::1]/synthetic', '//127.1/synthetic', '//2130706433/synthetic',
        '///2130706433/synthetic', 'https:////2130706433/synthetic'].map((target) =>
        `https://daan.lifenet.com.tw/help?to=${encodeURIComponent(target)}`),
      '\\\\[2001:db8::1]/synthetic', '\\/[2001:db8::1]/synthetic', '/\\[2001:db8::1]/synthetic',
      '/\t\\[2001:db8::1]/synthetic',
      'https://daan.lifenet.com.tw/help?to=https%253A%252F%252Fevil%252Ecom%252Fsynthetic',
      'https://daan.lifenet.com.tw/help?value=%ff',
      'https://daan.lifenet.com.tw\n詳情稍後更新',  // ambiguous bare authority; use an explicit path or delimiter
      `https${'\t\r\n'.repeat(8)}://[2001:db8::1]/synthetic`,
      ...['https:/\n/[2001:db8::1]/synthetic', 'https:\t/\n/[2001:db8::1]/synthetic',
        '/\n/[2001:db8::1]/synthetic', 'h\tttps://[2001:db8::1]/synthetic',
        'https:\\[2001:db8::1]/synthetic', 'https:\\\\[2001:db8::1]/synthetic',
        'https:[2001:db8::1]/synthetic', 'https:/[2001:db8::1]/synthetic'].flatMap((target) =>
        [target, `https://daan.lifenet.com.tw/help?to=${encodeURIComponent(target)}`]),
    ];
    const encodeEveryByte = (value) => [...Buffer.from(value)].map((byte) => `%${byte.toString(16).padStart(2, '0')}`).join('');
    const encodeLayers = (value, depth) => {
      let encoded = encodeEveryByte(value);
      for (let layer = 1; layer < depth; layer += 1) encoded = encoded.replaceAll('%', '%25');
      return encoded;
    };
    // Encoding depth is limited by the 600-character reply, never an arbitrary two-layer decoder.
    for (const depth of [2, 3, 5]) {
      authorityAttacks.push(`https://daan.lifenet.com.tw/help?to=${encodeLayers('https://evil.com/x', depth)}`);
    }
    const encodedPhones = [1, 2, 3, 7, 15].map((depth) =>
      `https://daan.lifenet.com.tw/help?value=${encodeLayers('0912345678', depth)}`);
    let deepestPhone = encodeEveryByte('0912345678');
    while (`${url(7)} https://daan.lifenet.com.tw/help?value=${deepestPhone.replaceAll('%', '%25')}`.length <= 600) {
      deepestPhone = deepestPhone.replaceAll('%', '%25');
    }
    encodedPhones.push(`https://daan.lifenet.com.tw/help?value=${deepestPhone}`);
    // #4386 r4344834157: URI data must keep literal +, / and padding before decoding/delimiting.
    const credential = 'QWxhZGRpbjpvcGVu+IHNlc2FtZQ1234567890abcd==';
    const mixedCredential = credential.replace('+', '+/');
    const paddedCredential = `${'A'.repeat(29)}1==`;  // padding is needed to reach the existing 32-character rule
    const credentialUrls = (value) => [
      `https://daan.lifenet.com.tw/help?value=${value}`,
      `https://daan.lifenet.com.tw/help?${value}=open`,
      `https://daan.lifenet.com.tw/#${value}`,
    ];
    const credentialAttacks = [
      ...credentialUrls(credential), ...credentialUrls(credential.replace('+', '/')),
      ...credentialUrls(mixedCredential), `https://daan.lifenet.com.tw/p/${credential}/Z==`,
      `https://daan.lifenet.com.tw/help?value=${paddedCredential}`,
      `https://daan.lifenet.com.tw/#${paddedCredential}`, `https://daan.lifenet.com.tw/p/${paddedCredential}`,
      `https://daan.lifenet.com.tw/help?${encodeURIComponent(paddedCredential)}=open`,
    ];
    for (const depth of [1, 2, 3]) {
      const encoded = encodeLayers(mixedCredential, depth);
      credentialAttacks.push(...credentialUrls(encoded), `https://daan.lifenet.com.tw/p/${encoded}`);
    }
    for (const depth of [2, 3, 5]) {
      const slashEncoded = mixedCredential.replace('/', `%${'25'.repeat(depth - 1)}2F`);
      credentialAttacks.push(...credentialUrls(slashEncoded), `https://daan.lifenet.com.tw/p/${slashEncoded}`);
      const nestedApp = encodeLayers('https://daan.lifenet.com.tw/help?token=', depth) + slashEncoded;
      credentialAttacks.push(`https://daan.lifenet.com.tw/help?to=${nestedApp}`);
    }
    const harmlessLinks = [
      'https://daan.lifenet.com.tw',
      'https://daan.lifenet.com.tw/courses?tab=open#help',
      "'https://daan.lifenet.com.tw/help'",
      '"https://daan.lifenet.com.tw"',
      '`https://daan.lifenet.com.tw`',
      '「https://daan.lifenet.com.tw」',
      'https://daan.lifenet.com.tw。',
      '[課程](https://daan.lifenet.com.tw/courses)',
      `(https://daan.lifenet.com.tw/help)`,
      `'${url(7)}'`,
      `[追蹤](${url(7)})`,
      `https://daan.lifenet.com.tw/help?to=${encodeURIComponent(url(7))}`,
      `https://daan.lifenet.com.tw/help?to=${encodeLayers(url(7), 3)}`,
      'https://daan.lifenet.com.tw/help?progress=50%25&label=%E8%AA%B2%E7%A8%8B',
      `https://daan.lifenet.com.tw/help\n詳情 ${url(7)}`,
      `${url(7)}\r\nhttps://daan.lifenet.com.tw/help`,
      `https://daan.lifenet.com.tw\n${url(7)}`,
      'https://daan.lifenet.com.tw/help//12345/session/67890',
      'https://daan.lifenet.com.tw/learning-records/student-12345/session-67890?tab=open',
      'https://daan.lifenet.com.tw/learning-records/student-12345/session-67890?tab=open#details',
      `https://daan.lifenet.com.tw/help?${paddedCredential}=open`,  // literal first '=' is structure, not key padding
    ];
    for (const kind of ['phase-a', 'followup']) {
      const [candidate, first] = kind === 'phase-a' ? [a[0], a[1]] : [f[0], f[1]];
      for (const mode of ['preview', 'write']) {
        authorityAttacks.forEach((link, index) => {
          const result = run(kind, mode, [first, { ...candidate, public_reply: `${candidate.github_issue_url} ${link}` }]);
          assert.ok(!result.ok, `${kind}/${mode} rejects complete malicious authority case ${index}`);
          assert.ok(result.out.includes('row 2: public_reply rejected by content rule url_not_allowed'),
            `${kind}/${mode} rejects authority case ${index} with a rule-only error`);
          assert.ok(!result.out.includes(link) && !result.out.includes('/issues/') && result.summary === '' && result.output === '',
            `${kind}/${mode} authority case ${index} emits no reply or partial summary`);
        });
        harmlessLinks.forEach((link, index) => {
          assert.ok(run(kind, mode, [{ ...candidate, public_reply: `${candidate.github_issue_url} ${link}` }]).ok,
            `${kind}/${mode} preserves harmless allowed link case ${index}`);
        });
        const actualRepo = 'jerry200176-png/AllTrue_System';
        const actualIssue = `https://github.com/${actualRepo}/issues/7`;
        for (const nested of [actualIssue, 'https://daan.lifenet.com.tw/learning-records/student-12345/session-67890?tab=open']) {
          const result = run(kind, mode, [{ ...candidate, github_issue_url: actualIssue,
            public_reply: `${actualIssue} https://daan.lifenet.com.tw/help?to=${encodeURIComponent(nested)}` }], 'refs/heads/main', actualRepo);
          assert.ok(result.ok, `${kind}/${mode} preserves an allowed nested URL with the actual repository and deep-link path`);
        }
        encodedPhones.forEach((link, index) => {
          const result = run(kind, mode, [first, { ...candidate, public_reply: `${candidate.github_issue_url} ${link}` }]);
          assert.ok(!result.ok, `${kind}/${mode} rejects multiply encoded synthetic phone case ${index}`);
          assert.ok(result.out.includes('row 2: public_reply rejected by content rule tw_mobile'),
            `${kind}/${mode} identifies encoded synthetic phone case ${index} with the original data rule`);
          assert.ok(!result.out.includes(link) && !result.out.includes('/issues/') && result.summary === '' && result.output === '',
            `${kind}/${mode} encoded phone case ${index} emits no reply or partial summary`);
        });
        credentialAttacks.forEach((link, index) => {
          const result = run(kind, mode, [first, { ...candidate, public_reply: `${candidate.github_issue_url} ${link}` }]);
          assert.ok(!result.ok && result.out.includes('row 2: public_reply rejected by content rule long_base64'),
            `${kind}/${mode} rejects credential component case ${index} with the original data rule`);
          assert.ok(!result.out.includes(link) && !result.out.includes('/issues/') && result.summary === '' && result.output === '',
            `${kind}/${mode} credential component case ${index} emits no reply or partial output`);
        });
      }
    }
    for (const [rule, leak] of [
      ['tw_mobile', '0912345678'], ['tw_mobile', '0912-345-678'], ['tw_mobile', '+886 912 345 678'],
      ['tw_landline', '02-2345-6789'], ['tw_landline', '(02)23456789'], ['tw_landline', '+886-2-2345-6789'],
      ['tw_national_id', 'A123456789'], ['email', 'parent.name@example.com'],
      ['secret_token', 'ghp_abcdefghijklmnop'], ['secret_token', 'github_pat_11AAAAAAAAAA'], ['secret_token', 'sk-abcdefghijkl'],
      ['secret_token', 'xoxb-123456789012'], ['secret_token', 'AKIA' + 'ABCDEFGHIJKLMNOP'], ['private_key', '-----BEGIN' + ' RSA PRIVATE KEY-----'],
      ['long_hex', 'a'.repeat(40)], ['long_base64', 'QWxhZGRpbjpvcGVuIHNlc2FtZQ1234567890abcd=='],
      ['url_not_allowed', 'https://evil.example/x'], ['url_not_allowed', 'https://github.com/o/r/pull/3'],
      ['url_not_allowed', 'https://github.com/o/other/issues/1'], ['url_not_allowed', 'https://daan.lifenet.com.tw.evil.io/'],
      ['url_not_allowed', 'www.evil.com'], ['url_not_allowed', 'evil.com/x'],
      // An allowed link must not smuggle data in its path, query or fragment (#4351 review, P1).
      ['email', 'https://daan.lifenet.com.tw/?email=alice@example.com'], ['email', 'https://daan.lifenet.com.tw/?e=alice%40example.com'],
      ['tw_mobile', 'https://daan.lifenet.com.tw/p/0912345678'], ['secret_token', 'https://daan.lifenet.com.tw/?t=ghp_abcdefghijklmnop'],
      ['tw_national_id', 'https://daan.lifenet.com.tw/?id=A123456789'], ['long_hex', `https://daan.lifenet.com.tw/x#${'a'.repeat(40)}`],
      // Independent review bypasses (5598196260): NFKC + format-character stripping, loose separators,
      // obfuscated emails, any foreign/defanged/IP host, and redirect targets on the allowed app host.
      ['tw_mobile', '0912.345.678'], ['tw_mobile', '09-12345678'], ['tw_mobile', '+886 (0)912 345 678'],
      ['tw_mobile', '0912\u200b345\u200b678'], ['tw_mobile', '０９１２３４５６７８'], ['tw_landline', '02.2345.6789'],
      ['tw_national_id', 'Ａ123456789'], ['tw_national_id', 'A 123456789'], ['tw_national_id', 'A123-456-789'],
      ['email', 'user＠gmail．com'], ['email', 'user@gmail'], ['email', 'user at gmail dot com'],
      ['email', 'user(at)gmail(dot)com'], ['url_not_allowed', 'evil.ai'], ['url_not_allowed', 'x.tk'],
      ['url_not_allowed', 'y.site'], ['url_not_allowed', 'xn--80ak6aa92e.xn--p1ai'], ['url_not_allowed', 'ftp://x.ai'],
      ['url_not_allowed', '1.2.3.4/x'], ['url_not_allowed', 'evil[.]com'], ['url_not_allowed', 'evil(.)com'],
      ['url_not_allowed', 'https://daan.lifenet.com.tw/redirect?to=evil.ai'], ['url_not_allowed', 'https://daan.lifenet.com.tw/r?to=evil%2Eai'],
    ]) {
      const r = run('phase-a', 'preview', [a[1], { ...a[0], public_reply: `${url(7)} ${leak}` }]);
      assert.ok(!r.ok && r.out.includes(`row 2: public_reply rejected by content rule ${rule}`), `${rule} must reject the batch: ${r.out}`);
      assert.ok(!r.out.includes(leak) && r.summary === '', `${rule}: the rejected value must not be echoed or summarised`);
    }
    for (const [label, kind, rows, ref] of [
      ['non-main ref', 'phase-a', a, 'refs/heads/chore/x'],
      ['invalid JSON', 'phase-a', `[{"bug_id": "${secret}"`],
      ['empty batch', 'phase-a', []],
      ['21 rows', 'phase-a', Array.from({ length: 21 }, (_, i) => ({ bug_id: String(i + 1), github_issue_url: url(7), public_reply: url(7) }))],
      ['duplicate bug_id', 'phase-a', [a[0], { ...a[1], bug_id: '41' }]],
      ['bad bug_id', 'phase-a', [a[1], { ...a[0], bug_id: secret }]],
      ['other repository', 'phase-a', [a[1], { ...a[0], github_issue_url: 'https://github.com/other/repo/issues/7', public_reply: `${secret} https://github.com/other/repo/issues/7` }]],
      ['reply without the URL', 'phase-a', [a[1], { ...a[0], public_reply: secret }]],
      ['601-character reply', 'phase-a', [a[1], { ...a[0], public_reply: `${max}字` }]],
      ['bad disposition', 'phase-a', [a[1], { ...a[0], disposition: secret }]],
      ['string close_as_logged', 'phase-a', [a[1], { ...a[0], close_as_logged: 'true' }]],
      ['unknown field', 'phase-a', [a[1], { ...a[0], expected_status: 'resolved' }]],
      ['bad expected_status', 'followup', [f[0], { ...f[0], bug_id: '52', expected_status: secret }]],
      ['closed without evidence', 'followup', [f[0], { ...f[1], deploy_run_id: '' }]],
      ['bad revision', 'followup', [f[0], { ...f[1], production_revision: secret }]],
    ]) {
      for (const mode of ['preview', 'write']) {
        const r = run(kind, mode, rows, ref);
        assert.ok(!r.ok, `${label} must fail the whole ${mode}`);
        assert.equal(r.summary, '', `${label}: a rejected batch writes nothing to the summary`);
        assert.ok(!r.out.includes('王小明') && !r.out.includes('第一行') && !r.out.includes('/issues/'), `${label}: errors must not echo values`);
      }
    }

    // Run each writer step end to end: a stub ssh runs the remote command locally, stub php/git stand in
    // for tinker. Replies must round-trip exactly, and a mid-batch failure publishes only written rows.
    const stubs = path.join(dir, 'bin');
    fs.mkdirSync(stubs);
    fs.writeFileSync(path.join(stubs, 'php'), '#!/usr/bin/env bash\n[[ "$BUG_ID" == "$FAIL_BUG" ]] && exit 1\n# tinker prints an uncaught exception and still exits 0\n[[ "$BUG_ID" == "$SWALLOW_BUG" ]] && { echo "RuntimeException  status changed: in_progress."; exit 0; }\n{ printf "%s %s %s " "$BUG_ID" "$GITHUB_ISSUE_URL" "${DISPOSITION:-$EXPECTED_STATUS}"; printf "%s" "$PUBLIC_REPLY" | base64 -w0; printf " %s\\n" "$(cat | wc -c)"; } >> "$CALLS"\necho "WRITEBACK_OK $BUG_ID"\n');
    fs.writeFileSync(path.join(stubs, 'git'), '#!/usr/bin/env bash\n[[ "$1" == rev-parse ]] && echo deadbeef; exit 0\n');
    fs.writeFileSync(path.join(stubs, 'ssh'), '#!/usr/bin/env bash\nexec bash -c "${@: -1}"\n');
    for (const name of ['php', 'git', 'ssh']) fs.chmodSync(path.join(stubs, name), 0o755);
    for (const [workflow, kind, rows] of [[workflows[0][0], 'phase-a', a], [workflows[1][0], 'followup', f]]) {
      const source = fs.readFileSync(workflow, 'utf8');
      const runBlock = source.match(/\n        run: \|\n          set -euo pipefail\n          ROWS="\$\(python3[\s\S]*?\n          exit "\$rc"\n/)[0];
      const script = runBlock.split('\n').slice(2).map((l) => l.replace(/^ {10}/, '')).join('\n')
        .replace('cd /home/admin/backend', 'cd "$BACKEND"');
      const writeStep = (failBug, swallowBug = 'none') => {
        const calls = path.join(dir, `${kind}-${failBug}.calls`);
        fs.writeFileSync(calls, '');
        fs.writeFileSync(output, '');
        fs.writeFileSync(summary, '');
        fs.writeFileSync(eventPath, JSON.stringify({ inputs: { rows_json: JSON.stringify(rows) } }));
        let ok = true;
        try {
          execFileSync('bash', ['-c', script], { stdio: 'ignore', env: { ...process.env, PATH: `${stubs}:${process.env.PATH}`,
            CALLS: calls, BACKEND: dir, RUNNER_TEMP: dir, FAIL_BUG: failBug, SWALLOW_BUG: swallowBug, GITHUB_OUTPUT: output, GITHUB_STEP_SUMMARY: summary,
            GITHUB_EVENT_PATH: eventPath, GITHUB_REPOSITORY: 'o/r', GITHUB_REF: 'refs/heads/main', PI_SSH_USER: 'u', PI_SSH_HOST: 'h' } });
        } catch { ok = false; }
        const got = fs.readFileSync(calls, 'utf8').trim().split('\n').filter(Boolean).map((l) => l.split(' '));
        return { ok, got, output: fs.readFileSync(output, 'utf8'), summary: fs.readFileSync(summary, 'utf8') };
      };
      const all = writeStep('none');
      assert.ok(all.ok, `${workflow} write step must succeed`);
      assert.equal(all.got.length, rows.length, `${workflow} must apply every row once, in order`);
      assert.equal(all.output, `applied=${JSON.stringify(rows.map((r) => String(r.bug_id)))}\n`, `${workflow} must publish every applied bug_id`);
      assert.ok(all.summary.includes(`${rows.length} of ${rows.length} row(s) applied (exit 0)`), `${workflow} must report the write result`);
      rows.forEach((row, i) => {
        assert.equal(all.got[i][0], String(row.bug_id));
        assert.equal(all.got[i][1], row.github_issue_url);
        assert.equal(all.got[i][2], row.disposition || row.expected_status || 'bug');
        assert.equal(Buffer.from(all.got[i][3], 'base64').toString('utf8'), row.public_reply.replace(/\n+$/, ''),
          `${workflow} reply must reach tinker exactly (command substitution trims trailing newlines, as before)`);
        assert.equal(all.got[i][4], '0', `${workflow} tinker stdin must be /dev/null, not the remote script`);
      });
      const partial = writeStep(String(rows[1].bug_id));
      assert.ok(!partial.ok, `${workflow} a failing row must fail the job`);
      assert.equal(partial.output, `applied=${JSON.stringify([String(rows[0].bug_id)])}\n`,
        `${workflow} must still publish the rows written before the failure`);
      const swallowed = writeStep('none', String(rows[1].bug_id));
      assert.ok(!swallowed.ok, `${workflow} a row whose tinker run threw (exit 0) must fail the job`);
      assert.equal(swallowed.output, `applied=${JSON.stringify([String(rows[0].bug_id)])}\n`,
        `${workflow} must not count a row without its WRITEBACK_OK marker`);
      const downstream = jobBlock(source, workflows.find((w) => w[0] === workflow)[3]);
      assert.ok(downstream.includes('if: always() && ') && downstream.includes('--argjson a "$APPLIED"'),
        `${workflow} follow-on job must run for, and only for, applied rows`);
      assert.ok(downstream.includes('failed=0') && downstream.includes('failed=1;') && downstream.trimEnd().endsWith('exit "$failed"'),
        `${workflow} follow-on job must attempt every applied row and fail only after the loop`);
    }
    assert.ok(!fs.existsSync(marker) && !fs.existsSync('executed'), 'reply metacharacters must never execute');
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

const phaseASource = fs.readFileSync('.github/workflows/bug-phase-a-triage.yml', 'utf8');
{
  // The Phase-A "already replied" check must not treat a comment linking issues/123 as the reply for issues/12.
  const line = phaseASource.match(/\n          (\$issueLinked = static fn \(\$body\) => [^\n]*;)\n/);
  assert.ok(line && phaseASource.includes('->pluck("body")->contains($issueLinked)'), 'Phase-A duplicate check must use the anchored predicate');
  const php = `$issueUrl = "https://github.com/o/r/issues/12"; ${line[1]}
    $cases = [["see https://github.com/o/r/issues/12", true], ["https://github.com/o/r/issues/12。", true],
      ["https://github.com/o/r/issues/123", false], ["https://github.com/o/r/issues/1", false]];
    foreach ($cases as [$body, $want]) { if ($issueLinked($body) !== $want) { fwrite(STDERR, $body); exit(1); } }`;
  execFileSync('php', ['-r', php]);
}
assert.ok(
  phaseASource.includes('while read -r -u 3 BUG_ID GITHUB_ISSUE_URL DISPOSITION CLOSE_AS_LOGGED PUBLIC_REPLY_B64; do')
    && phaseASource.includes('"disposition" => $disposition')
    && phaseASource.includes('"github_issue_url" => $issueUrl'),
  'Phase-A must pass each row\'s disposition and GitHub issue to the existing service',
);
assert.match(phaseASource, /\[\[ "\$DISPOSITION" =~ \^\[a-z_\]\+\$ \]\]/,
  'Phase-A must constrain the disposition before passing it through SSH');
assert.ok(!phaseASource.includes('"disposition" => "bug"'), 'Phase-A must not force every report to bug');
assert.ok(!phaseASource.includes('"engineering_required" => true'),
  'Phase-A must use the service-owned engineering requirement default');
assert.ok(phaseASource.includes('$svc::normalizeDispositionOptions(['),
  'Phase-A must validate disposition against the deployed service even on an idempotent rerun');

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

// F17 auto-intake (#Founder GO 6A, 2026-10-07): hourly, IDs-only, deduped, dry-run on manual dispatch.
{
  const src = fs.readFileSync('.github/workflows/bug-auto-intake.yml', 'utf8');
  assert.ok(src.includes("cron: '23 * * * *'"), 'auto-intake runs hourly');
  assert.match(src, /^permissions:\n  contents: read$/m, 'top-level permissions stay read-only');
  assert.equal((src.match(/issues: write/g) || []).length, 1, 'issues: write only on the issue job');
  assert.ok(src.includes('SourceRef: alltrue:bug_report:${id}') && src.includes('.login == $owner or .login == "app/github-actions"')
    && /\[\.comments\[\]\?? \| select\(/.test(src), 'issue dedupe by SourceRef in body or comments, trusted authors only');
  assert.equal((src.match(/if: github\.ref == 'refs\/heads\/main'/g) || []).length, 3, 'every job is main-only');
  assert.ok(!src.includes('page_key'), 'client-controlled page_key is never published');
  assert.ok(src.includes("!(github.event_name == 'workflow_dispatch' && inputs.dry_run)"), 'manual dry run never writes production');
  assert.ok(src.includes('bugs:auto-intake --candidates') && src.includes('bugs:auto-intake --ack'), 'uses the tested command');
  assert.ok(!/\.title|\.description|client_info/.test(src), 'no report free text in the workflow');
  assert.ok(fs.readFileSync('.github/pii-log-workflows.txt', 'utf8').split('\n').includes('bug-auto-intake.yml'), 'runs are purged hourly');
}

// Issue ownership is a line-start SourceRef, so a cross-reference ("Related earlier SourceRef: …") never counts.
assert.ok(fs.readFileSync('scripts/inapp-issue-reconcile.py', 'utf8').includes('^[ \\t>*_#.)0-9`-]*SourceRef\\b.*$'),
  'reconcile uses the line-start ownership rule');
{
  const labelJob = phaseASource.split('\n  label-logged:')[1];
  assert.ok(labelJob && /needs: \[preview, triage\]/.test(labelJob) && /issues: write/.test(labelJob) && /in-app:logged/.test(labelJob),
    'close_as_logged labels the backlog issue in-app:logged after triage succeeds');
  const followSource = fs.readFileSync('.github/workflows/bug-followup-comment.yml', 'utf8');
  const shipJob = followSource.split('\n  close-shipped-issue:')[1];
  assert.ok(shipJob && /needs: \[preview, comment\]/.test(shipJob) && /issues: write/.test(shipJob) && /--remove-label in-app:logged/.test(shipJob),
    'a shipped logged suggestion closes its backlog issue after the notice succeeds');
  assert.ok(/type:epic/.test(shipJob) && /lifecycle:frozen/.test(shipJob) && /also maps in-app/.test(shipJob),
    'the logged ship path never closes an epic, a frozen issue or an issue that maps other reports');
  assert.match(fs.readFileSync('.github/scripts/inapp-writeback-rows.py', 'utf8'), /must be an issue in this repository/, 'issue URLs from other repositories are rejected before any write');
}
console.log('bug-writeback-workflow.test.mjs: ok');
