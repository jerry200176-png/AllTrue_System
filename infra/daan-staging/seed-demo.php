<?php
// Synthetic staging demo data. Piped to `php` inside the app container (see lifecycle/seed-demo.sh).
// Idempotent. All names/phones are fake; passwords come from env, never from the repo.
chdir('/var/www');
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Campus, Student, Teacher, User, UserCampus};
use Illuminate\Support\Facades\{DB, Hash};

if (!in_array(config('app.env'), ['staging', 'local'], true)) { fwrite(STDERR, "refusing: not staging\n"); exit(2); }

$campus = Campus::firstOrCreate(['code' => 'demo'], [
    'name' => '示範分校', 'active' => 1, 'Current' => 1, 'LineNotifyID' => '', 'Client_ID' => '',
    'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '',
    'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
]);

$user = function (string $login, string $name, string $type, string $envKey, int $admin) use ($campus) {
    $u = User::updateOrCreate(['LoginName' => $login], [
        'Name' => $name, 'type' => $type, 'status' => 'active', 'employment_type' => 'full_time',
        'PSW' => Hash::make((string) getenv($envKey)), 'MustChangePassword' => false,
        'phone' => '0900000000', 'TeachingSessionCount' => 0,
    ]);
    UserCampus::updateOrCreate(['UserID' => $u->id, 'CampusID' => $campus->id], ['Admin' => $admin, 'Approved' => 1]);
    return $u;
};
$director = $user('demo-director', '示範主任', 'D', 'DEMO_PW_DIRECTOR', 1);
$teacher  = $user('demo-teacher', '示範老師', 'T', 'DEMO_PW_TEACHER', 0);
Teacher::updateOrCreate(['T_Name' => '示範老師', 'CampusID' => $campus->id],
    ['Phone' => '0900000000', 'LineID' => '', 'TelegramID' => '', 'Notify_Token' => '', 'Enable' => 1, 'MDT' => now()]);

$out = ['campus_id' => $campus->id, 'director_id' => $director->id, 'teacher_id' => $teacher->id, 'students' => []];
foreach ([['王小明', '0911000001'], ['陳小美', '0911000002'], ['林小華', '0911000003']] as [$n, $ph]) {
    $s = Student::updateOrCreate(['name' => $n, 'CampusID' => $campus->id], [
        'ClassID' => 7, 'SchoolName' => '示範國小', 'LineID' => '', 'TelegramID' => '',
        'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => $ph,
        'parent_name' => $n . '家長', 'parent_phone' => $ph, 'status' => 'active',
    ]);
    $out['students'][] = ['id' => $s->id, 'name' => $n, 'phone' => $ph];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
