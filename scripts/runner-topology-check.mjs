#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'

const WORKFLOW_DIR = path.resolve('.github/workflows')
const ALLOWED_RUNNER = 'ubuntu-latest'
const ALLOWED_REUSABLE_WORKFLOWS = new Set([
  'google/osv-scanner-action/.github/workflows/osv-scanner-reusable.yml@a345acffa64b0eaede81a3d9aae6141214d9c8fc',
])

function normalizeUses(value) {
  const scalar = value.trim()
  // Comments begin outside quotes. Preserve quoted '#' and decode JSON-compatible
  // YAML keys (including Unicode escapes); reject other scalar forms as unknown.
  if (scalar.startsWith("'")) {
    const quoted = scalar.match(/^'((?:[^']|'')*)'(?:\s+#.*)?$/)
    return quoted ? quoted[1].replace(/''/g, "'") : '<uninspectable-scalar>'
  }
  if (scalar.startsWith('"')) {
    const quoted = scalar.match(/^("(?:[^"\\]|\\.)*")(?:\s+#.*)?$/)
    try { return quoted ? JSON.parse(quoted[1]) : '<uninspectable-scalar>' }
    catch { return '<uninspectable-scalar>' }
  }
  return scalar.replace(/^#.*$|\s+#.*$/, '').trim()
}

function normalizeKey(value) {
  const key = normalizeUses(value)
  // Tags, anchors and complex keys can hide the effective runs-on key. Other
  // reusable inputs remain allowed only when their key is inspectable.
  return /^[A-Za-z_][A-Za-z0-9_-]*$/.test(key) ? key : '<uninspectable-scalar>'
}

