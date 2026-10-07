<?php

namespace Tests\Feature;

use App\Services\Scheduling\CommitmentReasonCodes;
use App\Services\Scheduling\EnsureSessionHorizonService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR-006 Phase 1B EnsureSessionHorizon — default-off / dry-run; gated execute. */
class EnsureSessionHorizonTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = Carbon::parse('2026-07-13', 'Asia/Taipei');
        putenv('FEATURE_ENSURE_SESSION_HORIZON=false');
        $_ENV['FEATURE_ENSURE_SESSION_HORIZON'] = 'false';
        $_SERVER['FEATURE_ENSURE_SESSION_HORIZON'] = 'false';
        config(['feature_flags.values.FEATURE_ENSURE_SESSION_HORIZON' => false]);
    }

    public function test_2833_actual_cache_preserves_service_gate_and_worker_snapshot(): void
    {
        $cache = sys_get_temp_dir() . '/alltrue-2833-config-' . bin2hex(random_bytes(8)) . '.php';
        $names = ['APP_CONFIG_CACHE', 'FEATURE_ENSURE_SESSION_HORIZON', 'FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_1'];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }
        try {
            foreach (['APP_CONFIG_CACHE' => $cache, 'FEATURE_ENSURE_SESSION_HORIZON' => 'true', 'FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_1' => 'false'] as $name => $value) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
            $this->assertSame(0, Artisan::call('config:cache'));
            \Illuminate\Container\Container::setInstance($this->app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($this->app['db']);
            \Illuminate\Database\Eloquent\Model::setEventDispatcher($this->app['events']);
            $this->assertFileExists($cache);
            (new \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($this->app);
            (new \Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($this->app);
            $this->assertTrue($this->app->configurationIsCached());
            $this->assertSame(require $cache, config()->all());
            $sc = $this->explicitCourse(remaining: 8);
            $service = app(EnsureSessionHorizonService::class);
            $before = DB::table('ClassSession')->count();
            $dto = $service->ensure($sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE);
            $this->assertSame('FEATURE_FLAG_OFF', $dto['ensure']['primary_reason']);
            $this->assertSame($before, DB::table('ClassSession')->count());
            // A long-lived reader retains its configuration, even if process values change.
            putenv('FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_1=true');
            $_ENV['FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_1'] = $_SERVER['FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_1'] = 'true';
            $dto = $service->ensure($sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE);
            $this->assertSame('FEATURE_FLAG_OFF', $dto['ensure']['primary_reason']);
            $this->assertSame($before, DB::table('ClassSession')->count());
            $this->assertSame(0, Artisan::call('config:cache'));
            \Illuminate\Container\Container::setInstance($this->app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($this->app['db']);
            \Illuminate\Database\Eloquent\Model::setEventDispatcher($this->app['events']);
            (new \Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($this->app);
            $dto = $service->ensure($sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE);
            $this->assertTrue($dto['ensure']['ok']);
            $this->assertGreaterThan(0, $dto['ensure']['created_count']);
            $sc = $this->explicitCourse(remaining: 8); // A separate course still needs materialization.
            $this->app['env'] = 'production'; // Still the isolated test DB; only exercise the deny gate.
            $before = DB::table('ClassSession')->count();
            $dto = $service->ensure($sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE);
            $this->assertSame('PRODUCTION_EXECUTE_REQUIRES_GO', $dto['ensure']['primary_reason']);
            $this->assertSame($before, DB::table('ClassSession')->count());
        } finally {
            $this->app['env'] = 'testing';
            foreach ($saved as $name => [$process, $envValue, $serverValue]) {
                $process === false ? putenv($name) : putenv($name . '=' . $process);
                unset($_ENV[$name], $_SERVER[$name]);
                if ($envValue !== null) $_ENV[$name] = $envValue;
                if ($serverValue !== null) $_SERVER[$name] = $serverValue;
            }
            if (is_file($cache)) unlink($cache);
        }
    }

    public function test_dry_run_does_not_write_and_lists_candidates(): void
    {
        $sc = $this->explicitCourse(remaining: 8);
        $before = DB::table('ClassSession')->count();

        $dto = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_DRY_RUN
        );

        $this->assertTrue($dto['ensure']['ok']);
        $this->assertFalse($dto['ensure']['blocked']);
        $this->assertSame('DRY_RUN_OK', $dto['ensure']['primary_reason']);
        $this->assertGreaterThan(0, count($dto['ensure']['candidates']));
        $this->assertTrue($dto['meta']['read_only']);
        $this->assertSame($before, DB::table('ClassSession')->count());
    }

    public function test_execute_blocked_when_feature_flag_off(): void
    {
        $sc = $this->explicitCourse(remaining: 8);
        $before = DB::table('ClassSession')->count();

        $dto = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE
        );

        $this->assertSame('FEATURE_FLAG_OFF', $dto['ensure']['primary_reason']);
        $this->assertTrue($dto['ensure']['blocked']);
        $this->assertSame($before, DB::table('ClassSession')->count());
    }

    public function test_execute_in_production_returns_requires_go_even_if_flag_off(): void
    {
        $prev = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $sc = $this->explicitCourse(remaining: 8);
            $before = DB::table('ClassSession')->count();
            $dto = app(EnsureSessionHorizonService::class)->ensure(
                $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE
            );
            $this->assertSame('PRODUCTION_EXECUTE_REQUIRES_GO', $dto['ensure']['primary_reason']);
            $this->assertTrue($dto['ensure']['blocked']);
            $this->assertSame($before, DB::table('ClassSession')->count());
        } finally {
            $this->app['env'] = $prev;
        }
    }

    public function test_command_execute_exits_nonzero_when_blocked(): void
    {
        // High remaining so execute reaches feature-flag gate (not entitlement shortage).
        $sc = $this->explicitCourse(remaining: 40);
        Carbon::setTestNow($this->today);
        try {
            $code = Artisan::call('sessions:ensure-horizon', [
                'student_class_id' => $sc,
                '--as-of' => '2026-07-13',
                '--execute' => true,
                '--summary' => true,
            ]);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame(1, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('blocked=yes', $out);
        $this->assertStringContainsString('FEATURE_FLAG_OFF', $out);
    }

    public function test_dormant_explicit_is_blocked_from_ensure(): void
    {
        $sc = $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 8]);
        foreach (['2026-06-01', '2026-06-08'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }
        $dto = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_DRY_RUN
        );
        $this->assertTrue($dto['ensure']['blocked']);
        $this->assertSame(CommitmentReasonCodes::SKIP_DORMANT, $dto['ensure']['primary_reason']);
        $this->assertFalse($dto['preview']['auto_ensure_eligible']);
    }

    public function test_execute_creates_sessions_when_flag_on_non_production(): void
    {
        putenv('FEATURE_ENSURE_SESSION_HORIZON=true');
        $_ENV['FEATURE_ENSURE_SESSION_HORIZON'] = 'true';
        $_SERVER['FEATURE_ENSURE_SESSION_HORIZON'] = 'true';
        config(['feature_flags.values.FEATURE_ENSURE_SESSION_HORIZON' => true]);

        $sc = $this->explicitCourse(remaining: 8);
        $before = DB::table('ClassSession')->count();

        $dto = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE
        );

        $this->assertTrue($dto['ensure']['ok']);
        $this->assertFalse($dto['ensure']['blocked']);
        $this->assertGreaterThan(0, $dto['ensure']['created_count']);
        $this->assertSame(
            $before + $dto['ensure']['created_count'],
            DB::table('ClassSession')->count()
        );
        $note = (string) DB::table('ClassSession')
            ->whereIn('id', $dto['ensure']['created_session_ids'])->value('Note');
        $this->assertStringContainsString('adr006-ensure', $note);

        // Idempotent re-run
        $dto2 = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE
        );
        $this->assertSame(0, $dto2['ensure']['created_count']);
    }

    public function test_execute_skips_candidate_partially_overlapping_another_contract(): void
    {
        // #380 family: a partial overlap used to throw inside the batch and fail the whole ensure run.
        putenv('FEATURE_ENSURE_SESSION_HORIZON=true');
        $_ENV['FEATURE_ENSURE_SESSION_HORIZON'] = 'true';
        $_SERVER['FEATURE_ENSURE_SESSION_HORIZON'] = 'true';
        config(['feature_flags.values.FEATURE_ENSURE_SESSION_HORIZON' => true]);

        $sc = $this->explicitCourse(remaining: 8);
        $first = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_DRY_RUN
        )['ensure']['candidates'][0]['date'];
        $other = $this->course(['StudentID' => (int) DB::table('StudentClass')->where('ID', $sc)->value('StudentID')]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $other, 'SessionDate' => $first, 'StartTime' => '17:00:00',
            'EndTime' => '18:00:00', 'Status' => 'scheduled', 'Note' => '',
        ]);

        $dto = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE
        );

        $this->assertTrue($dto['ensure']['ok']);
        $this->assertGreaterThan(0, $dto['ensure']['created_count']);
        $this->assertFalse(DB::table('ClassSession')->where('StudentClassID', $sc)->whereDate('SessionDate', $first)->exists());
    }

    public function test_shared_pool_shortage_warns_but_does_not_block_planned_sessions(): void
    {
        putenv('FEATURE_ENSURE_SESSION_HORIZON=true');
        $_ENV['FEATURE_ENSURE_SESSION_HORIZON'] = 'true';
        $_SERVER['FEATURE_ENSURE_SESSION_HORIZON'] = 'true';
        config(['feature_flags.values.FEATURE_ENSURE_SESSION_HORIZON' => true]);

        $owner = 98222;
        DB::table('Student')->insert([
            'id' => $owner, 'name' => 'Pkg', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $pkgId = (int) DB::table('course_packages')->insertGetId([
            'student_id' => $owner, 'campus_id' => 1, 'name' => 'ES pool',
            'total_sessions' => 10, 'remaining_sessions' => 1, 'used_sessions' => 0,
            'stop' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sc = $this->explicitCourse(remaining: 0, packageId: $pkgId);
        $before = DB::table('ClassSession')->count();

        $dto = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_EXECUTE
        );

        $this->assertTrue($dto['ensure']['ok']);
        $this->assertFalse($dto['ensure']['blocked']);
        $this->assertTrue($dto['preview']['pool_projection']['renewal_warning']);
        $this->assertGreaterThan(0, $dto['ensure']['created_count']);
        $this->assertGreaterThan($before, DB::table('ClassSession')->count());
    }

    public function test_legacy_not_ensure_eligible(): void
    {
        $sc = $this->course([]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }
        $dto = app(EnsureSessionHorizonService::class)->ensure(
            $sc, null, $this->today, null, EnsureSessionHorizonService::MODE_DRY_RUN
        );
        $this->assertSame(CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE, $dto['ensure']['primary_reason']);
        $this->assertTrue($dto['ensure']['blocked']);
    }

    public function test_command_default_is_dry_run(): void
    {
        $sc = $this->explicitCourse(remaining: 4);
        $before = DB::table('ClassSession')->count();
        Carbon::setTestNow($this->today);
        try {
            $this->assertSame(0, Artisan::call('sessions:ensure-horizon', [
                'student_class_id' => $sc, '--as-of' => '2026-07-13', '--summary' => true,
            ]));
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame($before, DB::table('ClassSession')->count());
        $this->assertStringContainsString('dry_run', Artisan::output());
    }

    private function explicitCourse(int $remaining, ?int $packageId = null): int
    {
        $over = ['week' => 1, 'time' => '16:00', 'RemainingSessions' => $remaining];
        if ($packageId !== null) {
            $over['PackageID'] = $packageId;
        }
        $sc = $this->course($over);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }

        return $sc;
    }

    /** @param array<string,mixed> $over */
    private function course(array $over): int
    {
        $studentId = 98300 + random_int(1, 8999);
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'P1B Test', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $base = [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'EndDate' => '2026-12-31',
            'SessionCount' => 20, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ];
        foreach (['week', 'time', 'PackageID', 'RemainingSessions'] as $k) {
            if (array_key_exists($k, $over)) {
                $base[$k] = $over[$k];
                unset($over[$k]);
            }
        }

        return (int) DB::table('StudentClass')->insertGetId(array_merge($base, $over));
    }

    private function sess(int $sc, string $date, string $start, string $end): void
    {
        DB::table('ClassSession')->insert([
            'StudentClassID' => $sc, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => 'attended', 'Note' => '',
            'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:00:00',
        ]);
    }
}
