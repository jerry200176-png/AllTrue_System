<?php
// Idempotent grant/revoke of the director+teacher pair for ONE user on ONE campus (in-app #299 pilot).
// Env: PILOT_MODE=grant|revoke, PILOT_DRY_RUN=1 (no writes), PILOT_USER_ID, PILOT_CAMPUS_ID,
//      PILOT_ACTOR (audit only), PILOT_NARROW_FROM (sorted comma campus ids, from confirm string),
//      PILOT_FLAG_ON_ACK=1 (confirm string carried :flag=on).
// Executed via `php artisan tinker --execute` (first line stripped by the workflow).
$mode = (string) getenv('PILOT_MODE');
$dry = getenv('PILOT_DRY_RUN') === '1';
$uid = (int) getenv('PILOT_USER_ID');
$cid = (int) getenv('PILOT_CAMPUS_ID');
$fail = function (string $why) use ($dry) { echo 'pilot-result=REFUSED reason=' . $why . ($dry ? ' (dry-run)' : '') . "\n"; };
try {
    if (!in_array($mode, ['grant', 'revoke'], true) || $uid < 1 || $cid < 1) { $fail('bad-input'); return; }
    if (!\Illuminate\Support\Facades\Schema::hasTable('user_capability_grants')) { $fail('grants-table-missing'); return; }
    $caps = [\App\Services\StaffCapabilityAuthorizer::CAP_DIRECTOR, \App\Services\StaffCapabilityAuthorizer::CAP_TEACHER];

    // Never change grants under a live flag unless the operator said so explicitly.
    $flagOn = (bool) config('staff_capabilities.multi_role_v1_enabled', false);
    echo 'effective-flag=' . ($flagOn ? 'true' : 'false') . "\n";
    if ($flagOn && getenv('PILOT_FLAG_ON_ACK') !== '1') { $fail('flag-effectively-on-needs-:flag=on-suffix'); return; }

    if ($mode === 'grant') {
        $u = \App\Models\User::find($uid);
        if (!$u) { $fail('user-not-found'); return; }
        if (!in_array((string) $u->type, ['D', 'T'], true)) { $fail('user-type-not-D-or-T'); return; }
        if (in_array((string) $u->status, ['inactive', 'suspended'], true)) { $fail('user-not-active'); return; }
        if (!\Illuminate\Support\Facades\DB::table('Campus')->where('id', $cid)->exists()) { $fail('campus-not-found'); return; }
        $legacy = \App\Models\UserCampus::where('UserID', $uid)
            ->where(function ($w) { $w->where('Approved', true)->orWhereNull('Approved'); })
            ->pluck('CampusID')->map(fn ($x) => (int) $x)->unique()->sort()->values()->all();
        if (!in_array($cid, $legacy, true)) { $fail('user-not-approved-on-campus'); return; }
        $legacyCsv = implode(',', $legacy);
        echo "legacy-campuses=[$legacyCsv]\n";
        if ($legacy !== [$cid] && (string) getenv('PILOT_NARROW_FROM') !== $legacyCsv) {
            echo "NARROWING: with the flag ON this user's campus scope shrinks from [$legacyCsv] to [$cid]\n";
            echo "expected-confirm-suffix=:narrow_from=$legacyCsv\n";
            $fail('campus-narrowing-not-acknowledged');
            return;
        }
    }

    if ($dry) {
        foreach ($caps as $cap) {
            $row = \App\Models\UserCapabilityGrant::where(['user_id' => $uid, 'capability' => $cap, 'campus_id' => $cid])->first();
            $state = !$row ? ($mode === 'grant' ? 'would-create' : 'nothing-to-revoke')
                : ($row->revoked_at === null ? ($mode === 'grant' ? 'already-active' : 'would-revoke') : ($mode === 'grant' ? 'would-reactivate' : 'already-revoked'));
            echo "dry-run capability=$cap $state\n";
        }
        echo "pilot-result=DRY-RUN-OK\n";
        return;
    }

    $now = now();
    $correlation = (string) \Illuminate\Support\Str::uuid();
    \Illuminate\Support\Facades\DB::transaction(function () use ($mode, $caps, $uid, $cid, $now, $correlation) {
        if ($mode === 'grant') {
            foreach ($caps as $cap) {
                $row = \App\Models\UserCapabilityGrant::where(['user_id' => $uid, 'capability' => $cap, 'campus_id' => $cid])->lockForUpdate()->first();
                if ($row && $row->revoked_at === null) { echo "grant capability=$cap already-active\n"; continue; }
                if ($row) {
                    $row->update(['revoked_at' => null, 'granted_at' => $now]);
                    echo "grant capability=$cap reactivated\n";
                } else {
                    \App\Models\UserCapabilityGrant::create(['user_id' => $uid, 'capability' => $cap, 'campus_id' => $cid, 'granted_at' => $now, 'revoked_at' => null]);
                    echo "grant capability=$cap created\n";
                }
            }
        } else {
            foreach ($caps as $cap) {
                $n = \App\Models\UserCapabilityGrant::where(['user_id' => $uid, 'capability' => $cap, 'campus_id' => $cid])
                    ->whereNull('revoked_at')->update(['revoked_at' => $now]);
                echo "revoke capability=$cap rows=$n\n";
            }
        }
        // append() swallows its own errors, so verify the row landed; otherwise roll everything back.
        $eventType = 'staff.capability.pilot.' . $mode;
        \App\Models\SecurityAuditEvent::append($eventType, 'success',
            ['correlation_id' => $correlation, 'actor_type' => 'workflow', 'actor_id' => (string) getenv('PILOT_ACTOR'),
             'subject_type' => 'user', 'subject_id' => $uid, 'campus_id' => $cid],
            ['source' => 'staff-multirole-activation', 'reason_code' => 'founder_pilot']);
        if (!\Illuminate\Support\Facades\DB::table('security_audit_events')->where('correlation_id', $correlation)->where('event_type', $eventType)->exists()) {
            throw new \RuntimeException('audit-event-not-written');
        }
    });
    echo "audit user_id=$uid campus_id=$cid mode=$mode actor=" . getenv('PILOT_ACTOR') . ' at=' . $now->toIso8601String() . "\n";
    echo "pilot-result=SUCCESS\n";
} catch (\Throwable $e) {
    echo 'pilot-result=FAILED reason=' . get_class($e) . ':' . $e->getMessage() . " (transaction rolled back)\n";
}
