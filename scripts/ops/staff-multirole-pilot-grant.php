<?php
// Idempotent grant/revoke of the director+teacher pair for ONE user on ONE campus (in-app #299 pilot).
// Env: PILOT_MODE=grant|revoke, PILOT_USER_ID, PILOT_CAMPUS_ID, PILOT_ACTOR (workflow actor, audit only).
// Executed via `php artisan tinker --execute` (first line stripped by the workflow).
$mode = getenv('PILOT_MODE');
$uid = (int) getenv('PILOT_USER_ID');
$cid = (int) getenv('PILOT_CAMPUS_ID');
$fail = function (string $why) { echo "pilot-result=REFUSED reason=$why\n"; };
if (!in_array($mode, ['grant', 'revoke'], true) || $uid < 1 || $cid < 1) { $fail('bad-input'); return; }
if (!\Illuminate\Support\Facades\Schema::hasTable('user_capability_grants')) { $fail('grants-table-missing'); return; }
$caps = [\App\Services\StaffCapabilityAuthorizer::CAP_DIRECTOR, \App\Services\StaffCapabilityAuthorizer::CAP_TEACHER];
$now = now();

if ($mode === 'grant') {
    $u = \App\Models\User::find($uid);
    if (!$u) { $fail('user-not-found'); return; }
    if (!in_array((string) $u->type, ['D', 'T'], true)) { $fail('user-type-not-D-or-T'); return; }
    if (in_array((string) $u->status, ['inactive', 'suspended'], true)) { $fail('user-not-active'); return; }
    if (!\Illuminate\Support\Facades\DB::table('Campus')->where('id', $cid)->exists()) { $fail('campus-not-found'); return; }
    $legacy = \App\Models\UserCampus::where('UserID', $uid)
        ->where(function ($w) { $w->where('Approved', true)->orWhereNull('Approved'); })
        ->pluck('CampusID')->map(fn ($x) => (int) $x)->all();
    if (!in_array($cid, $legacy, true)) { $fail('user-not-approved-on-campus'); return; }
    sort($legacy);
    echo 'legacy-campuses=[' . implode(',', $legacy) . "]\n";
    if ($legacy !== [$cid]) {
        echo "WARNING: user has other approved campuses; with the flag ON their campus scope narrows to campus $cid\n";
    }
    foreach ($caps as $cap) {
        $row = \App\Models\UserCapabilityGrant::where(['user_id' => $uid, 'capability' => $cap, 'campus_id' => $cid])->first();
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
\App\Models\SecurityAuditEvent::append('staff.capability.pilot.' . $mode, 'success',
    ['actor_type' => 'workflow', 'actor_id' => (string) getenv('PILOT_ACTOR'), 'subject_type' => 'user', 'subject_id' => $uid, 'campus_id' => $cid],
    ['source' => 'staff-multirole-activation', 'reason_code' => 'founder_pilot']);
echo "audit user_id=$uid campus_id=$cid mode=$mode actor=" . getenv('PILOT_ACTOR') . ' at=' . $now->toIso8601String() . "\n";
echo "pilot-result=SUCCESS\n";
