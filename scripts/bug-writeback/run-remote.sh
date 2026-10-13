#!/usr/bin/env bash
# Shared in-app bug write-back (Phase-A triage, follow-up comment) on production.
# The one implementation of the input checks and BugReportService calls used by
# bug-phase-a-triage.yml, bug-followup-comment.yml and bug-writeback-request.yml.
# Usage: run-remote.sh phase-a|followup  (inputs from the environment, see each case;
# needs ~/.ssh/deploy_key and the pinned known_hosts from production-ssh-trust).
set -euo pipefail
MODE="${1:-}"
: "${GITHUB_REPOSITORY:?}" "${PI_SSH_USER:?}" "${PI_SSH_HOST:?}"
[[ "$BUG_ID" =~ ^[0-9]+$ ]] || { echo "invalid bug_id"; exit 1; }
[[ "$GITHUB_ISSUE_URL" =~ ^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[0-9]+$ ]] || { echo "invalid github_issue_url"; exit 1; }
[[ "$GITHUB_ISSUE_URL" == "https://github.com/${GITHUB_REPOSITORY}/issues/"* ]] || { echo "github_issue_url must be in this repository"; exit 1; }
[[ "$PUBLIC_REPLY" == *"$GITHUB_ISSUE_URL"* ]] || { echo "public reply must contain github_issue_url"; exit 1; }
PUBLIC_REPLY_B64="$(printf '%s' "$PUBLIC_REPLY" | base64 | tr -d '\n')"
pi_ssh() {
  ssh -i ~/.ssh/deploy_key \
    -o IdentitiesOnly=yes \
    -o BatchMode=yes \
    -o ConnectTimeout=20 \
    -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile=~/.ssh/known_hosts \
    "${PI_SSH_USER}@${PI_SSH_HOST}" "$1"
}

