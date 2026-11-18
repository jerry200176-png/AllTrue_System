#!/usr/bin/env node
/**
 * Architecture Fitness Functions — machine enforcement of the Engineering Constitution.
 * Humans do not police what a machine can. Fail-closed: any violation exits non-zero and blocks the PR.
 * Referenced by: docs/CONSTITUTION.md §L5, docs/RULE_ARCHITECTURE_GOVERNANCE.md, docs/OWNERSHIP_REGISTRY.md, docs/adr/.
 *
 * FIT-1  DI-1/ADR-005  ClassSession::create ratchet (single SessionWriter)
 * FIT-2  DEP-3         App\Services graph acyclic
 * FIT-3  Art. V        constitutional docs declare an owner
 * FIT-4  L2            ADR hygiene (status + Constitution link)
 * FIT-5  NM-7/ADR-008  status-literal duplication ratchet
 * FIT-6  DI-2          StudentClass.RemainingSessions direct-write ratchet (units owned by Billing ledger)
 * FIT-7  DI-3          Invoice.PaidAmount direct-write ratchet (paid owned by Billing)
 * FIT-8  DI-4          effectiveInstructorUserId call-site ratchet (instructor stored on Occurrence)
 * FIT-9  DI-6          Teacher:: write lock (identity is Party)
 * FIT-10 DEP-1         every Controller/Service mapped to a context (no undocumented context) + forbidden edges
 * FIT-11 Art. V        Ownership Registry completeness (every fact row has owner + single|ADR)
 * FIT-12 invariants    every DI-n has a present, resolvable automated enforcement
 * FIT-13 L3            required SOP docs present
 * FIT-16 #4659 step 3  fee math (Rate × count) in controllers ratchet (one fee source)
 * FIT-17 TD-076        files touching the `schedules` exception table directly ratchet (one timetable reader)
 * FIT-18 #4991 M       product files writing StudentClass.Paid / .Charge ratchet (one money writer)
 * FIT-19 #4991 M       frontend files multiplying a price by a count ratchet (screens display, they do not compute)
 * FIT-20 #4991 item 9  nightly reproduction allowances (reproduction_baselines.php) only shrink
 * FIT-21 #4991 item 9  nightly conditions verified fixed stay enforced (VerifyBugReproductions.php)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const J = (p) => JSON.parse(fs.readFileSync(path.join(ROOT, p), 'utf8'));
const baseline = J('scripts/arch-fitness-baseline.json');
const contexts = J('scripts/arch-contexts.json');
const invariants = J('scripts/arch-invariants.json');
const failures = [];
const ran = new Set();
const note = (s) => console.log(`  ok  ${s}`);

function walk(dir, filt, acc = []) {
  if (!fs.existsSync(dir)) return acc;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, filt, acc);
    else if (filt(p)) acc.push(p);
  }
  return acc;
}
const appPhp = walk(path.join(ROOT, 'backend/app'), (p) => p.endsWith('.php'));
const read = (f) => fs.readFileSync(f, 'utf8');

function ratchet(id, label, current, key) {
  ran.add(id);
  const limit = baseline[key];
  if (current > limit) failures.push(`${id} (${label}): ${current} > baseline ${limit}. Ratchet only decreases; raising requires an ADR.`);
  else note(`${id}: ${current} (baseline ${limit})${current < limit ? ' — improved; lower the baseline to lock it.' : ''}`);
}

// FIT-1
{ let n = 0; for (const f of appPhp) n += (read(f).match(/ClassSession::create\s*\(/g) || []).length; ratchet('FIT-1', 'DI-1 occurrence writer', n, 'classSessionCreateSites'); }

// FIT-2 service graph acyclic
let svcGraph = { svcFiles: [], edges: new Map(), ctxRule: null };
{
  ran.add('FIT-2');
  const svcFiles = walk(path.join(ROOT, 'backend/app/Services'), (p) => p.endsWith('.php'));
  const names = new Set(svcFiles.map((f) => path.basename(f, '.php')));
  const edges = new Map();
  for (const f of svcFiles) {
    const name = path.basename(f, '.php'); const deps = new Set();
    for (const m of read(f).matchAll(/App\\Services\\([A-Za-z0-9_]+)/g)) if (m[1] !== name && names.has(m[1])) deps.add(m[1]);
    edges.set(name, deps);
  }
  const color = new Map([...names].map((n) => [n, 0])); const stack = []; const cycles = [];
  const dfs = (n) => { color.set(n, 1); stack.push(n); for (const d of edges.get(n) || []) { if (color.get(d) === 1) cycles.push([...stack.slice(stack.indexOf(d)), d].join(' -> ')); else if (color.get(d) === 0) dfs(d); } stack.pop(); color.set(n, 2); };
  for (const n of names) if (color.get(n) === 0) dfs(n);
  if (cycles.length > baseline.serviceDependencyCycles) failures.push(`FIT-2 (DEP-3): service cycle(s): ${cycles.join(' | ')}`);
  else note(`FIT-2: service graph acyclic (${names.size} services).`);
  svcGraph = { svcFiles, edges };
}

// FIT-3 owner frontmatter
{ ran.add('FIT-3'); for (const rel of baseline.requireOwnerFrontmatter) { const p = path.join(ROOT, rel); if (!fs.existsSync(p)) continue; /* pending: doc not yet landed */ if (!/^---[\s\S]*?\bowner:\s*\S+/m.test(read(p).slice(0, 800))) failures.push(`FIT-3: ${rel} must declare owner: in frontmatter`); } note('FIT-3: constitutional docs ownership checked (present docs).'); }

