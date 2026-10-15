# Read-only Pi probe for bug-phase-a-request.yml. Piped to `bash -s` over SSH with PLAN_B64 set to
# base64 JSON [{bug_id, issue_url, reply_sha256}]. Prints one `PHASEA_PROBE <json>` line holding
# IDs, status and booleans only (no report text). No writes.
set -euo pipefail
cd /home/admin/backend
export PLAN_B64
# shellcheck disable=SC2016 # PHP source, expanded by PHP not bash
php artisan tinker --execute='
$plan = json_decode(base64_decode((string)getenv("PLAN_B64")), true, 8, JSON_THROW_ON_ERROR);
$out = [];
foreach ($plan as $p) {
    $id = (int)$p["bug_id"];
    $bug = \App\Models\BugReport::find($id);
    if (!$bug) { $out[] = ["bug_id" => $id, "found" => false]; continue; }
    $comments = \App\Models\BugReportComment::query()->where("bug_report_id", $id)->get(["body", "is_internal_note"]);
    $latest = \App\Services\BugReportService::latestDispositionWithKind($id);
    $out[] = [
        "bug_id" => $id,
        "found" => true,
        "status" => (string)$bug->status,
        "disposition_url" => ($latest["github_issue_url"] ?? null) === $p["issue_url"],
        "url_comment_present" => $comments->contains(fn ($c) => str_contains((string)$c->body, (string)$p["issue_url"])),
        "reply_present" => $comments->contains(fn ($c) => !$c->is_internal_note && hash("sha256", (string)$c->body) === $p["reply_sha256"]),
    ];
}
echo "PHASEA_PROBE ", json_encode($out), PHP_EOL;
'
