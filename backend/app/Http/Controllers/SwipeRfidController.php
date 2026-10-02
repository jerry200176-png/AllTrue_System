<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\TempRfid;
use App\Models\StudentClass;
use App\Models\StudentLineBinding;
use App\Models\SecurityAuditEvent;
use App\Models\StudentSignIn;
use App\Models\TeacherSignIn;
use App\Models\User;
use App\Services\AttendanceEffectsService;
use App\Services\SessionDeductionService;
use App\Services\StudentPresenceBackfillService;
use App\Services\TeacherAttendanceMonth;
use App\Services\TeacherClassCalendar;
use App\Support\LineNotifySettings;
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
     * 刷卡機拍照後呼叫：存照片（私有），推 LINE 文字「{姓名} {時間} 刷卡」+圖片給該學生已驗證綁定的家長，並回傳 image_url。
     * 分校要在 LINE 通知設定開「到班刷卡」或「離班刷卡」才會發（LineNotifySettings）。
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

        // 到班/離班開關分開；判斷不出來（照片早到/晚到）就任一開就發。文字一律中性，誤刷也不會講錯。
        // 剛簽退的是別校／分校不明／已作廢的紀錄 → 不是本校離班，跟 swipe-rfid 的 LineIDs 一樣不發。
        [$kind, $swipedAt] = $this->recentSwipe($student, (int) $campus->getKey());
        if ($kind === 'unsafe') {
            return response()->json(['ok' => true, 'sent' => 0, 'skipped' => 'unsafe_record']);
        }
        $settings = LineNotifySettings::get((int) $campus->getKey());
        $wanted = match ($kind) {
            'in' => $settings['swipe_in'],
            'out' => $settings['swipe_out'],
            default => $settings['swipe_in'] || $settings['swipe_out'],
        };
        if (!$wanted) {
            return response()->json(['ok' => true, 'sent' => 0, 'skipped' => 'disabled']);
        }

        $dir = self::PHOTO_DIR . '/' . $campus->getKey();
        $this->prunePhotos($dir);
        $file = Str::uuid() . '.' . $request->file('photo')->extension();
        $request->file('photo')->storeAs($dir, $file, 'local');

        $signed = URL::temporarySignedRoute(
            'swipe-photo.show',
            now()->addDays(self::PHOTO_TTL_DAYS),
            ['campus' => $campus->getKey(), 'file' => $file],
            false
        );
        $imageUrl = rtrim((string) config('app.url'), '/') . $signed;

        $text = "{$student->name} {$swipedAt->format('H:i')} 刷卡";
        $sent = $this->pushPhotoToParents($student, $campus, $text, $imageUrl);

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

    private function pushPhotoToParents(Student $student, Campus $campus, string $text, string $imageUrl): int
    {
        $token = (string) ($campus->messaging_channel_token ?? '');
        if ($token === '') {
            return 0;
        }
        $bindings = StudentLineBinding::query()->where('student_id', $student->getKey())
            ->whereNotNull('verified_at') // = scopeVerified()
            ->where('campus_id', $campus->getKey())
            ->get();

        $sent = 0;
        foreach ($bindings as $binding) {
            $delivered = false;
            try {
                $delivered = Http::withToken($token)->timeout(5)->post('https://api.line.me/v2/bot/message/push', [
                    'to' => $binding->line_user_id,
                    // Flex 一張卡片＝照片＋文字，算 1 則額度；altText 是通知列/聊天列表看到的字。
                    'messages' => [$this->swipePhotoFlex($text, $imageUrl)],
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

    /** @return array<string,mixed> LINE Flex bubble：上面照片（點了看原圖），下面文字。 */
    private function swipePhotoFlex(string $text, string $imageUrl): array
    {
        return [
            'type' => 'flex',
            'altText' => $text,
            'contents' => [
                'type' => 'bubble',
                'hero' => [
                    'type' => 'image', 'url' => $imageUrl, 'size' => 'full',
                    'aspectRatio' => '4:3', 'aspectMode' => 'cover',
                    'action' => ['type' => 'uri', 'uri' => $imageUrl],
                ],
                'body' => [
                    'type' => 'box', 'layout' => 'vertical',
                    'contents' => [['type' => 'text', 'text' => $text, 'weight' => 'bold', 'wrap' => true]],
                ],
            ],
        ];
    }

    /**
     * 這張照片對應的刷卡：讀卡機不知道到班/離班，看 swipe-rfid 剛寫的今日紀錄。
     * 只認 2 分鐘內的簽到/簽退，否則 unknown（時間用現在）。
     *
     * @return array{0:string,1:Carbon} [in|out|unsafe|unknown, 刷卡時間]；unsafe = 今天最新一筆不是本校未作廢紀錄
     */
    private function recentSwipe(Student $student, int $campusId): array
    {
        $now = now();
        // 排除簽退時 StudentPresenceBackfillService 補建的 presence-window 列：它們 id 較新，
        // 但不是這次刷卡碰到的紀錄，會蓋掉真正被簽退的（可能是別校／作廢）那筆。
        $latest = StudentSignIn::query()
            ->where('StudentID', $student->getKey())
            ->whereDate('SignInDT', $now->toDateString())
            ->where(fn ($q) => $q->whereNull('Memo')->orWhere('Memo', '!=', 'presence-window'))
            ->orderByDesc('id')
            ->first();
        if (!$latest) {
            return ['unknown', $now];
        }
        // 先看安全再看時間：今天最新一筆是別校／分校不明／已作廢 → 不管照片多晚到都不發。
        if (!$this->isOwnActiveRecord($latest, $campusId)) {
            return ['unsafe', $now];
        }

        $recent = fn ($dt) => $dt && Carbon::parse($dt)->diffInSeconds($now, true) <= self::PHOTO_TEXT_WINDOW_SECONDS;
        $out = $latest->getAttribute('SignOutDT');
        $in = $latest->getAttribute('SignInDT');
        if ($recent($out)) {
            return ['out', Carbon::parse($out)];
        }
        if (!$out && $recent($in)) {
            return ['in', Carbon::parse($in)];
        }

        return ['unknown', $now];
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
                    // 只有「本校、未作廢」的紀錄被簽退才算本校離班；別校／分校不明（當天轉校、待配對建立）
                    // 或已作廢的紀錄 → 不給 LineIDs，避免假的離班通知。簽到簽退本身不受影響。
                    'student'  => $this->studentPayload(
                        $student,
                        $this->isOwnActiveRecord($openRecord, (int) $campusId) ? 'swipe_out' : null
                    ),
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
                'student'  => $this->studentPayload($student, 'swipe_in'),
                'class'    => $studentClass ? [
                    'id'       => $studentClass->ID,
                    'teacher_id' => $studentClass->TeacherID,
                ] : null,
                'campus'   => ['TelegramToken' => $campus->TelegramToken ?? null],
            ], 201);
        });
    }

    private function isOwnActiveRecord(StudentSignIn $record, int $campusId): bool
    {
        $recordCampus = $record->getAttribute('CampusID');

        return $record->getAttribute('VoidedAt') === null
            && $recordCampus !== null
            && (int) $recordCampus === $campusId;
    }

    /**
     * 刷卡回應的學生資訊。
     * LineIDs = 已驗證綁定的家長 LINE userId，給還沒接 swipe-photo 的讀卡機自己推文字（舊路徑，延續通知）。
     * 只有該分校對應開關（到班 swipe_in／離班 swipe_out）開著才給；重複刷卡或開關關閉 → []。
     * 讀卡機若有呼叫 swipe-photo（AllTrue 會發 Flex 卡），就不要再用 LineIDs 自己推，否則家長收兩則。
     */
    private function studentPayload(Student $student, ?string $notifyType = null): array
    {
        $campusId = (int) $student->getAttribute('CampusID');
        $lineIds = $notifyType !== null && LineNotifySettings::enabled($campusId, $notifyType)
            ? StudentLineBinding::query()->where('student_id', $student->getKey())
                ->whereNotNull('verified_at')
                ->where('campus_id', $campusId) // 只給本分校頻道的綁定（轉校殘留／舊資料不外流給讀卡機）
                ->pluck('line_user_id')
                ->values()
                ->all()
            : [];

        return [
            'id'          => $student->id,
            'name'        => $student->name,
            'TelegramID'  => $student->TelegramID,
            'TelegramID1' => $student->TelegramID1,
            'TelegramID2' => $student->TelegramID2,
            'LineIDs'     => $lineIds,
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