// FIT-4 ADR hygiene
{ ran.add('FIT-4'); const adrs = walk(path.join(ROOT, 'docs/adr'), (p) => /\d{4}-.*\.md$/.test(path.basename(p))); for (const f of adrs) { const t = read(f); const rel = path.relative(ROOT, f); if (!/^\s*status:\s*\S+/m.test(t)) failures.push(`FIT-4: ${rel} missing status`); if (!/Constitution/i.test(t)) failures.push(`FIT-4: ${rel} must reference the Constitution`); } note(`FIT-4: ${adrs.length} ADR(s) inspected.`); }

// FIT-5 status-literal duplication
{ let n = 0; const re = /['"]leave['"]\s*,\s*['"]leave_adjusted['"]\s*,\s*['"]excused['"]/; for (const f of appPhp) if (re.test(read(f))) n++; ratchet('FIT-5', 'NM-7 status-literal duplication', n, 'statusLiteralFiles'); }

// FIT-6 DI-2 RemainingSessions direct writes
{ let n = 0; for (const f of appPhp) n += (read(f).match(/->RemainingSessions\s*=[^=]/g) || []).length; ratchet('FIT-6', 'DI-2 units owned by Billing ledger', n, 'remainingSessionsDirectWrites'); }

// FIT-7 DI-3 PaidAmount direct writes
{ let n = 0; for (const f of appPhp) n += (read(f).match(/->PaidAmount\s*=[^=]/g) || []).length; ratchet('FIT-7', 'DI-3 paid owned by Billing', n, 'paidAmountDirectWrites'); }

