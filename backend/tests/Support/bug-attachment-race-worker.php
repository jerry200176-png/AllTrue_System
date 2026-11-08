<?php

// #5090: real models, shared FileLock and MySQL row locks; synthetic configuration only.
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/alltrueTestDatabase.php';
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
alltrue_boot_race_worker($data);

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Services\BugReportService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

config(['cache.stores.file.path' => $data['cache_root'], 'filesystems.disks.public' => [
    'driver' => 'local', 'root' => $data['storage_root'], 'throw' => true,
]]);
Carbon::setTestNow(Carbon::parse($data['clock']));
DB::purge();
DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
if (($data['mode'] ?? '') === 'pending-admission') {
    touch($data['started']);
    $retained = DB::transaction(function () use ($data) {
        BugReport::whereKey($data['bug_id'])->lockForUpdate()->firstOrFail();
        BugReportAttachment::create($data['attachment']);
        touch($data['ready']);
        $deadline = microtime(true) + 15;
        while (!file_exists($data['cleanup_attempt'])) {
            if (microtime(true) > $deadline) throw new RuntimeException('synthetic settlement barrier timed out');
            usleep(5000);
        }
        if (!empty($data['hold_until'])) {
            while (!file_exists($data['hold_until'])) {
                if (microtime(true) > $deadline) throw new RuntimeException('synthetic pending admission release timed out');
                usleep(5000);
            }
        } else {
            usleep(200000);
        }
        return Storage::disk('public')->exists($data['attachment']['stored_path']);
    });
    echo json_encode(['status' => 200, 'retained_before_commit' => $retained], JSON_THROW_ON_ERROR);
    exit;
}
$paused = false;
$signalled = false;
DB::connection()->beforeExecuting(function ($sql) use ($data, &$paused, &$signalled) {
    $sql = strtolower($sql);
    $insert = str_contains($sql, 'insert into `bug_report_attachments`');
    $parentLock = str_contains($sql, 'from `bug_reports`') && str_contains($sql, 'for update');
    if (!$signalled && ($parentLock || $insert) && !empty($data['before_admission'])) {
        $signalled = true;
        touch($data['before_admission']);
    }
    if (!$paused && $insert && !empty($data['pause'])) {
        $paused = true;
        touch($data['pause']['ready']);
        $deadline = microtime(true) + 15;
        while (!file_exists($data['pause']['release'])) {
            if (microtime(true) > $deadline) throw new RuntimeException('synthetic attachment barrier timed out');
            usleep(5000);
        }
    }
});

$lock = Cache::store('file')->lock($data['lock_name'], 60);
try {
    if (!$lock->get()) throw new RuntimeException('synthetic FileLock was not acquired');
    touch($data['started']);
    $bug = BugReport::findOrFail($data['bug_id']);
    $files = array_map(fn ($i) => UploadedFile::fake()->create($data['worker'].'-'.$i.'.png', 1, 'image/png'), range(1, 5));
    $errors = BugReportService::attachUploadedFiles($bug, $files);
    echo json_encode(['status' => 200, 'attachment_errors' => $errors, 'lease_acquired' => true], JSON_THROW_ON_ERROR);
} finally {
    $lock->release();
}
