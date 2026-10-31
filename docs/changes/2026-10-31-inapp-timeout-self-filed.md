## 2026-10-31 — fix(inapp): reports the super admin filed can close after the 14-day wait (#410 follow-up)
<!-- release-notes: silent_ship=silent-2026-10-31-inapp-timeout-self-filed -->
<!-- silent-reason: back-office auto-close rule only; no screen or wording change -->
- Our write-back replies are saved under the super-admin account that the writer acts as. 37 of the reports since #327 were filed from that same account. So the daily `bugs:close-stale-resolved --auto` read our own reply as "the reporter answered", found no retest request, and skipped them forever (eligible=0 in run 41186111245).
- A public comment that links its GitHub issue now counts as our reply, whoever saved it. The write-back writers refuse any reply without that link. A real comment from the reporter, without a link, still blocks the timeout. The triaged "waiting on reporter" queue uses the same rule.