// FIT-8 DI-4 effectiveInstructorUserId sites
{ let n = 0; for (const f of appPhp) n += (read(f).match(/effectiveInstructorUserId\s*\(/g) || []).length; ratchet('FIT-8', 'DI-4 instructor stored on Occurrence', n, 'effectiveInstructorSites'); }

// FIT-9 DI-6 Teacher writes locked
{ let n = 0; for (const f of appPhp) n += (read(f).match(/Teacher::(create|insert|updateOrCreate)/g) || []).length; ratchet('FIT-9', 'DI-6 identity is Party', n, 'teacherDirectWrites'); }

// FIT-15 read-path write guard (ADR-0007 / DI-7): a query-shaped controller method
// (index/show/list/get*/fetch*/load*) must not mutate persistent state. This is the
// structural source of the ensure-past read-path-mutation class (#176). Baseline 0 = locked.
{
  ran.add('FIT-15');
  let n = 0; const hits = [];
  for (const f of walk(path.join(ROOT, 'backend/app/Http/Controllers'), (p) => p.endsWith('.php'))) {
    const t = read(f); const name = path.basename(f, '.php');
    for (const span of t.split(/(?=function\s+[A-Za-z_]+\s*\()/)) {
      const m = span.match(/^function\s+([A-Za-z_]+)/);
      if (!m) continue;
      if (/^(index|show|list|get|fetch|load)/i.test(m[1])) {
        const w = (span.match(/(::create|::updateOrCreate|->save)\s*\(/g) || []).length;
        if (w > 0) { n += w; hits.push(`${name}::${m[1]}`); }
      }
    }
  }
  const limit = baseline.readPathWritesInQueryMethods;
  if (n > limit) failures.push(`FIT-15 (ADR-0007/DI-7 read-path write): ${n} write(s) inside query method(s) (${hits.join(', ')}) > baseline ${limit}. Move mutation to a command/event off the read path.`);
  else note(`FIT-15: read-path writes in query methods = ${n} (baseline ${limit}).`);
}

// FIT-14 cross-context write guard: money models (Invoice/Payment/PaymentReport) may only be
// CREATED by the Billing context. Any other context creating them is an unmanaged cross-context
// write (must go through a Billing service / domain event). Ratchet toward 0.
{
  const ctxOf = (name) => contexts.rules.find((r) => new RegExp(r.match).test(name))?.context;
  const mod = walk(path.join(ROOT, 'backend/app/Http/Controllers'), (p) => p.endsWith('.php')).concat(svcGraph.svcFiles);
  let n = 0; const hits = [];
  for (const f of mod) {
    const name = path.basename(f, '.php'); if (name === 'Controller') continue;
    if (ctxOf(name) === 'billing') continue;
    const m = (read(f).match(/(Invoice|Payment|PaymentReport)::create\s*\(/g) || []).length;
    if (m > 0) { n += m; hits.push(`${name}[${ctxOf(name)}]`); }
  }
  ran.add('FIT-14');
  const limit = baseline.moneyWritesOutsideBilling;
  if (n > limit) failures.push(`FIT-14 (cross-context write): ${n} money-model create(s) outside Billing (${hits.join(', ')}) > baseline ${limit}. Route through a Billing service/event.`);
  else note(`FIT-14: money-model writes outside Billing = ${n} (baseline ${limit})${n < limit ? ' — improved; lower baseline.' : ''}.`);
}

// FIT-16 one fee source (#4659 step 3): screens drifted when each controller multiplied Rate by a
// count itself (calendar fee, slip vs reminder). New fee math belongs in the pricing/verdict services.
// ponytail: matches the direct `->Rate *` / `* (float) $x->rate` shapes only; a rate copied into a local
// first is not counted. Widen the pattern if that shape starts appearing.
{
  const re = /->(?:Rate|rate)\b\s*\*|\*\s*(?:\(float\)|\(int\))?\s*\$\w+(?:->\w+)*->(?:Rate|rate)\b/g;
  let n = 0; const hits = [];
  for (const f of walk(path.join(ROOT, 'backend/app/Http/Controllers'), (p) => p.endsWith('.php'))) {
    const m = (read(f).match(re) || []).length;
    if (m > 0) { n += m; hits.push(path.basename(f, '.php')); }
  }
  ratchet('FIT-16', `fee math in controllers (${hits.join(', ') || 'none'}); use StudentClassPricingService / ContractMoneyVerdict`, n, 'feeMathInControllers');
}

// FIT-17 (TD-076 / G-010): `schedules` holds exception rows only; a teacher's timetable is ClassSession plus the
// live exceptions. New code reads it through TeacherClassCalendar (or the occurrence resolver), never the table.
// Counted per file so edits inside an existing reader do not move the number.
{
  // Query-builder calls (plain or aliased `'schedules as s'`, either quote), raw SQL, and Schedule:: static calls.
  const re = /(?:table|[jJ]oin\w*|from)\(\s*['"]schedules(?:\s+as\s+\w+)?['"]|\b(?:FROM|JOIN)\s+`?schedules`?\b|\bSchedule::[a-z]\w*\(/;
  const hit = ["DB::table('schedules')", "DB::table('schedules as s')", '->leftJoin("schedules as sch", ', "->from('schedules')", 'FROM `schedules` ss', 'JOIN schedules s', 'Schedule::where('];
  const miss = ['Schedule::STATUS_SUPERSEDED', "DB::table('schedule_locks')", 'rows from schedules (idempotent)'];
  for (const form of hit) if (!re.test(form)) failures.push(`FIT-17 self-check: pattern must match ${form}`);
  for (const form of miss) if (re.test(form)) failures.push(`FIT-17 self-check: pattern must not match ${form}`);
  const n = appPhp.filter((f) => re.test(read(f))).length;
  ratchet('FIT-17', 'files touching the schedules table directly; read lessons through TeacherClassCalendar', n, 'scheduleTableReaderFiles');
}

// FIT-18 (#4991 family M): "paid" and the contract fee drifted because many files set them. New writes go through
// the Billing services (BillCorrection, ContractRenewal, the verdict); this list only shrinks.
// Operations/ is left out: one-off governed repairs, reviewed by the POP gates.
// ponytail: sees an assignment, setAttribute/increment/decrement, and an array key within 1500 chars of a write
// call; an array built in a variable first is missed.
{
  const re = /->(?:Paid|Charge)\s*[-+]?=[^=>]|(?:setAttribute|increment|decrement)\(\s*['"](?:Paid|Charge)['"]|(?:->update|->updateQuietly|::create|->create|->fill|->forceFill|->insert|updateOrCreate)\(\s*\[(?:[^\[\]]|\[[^\[\]]*\]){0,1500}?['"](?:Paid|Charge)['"]\s*=>/;
  const hit = ["->update(['Rate' => $in['rate'], 'Charge' => 0])", "$sc->packages()->create(['Paid' => 1])", "->decrement('Charge', 5)", '$sc->Paid = 1;', '$c->Charge = $fee;', '$c->Charge += 100;', "$c->setAttribute('Charge', 0)", "->increment('Charge', 5)",
    "->update(['Paid' => 0])", "->update([\n 'Stop' => 1,\n 'Charge' => 0])", "->updateQuietly(['Paid' => 1])", "StudentClass::create(['Charge' => 1])",
    "->fill(['Paid' => 1])", "->forceFill(['Charge' => 2])", "->insert(['Charge' => 2])", "updateOrCreate(['id' => 1, 'Paid' => 1]"];
  const miss = ['$sc->Paid == 1', "'Charge' => $sc->Charge,", '$inv->PaidAmount = 0;', "['Paid' => $x]", "getAttribute('Charge')"];
  for (const form of hit) if (!re.test(form)) failures.push(`FIT-18 self-check: pattern must match ${form}`);
  for (const form of miss) if (re.test(form)) failures.push(`FIT-18 self-check: pattern must not match ${form}`);
  const files = appPhp.filter((f) => !/backend\/app\/Operations\//.test(f.split(path.sep).join('/')) && re.test(read(f)));
  ratchet('FIT-18', `files writing Paid/Charge (${files.map((f) => path.basename(f, '.php')).join(', ')}); write through a Billing service`, files.length, 'contractMoneyWriterFiles');
}

// FIT-19 (#4991 family M, #3521): a screen that multiplies a price by a count shows its own amount. The server's
// verdict is the amount; lib/coursePricing.js is the one place allowed to preview it before save.
// ponytail: name-based (rate|price|charge|fee × count|sessions|lessons|hours|minutes|qty|units, or × x / 60); other names are missed.
{
  const re = /\b(?![\w.]*[aA]ttendance)[\w.]*(?:[rR]ate|[pP]rice|[cC]harge|[fF]ee)\w*(?:\(\))?\s*\*\s*\(?[\w.]*(?:[cC]ount|[sS]essions?|[lL]essons?|[hH]ours?|[qQ]ty|[uU]nits?|[mM]inutes?|\s*\/\s*60)/;
  for (const form of ['unitPrice * sessionCount', 'row.rate * (lessons', 'item.price * item.qty', 'rate * (actual / 60)']) if (!re.test(form)) failures.push(`FIT-19 self-check: pattern must match ${form}`);
  for (const form of ['width * count', 'rate + count', 'attendanceRate * totalSessions']) if (re.test(form)) failures.push(`FIT-19 self-check: pattern must not match ${form}`);
  const files = walk(path.join(ROOT, 'frontend/src'), (p) => /\.(js|ts|vue)$/.test(p) && !/\.(test|spec)\.|__tests__/.test(p) && !p.endsWith(path.join('lib', 'coursePricing.js')))
    .filter((f) => re.test(read(f)));
  ratchet('FIT-19', `frontend files computing money (${files.map((f) => path.basename(f)).join(', ') || 'none'}); show the server amount`, files.length, 'frontendMoneyMathFiles');
}

// FIT-20 (#4991 item 9): backend/config/reproduction_baselines.php says how many known-bad rows the nightly
// `bugs:verify-reproductions` tolerates. Raising a number, or adding a new allowance, hides a regression; both need
// the matching number raised here, which a reviewer sees. Fixing data lowers both.
{
  ran.add('FIT-20');
  const pinned = baseline.reproductionBaselines;
  const src = read(path.join(ROOT, 'backend/config/reproduction_baselines.php'));
  // Strict: inside `return [ ... ];` every line is `'key' => <integer>,` or `'key' => null,` (plus a comment).
  // Anything else (an expression, env(), two keys on a line) fails, so a raise cannot hide in a shape we do not read.
  // Whole-line anchors and exactly one `return`, so a `return [];` in a comment cannot stand in for the real array.
  const body = (src.match(/^return \[\n([\s\S]*?)^\];$/m) || [])[1];
  const bad = [];
  let n = 0, sum = 0;
  if (body === undefined) bad.push('no `return [` ... `];` block on its own lines in reproduction_baselines.php');
  if ((src.match(/\breturn\b/gi) || []).length !== 1) bad.push('reproduction_baselines.php must contain the word `return` exactly once');
  for (const raw of (body || '').split('\n')) {
    const line = raw.replace(/\/\/.*$/, '').trim();
    if (/\];|\/\*|\*\//.test(raw)) { bad.push(`no "];" or block comment inside the array: ${raw.trim()}`); continue; }
    if (line === '') continue;
    const m = line.match(/^'([a-z_]+)'\s*=>\s*(\d+|null),$/);
    if (!m) { bad.push(`unreadable line (write 'key' => <integer>,): ${line}`); continue; }
    const [, key, val] = m; n++;
    const pin = pinned[key];
    if (val === 'null') { if (pin !== null) bad.push(`${key} = null switches the check off; pin it as null in arch-fitness-baseline.json with the reason in the PR.`); continue; }
    sum += Number(val);
    if (pin === undefined) { if (Number(val) > 0) bad.push(`new allowance ${key} = ${val}; start at 0, or pin it in arch-fitness-baseline.json with the reason in the PR.`); }
    else if (pin === null || Number(val) > pin) bad.push(`allowance ${key} rose ${pin} -> ${val}. Allowances only shrink.`);
  }
  if (n === 0) bad.push('no allowance lines read');
  if (bad.length) for (const b of bad) failures.push(`FIT-20: ${b}`);
  else note(`FIT-20: ${n} nightly allowances at or below their pins (sum ${sum}).`);
}

// FIT-21 (#4991 item 9): a nightly condition marked `'enforced' => true` is a bug verified fixed. Flipping it to
// false, or deleting it, hides a regression just like raising its allowance (FIT-20). The pinned list only grows:
// check-ratchet-baseline-touch.mjs compares it with the merge base.
{
  ran.add('FIT-21');
  const pinned = baseline.enforcedReproductions;
  const whole = read(path.join(ROOT, 'backend/app/Console/Commands/VerifyBugReproductions.php'));
  // Only the conditions() list: handle() below also says 'enforced' => ... about each result.
  const from = whole.indexOf('function conditions(');
  const to = whole.indexOf('function census(');
  const src = from > -1 && to > from ? whole.slice(from, to) : '';
  const blocks = src.split(/^\s*'key'\s*=>\s*'/m).slice(1);
  const enforced = new Set();
  const bad = [];
  if (!src) bad.push('cannot find conditions() followed by census() in VerifyBugReproductions.php');
  if (whole.split('function conditions(').length !== 2 || !whole.includes('$this->conditions()')) bad.push('VerifyBugReproductions.php must define conditions() once and handle() must read $this->conditions()');
  // The flag decides nothing unless handle() reads it this way; a later assignment would override the list.
  if (/\[\s*'enforced'\s*\]\s*=(?!=)/.test(whole)) bad.push("'enforced' is assigned outside the conditions() list");
  if (!whole.includes("$isRegression = $cond['enforced'] && !$pending && $count > (int) $baseline;")) bad.push('handle() no longer decides a regression from the enforced flag; update FIT-21 with the change');
  for (const raw of blocks) {
    const b = raw.replace(/^\s*\/\/.*$/gm, ''); // whole-line comments only: a `//` inside a string must not hide a flag
    const key = (b.match(/^([a-z_]+)'/) || [])[1];
    const flags = [...b.matchAll(/'enforced'\s*=>\s*([^,\]\n]+)/g)].map((m) => m[1].trim());
    if (!key || flags.length !== 1 || !['true', 'false'].includes(flags[0])) { bad.push(`condition ${key || '?'}: write exactly one literal 'enforced' => true|false`); continue; }
    if (flags[0] === 'true') enforced.add(key);
  }
  if (blocks.length < 10) bad.push(`read only ${blocks.length} conditions from VerifyBugReproductions.php; the parser no longer matches the file`);
  for (const key of pinned) if (!enforced.has(key)) bad.push(`${key} is no longer enforced (or was removed). A condition verified fixed stays enforced; reopening one needs a reviewed change to this check (the pin list only grows against the merge base).`);
  for (const key of enforced) if (!pinned.includes(key)) bad.push(`${key} is enforced but not pinned; add it to enforcedReproductions in arch-fitness-baseline.json.`);
  if (bad.length) for (const b of bad) failures.push(`FIT-21: ${b}`);
  else note(`FIT-21: ${enforced.size} enforced nightly conditions, all pinned.`);
}

// FIT-10 context map completeness + forbidden edges (DEP-1)
{
  ran.add('FIT-10');
  const mod = walk(path.join(ROOT, 'backend/app/Http/Controllers'), (p) => p.endsWith('.php'))
    .concat(svcGraph.svcFiles);
  const ctxOf = (name) => contexts.rules.find((r) => new RegExp(r.match).test(name))?.context;
  const unmapped = [];
  for (const f of mod) { const name = path.basename(f, '.php'); if (name === 'Controller') continue; if (!ctxOf(name)) unmapped.push(name); }
  if (unmapped.length) failures.push(`FIT-10 (no undocumented context): unmapped module(s): ${unmapped.join(', ')}. Add a rule in arch-contexts.json.`);
  else note(`FIT-10: all ${mod.length} controllers/services mapped to a context.`);
  for (const f of svcGraph.svcFiles) {
    const name = path.basename(f, '.php'); const fromCtx = ctxOf(name);
    for (const dep of (svcGraph.edges.get(name) || [])) {
      const toCtx = ctxOf(dep);
      const rule = contexts.forbiddenEdges.find((e) => e.from === fromCtx);
      if (rule && rule.mustNotDependOn.includes(toCtx)) failures.push(`FIT-10 (DEP-1): ${name}[${fromCtx}] must not depend on ${dep}[${toCtx}] (use events).`);
    }
  }
}

// FIT-11 Ownership Registry completeness
{
  ran.add('FIT-11');
  const regPath = path.join(ROOT, 'docs/OWNERSHIP_REGISTRY.md');
  if (!fs.existsSync(regPath)) { note('FIT-11: pending (OWNERSHIP_REGISTRY.md not yet landed).'); }
  else {
  const reg = read(regPath);
  const rows = reg.split('\n').filter((l) => /^\|\s*F-\d{2}\s*\|/.test(l));
  if (rows.length < 10) failures.push(`FIT-11: Ownership Registry has only ${rows.length} fact rows (expected F-01..F-16).`);
  for (const r of rows) {
    const cells = r.split('|').map((c) => c.trim());
    const id = cells[1], owner = cells[3], status = cells[6], adr = cells[7];
    if (!owner) failures.push(`FIT-11: ${id} has no owner`);
    if (!/single|contested/.test(status || '')) failures.push(`FIT-11: ${id} has no ownership status`);
    if (/contested/.test(status || '') && !/\d{4}/.test(adr || '')) failures.push(`FIT-11: contested ${id} must cite an ADR`);
  }
  note(`FIT-11: ${rows.length} ownership facts validated.`);
  }
}

// FIT-12 every invariant has a present enforcement
{
  ran.add('FIT-12');
  for (const [di, spec] of Object.entries(invariants.invariants)) {
    if (spec.test) { const p = path.join(ROOT, spec.test); if (!fs.existsSync(p)) { /* pending: test not yet landed */ } else if (!read(p).includes(di)) failures.push(`FIT-12: ${di} test must tag ${di}`); }
    if (spec.fit && !ran.has(spec.fit)) failures.push(`FIT-12: ${di} references ${spec.fit} which did not run`);
  }
  note(`FIT-12: ${Object.keys(invariants.invariants).length} invariants have live enforcement.`);
}

// FIT-13 required SOP docs present
{ ran.add('FIT-13'); let present = 0; for (const rel of baseline.requiredSopDocs) if (fs.existsSync(path.join(ROOT, rel))) present++; note(`FIT-13: ${present}/${baseline.requiredSopDocs.length} SOP docs present (rest pending landing).`); }

if (failures.length) { console.error('\nArchitecture fitness FAILED:\n' + failures.map((f) => ' ✗ ' + f).join('\n')); process.exit(1); }
console.log(`\nArchitecture fitness: PASS (${ran.size} checks).`);
