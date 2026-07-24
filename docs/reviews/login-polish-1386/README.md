# Login.vue DS polish — visual evidence (PR #1386 / Epic #687)

Durable before/after screenshots committed for review. Regenerate with:

```bash
# Vite on :5173
node scripts/login-polish-screenshots.mjs before http://127.0.0.1:5173/ ./docs/reviews/login-polish-1386/before
node scripts/login-polish-screenshots.mjs after  http://127.0.0.1:5173/ ./docs/reviews/login-polish-1386/after
```

Default local (gitignored) output: `artifacts/login-polish/<phase>/`.

| State | 390 | 768 | 1440 |
|-------|-----|-----|------|
| Before default | ![b390](./before/login-default-390.png) | ![b768](./before/login-default-768.png) | ![b1440](./before/login-default-1440.png) |
| After default | ![a390](./after/login-default-390.png) | ![a768](./after/login-default-768.png) | ![a1440](./after/login-default-1440.png) |
| After error | | ![ae768](./after/login-error-768.png) | |
| After forgot | | ![af768](./after/forgot-default-768.png) | |
| After reduced-motion | | ![ar768](./after/login-reduced-motion-768.png) | |
