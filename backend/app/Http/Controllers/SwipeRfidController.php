<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Services\Line\ParentLinePush;
use App\Services\Line\SwipePhotoDelivery;
use App\Services\RecordStudentSwipe;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\TempRfid;
use App\Models\StudentClass;
use App\Support\LineNotifySettings;
use App\Models\StudentSignIn;
use App\Models\TeacherSignIn;
use App\Models\User;
use App\Services\AttendanceEffectsService;
use App\Services\SessionDeductionService;
use App\Services\StudentPresenceBackfillService;
use App\Services\TeacherAttendanceMonth;
use App\Services\TeacherClassCalendar;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * RFID 刷卡 API
 * 接收分校代碼、RFID，判斷為學生或老師並建立刷卡紀錄
 */
class SwipeRfidController extends Controller
{
    private const PHOTO_DIR = 'swipe-photos';
    private const PHOTO_TTL_DAYS = 7;

    /**
     * POST /api/v1/swipe-rfid
     * Body: { branch_code: "daan", rfid: "xxx" }
     */
    public function swipe(Request $request)
    {
        try {
            $data = $request->validate([
                'branch_code' => 'required|string|max:32',
                'rfid'        => 'required|string|max:32',
            ]);

            $branchCode = trim($data['branch_code']);
            $rfid = trim($data['rfid']);
            $swipeAt = now();

            $campus = $this->authorizeCampus($request, $branchCode);
            if ($campus instanceof JsonResponse) {
                return $campus;
            }

            $campusId = $campus->getKey();

            // 優先：UserCampus 每分校 RFID。若同一張卡誤綁到學生，老師本人打卡
            // 仍應進 TeacherSingIn，避免靜默寫成學生出勤而在老師打卡列表消失。
            $teacher = null;
            $teacherInCampus = false;
            if (Schema::hasColumn('UserCampus', 'RFID')) {
                $uc = DB::table('UserCampus')
                    ->where('CampusID', $campusId)
                    ->where('RFID', $rfid)
                    ->whereNotNull('RFID')
                    ->where('RFID', '!=', '')
                    ->first();
                if ($uc) {
                    $teacher = User::where('id', (int) $uc->UserID)
                        ->where('type', 'T')
                        ->where(function ($query) {
                            $query->whereNull('status')->orWhere('status', 'active');
                        })
                        ->first();
                    $teacherInCampus = (bool) $teacher;
                }
            }
            if ($teacherInCampus) {
                return $this->handleTeacherSwipe($teacher, $campus, $swipeAt);
            }

            $student = Student::where('RFID', $rfid)->where('CampusID', $campusId)->where('enable', 1)->first();
            if ($student) {
                return $this->handleStudentSwipe($student, $campus, $swipeAt);
            }

            // 每分校僅一筆：更新 RFID 時必須刷新 created_at，否則效期仍沿用舊時間，
            // TempRfidController 會誤判過期並刪除，導致後台「綁定卡片」顯示暫無刷卡資料。
            TempRfid::updateOrCreate(
                ['CampusID' => $campusId],
                ['RFID' => $rfid, 'created_at' => now()]
            );

            return response()->json([
                'ok'     => false,
                'error'  => 'rfid_not_found',
                'message' => 'RFID 未綁定學生或老師（已暫存，請於 5 分鐘內綁定）',
                'campus' => ['TelegramToken' => $campus->TelegramToken ?? null],
            ], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'error' => 'attendance_not_allowed',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /** 分校代碼 + 該分校 Bearer Token；失敗回 JsonResponse。swipe 與 photo 共用。 */
    private function authorizeCampus(Request $request, string $branchCode): Campus|JsonResponse
    {
        if (is_numeric($branchCode)) {
            $campus = Campus::find($branchCode);
        } else {
            $campus = Campus::where('code', $branchCode)->first();
            if (!$campus) {
                $campus = Campus::where('name', 'like', "%{$branchCode}%")->first();
            }
        }
        if (!$campus) {
            return response()->json([
                'ok'     => false,
                'error'  => 'branch_not_found',
                'message' => '分校代碼不存在',
            ], 404);
        }

        $authHeader = $request->header('Authorization');
        $bearerToken = null;
        if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
            $bearerToken = trim($m[1]);
        }
        if (!$bearerToken || $bearerToken !== ($campus->Token ?? '')) {
            return response()->json([
                'ok'     => false,
                'error'  => 'unauthorized',
                'message' => 'Authorization Bearer Token 無效或未提供',
            ], 401);
        }

        return $campus;
    }

    /**
     * POST /api/v1/swipe-photo（multipart）
     * Body: branch_code, rfid, photo（jpeg/png ≤1MB）
     * 刷卡機拍照後呼叫：存照片（私有），推 1 張 LINE Flex 卡（照片＋到班/離班文字）給該學生已驗證綁定的家長，並回傳 image_url。
     * 圖片網址是 APP_URL 上的簽章網址，所以刷卡機有沒有固定 IP 都沒差。
     */
    public function photo(Request $request)
    {
        $data = $request->validate([
            'branch_code' => 'required|string|max:32',
            'rfid'        => 'required|string|max:32',
            // LINE previewImageUrl 上限 1MB；同一張圖兩個欄位共用，所以整張限 1MB。
            'photo'       => 'required|file|mimes:jpeg,png|max:1024',
        ]);

        $campus = $this->authorizeCampus($request, trim($data['branch_code']));
        if ($campus instanceof JsonResponse) {
            return $campus;
        }

        $student = Student::query()->where('RFID', trim($data['rfid']))
            ->where('CampusID', $campus->getKey())
            ->where('enable', 1)
            ->first();
        if (!$student) {
            return response()->json(['ok' => false, 'error' => 'student_not_found'], 404);
        }

        $dir = self::PHOTO_DIR . '/' . $campus->getKey();
        $this->prunePhotos($dir);
        $file = Str::uuid() . '.' . $request->file('photo')->extension();
        $delivery = app(SwipePhotoDelivery::class);
        $flexRatio = $delivery->fitForFlex($request->file('photo')->getRealPath());
        $request->file('photo')->storeAs($dir, $file, 'local');

        $signed = URL::temporarySignedRoute(
            'swipe-photo.show',
            now()->addDays(self::PHOTO_TTL_DAYS),
            ['campus' => $campus->getKey(), 'file' => $file],
            false
        );
        $imageUrl = rtrim((string) config('app.url'), '/') . $signed;

        $sent = $delivery->pushToParents($student, $campus, $imageUrl, $flexRatio);

        return response()->json(['ok' => true, 'sent' => $sent, 'image_url' => $imageUrl]);
    }

    /** GET /api/v1/swipe-photo/{campus}/{file}?expires=&signature= — 給 LINE 伺服器下載。 */
    public function showPhoto(Request $request, int $campus, string $file)
    {
        // 相對簽章：簽的是 path+query，不綁網域，所以 proxy/網域差異不會讓簽章失效。
        if (!URL::hasValidSignature($request, false)) {
            abort(403);
        }
        $path = self::PHOTO_DIR . "/{$campus}/" . basename($file);
        if (!Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::path($path)); // default disk = local
    }

    /** 照片只留到簽章網址過期為止。ponytail: 每次上傳順手掃該分校目錄；量大再改排程。 */
    private function prunePhotos(string $dir): void
    {
        $cutoff = now()->subDays(self::PHOTO_TTL_DAYS)->getTimestamp();
        $disk = Storage::disk('local');
        foreach ($disk->files($dir) as $old) {
            if ($disk->lastModified($old) < $cutoff) {
                $disk->delete($old);
            }
        }
    }

    private function handleStudentSwipe(Student $student, Campus $campus, Carbon $swipeAt)
    {
        // 回應（含 studentPayload）在 transaction 內組好：組不出來就整筆刷卡 rollback，跟抽出前一樣。
        return app(RecordStudentSwipe::class)->handle($student, $campus, $swipeAt, function (array $result) use ($student, $campus) {
            $body = [
                'ok'      => true,
                'type'    => 'student',
                'action'  => $result['action'],
                'record'  => $result['record'],
                'student' => $this->studentPayload($student),
            ];
            if ($result['action'] === 'sign_in') {
                $body['class'] = $result['class']; // null = 自習（沒有符合的課堂）
            }
            $body['campus'] = ['TelegramToken' => $campus->TelegramToken ?? null];

            return response()->json($body, $result['status']);
        });
    }

    /**
     * 刷卡回應的學生資訊。LineIDs = 已驗證綁定的家長 LINE userId，供讀卡機用 LINE Bot 推播。
     * 分校關掉刷卡 LINE 通知時回空陣列，讀卡機就沒有對象可推。
     */
    private function studentPayload(Student $student): array
    {
        $campusId = (int) $student->getAttribute('CampusID');

        return [
            'id'          => $student->id,
            'name'        => $student->name,
            'TelegramID'  => $student->TelegramID,
            'TelegramID1' => $student->TelegramID1,
            'TelegramID2' => $student->TelegramID2,
            'LineIDs'     => !LineNotifySettings::enabled($campusId, 'swipe') ? [] : app(ParentLinePush::class)
                // 只給刷卡分校頻道的綁定：學生是以 CampusID = 刷卡分校查出來的，所以等於刷卡分校；
                // 轉校殘留／舊匯入的別校綁定不能交給這台讀卡機（跨分校）。
                ->bindings((int) $student->id, $campusId)
                ->pluck('line_user_id')
                ->values()
                ->all(),
        ];
    }

    private const TEACHER_SWIPE_DEBOUNCE_SECONDS = 60;

    /**
     * @param User $teacher
     */
    private function handleTeacherSwipe(User $teacher, Campus $campus, Carbon $swipeAt)
    {
        $campusId    = $campus->id;
        $today       = $swipeAt->toDateString();
        $teacherName = $teacher->Name ?? '';

        $openRecord = TeacherSignIn::where('TeacherID', $teacher->id)
            ->whereDate('SignInDT', $today)
            ->whereNull('SignOutDT')
            ->orderBy('id', 'desc')
            ->first();

        // 還沒簽退就到別間分校刷卡：前一筆標「跨校自動簽退」（月表算異常讓主任確認），這裡開新的上班
        if ($openRecord && (int) $openRecord->CampusID !== (int) $campusId) {
            $openRecord->SignOutDT = $swipeAt;
            $openRecord->Memo = TeacherAttendanceMonth::CROSS_CAMPUS_MEMO;
            $openRecord->MDT = $swipeAt;
            $openRecord->save();
            $openRecord = null;
        }

        if ($openRecord) {
            // NFR-003: RF bounce debounce — 60 秒內重複訊號直接忽略，不自動簽退
            $ageSeconds = Carbon::parse($openRecord->SignInDT)->diffInSeconds($swipeAt);
            if ($ageSeconds <= self::TEACHER_SWIPE_DEBOUNCE_SECONDS) {
                return response()->json([
                    'ok'     => true,
                    'type'   => 'teacher',
                    'action' => 'duplicate_ignored',
                    'record' => $openRecord,
                    'teacher' => ['id' => $teacher->id, 'name' => $teacherName],
                    'campus' => [
                        'TelegramChatID' => $campus->TelegramChatID ?? null,
                        'TelegramToken'  => $campus->TelegramToken ?? null,
                    ],
                ], 200);
            }

            $openRecord->SignOutDT = $swipeAt;
            $openRecord->MDT = $swipeAt;
            $openRecord->save();

            return response()->json([
                'ok'     => true,
                'type'   => 'teacher',
                'action' => 'sign_out',
                'record' => $openRecord->fresh(),
                'teacher' => ['id' => $teacher->id, 'name' => $teacherName],
                'campus' => [
                    'TelegramChatID' => $campus->TelegramChatID ?? null,
                    'TelegramToken'  => $campus->TelegramToken ?? null,
                ],
            ], 200);
        }

        $status = $this->resolveTeacherSignInStatus($teacher->id, $campusId, $swipeAt);

        $record = TeacherSignIn::create([
            'TeacherID'  => $teacher->id,
            'CampusID'   => $campusId,
            'SignInDT'   => $swipeAt,
            'SignOutDT'  => null,
            'MDT'        => $swipeAt,
            'Source'     => 'rfid',
            'Status'     => $status,
        ]);

        return response()->json([
            'ok'     => true,
            'type'   => 'teacher',
            'action' => 'sign_in',
            'record' => $record,
            'teacher' => ['id' => $teacher->id, 'name' => $teacherName],
            'campus' => [
                'TelegramChatID' => $campus->TelegramChatID ?? null,
                'TelegramToken'  => $campus->TelegramToken ?? null,
            ],
        ], 201);
    }

    /**
     * 計算老師簽到的異常狀態：只比「這間分校」今天第一堂，且只看今天第一次到這間分校（跑校不誤判）。
     * 月表／今日頁會用 TeacherAttendanceMonth 重算；這裡只是刷卡當下的快照。
     * 失敗時 fallback 為 pending_review，不中斷打卡流程。
     */
    private function resolveTeacherSignInStatus(int $teacherId, int $campusId, Carbon $swipeAt): string
    {
        try {
            $today = $swipeAt->toDateString();

            $classes = TeacherClassCalendar::load($today, $today, [$campusId], $teacherId)[$teacherId][$today] ?? [];
            if ($classes === []) {
                return 'source_only';
            }

            $arrivedEarlier = TeacherSignIn::query()->where('TeacherID', $teacherId)
                ->where('CampusID', $campusId)
                ->whereDate('SignInDT', $today)
                ->where('SignInDT', '<', $swipeAt)
                ->exists();
            if ($arrivedEarlier) {
                return 'normal';
            }

            $classStart = Carbon::parse("{$today} " . min(array_column($classes, 'start')));
            $threshold  = $classStart->copy()->addMinutes(TeacherAttendanceMonth::LATE_GRACE_MINUTES);

            return $swipeAt->lte($threshold) ? 'normal' : 'late';
        } catch (\Throwable $e) {
            Log::warning('resolveTeacherSignInStatus failed, fallback to pending_review', [
                'teacher_id' => $teacherId,
                'swipe_at'   => $swipeAt->toIso8601String(),
                'error'      => $e->getMessage(),
            ]);
            return 'pending_review';
        }
    }
}
