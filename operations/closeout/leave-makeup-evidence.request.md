# Leave/Makeup evidence closeout

Triggers `.github/workflows/leave-makeup-evidence-closeout.yml` after merge.
Runs `php artisan evidence:leave-makeup-closeout` on Pi (repair dry-run only; no `--execute`).
