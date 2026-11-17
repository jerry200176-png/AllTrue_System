<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\BuildsMoneyFixtures;
use Tests\TestCase;

/**
 * 當月學收 (2026-11-17 review): the page and the CSV list the same rows. A monthly package row is paged once (it was
 * appended to every page), its member contracts are not exported as separate rows, and 輔導 courses are never tuition.
 */
class BranchMonthlyTuitionRowsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;
    use TestTrait;

    private const AUG = ['2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24']; // 4 x 1500 = 6000

    private static ?string $previousSeed = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$previousSeed = getenv('ERIS_SEED') === false ? null : getenv('ERIS_SEED');
        if (self::$previousSeed === null) {
            putenv('ERIS_SEED=5458');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$previousSeed === null) {
            putenv('ERIS_SEED');
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30', 'Asia/Taipei'));
        DB::table('Subject')->insertOrIgnore([['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headers(): array
    {
        $user = User::create(['LoginName' => 'dir-rows-' . uniqid() . '@test.com', 'Name' => 'D', 'PSW' => 'secret', 'type' => 'A', 'phone' => 900005458]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => $t = bin2hex(random_bytes(16)), 'expires_at' => now()->addDay()]);

        return ['Authorization' => 'Bearer ' . $t, 'Accept' => 'application/json'];
    }

    private function monthlyPackage(int $rate = 700): CoursePackage
    {
        $student = Student::create(['name' => 'p' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return CoursePackage::create(['student_id' => $student->id, 'campus_id' => 1, 'name' => 'P' . uniqid(), 'billing_mode' => CoursePackage::BILLING_MODE_MONTHLY,
            'rate' => $rate, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
            'total_sessions' => 0, 'remaining_sessions' => 0, 'used_sessions' => 0]);
    }

    /** @return list<list<string>> data lines of the CSV (BOM and header dropped) */
    private function csvLines(array $headers): array
    {
        $res = $this->withHeaders($headers)->get('/api/v1/finance/branch-monthly-tuition/export?branch_id=1&year=2026&month=8');
        $res->assertOk();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($res->streamedContent(), "\xEF\xBB\xBF"))));

        return array_map('str_getcsv', array_slice($lines, 1));
    }

    public function test_every_row_is_on_exactly_one_page(): void
    {
        $this->limitTo(15)->forAll(Generators::choose(0, 6), Generators::choose(0, 3), Generators::choose(1, 4))
            ->then(function (int $courses, int $packages, int $perPage) {
                DB::beginTransaction();
                try {
                    for ($i = 0; $i < $courses; $i++) {
                        $this->course([], [], self::AUG);
                    }
                    for ($i = 0; $i < $packages; $i++) {
                        $this->monthlyPackage();
                    }
                    $headers = $this->headers();
                    $seen = [];
                    $page = 1;
                    do {
                        $res = $this->withHeaders($headers)
                            ->getJson("/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=8&per_page={$perPage}&page={$page}")->assertOk();
                        foreach ($res->json('data') as $row) {
                            $seen[] = $row['row_type'] . '-' . ($row['student_class_id'] ?? $row['package_id']);
                        }
                        $last = (int) $res->json('meta.last_page');
                    } while (++$page <= $last);

                    $this->assertSame($courses + $packages, (int) $res->json('meta.total'));
                    $this->assertCount($courses + $packages, $seen, "{$courses} courses + {$packages} packages at {$perPage} per page");
                    $this->assertSame($seen, array_values(array_unique($seen)), 'no row is repeated on another page');
                    $this->assertSame(max(1, (int) ceil(($courses + $packages) / $perPage)), $last);
                } finally {
                    DB::rollBack();
                }
            });
    }

    public function test_per_page_zero_is_one_row_per_page_not_a_crash(): void
    {
        $this->course([], [], self::AUG);
        $res = $this->withHeaders($this->headers())->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=8&per_page=0')->assertOk();
        $this->assertSame([1, 1], [(int) $res->json('meta.per_page'), (int) $res->json('meta.last_page')]);
    }

    public function test_csv_lists_the_page_rows_with_the_same_total_and_a_padded_month_in_its_name(): void
    {
        $this->course([], [], self::AUG); // 4 x 1500 = 6000
        $pkg = $this->monthlyPackage(700);
        $this->course([], ['PackageID' => $pkg->id, 'Rate' => 1500], self::AUG, Student::find($pkg->student_id)); // member: 4 x 700 on the package row
        $headers = $this->headers();

        $page = $this->withHeaders($headers)->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=8')->assertOk();
        $this->assertSame(2, (int) $page->json('meta.total'));
        $this->assertSame(8800, (int) $page->json('summary.total_tuition'));

        $csv = $this->csvLines($headers);
        $this->assertCount(2, $csv, 'one course row and one package row; the member is not a row of its own');
        $this->assertSame(['一對一', '月結套裝'], array_column($csv, 3));
        $this->assertSame(8800, (int) array_sum(array_column($csv, 6)), 'the CSV 月學收 adds up to the page 學收合計');

        $disposition = $this->withHeaders($headers)->get('/api/v1/finance/branch-monthly-tuition/export?branch_id=1&year=2026&month=8')
            ->headers->get('Content-Disposition');
        $this->assertStringContainsString('202608', (string) $disposition);
    }

    public function test_tutoring_course_is_not_tuition(): void
    {
        $this->course([], [], self::AUG); // 6000
        $tutoring = $this->course([], ['ClassType' => 'tutoring', 'Rate' => 500], self::AUG);
        $headers = $this->headers();

        $page = $this->withHeaders($headers)->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=8')->assertOk();
        $this->assertNotContains((int) $tutoring->ID, array_map('intval', array_column($page->json('data'), 'student_class_id')));
        $this->assertSame([1, 6000, 4], [(int) $page->json('meta.total'), (int) $page->json('summary.total_tuition'), (int) $page->json('summary.total_sessions')]);
        $this->assertCount(1, $this->csvLines($headers));
    }
}
