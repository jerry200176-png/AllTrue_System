#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'

const WORKFLOW_DIR = path.resolve('.github/workflows')
const ALLOWED_RUNNER = 'ubuntu-latest'

export function collectWorkflowRunners(source, file = '<memory>') {
  const runners = []
  let inJobs = false
  let currentJob = null

  for (const [index, line] of source.split(/\r?\n/).entries()) {
    if (/^jobs:\s*$/.test(line)) {
      inJobs = true
      currentJob = null
      continue
    }

    if (!inJobs) continue

    const job = line.match(/^  ([A-Za-z0-9_-]+):\s*$/)
    if (job) {
      currentJob = job[1]
      continue
    }

    const runner = line.match(/^    runs-on:\s*(.*?)\s*$/)
    if (runner) {
      runners.push({
        file,
        job: currentJob ?? '<unknown>',
        line: index + 1,
        runner: runner[1]
          ? runner[1].replace(/^['"]|['"]$/g, '')
          : '<empty-or-multiline>',
      })
    }
  }

  return runners
}

export function validateRunners(runners) {
  return runners.filter(({ runner }) => runner !== ALLOWED_RUNNER)
}

function selfTest() {
  const hosted = collectWorkflowRunners('jobs:\n  test:\n    runs-on: ubuntu-latest\n')
  const selfHosted = collectWorkflowRunners('jobs:\n  test:\n    runs-on: [self-hosted, Linux]\n')
  const multiline = collectWorkflowRunners('jobs:\n  test:\n    runs-on:\n      - self-hosted\n')

  if (hosted.length !== 1 || validateRunners(hosted).length !== 0) {
    throw new Error('self-test failed: ubuntu-latest must be accepted')
  }
  if (validateRunners(selfHosted).length !== 1) {
    throw new Error('self-test failed: self-hosted runner must be rejected')
  }
  if (validateRunners(multiline).length !== 1) {
    throw new Error('self-test failed: multiline runner declarations must be rejected')
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

  const runners = files.flatMap((file) => collectWorkflowRunners(
    fs.readFileSync(path.join(WORKFLOW_DIR, file), 'utf8'),
    `.github/workflows/${file}`,
  ))

  if (runners.length === 0) {
    console.error('runner-topology-check: no workflow jobs with runs-on were found')
    process.exit(1)
  }

  const violations = validateRunners(runners)
  if (violations.length > 0) {
    console.error(`runner-topology-check: active jobs must use ${ALLOWED_RUNNER}`)
    for (const violation of violations) {
      console.error(`- ${violation.file}:${violation.line} job=${violation.job} runs-on=${violation.runner}`)
    }
    console.error('Update docs/REF_CI_RUNNER_TOPOLOGY.md and receive an explicit security/operations review before changing this boundary.')
    process.exit(1)
  }

  console.log(`runner-topology-check: OK (${runners.length} jobs across ${files.length} workflows use ${ALLOWED_RUNNER})`)
}

main()
