#!/usr/bin/env node
/**
 * Cold-start discoverability checks for alltrue-inapp-product-loop entry.
 * Safe/local only — no production writes.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const skillAgents = path.join(root, '.agents/skills/alltrue-inapp-product-loop/SKILL.md');
const skillCursor = path.join(root, '.cursor/skills/alltrue-inapp-product-loop');
const agentsMd = path.join(root, 'AGENTS.md');
const policy = path.join(root, 'docs/plans/INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md');
const releaseSkill = path.join(root, '.cursor/skills/alltrue-release/SKILL.md');
const debugSkill = path.join(root, '.cursor/skills/alltrue-debugging/SKILL.md');

assert.ok(fs.existsSync(skillAgents), 'canonical skill missing under .agents/skills');
const cursorTarget = fs.realpathSync(skillCursor);
assert.equal(
  cursorTarget,
  fs.realpathSync(path.dirname(skillAgents)),
  '.cursor/skills/alltrue-inapp-product-loop must resolve to .agents canonical dir',
);

const skillBody = fs.readFileSync(skillAgents, 'utf8');
assert.match(skillBody, /name:\s*alltrue-inapp-product-loop/);
assert.match(skillBody, /處理 in-app 意見與建議/);
assert.match(skillBody, /INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1/);
assert.match(skillBody, /CHAT_BUG_SYSTEM/);
assert.match(skillBody, /禁止 Pi SSH|禁.*Pi SSH|Pi SSH/);
assert.match(skillBody, /ChatGPT.*可選|optional/i);
assert.doesNotMatch(skillBody, /Founder\s*→\s*ChatGPT/);

const agents = fs.readFileSync(agentsMd, 'utf8');
assert.match(agents, /alltrue-inapp-product-loop/);
assert.match(agents, /處理 in-app 意見與建議/);

const policyBody = fs.readFileSync(policy, 'utf8');
assert.match(policyBody, /optional/i);
assert.doesNotMatch(policyBody, /Founder\s*→\s*ChatGPT planning\s*→\s*Founder/);

const release = fs.readFileSync(releaseSkill, 'utf8');
assert.doesNotMatch(release, /ssh admin@pi/);
assert.match(release, /version\.json/);
assert.match(release, /deployment\.json/);
assert.match(release, /禁止 Pi SSH|任何 Pi SSH/);

const debug = fs.readFileSync(debugSkill, 'utf8');
assert.doesNotMatch(debug, /唯讀.*tinker/);
assert.match(debug, /禁止 Pi SSH/);

// Intent routing simulation: phrase → required paths
const intent = '去處理 in-app 意見與建議';
assert.ok(
  /意見與建議|in-app|product loop/i.test(intent)
    && agents.includes('alltrue-inapp-product-loop')
    && fs.existsSync(skillAgents)
    && fs.existsSync(policy),
  'intent must resolve to skill + policy via AGENTS route',
);

console.log('alltrue-inapp-product-loop cold-start discoverability: PASS');
