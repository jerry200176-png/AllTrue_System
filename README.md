# AllTrue 補習班管理系統

AllTrue is a production tutoring-center operations platform for multi-branch
schools. It brings scheduling, attendance, learning records, billing, staff
workflows, and parent communication into one system.

> Repository visibility is temporarily private while historical Git objects
> undergo credential and privacy remediation. Production credentials are not
> stored in this repository.

## Product

| User | Main workflows |
|---|---|
| Director / admin | Student and course management, billing review, branch operations |
| Teacher | Schedule, attendance, learning records, makeup and substitute requests |
| Front desk | Check-in, RFID binding, absence handling, parent notifications |
| Parent | Schedule, learning records, and payment status through the portal or LINE |

Production serves four campuses and runs on a Raspberry Pi 5.

## Architecture

Architecture documentation and code-backed diagrams are indexed in
[`docs/architecture/README.md`](docs/architecture/README.md). The protected
`main` branch remains the code source of truth; production changes run only
through the control plane defined in
[`docs/CONTROL_PLANE_CONTRACT.md`](docs/CONTROL_PLANE_CONTRACT.md).

MemPalace is a non-production, best-effort local system. It has no incident authority, no SLO, and no execution impact on production.
Its operating details live in
[`docs/MEMPALACE_OPERATIONS_HANDBOOK.md`](docs/MEMPALACE_OPERATIONS_HANDBOOK.md).

## Repository map

| Path | Purpose |
|---|---|
| `frontend/` | Vue application |
| `backend/` | Laravel API, migrations, and tests |
| `operations/` | Versioned production-operation catalog and policy |
| `scripts/` | CI, verification, backup, and maintenance tooling |
| `docs/` | Architecture, runbooks, decisions, and operational evidence |
| `.github/workflows/` | CI, security scanning, deploy, and production verification |

Start with:

- [Documentation index](docs/INDEX.md)
- [System technical guide](docs/SYSTEM_TECH_GUIDE.md)
- [Production incident entry](docs/INCIDENT_START_HERE.md)
- [Operations runbook](docs/OPERATIONS_RUNBOOK.md)
- [Deployment guide](docs/DEPLOYMENT.md)
- [Agent and worktree rules](AGENTS.md)

## Local development

Prerequisites: PHP 8.2, Composer, Node.js 22, npm, and MySQL.

```bash
git clone https://github.com/jerry200176-png/AllTrue_System.git
cd AllTrue_System
cp backend/.env.example backend/.env
cd backend && composer install && php artisan key:generate
cd ../frontend && npm install && npm run dev
```

Use a dedicated local database. Never point tests at production.

## Quality gates

Every production change goes through a pull request. Required checks cover
presubmit and provenance, PHPUnit, Vite, PHPStan, documentation integrity,
control-plane integrity, Gitleaks, and security scanning.

```bash
cd backend && php artisan test
cd frontend && npm test && npm run build
node scripts/control-plane-lint.mjs
node scripts/docs-integrity-check.mjs --strict
```

Read [`docs/DANGEROUS_OPERATIONS.md`](docs/DANGEROUS_OPERATIONS.md) before
production or data-repair work. Production tests and direct pushes to
`main` are prohibited.

## Delivery and operations

```text
feature branch → pull request → required CI → protected main
       → deploy.yml → health check → authenticated smoke → evidence
```

- Deployment: [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)
- Production identity: [`scripts/production-identity.sh`](scripts/production-identity.sh)
- Operation catalog: [`operations/catalog.yaml`](operations/catalog.yaml)
- Backup and recovery: [`docs/OPERATIONS_RUNBOOK.md`](docs/OPERATIONS_RUNBOOK.md)

## Security

Never open a public issue containing credentials, personal data, screenshots,
or production records. Follow [`SECURITY.md`](SECURITY.md) for reporting.

## Contributing

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`AGENTS.md`](AGENTS.md).
Changes must be narrow, issue-linked, tested, and production-verified when
applicable.

## License

Copyright © 2026 Jerry. All rights reserved. See [`LICENSE.md`](LICENSE.md).
