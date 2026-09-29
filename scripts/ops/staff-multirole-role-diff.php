<?php
// READ-ONLY role-resolution diff for STAFF_MULTI_ROLE_V1 (in-app #299).
// Executed via `php artisan tinker --execute` (first line stripped by the workflow).
// Never flips config, never writes. Output: user ids + role/teacher/campus ids only (no PII).
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

$fmt = function (array $r): string {
    $c = $r['campus_ids'];
    sort($c);
    return $r['role'] . '/' . ($r['teacher_id'] ?? '-') . '/[' . implode(',', $c) . ']';
};

$q = \App\Models\User::query()->orderBy('id');
if (\Illuminate\Support\Facades\Schema::hasColumn('User', 'status')) {
    $q->where(function ($w) {
        $w->whereNull('status')->orWhereNotIn('status', ['inactive', 'suspended']);
    });
}

$total = 0; $changed = 0; $unexpected = 0; $dual = 0;
foreach ($q->cursor() as $u) {
    $total++;
    $id = (int) $u->id;
    $type = (string) $u->type;
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
    $isDual = !empty($byUser[$id]['director']) && !empty($byUser[$id]['teacher']);
    if ($isDual) { $dual++; }
    $b = $fmt($before); $a = $fmt($after);
    if ($b === $a) { continue; }
    $changed++;
    $tag = $isDual ? 'expected-dual' : 'UNEXPECTED';
    if (!$isDual) { $unexpected++; }
    echo "diff-user id=$id before=$b after=$a $tag\n";
}
echo "diff-active-staff=$total\n";
echo "diff-changed=$changed\n";
echo "diff-dual-holders=$dual\n";
echo "diff-unexpected=$unexpected\n";
echo 'diff-result=' . ($unexpected === 0 ? 'OK' : 'UNEXPECTED') . "\n";
