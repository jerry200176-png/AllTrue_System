<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\TempRfid;
use App\Models\StudentClass;
use App\Models\StudentLineBinding;
use App\Support\LineNotifySettings;
use App\Models\SecurityAuditEvent;
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
use Illuminate\Support\Facades\Http;
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
    private const FLEX_IMAGE_MAX_PX = 1024;
    private const FLEX_RESIZE_MAX_PIXELS = 12_000_000; // 4000×3000 ≈ 48MB 解碼後
    private const PHOTO_TEXT_WINDOW_SECONDS = 120;

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
        $flexRatio = $this->fitPhotoForFlex($request->file('photo')->getRealPath());
        $request->file('photo')->storeAs($dir, $file, 'local');

        $signed = URL::temporarySignedRoute(
            'swipe-photo.show',
            now()->addDays(self::PHOTO_TTL_DAYS),
            ['campus' => $campus->getKey(), 'file' => $file],
            false
        );
        $imageUrl = rtrim((string) config('app.url'), '/') . $signed;

        $sent = $this->pushPhotoToParents($student, $campus, $imageUrl, $flexRatio);

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

    private function pushPhotoToParents(Student $student, Campus $campus, string $imageUrl, ?string $flexRatio): int
    {
        $token = (string) ($campus->messaging_channel_token ?? '');
        if ($token === '' || !LineNotifySettings::enabled((int) $campus->getKey(), 'swipe')) {
            return 0;
        }
        $bindings = StudentLineBinding::query()->where('student_id', $student->getKey())
            ->whereNotNull('verified_at') // = scopeVerified()
            ->where('campus_id', $campus->getKey())
            ->get();

        $text = $this->swipePhotoText($student);
        $sent = 0;
        foreach ($bindings as $binding) {
            $delivered = false;
            try {
                $delivered = Http::withToken($token)->timeout(5)->post('https://api.line.me/v2/bot/message/push', [
                    'to' => $binding->line_user_id,
                    // 照片＋文字做成 1 張 Flex 卡＝聊天室 1 則；altText 是通知列看到的字。
                    // 照片超過 Flex 上限又縮不了 → 退回文字＋圖片 2 則，家長至少收得到。
                    'messages' => $flexRatio !== null
                        ? [$this->swipePhotoFlex($text, $imageUrl, $flexRatio)]
                        : [
                            ['type' => 'text', 'text' => $text],
                            ['type' => 'image', 'originalContentUrl' => $imageUrl, 'previewImageUrl' => $imageUrl],
                        ],
                ])->successful();
            } catch (\Throwable $e) {
                Log::warning('swipe_photo_line_push_failed: ' . $e->getMessage());
            }
            SecurityAuditEvent::append('notification.delivery', $delivered ? 'success' : 'failure', [
                'campus_id' => $campus->getKey(),
                'subject_type' => 'student',
                'subject_id' => $student->getKey(),
                'binding_id' => $binding->getKey(),
            ], [
                'method' => 'line_push',
                'notification_type' => 'swipe_photo',
                'delivery_status' => $delivered ? 'delivered' : 'failed',
                'binding_verified' => true,
            ]);
            if ($delivered) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * LINE Flex 圖片上限 1024×1024。超過就用 GD 等比縮到 1024 並覆寫上傳暫存檔。
     * 回傳卡片用的長寬比 "w:h"；null = 超過又縮不了（沒有 GD、圖太大或讀不了），呼叫端改推一般圖片訊息。
     */
    private function fitPhotoForFlex(string $path): ?string
    {
        [$w, $h, $type] = @getimagesize($path) ?: [0, 0, 0];
        if ($w <= 0) {
            return null;
        }
        // 手機直拍的 JPEG 靠 EXIF 轉向：6/8 = 轉 90°，顯示的寬高對調。
        $orientation = $type === IMAGETYPE_JPEG && function_exists('exif_read_data')
            ? (int) (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $turned = in_array($orientation, [6, 8], true);
        if ($w <= self::FLEX_IMAGE_MAX_PX && $h <= self::FLEX_IMAGE_MAX_PX) {
            return $turned ? "{$h}:{$w}" : "{$w}:{$h}";
        }
        // 1MB 的檔案可以宣稱 20000×20000；解碼前先擋，避免 GD 吃光記憶體。
        if ($w * $h > self::FLEX_RESIZE_MAX_PIXELS || !function_exists('imagescale')) {
            return null;
        }
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            return null;
        }
        // 重新編碼會丟掉 EXIF，所以先把像素轉正。ponytail: 鏡像（2/4/5/7）不處理，讀卡機相機不會出現。
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $src = imagerotate($src, $angle, 0);
            [$w, $h] = [imagesx($src), imagesy($src)];
        }
        $scale = self::FLEX_IMAGE_MAX_PX / max($w, $h);
        [$nw, $nh] = [max(1, (int) floor($w * $scale)), max(1, (int) floor($h * $scale))];
        $dst = imagescale($src, $nw, $nh);
        if ($dst === false) {
            return null;
        }
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        $ok = $type === IMAGETYPE_PNG ? imagepng($dst, $path) : imagejpeg($dst, $path, 85);

        return $ok ? "{$nw}:{$nh}" : null;
    }

    /** @return array<string,mixed> LINE Flex bubble：上面照片，下面文字。 */
    private function swipePhotoFlex(string $text, string $imageUrl, string $aspectRatio): array
    {
        return [
            'type' => 'flex',
            'altText' => $text,
            'contents' => [
                'type' => 'bubble',
                'hero' => [
                    'type' => 'image', 'url' => $imageUrl, 'size' => 'full',
                    // 用照片自己的比例，不裁切。不加點擊動作：簽章網址 7 天就失效。
                    'aspectRatio' => $aspectRatio, 'aspectMode' => 'fit',
                ],
                'body' => [
                    'type' => 'box', 'layout' => 'vertical',
                    'contents' => [['type' => 'text', 'text' => $text, 'weight' => 'bold', 'wrap' => true]],
                ],
            ],
        ];
    }

    /**
     * 照片配的文字。讀卡機不知道到班/離班，由 swipe-rfid 剛寫的今日刷卡紀錄判斷。
     * 只認 2 分鐘內的簽到/簽退；照片比刷卡先到或找不到紀錄 → 不寫到班/離班，避免講錯。
     */
    private function swipePhotoText(Student $student): string
    {
        $now = now();
        $latest = StudentSignIn::query()
            ->where('StudentID', $student->getKey())
            ->whereDate('SignInDT', $now->toDateString())
            // 只看刷卡寫的列；簽退後補的 presence-window、人工補登不算。
            ->whereIn('Memo', ['swipe-rfid', 'self_study'])
            ->orderByDesc('id')
            ->first();

        $recent = fn ($dt) => $dt && Carbon::parse($dt)->diffInSeconds($now, true) <= self::PHOTO_TEXT_WINDOW_SECONDS;
        $label = '刷卡';
        $at = $now;
        if ($latest && $recent($latest->getAttribute('SignOutDT'))) {
            $label = '離班';
            $at = Carbon::parse($latest->getAttribute('SignOutDT'));
        } elseif ($latest && !$latest->getAttribute('SignOutDT') && $recent($latest->getAttribute('SignInDT'))) {
            $label = '到班';
            $at = Carbon::parse($latest->getAttribute('SignInDT'));
        }

        return "{$student->name} 已於 {$at->format('H:i')} {$label}";
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
        $campusId = $campus->id;
        return DB::transaction(function () use ($student, $campusId, $campus, $swipeAt) {
            $today = $swipeAt->toDateString();

            $openRecord = StudentSignIn::where('StudentID', $student->id)
                ->whereDate('SignInDT', $today)
                ->whereNull('SignOutDT')
                ->orderBy('id', 'desc')
                ->first();

            if ($openRecord) {
                // TD-006: debounce — RF bounce 在 60 秒內的重複訊號直接忽略，不自動簽退
                $ageSeconds = Carbon::parse($openRecord->SignInDT)->diffInSeconds($swipeAt);
                if ($ageSeconds <= self::STUDENT_SWIPE_DEBOUNCE_SECONDS) {
                    return response()->json([
                        'ok'     => true,
                        'type'   => 'student',
                        'action' => 'duplicate_ignored',
                        'record' => $openRecord,
                        'student' => $this->studentPayload($student),
                        'campus' => ['TelegramToken' => $campus->TelegramToken ?? null],
                    ], 200);
                }

                $openRecord->SignOutDT = $swipeAt;
                $openRecord->MDT = $swipeAt;
                $openRecord->save();

                StudentPresenceBackfillService::backfill(
                    $student,
                    Carbon::parse($openRecord->SignInDT),
                    $swipeAt,
                    $campusId
                );

                return response()->json([
                    'ok'       => true,
                    'type'     => 'student',
                    'action'   => 'sign_out',
                    'record'   => $openRecord,
                    'student'  => $this->studentPayload($student),
                    'campus'   => ['TelegramToken' => $campus->TelegramToken ?? null],
                ], 200);
            }

            [$studentClass, $hours, $classSessionId] = $this->findMatchingClass($student, $swipeAt);

            // TD-007: duplicate sign-in guard — 若同一個 ClassSession 當天已有未作廢的記錄則不重複建立
            if ($classSessionId !== null) {
                $existingSignIn = StudentSignIn::where('StudentID', $student->id)
                    ->where('ClassSessionID', $classSessionId)
                    ->whereNull('VoidedAt')
                    ->first();

                if ($existingSignIn) {
                    return response()->json([
                        'ok'     => true,
                        'type'   => 'student',
                        'action' => 'duplicate_ignored',
                        'record' => $existingSignIn,
                        'student' => $this->studentPayload($student),
                        'campus' => ['TelegramToken' => $campus->TelegramToken ?? null],
                    ], 200);
                }
            }

            // FR-005: TeacherID fallback — if StudentClass.TeacherID is null but we have
            // a ClassSession, try to get TeacherID from the ClassSession's StudentClass.
            $resolvedTeacherId = $studentClass?->TeacherID;
            if ($resolvedTeacherId === null && $classSessionId !== null) {
                $fallbackSc = ClassSession::find($classSessionId)?->studentClass;
                $resolvedTeacherId = $fallbackSc?->TeacherID ?? null;
                if ($resolvedTeacherId === null) {
                    Log::warning('[swipe] TeacherID resolved to null', [
                        'student_id'       => $student->id,
                        'class_session_id' => $classSessionId,
                        'student_class_id' => $studentClass?->ID,
                    ]);
                }
            }

            $signIn = StudentSignIn::create([
                'StudentClassID'   => $studentClass?->ID,
                'StudentID'        => $student->id,
                'TeacherID'        => $resolvedTeacherId,
                'RecordedByUserID' => null,
                'GradeID'          => $studentClass?->GradeID,
                'SubjectID'        => $studentClass?->SubjectID,
                'Get1byID'         => $studentClass?->by1,
                'Hours'            => $hours,
                'Memo'             => $studentClass ? 'swipe-rfid' : 'self_study',
                'SignInDT'         => $swipeAt,
                'SignOutDT'        => null,
                'MDT'              => $swipeAt,
                'ClassSessionID'   => $classSessionId,
                'Status'           => 'present',
                'CampusID'         => $campusId,
                'PersonType'       => 'student',
                'SessionDeducted'  => false,
            ]);

            // FR-001/FR-002: Sync ClassSession.Status after successful swipe.
            // Only updates when Status = 'scheduled' (guard prevents overwriting human decisions).
            if ($classSessionId !== null) {
                $classSession = ClassSession::find($classSessionId);
                if ($classSession) {
                    $swipeStatus = AttendanceEffectsService::resolveSwipeStatus($classSession, $swipeAt);
                    AttendanceEffectsService::applySessionStatus($classSession, $swipeStatus);
                }
            }

            // Deduct session on sign-in (點名成功才扣堂)
            if ($studentClass && !$signIn->SessionDeducted) {
                SessionDeductionService::deductOnAttendance($studentClass, $signIn);
                StudentPresenceBackfillService::alertIfNotDeducted($signIn, $campusId);
            }

            return response()->json([
                'ok'       => true,
                'type'     => 'student',
                'action'   => 'sign_in',
                'record'   => $signIn,
                'student'  => $this->studentPayload($student),
                'class'    => $studentClass ? [
                    'id'       => $studentClass->ID,
                    'teacher_id' => $studentClass->TeacherID,
                ] : null,
                'campus'   => ['TelegramToken' => $campus->TelegramToken ?? null],
            ], 201);
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
            'LineIDs'     => !LineNotifySettings::enabled($campusId, 'swipe') ? [] : StudentLineBinding::query()->where('student_id', $student->id)
                ->whereNotNull('verified_at')
                // 只給刷卡分校頻道的綁定：學生是以 CampusID = 刷卡分校查出來的，所以等於刷卡分校；
                // 轉校殘留／舊匯入的別校綁定不能交給這台讀卡機（跨分校）。
                ->where('campus_id', $campusId)
                ->pluck('line_user_id')
                ->values()
                ->all(),
        ];
    }

    /**
     * 依當日 ClassSession 找出最接近刷卡時間的課堂。
     * 無符合的 ClassSession → self_study（不扣堂）。不再依 StudentClass week/time 回退：
     * 沒有真實 ClassSession 就不得標記出席或扣堂（#2809 Founder 2026-09-29）。
     */
    private function findMatchingClass(Student $student, Carbon $swipeAt): array
    {
        $sessions = ClassSession::with('studentClass')
            ->whereDate('SessionDate', $swipeAt->toDateString())
            ->whereNotIn(DB::raw('LOWER(Status)'), array_merge(
                [\App\Support\SessionStatus::CANCELLED],
                \App\Support\SessionStatus::leaveFamily()
            ))
            ->whereHas('studentClass', function ($q) use ($student) {
                $q->where('StudentID', $student->id)->where('Stop', 0);
            })
            ->get();

        if (!$sessions->isEmpty()) {
            // TD-011: 窗口從「距 StartTime ≤ 30 min」擴展為「StartTime-30min ≤ swipeAt ≤ EndTime」
            // 優先選 ongoing sessions（startTime ≤ swipeAt ≤ endTime），再選最近的 upcoming session。
            $ongoingSessions  = [];
            $upcomingSessions = [];

            foreach ($sessions as $session) {
                if (!$session->EndTime) {
                    continue;
                }
                $startTime   = Carbon::parse($session->SessionDate . ' ' . $session->StartTime);
                $endTime     = Carbon::parse($session->SessionDate . ' ' . $session->EndTime);
                $windowStart = $startTime->copy()->subMinutes(30);

                if (!$swipeAt->between($windowStart, $endTime)) {
                    continue;
                }

                if ($swipeAt->greaterThanOrEqualTo($startTime)) {
                    // Ongoing: startTime ≤ swipeAt ≤ endTime
                    $ongoingSessions[] = ['session' => $session, 'start_ts' => $startTime->timestamp];
                } else {
                    // Upcoming: windowStart ≤ swipeAt < startTime
                    $upcomingSessions[] = ['session' => $session, 'start_ts' => $startTime->timestamp];
                }
            }

            // Ongoing 優先：取最近啟動的（最大 startTime = 遲到最少）
            if (!empty($ongoingSessions)) {
                usort($ongoingSessions, fn ($a, $b) => $b['start_ts'] <=> $a['start_ts']);
                $best = $ongoingSessions[0]['session'];
            } elseif (!empty($upcomingSessions)) {
                // 無 ongoing：取最近即將開始的（最小 startTime）
                usort($upcomingSessions, fn ($a, $b) => $a['start_ts'] <=> $b['start_ts']);
                $best = $upcomingSessions[0]['session'];
            } else {
                $best = null;
            }

            if ($best) {
                $sc    = $best->studentClass;
                $hours = $sc->TotalHours ? (int) $sc->TotalHours : null;
                return [$sc, $hours, $best->id];
            }
        }

        return [null, null, null];
    }

    private const STUDENT_SWIPE_DEBOUNCE_SECONDS = 60;
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