export function collectWorkflowJobs(source, file = '<memory>') {
  const jobs = []
  let inJobs = false
  let currentJob = null
  let inWith = false
  let withInputIndent = null
  let withSeen = false
  let inJobSequence = false
  let jobsSeen = false
  const jobNames = new Set()

  const finishJob = () => {
    if (currentJob) jobs.push(currentJob)
    currentJob = null
  }
  const uninspectableJob = (job, line) => ({
    file, job, line, runner: '<missing>', uses: null, inputRunner: null,
    uninspectable: true,
  })

  const finishJobsBlock = () => {
    finishJob()
    inJobs = false
    inWith = false
    inJobSequence = false
  }

  for (const [index, line] of source.split(/\r?\n/).entries()) {
    if (!line.trim() || /^\s*#/.test(line)) continue
    if (/^\S/.test(line)) {
      finishJobsBlock()
      if (/^(?:---|\.\.\.)\s*$/.test(line)) continue
      const root = line.match(/^(\S.*?)\s*:\s*(.*?)\s*$/)
      const key = root ? normalizeKey(root[1]) : '<uninspectable-scalar>'
      if (key === 'jobs') {
        if (jobsSeen || normalizeUses(root[2])) jobs.push(uninspectableJob('<uninspectable-jobs>', index + 1))
        jobsSeen = true
        inJobs = true
      } else if (key === '<uninspectable-scalar>') {
        jobs.push(uninspectableJob('<uninspectable-root>', index + 1))
      }
      continue
    }

    if (!inJobs) continue

    if (/^ {1,2}\S/.test(line)) {
      finishJob()
      const job = line.match(/^  (\S.*?)\s*:\s*(?:#.*)?$/)
      const name = job ? normalizeKey(job[1]) : '<uninspectable-job>'
      currentJob = {
        file,
        job: name,
        line: index + 1,
        runner: '<missing>',
        uses: null,
        inputRunner: null,
        uninspectable: !job || name === '<uninspectable-scalar>' || jobNames.has(name),
      }
      jobNames.add(name)
      inWith = false
      withInputIndent = null
      withSeen = false
      inJobSequence = false
      continue
    }

    if (inWith && /^\S|^ {1,4}\S/.test(line)) inWith = false

    // YAML permits a block sequence at the same indentation as its mapping key.
    // Only steps/needs own these entries; a following job property is still inspected.
    if (inJobSequence && /^    -(?:\s|$)/.test(line)) continue
    const property = line.match(/^    (\S.*?)\s*:\s*(.*?)\s*$/)
    const propertyKey = property ? normalizeKey(property[1]) : null
    if (/^    \S/.test(line)) inJobSequence = ['steps', 'needs'].includes(propertyKey) && !normalizeUses(property[2])
    if (currentJob && /^    \S/.test(line) && (!property || propertyKey === '<uninspectable-scalar>')) {
      currentJob.uninspectable = true
    }
    if (currentJob && propertyKey === 'with') {
      if (withSeen || normalizeUses(property[2])) currentJob.inputRunner = '<uninspectable-with>'
      withSeen = true
      inWith = true
      withInputIndent = null
      continue
    }
    if (currentJob && propertyKey === '<<') currentJob.inputRunner = '<uninspectable-job-merge>'
    if (currentJob && propertyKey === '<uninspectable-scalar>') currentJob.inputRunner = propertyKey
    const input = inWith && line.match(/^( {5,})([^\s#].*)$/)
    if (input && currentJob) {
      withInputIndent ??= input[1].length
      if (input[1].length === withInputIndent) {
        const field = input[2].match(/^(\S.*?)\s*:\s*(.*?)\s*$/)
        const key = field ? normalizeKey(field[1]) : '<uninspectable-scalar>'
        if (key === 'runs-on') {
          currentJob.inputRunner = currentJob.inputRunner === null
            ? normalizeUses(field[2]) || '<empty-or-multiline>'
            : '<duplicate-or-uninspectable-runner>'
          currentJob.line = index + 1
        } else if (key === '<<' || key === '<uninspectable-scalar>') {
          currentJob.inputRunner = '<uninspectable-with-input>'
        }
      }
    }

    const runner = propertyKey === 'runs-on' ? [null, property[2]] : null
    if (runner && currentJob) {
      currentJob.runner = runner[1]
        ? normalizeUses(runner[1])
        : '<empty-or-multiline>'
      currentJob.line = index + 1
      continue
    }

    const reusable = propertyKey === 'uses' ? [null, property[2]] : null
    if (reusable && currentJob) {
      currentJob.uses = reusable[1] ? normalizeUses(reusable[1]) : '<empty>'
    }
  }

  finishJob()
  if (!jobsSeen || jobs.length === 0) jobs.push(uninspectableJob('<missing-or-uninspectable-jobs>', 1))
  return jobs
}

export function validateJobs(jobs) {
  return jobs.filter(({ runner, uses, inputRunner, uninspectable }) => {
    if (uninspectable) return true
    if (runner === ALLOWED_RUNNER && uses === null) return false
    if (runner === '<missing>' && uses && ALLOWED_REUSABLE_WORKFLOWS.has(uses)
      && (inputRunner === null || inputRunner === ALLOWED_RUNNER)) return false
    return true
  })
}

function selfTest() {
  const hosted = collectWorkflowJobs('jobs:\n  test:\n    runs-on: ubuntu-latest\n')
  const selfHosted = collectWorkflowJobs('jobs:\n  test:\n    runs-on: [self-hosted, Linux]\n')
  const indentlessSteps = collectWorkflowJobs('jobs:\n  safe:\n    runs-on: ubuntu-latest\n    steps:\n    - run: echo safe\n    - uses: actions/checkout@v4\n')
  if (validateJobs(indentlessSteps).length) throw new Error('self-test failed: legal indentless steps sequence rejected')
  const runnerAfterIndentlessSteps = collectWorkflowJobs('jobs:\n  unsafe:\n    steps:\n    - run: echo safe\n    runs-on: self-hosted\n')
  if (!validateJobs(runnerAfterIndentlessSteps).length) throw new Error('self-test failed: runner after indentless steps hidden')
  const indentlessNeeds = collectWorkflowJobs('jobs:\n  safe:\n    runs-on: ubuntu-latest\n  child:\n    runs-on: ubuntu-latest\n    needs:\n    - safe\n    steps:\n      - run: echo safe\n')
  if (validateJobs(indentlessNeeds).length) throw new Error('self-test failed: legal indentless needs sequence rejected')
  const multiline = collectWorkflowJobs('jobs:\n  test:\n    runs-on:\n      - self-hosted\n')
  const reusable = collectWorkflowJobs('jobs:\n  delegated:\n    uses: owner/repo/.github/workflows/ci.yml@main\n')
  const pinnedOsv = collectWorkflowJobs('jobs:\n  scan:\n    uses: "google/osv-scanner-action/.github/workflows/osv-scanner-reusable.yml@a345acffa64b0eaede81a3d9aae6141214d9c8fc" # v2.6.0\n')

  // #4426 parser-only reconstruction: preserve the currently reviewed pin.
  const withInputs = (inputs) => collectWorkflowJobs(`jobs:\n  scan:\n    uses: "${pinnedOsv[0].uses}"\n${inputs}\n`)
  for (const inputs of [
    '    with:\n      runs-on: self-hosted',
    '    with:\n      runs-on: ubuntu-24.04',
    '    with:\n      runs-on: ${{ inputs.runner }}',
    '    with:\n      runs-on:\n        - self-hosted',
    '    with:\n        runs-on: self-hosted',
    '    "with":\n      "runs-on": self-hosted',
    '    with: {runs-on: self-hosted}',
    '    with: *uninspected-inputs',
    '    with:\n      <<: *uninspected-inputs',
    '    <<: *uninspected-job-inputs',
    '    with:\n      runs-on: ubuntu-latest\n      runs-on: self-hosted',
    "    with:\n      runs-on: 'ubuntu-latest # custom'",
    '    with:\n      runs-on: "ubuntu-latest # custom"',
    '    with:\n      "\\u0072uns-on": self-hosted',
    '    "\\u0077ith":\n      runs-on: self-hosted',
    '    "\\u0072uns-on": self-hosted',
    '    with:\n      runs-on: "ubuntu-latest"#invalid-comment',
    '    with:\n      !!str runs-on: self-hosted',
    '    with:\n      &runner-key runs-on: self-hosted',
    '    with:\n      ? runs-on\n      : self-hosted',
  ]) {
    if (validateJobs(withInputs(inputs)).length !== 1) {
      throw new Error(`self-test failed: unsafe/uninspectable runner input accepted: ${inputs}`)
    }
  }
  for (const inputs of [
    '    with: # ordinary block inputs\n      runs-on: "ubuntu-latest" # reviewed runner',
    '    with:\n      scan-args: |-\n        runs-on: self-hosted',
    '    with:\n      upload-sarif: false',
    '    with:\n      "\\u0072uns-on": "ubuntu-latest"',
  ]) {
    if (validateJobs(withInputs(inputs)).length !== 0) {
      throw new Error(`self-test failed: reviewed runner/default rejected: ${inputs}`)
    }
  }
  for (const pin of ['main', 'v2.6.0', 'b1d267aea5211fa648d9d442fa64b90dbdcbdd36']) {
    if (validateJobs(collectWorkflowJobs(`jobs:\n  scan:\n    uses: google/osv-scanner-action/.github/workflows/osv-scanner-reusable.yml@${pin}\n`)).length !== 1) {
      throw new Error(`self-test failed: unreviewed reusable reference accepted: ${pin}`)
    }
  }
  // Independent review: a valid but unrecognized header/key must never omit a job.
  for (const header of [
    '  "scan":', "  'scan':", '  "\\u0073can":',
    '  scan: &job', '  scan: # ordinary comment', '  ? scan\n  :',
  ]) {
    const source = `jobs:\n${header}\n    uses: ${pinnedOsv[0].uses}\n    with:\n      runs-on: self-hosted\n  safe:\n    runs-on: ubuntu-latest\n`
    if (validateJobs(collectWorkflowJobs(source)).length === 0) {
      throw new Error(`self-test failed: unrecognized job header hid an unsafe job: ${header}`)
    }
  }
  for (const inputs of [
    '    ? with\n    :\n      runs-on: self-hosted',
    '    ? "\\u0077ith"\n    :\n      runs-on: self-hosted',
    '    ? <<\n    : *job',
  ]) {
    if (validateJobs(withInputs(inputs)).length === 0) {
      throw new Error(`self-test failed: explicit job key was ignored: ${inputs}`)
    }
  }
  for (const root of ['jobs:', '"jobs":', '"\\u006aobs":']) {
    if (validateJobs(collectWorkflowJobs(`${root}\n  unsafe:\n    runs-on: self-hosted\n`)).length === 0) {
      throw new Error(`self-test failed: jobs root was silently omitted: ${root}`)
    }
  }
  const afterJobs = collectWorkflowJobs('jobs:\n  safe:\n    runs-on: ubuntu-latest\nenv:\n  EXAMPLE:\n    ignored: value\n')
  if (afterJobs.length !== 1 || validateJobs(afterJobs).length !== 0) {
    throw new Error('self-test failed: a later root mapping is not a job')
  }

  if (hosted.length !== 1 || validateJobs(hosted).length !== 0) {
    throw new Error('self-test failed: ubuntu-latest must be accepted')
  }
  if (validateJobs(selfHosted).length !== 1) {
    throw new Error('self-test failed: self-hosted runner must be rejected')
  }
  if (validateJobs(multiline).length !== 1) {
    throw new Error('self-test failed: multiline runner declarations must be rejected')
  }
  if (validateJobs(reusable).length !== 1) {
    throw new Error('self-test failed: reusable workflow jobs must require an explicit topology review')
  }
  if (validateJobs(pinnedOsv).length !== 0) {
    throw new Error('self-test failed: the pinned OSV reusable workflow must be accepted')
  }

  console.log('runner-topology-check self-test: OK')
}

function main() {
  if (process.argv.includes('--self-test')) {
    selfTest()
    return
  }

  const files = fs.readdirSync(WORKFLOW_DIR)
    .filter((file) => /\.ya?ml$/i.test(file))
    .sort()

  const jobs = files.flatMap((file) => collectWorkflowJobs(
    fs.readFileSync(path.join(WORKFLOW_DIR, file), 'utf8'),
    `.github/workflows/${file}`,
  ))

  if (jobs.length === 0) {
    console.error('runner-topology-check: no workflow jobs were found')
    process.exit(1)
  }

  const violations = validateJobs(jobs)
  if (violations.length > 0) {
    console.error(`runner-topology-check: jobs must directly use ${ALLOWED_RUNNER} or match the reviewed reusable-workflow allow-list`)
    for (const violation of violations) {
      const delegated = violation.uses ? ` uses=${violation.uses}` : ''
      console.error(`- ${violation.file}:${violation.line} job=${violation.job} runs-on=${violation.runner}${delegated}`)
    }
    console.error('Update docs/REF_CI_RUNNER_TOPOLOGY.md and receive an explicit security/operations review before changing this boundary.')
    process.exit(1)
  }

  const delegated = jobs.filter(({ uses }) => uses !== null).length
  console.log(`runner-topology-check: OK (${jobs.length - delegated} direct ${ALLOWED_RUNNER} jobs + ${delegated} reviewed reusable job across ${files.length} workflows)`)
}

main()