case "$MODE" in
phase-a)
# DISPOSITION, CLOSE_AS_LOGGED (true|false). Never resolves a bug.
[[ "$DISPOSITION" =~ ^[a-z_]+$ ]] || { echo "invalid disposition syntax"; exit 1; }
[[ "$CLOSE_AS_LOGGED" =~ ^(true|false)$ ]] || { echo "invalid close_as_logged"; exit 1; }
pi_ssh "BUG_ID='$BUG_ID' GITHUB_ISSUE_URL='$GITHUB_ISSUE_URL' DISPOSITION='$DISPOSITION' CLOSE_AS_LOGGED='$CLOSE_AS_LOGGED' PUBLIC_REPLY_B64='$PUBLIC_REPLY_B64' bash -s" <<'REMOTE'
set -euo pipefail
cd /home/admin/backend
PUBLIC_REPLY="$(printf '%s' "$PUBLIC_REPLY_B64" | base64 -d)"
export BUG_ID GITHUB_ISSUE_URL DISPOSITION PUBLIC_REPLY CLOSE_AS_LOGGED
php artisan tinker --execute='
$bugId = (int)getenv("BUG_ID");
$issueUrl = (string)getenv("GITHUB_ISSUE_URL");
$disposition = (string)getenv("DISPOSITION");
$reply = (string)getenv("PUBLIC_REPLY");
$bug = \App\Models\BugReport::find($bugId);
if (!$bug) { throw new \RuntimeException("bug not found: ".$bugId); }
$status = (string)$bug->status;
// close_as_logged reruns may find the report already closed; closeAsLogged decides idempotently.
$allowedStatuses = getenv("CLOSE_AS_LOGGED") === "true" ? ["new", "triaged", "closed"] : ["new", "triaged"];
if (!in_array($status, $allowedStatuses, true)) {
    throw new \RuntimeException("refusing status: ".$status);
}
// Global write-back: the actor is the verified super-admin (type S), as bugs:auto-intake uses,
// never a campus director picked by lowest ID and never a guessed user 1.
$actor = (int)(\App\Models\User::query()->where("type", "S")->orderBy("id")->value("id") ?? 0);
if ($actor <= 0) { throw new \RuntimeException("no type=S actor user"); }
$svc = \App\Services\BugReportService::class;
if (getenv("CLOSE_AS_LOGGED") === "true") {
    $closed = $svc::closeAsLogged($bugId, $actor, $disposition, $issueUrl, $reply);
    if (!($closed["ok"] ?? false)) { throw new \RuntimeException(json_encode($closed)); }
    echo json_encode(["bug_id" => $bugId, "from" => $status, "close_as_logged" => $closed, "issue" => $issueUrl], JSON_UNESCAPED_UNICODE), PHP_EOL;
} else {
// Same row lock as bugs:auto-intake --ack: whoever runs second re-reads the status after the
// first commits, so a report is never moved new->triaged twice or answered twice concurrently.
\Illuminate\Support\Facades\DB::transaction(function () use ($bugId, $issueUrl, $disposition, $reply, $actor, $svc) {
$locked = \App\Models\BugReport::query()->whereKey($bugId)->lockForUpdate()->first();
$status = (string)$locked->status;
if (!in_array($status, ["new", "triaged"], true)) { throw new \RuntimeException("refusing status: ".$status); }
$validatedDisposition = $svc::normalizeDispositionOptions([
    "disposition" => $disposition,
    "github_issue_url" => $issueUrl,
]);
if (!($validatedDisposition["ok"] ?? false)) { throw new \RuntimeException(json_encode($validatedDisposition)); }
$transition = null;
if ($status === "new") {
    $transition = $svc::changeStatus($bugId, $actor, "triaged", "phase-a verified intake; GitHub issue=".$issueUrl, [
        "disposition" => $disposition,
        "github_issue_url" => $issueUrl,
    ]);
    if (!($transition["ok"] ?? false)) { throw new \RuntimeException(json_encode($transition)); }
} else {
    // Already triaged (e.g. hourly auto-intake): record the verified disposition without a transition.
    $transition = $svc::restateDisposition($bugId, $actor, [
        "disposition" => $disposition,
        "github_issue_url" => $issueUrl,
    ], "phase-a verified intake; GitHub issue=".$issueUrl);
    if (!($transition["ok"] ?? false)) { throw new \RuntimeException(json_encode($transition)); }
}
$already = \App\Models\BugReportComment::query()->where("bug_report_id", $bugId)->where("is_internal_note", false)
    ->where("body", "like", "%".$issueUrl."%")->exists();
$comment = $already ? ["ok" => true, "skipped" => true] : $svc::addComment($bugId, $actor, $reply, false);
// addComment() returns the persisted BugReportComment model, while
// an idempotent skip returns the small {ok, skipped} shape.
$commentSucceeded = ($comment["ok"] ?? false) || isset($comment["id"]) || ($comment["skipped"] ?? false);
if (!$commentSucceeded) { throw new \RuntimeException(json_encode($comment)); }
$fresh = \App\Models\BugReport::find($bugId);
echo json_encode([
    "bug_id" => $bugId,
    "from" => $status,
    "final" => $fresh ? (string)$fresh->status : "missing",
    "transition" => $transition,
    "comment_id" => is_array($comment) ? ($comment["skipped"] ?? false) : $comment->id,
    "issue" => $issueUrl,
], JSON_UNESCAPED_UNICODE), PHP_EOL;
});
}
'
REMOTE
;;
followup)
# EXPECTED_STATUS; PRODUCTION_REVISION and DEPLOY_RUN_ID when it is closed. Never changes status.
[[ "$EXPECTED_STATUS" =~ ^(triaged|in_progress|resolved|closed)$ ]] || { echo "invalid expected_status"; exit 1; }
PRODUCTION_REVISION="${PRODUCTION_REVISION:-}"
DEPLOY_RUN_ID="${DEPLOY_RUN_ID:-}"
if [[ "$EXPECTED_STATUS" == closed ]]; then
  [[ "$PRODUCTION_REVISION" =~ ^[0-9a-f]{40}$ ]] || { echo "closed follow-up needs the full 40-char production_revision"; exit 1; }
  [[ "$DEPLOY_RUN_ID" =~ ^[0-9]+$ ]] || { echo "closed follow-up needs deploy_run_id"; exit 1; }
