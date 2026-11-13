<?php

namespace App\Operations\Strategies;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Operations\Inapp\InappProgressReplyTemplates as Templates;
use App\Operations\Inapp\InappStandingPolicy;
use App\Services\BugReportService;
use App\Services\BugSafetyScreen;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Founder decision 2026-11-13 (#4991), ADR-POP-015 family: post one canned public progress reply on a report. Input is the report
 * and a template key; no text of any kind comes from the caller. It adds a comment and changes nothing else (not status, not
 * assignee). Replies of this operation are recognised by their exact canned text on any public comment (the author is not
 * filtered: the issuing super admin is often the reporter too, and verify() counts the same way): no marker column, no migration.
 * Every check is a hard plan error and is rechecked under the row lock in execute().
 *
 * Side effect to know: `deployed_please_confirm` matches BugReportService::RETEST_REQUEST_PATTERN and AWAITING_REPORTER_QUESTION_PATTERN,
 * so on a resolved report (the only status it may be posted on) it also counts as the retest request for the scheduled auto-close.
 */
final class InappReportProgressReplyStrategy
{
    public const OPERATION = 'inapp-report-progress-reply';
    public const MAX_PER_REPORT = 3;

    /** @param list<string> $retiredTexts old wording that still counts for idempotency and the cap */
    public function __construct(private readonly array $retiredTexts = Templates::RETIRED_TEXTS) {}

    public function plan(array $p): array
    {
        $errors = [];
        foreach (['bug_report_id', 'campus_id'] as $key) if (!is_int($p[$key] ?? null) || $p[$key] <= 0) $errors[] = $key . '_invalid';
        if (!is_string($p['request_id'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $p['request_id']) !== 1) $errors[] = 'request_id_invalid';
        if (!Templates::has($p['template'] ?? null)) $errors[] = 'template_unknown';
        if ($errors !== []) return $this->result($errors, $p);

        $bug = BugReport::query()->whereKey($p['bug_report_id'])->first();
        if (!$bug) return $this->result(['report_missing'], $p);

        return $this->result($this->blockers($bug, $p), $p, (string) $bug->getRawOriginal('status'));
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false)) throw new RuntimeException('inapp_progress_plan_not_ready');
        $request = DB::table('pop_operation_requests')->where('id', (string) $context['operation_id'])->first();
        $p = json_decode((string) $request?->parameters, true, 512, JSON_THROW_ON_ERROR);
        $bugId = (int) $p['bug_report_id'];
        $commentId = null;
        $status = null;
        try {
            $actor = InappStandingPolicy::attributionUserId($request);
            DB::transaction(function () use ($p, $bugId, $actor, $context, &$commentId, &$status): void {
                $bug = BugReport::query()->whereKey($bugId)->lockForUpdate()->first();
                InappStandingPolicy::assertKillSwitchesOpen((string) $context['operation_id']);
                if (!$bug) throw new RuntimeException('inapp_progress_report_missing');
                $errors = $this->blockers($bug, $p);
                if ($errors !== []) throw new RuntimeException('inapp_progress_' . $errors[0]);
                $status = (string) $bug->getRawOriginal('status');
                $commentId = BugReportService::addComment($bugId, $actor, Templates::text($p['template']), false)->id;
            });
        } catch (RuntimeException $e) { // nothing was written: the transaction rolled back
            $code = $e->getMessage();

            return ['ok' => false, 'errors' => [str_starts_with($code, 'inapp_') ? $code : 'inapp_progress_failed'], 'bug_id' => $bugId];
        }

        return ['ok' => true, 'bug_id' => $bugId, 'template' => $p['template'], 'comment_id' => (int) $commentId, 'status' => $status];
    }

    /** Re-reads the report: exactly one public comment with the template text (the new one), and the status as it was. */
    public function verify(array $plan, array $result): array
    {
        $bugId = (int) ($result['bug_id'] ?? 0);
        $template = $result['template'] ?? null;
        $ok = ($result['ok'] ?? false) === true && Templates::has($template)
            && BugReportComment::query()->where('bug_report_id', $bugId)->where('is_internal_note', false)
                ->where('body', Templates::text($template))->pluck('id')->map(fn ($id) => (int) $id)->all() === [(int) ($result['comment_id'] ?? 0)]
            && (string) BugReport::query()->whereKey($bugId)->value('status') === (string) ($result['status'] ?? '');

        return ['ok' => $ok, 'errors' => $ok ? [] : ['reply_not_posted_once'], 'checks' => ['one_reply', 'status_unchanged']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        return ['ok' => false, 'errors' => ['not_reversible']]; // same contract as inapp-report-triage: a posted public reply is not unsent
    }

    /** @return list<string> named refusal reasons; [] = may post */
    private function blockers(BugReport $bug, array $p): array
    {
        $errors = [];
        $status = (string) $bug->getRawOriginal('status');
        $template = (string) $p['template'];
        if ((int) $bug->getAttribute('CampusID') !== $p['campus_id']) $errors[] = 'report_outside_campus';
        if ($status === 'closed') $errors[] = 'report_closed';
        if (!in_array($status, Templates::REQUIRED_STATUS[$template], true)) $errors[] = 'template_status_mismatch';
        $posted = BugReportComment::query()->where('bug_report_id', $bug->getKey())->where('is_internal_note', false)
            ->whereIn('body', [...Templates::currentTexts(), ...$this->retiredTexts])->pluck('body')->all();
        if (count($posted) >= self::MAX_PER_REPORT) $errors[] = 'progress_reply_cap_reached';
        if (in_array(Templates::text($template), $posted, true)) $errors[] = 'template_already_posted';
        // Same rule as triage: automation does not write to a report the safety screen flags; a person handles it in the app.
        $own = BugReportComment::query()->where('bug_report_id', $bug->getKey())->where('author_user_id', $bug->getAttribute('reporter_user_id'))->pluck('body')->all();
        if (BugSafetyScreen::screenReport($bug->title, $bug->description, $own)['flagged']) $errors[] = 'safety_review_required';

        return $errors;
    }

    /** IDs and hashes only: the plan is stored in the execution record. */
    private function result(array $errors, array $p, ?string $status = null): array
    {
        $errors = array_values(array_unique($errors));
        $template = Templates::has($p['template'] ?? null) ? $p['template'] : null;
        $text = $template !== null ? Templates::text($template) : null;

        // `reply_text` is the fixed canned wording, so the dry-run shows exactly what will be posted.
        return ['ok' => $errors === [], 'errors' => $errors, 'bug_id' => is_int($p['bug_report_id'] ?? null) ? $p['bug_report_id'] : null,
            'status' => $status, 'template' => $template, 'reply_text' => $text, 'policy_blockers' => [],
            'digest' => hash('sha256', json_encode([self::OPERATION, $p['bug_report_id'] ?? null, $p['campus_id'] ?? null, $status, $template,
                $text !== null ? hash('sha256', $text) : null, $errors], JSON_THROW_ON_ERROR))];
    }
}
