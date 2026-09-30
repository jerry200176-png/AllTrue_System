<?php
// READ-ONLY role-resolution diff for STAFF_MULTI_ROLE_V1 (in-app #299).
// Executed via `php artisan tinker --execute` (first line stripped by the workflow).
// Never flips config, never writes. Output: user ids + role/teacher/campus ids only (no PII).
// Inactive/suspended users are INCLUDED and tagged (their tokens may still exist).
$authorizer = app(\App\Services\StaffCapabilityAuthorizer::class);
$hasGrants = \Illuminate\Support\Facades\Schema::hasTable('user_capability_grants');
echo 'grants-table-exists=' . ($hasGrants ? 'true' : 'false') . "\n";

$byUser = [];
$byCapCampus = [];
if ($hasGrants) {
    foreach (\App\Models\UserCapabilityGrant::query()->whereNull('revoked_at')->get(['user_id', 'capability', 'campus_id']) as $g) {
        $byUser[(int) $g->user_id][(string) $g->capability][] = (int) $g->campus_id;
        $k = $g->capability . '@' . (int) $g->campus_id;
        $byCapCampus[$k] = ($byCapCampus[$k] ?? 0) + 1;
    }
}
ksort($byCapCampus);
foreach ($byCapCampus as $k => $n) {
    echo "grant-summary capability@campus=$k users=$n\n";
}
// Dual holders come from the grants themselves (a grant for a missing user id still counts).
$dualIds = [];
foreach ($byUser as $uid => $caps) {
    if (!empty($caps['director']) && !empty($caps['teacher'])) { $dualIds[] = (int) $uid; }
}
sort($dualIds);

$fmt = function (array $r): string {
    $c = $r['campus_ids'];
    sort($c);
    return $r['role'] . '/' . ($r['teacher_id'] ?? '-') . '/[' . implode(',', $c) . ']';
};

$total = 0; $changed = 0; $unexpected = 0; $narrowed = 0; $inactiveTotal = 0;
foreach (\App\Models\User::query()->orderBy('id')->cursor() as $u) {
    $total++;
    $id = (int) $u->id;
    $type = (string) $u->type;
    $inactive = in_array((string) $u->status, ['inactive', 'suspended'], true);
    if ($inactive) { $inactiveTotal++; }
    // flag OFF: legacy mapping, mirrors AttachAuthUser else-branch
    $role = match ($type) { 'S' => 'super_admin', 'T' => 'teacher', 'U' => 'pending', default => 'director' };
    $campus = \App\Models\UserCampus::where('UserID', $id)
        ->where(function ($w) { $w->where('Approved', true)->orWhereNull('Approved'); })
        ->pluck('CampusID')->map(fn ($x) => (int) $x)->all();
    $before = ['role' => $role, 'teacher_id' => $role === 'teacher' ? $id : null, 'campus_ids' => $campus];
    // flag ON: resolver called directly; AttachAuthUser skips it for type S
    $after = $type === 'S' ? $before : $authorizer->resolve($u, null);
    if ($after['context_denied'] ?? false) {
        $after = ['role' => 'forbidden', 'teacher_id' => null, 'campus_ids' => []];
    }
    $isDual = in_array($id, $dualIds, true);
    $b = $fmt($before); $a = $fmt($after);
    if ($b === $a) { continue; }
    $changed++;
    $tags = [$isDual ? 'expected-dual' : 'UNEXPECTED'];
    if (!$isDual) { $unexpected++; }
    if ($inactive) { $tags[] = 'inactive'; }
    if ($isDual && array_diff($before['campus_ids'], $after['campus_ids']) !== []) { $narrowed++; $tags[] = 'NARROWED'; }
    echo "diff-user id=$id before=$b after=$a " . implode(' ', $tags) . "\n";
}
echo "diff-users-scanned=$total\n";
echo "diff-users-inactive=$inactiveTotal\n";
echo "diff-changed=$changed\n";
echo 'diff-dual-holders=' . count($dualIds) . "\n";
echo 'diff-dual-user-ids=' . implode(',', $dualIds) . "\n";
echo "diff-narrowed-dual=$narrowed\n";
echo "diff-unexpected=$unexpected\n";
echo 'diff-result=' . ($unexpected === 0 ? 'OK' : 'UNEXPECTED') . "\n";
