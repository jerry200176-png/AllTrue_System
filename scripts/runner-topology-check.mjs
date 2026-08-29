#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'

const WORKFLOW_DIR = path.resolve('.github/workflows')
const ALLOWED_RUNNER = 'ubuntu-latest'
const ALLOWED_REUSABLE_WORKFLOWS = new Set([
  'google/osv-scanner-action/.github/workflows/osv-scanner-reusable.yml@6e4298ebc4db23e847df9b2e2de2939d6f066c67',
])

function normalizeUses(value) {
  return value
    .replace(/\s+#.*$/, '')
    .trim()
    .replace(/^['"]|['"]$/g, '')
}

export function collectWorkflowJobs(source, file = '<memory>') {
  const jobs = []
  let inJobs = false
  let currentJob = null

  const finishJob = () => {
    if (currentJob) jobs.push(currentJob)
  }

  for (const [index, line] of source.split(/\r?\n/).entries()) {
    if (/^jobs:\s*$/.test(line)) {
      inJobs = true
      currentJob = null
      continue
    }

    if (!inJobs) continue

    const job = line.match(/^  ([A-Za-z0-9_-]+):\s*$/)
    if (job) {
      finishJob()
      currentJob = {
        file,
        job: job[1],
        line: index + 1,
        runner: '<missing>',
        uses: null,
      }
      continue
    }

    const runner = line.match(/^    runs-on:\s*(.*?)\s*$/)
    if (runner && currentJob) {
      currentJob.runner = runner[1]
        ? runner[1].replace(/^['"]|['"]$/g, '')
        : '<empty-or-multiline>'
      currentJob.line = index + 1
      continue
    }

    const reusable = line.match(/^    uses:\s*(.*?)\s*$/)
    if (reusable && currentJob) {
      currentJob.uses = reusable[1] ? normalizeUses(reusable[1]) : '<empty>'
    }
  }

  finishJob()
  return jobs
}

export function validateJobs(jobs) {
  return jobs.filter(({ runner, uses }) => {
    if (runner === ALLOWED_RUNNER && uses === null) return false
    if (runner === '<missing>' && uses && ALLOWED_REUSABLE_WORKFLOWS.has(uses)) return false
    return true
  })
}

function selfTest() {
  const hosted = collectWorkflowJobs('jobs:\n  test:\n    runs-on: ubuntu-latest\n')
  const selfHosted = collectWorkflowJobs('jobs:\n  test:\n    runs-on: [self-hosted, Linux]\n')
  const multiline = collectWorkflowJobs('jobs:\n  test:\n    runs-on:\n      - self-hosted\n')
  const reusable = collectWorkflowJobs('jobs:\n  delegated:\n    uses: owner/repo/.github/workflows/ci.yml@main\n')
  const pinnedOsv = collectWorkflowJobs('jobs:\n  scan:\n    uses: "google/osv-scanner-action/.github/workflows/osv-scanner-reusable.yml@6e4298ebc4db23e847df9b2e2de2939d6f066c67" # v2.5.1\n')

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
