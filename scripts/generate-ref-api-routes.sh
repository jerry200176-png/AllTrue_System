#!/bin/bash
# Regenerate docs/REF_API_ROUTES.md from the actual router (#992).
# Usage: bash scripts/generate-ref-api-routes.sh   (from repo root; backend vendor installed)
set -euo pipefail
cd "$(dirname "$0")/.."
php backend/artisan route:list --json > /tmp/ref-routes.json
python3 - <<'PY'
import json, datetime, collections

routes = json.load(open('/tmp/ref-routes.json'))
api = [r for r in routes if (r.get('uri') or '').startswith('api/')]

def auth_summary(mw):
    mw = mw or []
    if isinstance(mw, str):
        mw = [mw]
    middleware = [str(item).lower() for item in mw]
    tags = []
    has_role = any(
        'requirerole' in item
        or 'requiresuperadmin' in item
        or item.startswith('role:')
        for item in middleware
    )
    has_campus = any('requirecampus' in item or item == 'require_campus' for item in middleware)
    has_pin = any('requirepin' in item or item == 'require_pin' for item in middleware)
    has_auth = any(
        'apikeyauth' in item
        or 'authenticate' in item
        or item == 'auth'
        or item.startswith('auth:')
        for item in middleware
    )
    if has_role: tags.append('role')
    if has_campus: tags.append('campus')
    if has_pin: tags.append('pin')
    if has_auth and not has_role: tags.append('auth')
    if not tags: tags.append('public')
    return '+'.join(tags)

# Laravel 8 route:list expands middleware aliases to class names. Keep both forms
# covered so a framework/CLI representation change cannot silently mark protected
# finance routes as public again.
assert auth_summary([
    'App\\Http\\Middleware\\RequireRole:director',
    'App\\Http\\Middleware\\RequireCampus',
    'App\\Http\\Middleware\\RequirePin',
]) == 'role+campus+pin'
assert auth_summary(['role:director', 'require_campus', 'require_pin']) == 'role+campus+pin'
assert auth_summary(['App\\Http\\Middleware\\ApiKeyAuth']) == 'auth'
assert auth_summary(['api']) == 'public'

groups = collections.OrderedDict()
for r in sorted(api, key=lambda r: r['uri']):
    parts = r['uri'].split('/')
    prefix = '/'.join(parts[:3]) if len(parts) >= 3 else '/'.join(parts)
    groups.setdefault(prefix, []).append(r)

lines = [
    '# REF — API Routes',
    '',
    '> **GENERATED FILE — do not hand-edit.** Regenerate: `bash scripts/generate-ref-api-routes.sh`',
    f'> Source: `php artisan route:list --json` · {len(api)} api/* routes · generated {datetime.date.today().isoformat()}',
    '>',
    '> Auth legend: `role`=role middleware group, `campus`=require_campus, `pin`=require_pin,',
    '> `auth`=non-role authentication (for example API key), `public`=no enforcing auth middleware.',
    '> ⚠️ `public` means no auth *middleware* — some public routes carry inline guards',
    '> (X-Deploy-Secret, LINE channel signature, ParentSession bearer). Verify the route',
    '> closure/controller before treating a `public` row as exposed (R60 checks belong in CI).',
    '',
]
for prefix, rs in groups.items():
    lines.append(f'## /{prefix} ({len(rs)})')
    lines.append('')
    lines.append('| Method | URI | Action | Auth |')
    lines.append('|--------|-----|--------|------|')
    for r in rs:
        method = (r.get('method') or '').replace('|HEAD', '')
        action = (r.get('action') or '').replace('App\\Http\\Controllers\\', '')
        if len(action) > 70: action = action[:67] + '...'
        lines.append(f"| {method} | `{r['uri']}` | `{action}` | {auth_summary(r.get('middleware'))} |")
    lines.append('')

open('docs/REF_API_ROUTES.md', 'w', encoding='utf-8').write('\n'.join(lines) + '\n')
print(f"wrote docs/REF_API_ROUTES.md ({len(api)} routes, {len(groups)} groups)")
PY