fi
pi_ssh "BUG_ID='$BUG_ID' EXPECTED_STATUS='$EXPECTED_STATUS' GITHUB_ISSUE_URL='$GITHUB_ISSUE_URL' PRODUCTION_REVISION='$PRODUCTION_REVISION' DEPLOY_RUN_ID='$DEPLOY_RUN_ID' PUBLIC_REPLY_B64='$PUBLIC_REPLY_B64' bash -s" <<'REMOTE'
set -euo pipefail
cd /home/admin/backend
PUBLIC_REPLY="$(printf '%s' "$PUBLIC_REPLY_B64" | base64 -d)"
export BUG_ID EXPECTED_STATUS GITHUB_ISSUE_URL PUBLIC_REPLY PRODUCTION_REVISION DEPLOY_RUN_ID
if [[ "$EXPECTED_STATUS" == closed ]]; then
  # Same gate as Phase-C: never announce a revision that is not in production.
  PROD_HEAD=$(git rev-parse HEAD)
  echo "prod_head=$PROD_HEAD"
  git merge-base --is-ancestor "$PRODUCTION_REVISION" "$PROD_HEAD" || { echo "production_revision is not in production"; exit 1; }
fi
php artisan tinker --execute='
$bugId = (int)getenv("BUG_ID");
$expected = (string)getenv("EXPECTED_STATUS");
$issueUrl = (string)getenv("GITHUB_ISSUE_URL");
$reply = (string)getenv("PUBLIC_REPLY");
$bug = \App\Models\BugReport::find($bugId);
if (!$bug) { throw new \RuntimeException("bug not found: ".$bugId); }
if ((string)$bug->status !== $expected) { throw new \RuntimeException("status changed: ".$bug->status); }
// Global write-back: the actor is the verified super-admin (type S), as bugs:auto-intake uses,
// never a campus director picked by lowest ID and never a guessed user 1.
$actor = (int)(\App\Models\User::query()->where("type", "S")->orderBy("id")->value("id") ?? 0);
if ($actor <= 0) { throw new \RuntimeException("no type=S actor user"); }
if ($expected === "closed") {
    $shipped = \App\Services\BugReportService::notifyLoggedSuggestionShipped(
        $bugId, $actor, $issueUrl, (string)getenv("PRODUCTION_REVISION"), (string)getenv("DEPLOY_RUN_ID"), $reply
    );
    if (!($shipped["ok"] ?? false)) { throw new \RuntimeException(json_encode($shipped)); }
    echo json_encode(["bug_id" => $bugId, "status" => "closed", "logged_ship" => $shipped, "issue" => $issueUrl], JSON_UNESCAPED_UNICODE), PHP_EOL;
} else {
// The Phase-A acknowledgement already contains the same GitHub URL.
// Idempotency must therefore match this exact follow-up payload,
// not merely the shared issue link, or the requested post-deploy
// verification message is silently skipped.
// Same row lock as phase-a: the dispatch workflow and the request-file workflow serialize here,
// so two producers cannot both miss the exact-payload check and post the comment twice.
\Illuminate\Support\Facades\DB::transaction(function () use ($bugId, $expected, $reply, $actor, $issueUrl) {
$locked = \App\Models\BugReport::query()->whereKey($bugId)->lockForUpdate()->first();
if ((string)$locked->status !== $expected) { throw new \RuntimeException("status changed: ".$locked->status); }
$already = \App\Models\BugReportComment::query()->where("bug_report_id", $bugId)->where("is_internal_note", false)
    ->where("body", $reply)->exists();
$comment = $already ? ["ok" => true, "skipped" => true] : \App\Services\BugReportService::addComment($bugId, $actor, $reply, false);
$commentSucceeded = ($comment["ok"] ?? false) || isset($comment["id"]) || ($comment["skipped"] ?? false);
if (!$commentSucceeded) { throw new \RuntimeException(json_encode($comment)); }
echo json_encode(["bug_id" => $bugId, "status" => (string)$locked->status, "comment_id" => is_array($comment) ? ($comment["skipped"] ?? false) : $comment->id, "issue" => $issueUrl], JSON_UNESCAPED_UNICODE), PHP_EOL;
});
}
'
REMOTE
;;
*)
echo "usage: run-remote.sh phase-a|followup"; exit 2
;;
esac
