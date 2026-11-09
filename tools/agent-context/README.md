# Bounded code context

Install the locked, local-only tools with `npm ci --prefix tools/agent-context`.
They do not enter the application bundle or change production permissions.

Use [Repomix 1.19.0](https://github.com/yamadashy/repomix) (MIT)
with explicit public source paths and a task-specific temporary output:

```bash
npm --prefix tools/agent-context run context -- --include 'frontend/src/lib/roleCapabilities.js' --output /tmp/alltrue-role-context.xml
```

The default budget is 12,000 `o200k_base` tokens; an oversized pack fails. Keep
security scanning and gitignore enabled. Do not pack `.env`, `.local`, secrets,
private evidence or production exports. A pack is navigation, not source authority.
Read exact source before editing or reviewing a relevant behavior.

[ast-grep 0.51.1](https://github.com/ast-grep/ast-grep) (MIT) finds
code by syntax rather than loading a whole file. For Vue, extract `script setup`
with the existing `@vue/compiler-sfc` parser before a JavaScript query; preserve
the original path/head and account for the script offset when citing lines.

Compression stays opt-in: the receipt component/test pair at `620a74fb` measured
27,345 tokens normally and 29,301 with Repomix compression. Measure each language
and task; do not assume compression saves tokens or preserves full behavior.

For policy and SOP reads, use the existing exact `scripts/ci/docs-section.mjs`
outline/section reader. Its source hash detects stale references; required
policy sections and exceptions must still be read in full.
