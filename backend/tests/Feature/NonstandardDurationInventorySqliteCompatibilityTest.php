<?php

namespace Tests\Feature;

use App\Services\Scheduling\NonstandardDurationInventoryReporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\CreatesApplication;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0A — SQLite driver-compatibility proof.
 *
 * The main correctness suite (NonstandardDurationInventoryReportTest) runs against the
 * repo's standard mysql-configured RefreshDatabase (phpunit.xml forces DB_CONNECTION=mysql
 * for the whole suite; that is what CI actually exercises). This file exists solely to
 * prove NonstandardDurationInventoryReporter's queries — in particular the raw
 * `whereRaw("l.minutes <> CASE WHEN sc.SessionDuration >= 1 THEN sc.SessionDuration ELSE 60 END")`
 * expression, the only hand-written SQL fragment in the reporter — execute correctly
 * against SQLite too, not just MySQL.
 *
 * Deliberately does NOT extend Tests\TestCase (which seeds the `Subject` table against
 * whatever the *current default* connection is during its own setUp() — before this test
 * gets a chance to switch the default to sqlite) and does NOT run the full 330-migration
 * suite against sqlite (slow, and several existing migrations use MySQL-only DDL that was
 * never meant to run under sqlite). Instead it builds the minimal handful of tables this
 * one reporter actually touches, directly, against an in-memory sqlite connection.
 */
class NonstandardDurationInventorySqliteCompatibilityTest extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::connection('sqlite')->create('Student', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('CampusID');
        });
        Schema::connection('sqlite')->create('StudentClass', function (Blueprint $t) {
            $t->increments('ID');
            $t->integer('StudentID');
            $t->integer('SessionCount')->nullable();
            $t->integer('SessionDuration')->nullable();
            $t->integer('RemainingSessions')->nullable();
            $t->integer('UsedSessions')->nullable();
            $t->integer('RemainingMinutes')->nullable();
            $t->integer('PurchasedMinutes')->nullable();
            $t->integer('PackageID')->nullable();
            $t->string('ScheduleMode', 16)->default('count');
        });
        Schema::connection('sqlite')->create('ClassSession', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('StudentClassID');
            $t->string('SessionDate');
            $t->string('StartTime');
            $t->string('EndTime');
            $t->string('Status', 16)->default('scheduled');
        });
        Schema::connection('sqlite')->create('session_deduction_ledger', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('student_class_id');
            $t->integer('class_session_id')->nullable();
            $t->string('event_type', 16);
            $t->string('source', 32);
            $t->integer('minutes')->nullable();
            $t->timestamps();
        });
        Schema::connection('sqlite')->create('schedules', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('student_course_id')->nullable();
            $t->string('schedule_date')->nullable();
            $t->string('start_time')->nullable();
            $t->string('type', 16)->nullable();
        });
        Schema::connection('sqlite')->create('course_packages', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('student_id');
            $t->integer('campus_id');
            $t->string('name', 128);
            $t->unsignedInteger('total_sessions');
        });
    }

    public function test_reporter_runs_correctly_against_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        DB::table('Student')->insert(['id' => 1, 'CampusID' => 7]);
        $scId = DB::table('StudentClass')->insertGetId([
            'StudentID' => 1, 'SessionCount' => 4, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'UsedSessions' => 0, 'ScheduleMode' => 'count',
        ]);
        // A normal (non-makeup) 180-minute session against a 120-minute contract.
        DB::table('ClassSession')->insert([
            'StudentClassID' => $scId, 'SessionDate' => '2026-06-01',
            'StartTime' => '16:00:00', 'EndTime' => '19:00:00', 'Status' => 'attended',
        ]);
        // A partial-minute ledger row (180 != 120 per-session) — exercises the whereRaw
        // CASE WHEN expression under sqlite.
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $scId, 'class_session_id' => 1, 'event_type' => 'deduct',
            'source' => 'attendance', 'minutes' => 180,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, true);

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'sanity: still on sqlite when the report ran');
        $this->assertSame(1, $report['b1_contract_schedule_mismatch']['mismatched_normal_sessions']);
        $this->assertSame(1, $report['b1_contract_schedule_mismatch']['affected_courses']);
        $this->assertSame(1, $report['b2_minute_ledger_adoption']['courses_with_minutes_ne_per_session'], 'whereRaw CASE WHEN expression must work under sqlite');
        $this->assertFalse($report['b6_mutable_default_risk']['authoritative_branch_or_system_setting_found']);
    }

    public function test_reporter_is_read_only_under_sqlite(): void
    {
        DB::table('Student')->insert(['id' => 2, 'CampusID' => 7]);
        $scId = DB::table('StudentClass')->insertGetId([
            'StudentID' => 2, 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'ScheduleMode' => 'count',
        ]);
        $before = (array) DB::table('StudentClass')->where('ID', $scId)->first();
        $beforeCount = DB::table('StudentClass')->count();

        app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, true);

        $after = (array) DB::table('StudentClass')->where('ID', $scId)->first();
        $this->assertSame($before, $after);
        $this->assertSame($beforeCount, DB::table('StudentClass')->count());
    }
}
