<?php

namespace Tests\Support;

use App\Models\{AuthToken, ClassSession, Invoice, InvoiceItem, Student};
use App\Services\Billing\ExistingCourseCreditPreview;
use Illuminate\Support\Facades\DB;

/** Synthetic paid-header, already-used targets and fully reserved source; no production identifiers. */
trait ExistingCourseCreditFixtures
{
    use SubjectCreditFixtures;

    protected function allocation(): array
    {
        [$token, $source, $target, $invoice] = $this->fixture();
        InvoiceItem::where('InvoiceID', $invoice->id)->delete();
        $debt = Invoice::where('StudentClassID', $target->ID)->firstOrFail();
        InvoiceItem::where('InvoiceID', $debt->id)->delete(); $debt->delete();
        $target->forceFill(['SessionCount' => 1, 'Charge' => 1500, 'UsedSessions' => 1, 'RemainingSessions' => 0])->save();
        $this->appendClassSession($target, '2026-09-10', 'attended');
        $other = $target->replicate();
        $other->forceFill(['SubjectID' => 970003, 'SessionCount' => 4, 'Charge' => 6000, 'UsedSessions' => 3, 'RemainingSessions' => 1])->save();
        for ($i = 0; $i < 3; $i++) $this->appendClassSession($other, '2026-09-' . (11 + $i), 'attended');
        ClassSession::where('Status', 'attended')->update(['EndTime' => '10:00']);
        for ($i = 0; $i < 6; $i++) DB::table('ClassSession')->insert(['StudentClassID' => $source->ID,
            'SessionDate' => '2026-10-' . sprintf('%02d', $i + 1), 'StartTime' => '08:00', 'EndTime' => '10:00', 'Status' => 'scheduled']);
        $ids = ClassSession::where('StudentClassID', $source->ID)->where('Status', 'scheduled')->orderByDesc('id')->limit(5)->pluck('id')->all(); sort($ids);
        $targets = [(int) $target->ID => [1, 1, 1500], (int) $other->ID => [4, 3, 6000]];
        $campus = (int) Student::findOrFail($source->StudentID)->CampusID;
        $input = ['target_course_ids' => array_keys($targets), 'released_session_ids' => $ids];
        $preview = app(ExistingCourseCreditPreview::class)->run($source, $input, [$campus]);
        return [$source, $targets, $input + ['state_hash' => $preview['state_hash'], 'idempotency_key' => 'isolated-group', 'reason' => 'existing lesson funding'],
            (int) AuthToken::where('token', $token)->value('user_id'), $campus];
    }

}
