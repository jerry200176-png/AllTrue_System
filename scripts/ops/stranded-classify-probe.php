<?php
/** Read-only #1062/#1130 probe. Usage: php probe.php /home/admin/backend — no writes, no names. */
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
$backend = rtrim((string)($argv[1] ?? getcwd()), '/');
if (!is_file($backend.'/artisan')) { fwrite(STDERR, "bad backend\n"); exit(1); }
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$asOf = date('Y-m-d');
$base = function () {
  return DB::table('StudentClass as sc')
    ->where(fn($w) => $w->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
    ->where('sc.ScheduleMode', 'count')->where('sc.RemainingSessions', '>', 0)
    ->whereNotExists(function ($q) {
      $q->select(DB::raw(1))->from('ClassSession as cs')
        ->whereColumn('cs.StudentClassID', 'sc.ID')->whereRaw('cs.SessionDate >= CURDATE()')
        ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
    });
};
$ids = (clone $base())->pluck('sc.ID');
$sessions = (int)(clone $base())->sum('sc.RemainingSessions');
$students = (int)(clone $base())->distinct('sc.StudentID')->count('sc.StudentID');
$totalAmt = (float)(clone $base())->sum(DB::raw('sc.RemainingSessions * COALESCE(sc.Rate, 0)'));
$activeIds = DB::table('ClassSession')->whereIn('StudentClassID', $ids)
  ->where('SessionDate', '>=', date('Y-m-d', strtotime('-21 days')))->distinct()->pluck('StudentClassID');
$dormantIds = $ids->diff($activeIds);
$sumAmt = fn($set) => $set->isEmpty() ? 0.0 : (float)DB::table('StudentClass')->whereIn('ID', $set)->sum(DB::raw('RemainingSessions * COALESCE(Rate, 0)'));
$sumSess = fn($set) => $set->isEmpty() ? 0 : (int)DB::table('StudentClass')->whereIn('ID', $set)->sum('RemainingSessions');
$mdate = function (int $h) use ($base) {
  $since = date('Y-m-d H:i:s', time() - $h * 3600);
  return ['courses'=>(int)(clone $base())->where('sc.MDate','>=',$since)->count(),
          'sessions'=>(int)(clone $base())->where('sc.MDate','>=',$since)->sum('sc.RemainingSessions'), 'since'=>$since];
};
$pay = function (int $h) use ($ids) {
  $since = date('Y-m-d H:i:s', time() - $h * 3600);
  if ($ids->isEmpty()) return ['payments'=>0,'courses'=>0,'since'=>$since];
  try {
    $q = DB::table('Payment as p')->join('Invoice as i','i.id','=','p.InvoiceID')
      ->whereIn('i.StudentClassID', $ids)->where('p.PaidAt','>=',$since);
    return ['payments'=>(int)(clone $q)->count(),'courses'=>(int)(clone $q)->distinct('i.StudentClassID')->count('i.StudentClassID'),'since'=>$since];
  } catch (Throwable $e) { return ['payments'=>null,'courses'=>null,'since'=>$since,'error'=>'query_failed']; }
};
$attGroups = (int)DB::table(DB::raw("(SELECT 1 FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID WHERE LOWER(cs.Status) IN ('attended','completed') GROUP BY sc.StudentID, cs.SessionDate, SUBSTRING(cs.StartTime,1,5) HAVING COUNT(*)>1 AND COUNT(DISTINCT cs.StudentClassID)>1) d"))->count();
$futSql = "SELECT sc.StudentID, cs.SessionDate, SUBSTRING(cs.StartTime,1,5) AS st FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID WHERE LOWER(cs.Status)='scheduled' AND cs.SessionDate>=CURDATE() GROUP BY sc.StudentID, cs.SessionDate, SUBSTRING(cs.StartTime,1,5) HAVING COUNT(DISTINCT cs.StudentClassID)>1";
$futGroups = (int)DB::table(DB::raw("($futSql) g"))->count();
$futStudents = (int)DB::table(DB::raw("($futSql) g"))->distinct('g.StudentID')->count('g.StudentID');
$futCourses = (int)DB::table(DB::raw("(SELECT DISTINCT cs.StudentClassID AS scid FROM ClassSession cs JOIN StudentClass sc ON sc.ID=cs.StudentClassID WHERE LOWER(cs.Status)='scheduled' AND cs.SessionDate>=CURDATE() AND EXISTS (SELECT 1 FROM ClassSession cs2 JOIN StudentClass sc2 ON sc2.ID=cs2.StudentClassID WHERE cs2.SessionDate=cs.SessionDate AND SUBSTRING(cs2.StartTime,1,5)=SUBSTRING(cs.StartTime,1,5) AND sc2.StudentID=sc.StudentID AND cs2.StudentClassID<>cs.StudentClassID AND LOWER(cs2.Status)='scheduled')) x"))->count();
$pair = function (bool $future) use ($asOf) {
  $q = DB::table('ClassSession as a')->join('ClassSession as b', function ($j) {
      $j->on('a.SessionDate','=','b.SessionDate')->on('a.StartTime','=','b.StartTime')->whereColumn('a.id','<','b.id');
    })->join('StudentClass as sca','sca.ID','=','a.StudentClassID')->join('StudentClass as scb','scb.ID','=','b.StudentClassID')
    ->whereColumn('sca.StudentID','=','scb.StudentID')->whereColumn('sca.ID','<>','scb.ID')
    ->whereRaw('LOWER(a.Status)=?',['scheduled'])->whereRaw('LOWER(b.Status)=?',['scheduled']);
  if ($future) $q->where('a.SessionDate','>=',$asOf);
  return (int)$q->count();
};
$pairAll = $pair(false); $pairFut = $pair(true);
echo json_encode([
  'as_of'=>$asOf,'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'execute'=>false,
  'stranded'=>[
    'courses'=>$ids->count(),'sessions'=>$sessions,'students'=>$students,
    'active_21d_courses'=>$activeIds->count(),'active_21d_sessions'=>$sumSess($activeIds),
    'dormant_courses'=>$dormantIds->count(),'dormant_sessions'=>$sumSess($dormantIds),
  ],
  'exposure_ntd'=>[
    'total'=>(int)round($totalAmt),'active_21d'=>(int)round($sumAmt($activeIds)),'dormant'=>(int)round($sumAmt($dormantIds)),
    'note'=>'Do not hang total exposure on active_21d session count; active_21d≠still producing',
  ],
  'producer_probe'=>[
    'mdate_stranded_24h'=>$mdate(24),'mdate_stranded_72h'=>$mdate(72),
    'payment_on_stranded_24h'=>$pay(24),'payment_on_stranded_72h'=>$pay(72),
    'interpretation'=>'Proxy only. Confirm producer before skip-reason/forward-gen dry-run; no bulk repair without CEO GO',
  ],
  'cross_sc_1130'=>[
    'attended_completed_groups'=>$attGroups,'future_scheduled_groups'=>$futGroups,
    'future_scheduled_students'=>$futStudents,'future_scheduled_courses'=>$futCourses,
    'scheduled_pairs_all'=>$pairAll,'scheduled_pairs_future'=>$pairFut,
    'unit_glossary'=>['group'=>'student+date+start 2+ SC','pair'=>'ClassSession pair count','student'=>'distinct StudentID','course'=>'distinct SC','future_active'=>'future_scheduled_groups'],
    'two_35_check'=>['pairs_future'=>$pairFut,'groups_future'=>$futGroups,'same_number_different_units'=>$pairFut===$futGroups,
      'note'=>'If both 35, units still differ unless coincidence'],
  ],
  'server_now'=>date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE), PHP_EOL;
