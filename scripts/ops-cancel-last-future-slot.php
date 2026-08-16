<?php
declare(strict_types=1);

$slotId = (int) getenv('SLOT_ID');
$targetId = (int) getenv('TARGET_CLASS');
if (!in_array($targetId, [3230, 3231], true) || $slotId <= 0) {
    fwrite(STDERR, "CANCEL_SCOPE_MISMATCH\n");
    exit(1);
}

require '/home/admin/backend/vendor/autoload.php';
$app = require '/home/admin/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ClassSession;
use App\Models\StudentSignIn;
use App\Services\SessionDeductionService;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($slotId, $targetId): void {
    $session = ClassSession::query()->where('id', $slotId)->lockForUpdate()->first();
    if (!$session || (int) $session->StudentClassID !== $targetId) {
        throw new RuntimeException('CANCEL_GUARD_FAIL owner');
    }
    if (strtolower((string) $session->Status) !== 'scheduled') {
        throw new RuntimeException('CANCEL_GUARD_FAIL status');
    }
    $date = substr((string) $session->SessionDate, 0, 10);
    if ($date < '2026-08-19') {
        throw new RuntimeException('CANCEL_GUARD_FAIL date');
    }
    if (StudentSignIn::query()->where('ClassSessionID', $slotId)->whereNull('VoidedAt')->exists()) {
        throw new RuntimeException('CANCEL_HAS_SIGNIN');
    }
    $session->Status = 'cancelled';
    $session->save();
    SessionDeductionService::recomputeCounters($targetId);
    echo "SLOT_CANCELLED id={$slotId} date={$date}\n";
});
