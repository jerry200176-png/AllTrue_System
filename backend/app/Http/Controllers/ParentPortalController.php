<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\LearningRecordFeedback;
use App\Models\ParentSession;
use App\Services\ParentBinding\ParentBindingObservability;
use App\Services\ParentBinding\ParentGuardianAccessService;
use App\Support\ParentBinding\ParentBindingCodes;
use App\Models\ParentCrossCampusAccess;
use App\Models\StudentIdentityMember;
use App\Support\StudentContactPhone;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentGuardian;
use App\Models\StudentLineBinding;
use App\Models\SecurityAuditEvent;
use App\Models\StudentSignIn;
use App\Models\ClassSession;
use App\Models\ExceptionWorkflow;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Announcement;
use App\Models\Subject;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentRemediationAction;
use App\Models\User;
use App\Http\Controllers\LearningRecordController;
use App\Services\ExceptionWorkflowService;
use App\Services\SessionDeductionService;
use App\Services\StudentIdentityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class ParentPortalController extends Controller
{
    private function identityService(): StudentIdentityService
    {
        return app(StudentIdentityService::class);
    }

    // ── Login: Student ID + Phone OR Student Name + Phone ───────────────────

    public function login(Request $request)
    {
        $obs = app(ParentBindingObservability::class);
        $cid = $obs->newCorrelationId($request->headers->get('X-Request-Id') ?? $request->headers->get('X-Correlation-Id'));
        $validator = Validator::make($request->all(), [
            'StudentID' => 'nullable|integer',
            'Name' => 'nullable|string|max:64',
            'Phone' => 'required|string|max:20',
        ]);
        if ($validator->fails()) {
            $obs->observe($cid, ParentBindingCodes::CHANNEL_PORTAL, ParentBindingCodes::METHOD_UNKNOWN, $obs->classifier()->invalidInput());
            throw new \Illuminate\Validation\ValidationException($validator);
        }
        $data = $validator->validated();

        $phoneNorm = $this->normalizePhone($data['Phone']);
        if ($phoneNorm === '') {
            $obs->observe($cid, ParentBindingCodes::CHANNEL_PORTAL, ParentBindingCodes::METHOD_UNKNOWN, $obs->classifier()->invalidInput());
            return response()->json(['message' => '請輸入手機號碼'], 422);
        }

        $rawName = trim((string) ($data['Name'] ?? ''));
        $hasStudentId = !empty($data['StudentID']) && (int) $data['StudentID'] > 0;

        // PRD-B FR-B-001: require precise single-row match. Either:
        //   (a) StudentID + Phone (exact match), or
        //   (b) Name + Phone (must return exactly one matching Student).
        // 「相同 Phone 的所有學生均列出」邏輯已於 2026-04-18 移除以避免跨家庭 PII 洩漏。
        if (!$hasStudentId && $rawName === '') {
            $obs->observe($cid, ParentBindingCodes::CHANNEL_PORTAL, ParentBindingCodes::METHOD_UNKNOWN, $obs->classifier()->invalidInput(), $phoneNorm);
            return response()->json(['message' => '請輸入學生姓名與手機號碼'], 422);
        }

        $student = null;
        $candidate = null;
        $allByName = collect();
        $method = $hasStudentId ? ParentBindingCodes::METHOD_STUDENT_ID : ParentBindingCodes::METHOD_NAME;

        if ($hasStudentId) {
            $candidate = Student::find((int) $data['StudentID']);
            if ($candidate
                && StudentContactPhone::matchesNormalizedInput($candidate, $phoneNorm)
                && ($rawName === '' || trim((string) $candidate->name) === $rawName)) {
                $student = $candidate;
            } elseif ($candidate && empty(trim($this->resolveContactPhone($candidate)))
                && !StudentGuardian::activeAccess()
                    ->where('student_id', (int) $candidate->id)
                    ->whereHas('guardian', fn ($q) => $q->whereNotNull('phone_normalized')->where('phone_normalized', '!=', ''))
                    ->exists()) {
                $obs->observe($cid, ParentBindingCodes::CHANNEL_PORTAL, $method, $obs->classifier()->classifyPortalStudentId($candidate, $phoneNorm, $rawName), $phoneNorm);
                return response()->json(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入'], 401);
            }
        } else {
            $allByName = Student::whereRaw('TRIM(name) = ?', [$rawName])->get();
            // SEC-AUDIT-003 (#972): this branch previously logged student names and
            // phone numbers (Phone / parent_phone / resolved) on every name-based
            // login attempt, ungated. Log a PII-free count only — preserves the
            // "ambiguous name lookup" signal without leaking PII to the log.
            \Illuminate\Support\Facades\Log::info('parent.login.name_lookup', [
                'name_match_count' => $allByName->count(),
                'correlation_id' => $cid,
            ]);
            $candidates = $allByName
                ->filter(function ($s) use ($phoneNorm) {
                    return StudentContactPhone::matchesNormalizedInput($s, $phoneNorm);
                })
                ->values();

            if ($candidates->count() === 1) {
                $student = $candidates->first();
            } elseif ($candidates->count() > 1) {
                // 極罕見：姓名 + 手機完全相同但不同 Student 記錄。業界作法為不自動登入，
                // 要求使用者改以 LINE 綁定或 StudentID 精確登入，避免誤選他家庭學生。
                // A director-confirmed identity group is the only exception to
                // the legacy ambiguous-name rule. Same name/phone alone never
                // creates or expands a parent scope.
                $groupIds = $candidates->map(fn ($s) => $this->identityService()->groupForStudent((int) $s->id)?->id)
                    ->filter()->unique()->values();
                if ($groupIds->count() === 1 && $candidates->every(fn ($s) => (int) $this->identityService()->groupForStudent((int) $s->id)?->id === (int) $groupIds->first())) {
                    $student = $candidates->first();
                } else {
                    $obs->observe($cid, ParentBindingCodes::CHANNEL_PORTAL, $method, $obs->classifier()->classifyPortalName($allByName, $phoneNorm), $phoneNorm);
                    return response()->json([
                        'message' => '找到多筆相符資料，請改以 LINE 綁定或提供學生代號登入',
                    ], 409);
                }
            } else {
                // Hint to front desk if name matched but phone didn't for any row with empty phone
                $nameOnly = Student::whereRaw('TRIM(name) = ?', [$rawName])->get();
                if ($nameOnly->isNotEmpty() && $nameOnly->contains(fn ($s) => empty(trim($this->resolveContactPhone($s))))) {
                    $obs->observe($cid, ParentBindingCodes::CHANNEL_PORTAL, $method, $obs->classifier()->classifyPortalName($allByName, $phoneNorm), $phoneNorm);
                    return response()->json(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入'], 401);
                }
            }
        }

        if (!$student) {
            $c = $hasStudentId
                ? $obs->classifier()->classifyPortalStudentId($candidate, $phoneNorm, $rawName)
                : $obs->classifier()->classifyPortalName($allByName, $phoneNorm);
            $obs->observe($cid, ParentBindingCodes::CHANNEL_PORTAL, $method, $c, $phoneNorm);
            return response()->json(['message' => '查無此學生或手機號碼不符，請確認姓名與手機是否正確'], 404);
        }

        $obs->observe(
            $cid,
            ParentBindingCodes::CHANNEL_PORTAL,
            $method,
            $hasStudentId
                ? $obs->classifier()->classifyPortalStudentId($student, $phoneNorm, $rawName)
                : $obs->classifier()->classifyPortalName(collect([$student]), $phoneNorm),
            $phoneNorm,
        );
        \Illuminate\Support\Facades\Log::info('parent.login.success', [
            'student_id' => $student->id,
            'ip' => $request->ip(),
            'correlation_id' => $cid,
        ]);

        $result = $this->createSession($student, null, $phoneNorm);

        // Only attach additional students if they share an explicit LINE binding.
        // 不再以「相同 Phone」自動帶出 siblings，避免跨家庭 PII 洩漏。
        $lineUserIds = StudentLineBinding::where('student_id', $student->id)
            ->verified()
            ->pluck('line_user_id')
            ->filter(fn ($id) => $this->isValidLineUserId($id));
        if ($lineUserIds->isNotEmpty()) {
            $siblingIds = StudentLineBinding::query()
                ->whereNotNull('verified_at')
                ->whereIn('line_user_id', $lineUserIds)
                ->where('student_id', '!=', $student->id)
                ->pluck('student_id')
                ->unique();
            if ($siblingIds->isNotEmpty()) {
                $siblings = Student::whereIn('id', $siblingIds)->get();
                $allStudents = collect([['id' => $student->id, 'name' => $student->name]])
                    ->concat($siblings->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]))
                    ->values();
                if ($allStudents->count() > 1) {
                    $result['students'] = $allStudents;
                }
            }
        }

        return response()->json($result);
    }

    // ── Resolve LIFF ID from hostname (public, no auth) ──────────────────

    public function resolveLiff(Request $request)
    {
        $host = $request->getHost();

        // 多分校共用同一網域（例：13 新莊中平 與 15 大安 都用 daan.lifenet.com.tw）時，
        // 純靠 host 比對只會回傳「第一個」分校的 LIFF，導致其他分校家長拿到錯的 LINE Login
        // channel → getProfile().userId 與其綁定（屬不同 provider）對不上 → 自動登入 404。
        // 入口連結一律帶 campus_id（LineWebhookController::getPortalUrl），故 campus_id 才是權威來源。
        $requestedCampusId = (int) ($request->query('campus_id') ?? 0);
        if ($requestedCampusId > 0) {
            $byCampus = \Illuminate\Support\Facades\DB::table('Campus')
                ->where('id', $requestedCampusId)
                ->whereNotNull('LIFFID')
                ->where('LIFFID', '!=', '')
                ->first();
            if ($byCampus) {
                return response()->json([
                    'liff_id'     => $byCampus->LIFFID,
                    'campus_id'   => $byCampus->id,
                    'campus_name' => $byCampus->name,
                ]);
            }
        }

        // CRDE Phase 4 — immutable versioned routing store is authoritative once a
        // version is published. Resolution is deterministic (exact-then-wildcard);
        // ambiguity fails loud; absence returns null. Falls through to the Campus-
        // derived resolver only during the pre-migration transition (no version yet).
        $store = app(\App\Services\RoutingRuleStore::class);
        if ($store->activeVersion()) {
            $r = $store->resolve($host);
            if ($r['status'] === 'ambiguous') {
                \Illuminate\Support\Facades\Log::error('[resolveLiff] ambiguous campus domain mapping (routing store)', [
                    'host'       => $host,
                    'candidates' => $r['candidates'],
                    'version'    => $r['version'],
                ]);
                return response()->json(['liff_id' => null, 'error' => 'ambiguous_campus_domain'], 409);
            }
            if ($r['status'] !== 'ok') {
                return response()->json(['liff_id' => null]);
            }
            $campus = \Illuminate\Support\Facades\DB::table('Campus')->where('id', $r['campus_id'])->first(['id', 'name']);
            return response()->json([
                'liff_id'     => $r['liff_id'],
                'campus_id'   => $r['campus_id'],
                'campus_name' => $campus->name ?? null,
            ]);
        }

        // Deterministic host → campus resolution (CampusDomainResolver): EXACT match
        // only, ambiguity (shared URL) fails loud instead of silently serving the
        // first/wrong campus's LIFF — the root cause of the 2026-06-27 mis-binding.
        $campusRows = \Illuminate\Support\Facades\DB::table('Campus')
            ->whereNotNull('LIFFID')
            ->where('LIFFID', '!=', '')
            ->whereNotNull('URL')
            ->where('URL', '!=', '')
            ->get(['id', 'name', 'URL', 'LIFFID']);

        $resolution = \App\Services\CampusDomainResolver::resolve(
            $host,
            $campusRows->map(fn ($c) => [
                'id'      => (int) $c->id,
                'url'     => $c->URL,
                'liff_id' => $c->LIFFID,
            ])->all()
        );

        if ($resolution['status'] === 'ambiguous') {
            \Illuminate\Support\Facades\Log::error('[resolveLiff] ambiguous campus domain mapping', [
                'host'       => $host,
                'candidates' => $resolution['candidates'],
            ]);
            return response()->json(['liff_id' => null, 'error' => 'ambiguous_campus_domain'], 409);
        }

        if ($resolution['status'] !== 'ok') {
            return response()->json(['liff_id' => null]);
        }

        $campus = $campusRows->first(fn ($c) => (int) $c->id === $resolution['campus_id']);

        return response()->json([
            'liff_id'     => $resolution['liff_id'],
            'campus_id'   => $resolution['campus_id'],
            'campus_name' => $campus->name ?? null,
        ]);
    }

    // ── Login: LINE userId ────────────────────────────────────────────────

    public function loginWithLine(Request $request)
    {
        $data = $request->validate([
            'access_token' => 'required|string|max:2048',
            'campus_id'    => 'nullable|integer',
        ]);

        try {
            $profileResponse = Http::withToken($data['access_token'])
                ->acceptJson()
                ->timeout(5)
                ->get('https://api.line.me/v2/profile');
        } catch (\Throwable $e) {
            Log::warning('parent.line_login.profile_unavailable', [
                'campus_id' => (int) ($data['campus_id'] ?? 0),
            ]);
            SecurityAuditEvent::append('parent.auth', 'failure', [
                'campus_id' => (int) ($data['campus_id'] ?? 0),
            ], ['method' => 'line', 'reason_code' => 'profile_unavailable']);
            return response()->json(['message' => 'LINE authentication unavailable'], 503);
        }

        if (!$profileResponse->successful()) {
            SecurityAuditEvent::append('parent.auth', 'failure', [
                'campus_id' => (int) ($data['campus_id'] ?? 0),
            ], ['method' => 'line', 'reason_code' => 'profile_rejected', 'http_status' => $profileResponse->status()]);
            return response()->json(['message' => 'Invalid LINE authentication'], 401);
        }

        $lineUserId = (string) $profileResponse->json('userId', '');
        if (!$this->isValidLineUserId($lineUserId)) {
            SecurityAuditEvent::append('parent.auth', 'failure', [
                'campus_id' => (int) ($data['campus_id'] ?? 0),
            ], ['method' => 'line', 'reason_code' => 'invalid_subject']);
            return response()->json(['message' => 'Invalid LINE authentication'], 401);
        }

        $preferredCampusId = !empty($data['campus_id']) ? (int) $data['campus_id'] : null;
        $students = app(ParentGuardianAccessService::class)
            ->studentsForLineUser($lineUserId, $preferredCampusId);
        if ($students->isEmpty()) {
            SecurityAuditEvent::append('parent.auth', 'failure', [
                'campus_id' => (int) ($data['campus_id'] ?? 0),
            ], ['method' => 'line', 'reason_code' => 'binding_not_found']);
            return response()->json(['message' => '尚未綁定學生帳號（此入口僅供家長/學生）。請透過 LINE 官方帳號輸入「綁定 學生姓名 手機號碼」完成綁定'], 404);
        }

        // Create session for the first student (frontend can switch later)
        $firstStudent = $students->first();
        $result = $this->createSession(
            $firstStudent,
            null,
            StudentContactPhone::normalizedDigits($firstStudent),
            $lineUserId
        );
        SecurityAuditEvent::append('parent.auth', 'success', [
            'campus_id' => (int) ($firstStudent->CampusID ?? 0),
            'subject_type' => 'student',
            'subject_id' => $firstStudent->id,
        ], ['method' => 'line', 'student_count' => $students->count()]);
        $result['students'] = $this->parentStudentSwitcherRows($students);
        // Do not expose group members until this login has passed the same
        // cross-campus phone verification used by dashboard aggregation.
        $result['identity_groups'] = !empty($result['identity_group_id'])
            ? $this->identityGroupRows($students)
            : [];
        return response()->json($result);
    }

    // ── Switch student (for multi-child parents) ───────────────────────────

    public function switchStudent(Request $request)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            SecurityAuditEvent::append('parent.sibling_switch', 'failure', [], [
                'method' => 'parent_session', 'reason_code' => 'session_missing', 'allowed' => false,
            ]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'student_id' => 'required|integer',
        ]);

        $targetStudent = Student::find($data['student_id']);
        if (!$targetStudent) {
            SecurityAuditEvent::append('parent.sibling_switch', 'failure', [
                'subject_type' => 'student', 'subject_id' => $data['student_id'],
            ], ['method' => 'parent_session', 'reason_code' => 'target_not_found', 'allowed' => false]);
            return response()->json(['message' => 'Student not found'], 404);
        }

        $currentStudent = Student::find($session->StudentID);
        $allowed = false;
        $targetGroup = $this->identityService()->groupForStudent((int) $targetStudent->id);
        if ($session->getAttribute('identity_group_id')
            && $targetGroup
            && (int) $targetGroup->id === (int) $session->getAttribute('identity_group_id')
            && $this->identityService()->accessMode((int) $session->getAttribute('identity_group_id')) !== StudentIdentityService::MODE_OFF) {
            $allowed = true;
        }

        $sessionLineId = trim((string) ($session->getAttribute('line_user_id') ?? ''));
        if (!$allowed && $sessionLineId !== '' && $this->isValidLineUserId($sessionLineId)) {
            // Canonical guardian path (multi-guardian / multi-child) + SLB orphan fallback.
            $allowed = app(ParentGuardianAccessService::class)
                ->lineMayAccessStudent($sessionLineId, (int) $targetStudent->id);
        } elseif (!$allowed && $currentStudent) {
            $access = app(ParentGuardianAccessService::class);
            if (ParentGuardianAccessService::portalDualReadEnabled()) {
                // Phone-login multi-child: shared active/read_only guardians (not SLB).
                $allowed = $access->studentsSharingActiveGuardians((int) $currentStudent->id)
                    ->contains(fn ($s) => (int) $s->id === (int) $targetStudent->id);
            }
            if (!$allowed) {
                // Flag-off / no guardian links: legacy shared verified SLB only.
                $currentLineIds = StudentLineBinding::where('student_id', $currentStudent->id)
                    ->verified()
                    ->pluck('line_user_id')
                    ->filter(fn ($id) => $this->isValidLineUserId($id));
                if ($currentLineIds->isNotEmpty()) {
                    $allowed = StudentLineBinding::query()
                        ->whereNotNull('verified_at')
                        ->where('student_id', $targetStudent->id)
                        ->whereIn('line_user_id', $currentLineIds)
                        ->exists();
                }
            }
            // PRD-B FR-B-001: 不再以「相同 Phone」允許切換，避免跨家庭 PII 洩漏。
        }

        if (!$allowed) {
            SecurityAuditEvent::append('parent.sibling_switch', 'failure', [
                'campus_id' => (int) ($targetStudent->CampusID ?? 0),
                'subject_type' => 'student', 'subject_id' => $targetStudent->id,
            ], ['method' => 'parent_session', 'reason_code' => 'shared_verified_binding_missing', 'allowed' => false]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Create new session for the target student (preserve LINE subject)
        SecurityAuditEvent::append('parent.sibling_switch', 'success', [
            'campus_id' => (int) ($targetStudent->CampusID ?? 0),
            'subject_type' => 'student', 'subject_id' => $targetStudent->id,
        ], ['method' => 'parent_session', 'reason_code' => 'shared_verified_binding', 'allowed' => true]);
        return response()->json($this->createSession(
            $targetStudent,
            $targetGroup?->id,
            StudentContactPhone::normalizedDigits($targetStudent),
            $session->getAttribute('line_user_id')
        ));
    }

    // ── Dashboard ─────────────────────────────────────────────────────────

    public function dashboard(Request $request)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = Student::find($session->StudentID);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        [$identityGroup, $accessMode, $identityMembers] = $session->getAttribute('identity_group_id')
            ? $this->identityService()->parentContext((int) $student->id)
            : [null, StudentIdentityService::MODE_OFF, collect()];
        $crossCampusEnabled = $identityGroup
            && $accessMode !== StudentIdentityService::MODE_OFF
            && $identityMembers->count() > 1;
        $requestedScope = (string) $request->query('scope', 'all');
        $memberRows = $crossCampusEnabled ? $identityMembers : $identityMembers->filter(fn ($m) => (int) $m->student_id === (int) $student->id)->values();
        if ($memberRows->isEmpty()) {
            $memberRows = collect([(object) [
                'student_id' => (int) $student->id,
                'campus_id' => (int) $student->CampusID,
                'student' => $student,
            ]]);
        }
        if ($crossCampusEnabled && $requestedScope === 'campus') {
            $requestedCampusId = (int) ($request->query('campus_id') ?: 0);
            $memberRows = $identityMembers->filter(fn ($m) => (int) $m->campus_id === $requestedCampusId)->values();
            if ($memberRows->isEmpty()) {
                return response()->json(['message' => 'Forbidden: campus is not in the active identity group.'], 403);
            }
        }
        $studentIds = $memberRows->pluck('student_id')->map(fn ($id) => (int) $id)->values()->all();
        $campusIds = $memberRows->pluck('campus_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $activeScope = $crossCampusEnabled && $requestedScope === 'campus' ? 'campus' : ($crossCampusEnabled ? 'all' : 'campus');
        $campusMap = \App\Models\Campus::query()->whereIn('id', $campusIds)->get()->keyBy('id');
        $studentCampusMap = $memberRows->keyBy('student_id')->map(fn ($m) => [
            'campus_id' => (int) $m->campus_id,
            'campus_name' => optional($campusMap->get((int) $m->campus_id))->getAttribute('name'),
        ]);

        $classes = StudentClass::query()->whereIn('StudentID', $studentIds)
            ->orderBy('ID', 'desc')
            ->get();

        $classIds = $classes->pluck('ID')->all();
        $observedUsedByClass = SessionDeductionService::batchObservedUsedSessions($classIds);
        $paidAtMap = AlertController::lastPaidAtByStudentClassIds($classIds);

        // 共用方案（course_packages）：同一池的多科課程，每筆 StudentClass.SessionCount 都 = 池總堂數。
        // 若用 per-member 計算，家長端會看到「每科都顯示總堂數」且總數被重複加總（in-app #158/#162 家族）。
        // 池子（remaining/total/used）才是單一真相，這裡載入供 sessionMetrics 與顯示聚合使用。
        $packageIds = $classes->pluck('PackageID')->filter(fn ($id) => (int) $id > 0)->unique()->values()->all();
        $packageMap = !empty($packageIds)
            ? CoursePackage::query()->whereIn('id', $packageIds)->get()->keyBy('id')
            : collect();

        // PRD-H (2026-04-18)：月結制專用的「本月已上堂數」與預估月費，供家長端學習情況卡片顯示。
        $monthlyClassIds = $classes
            ->filter(fn ($c) => (string) ($c->ScheduleMode ?? 'count') !== 'count')
            ->pluck('ID')->values()->all();
        $attendedThisMonth = [];
        $currentMonthLabel = Carbon::now()->format('n') . '月';
        $monthlyBillingPeriods = [];
        $monthlyDisplayLabels = [];
        $monthlyInvoiceRowsByClass = collect();
        if (!empty($monthlyClassIds)) {
            $monthlyInvoiceRowsByClass = Invoice::query()
                ->whereIn('StudentID', $studentIds)
                ->whereIn('StudentClassID', $monthlyClassIds)
                ->notVoided()
                ->whereNotNull('billing_period')
                ->orderByRaw("CASE WHEN Status IN ('unpaid', 'partial', 'partially_paid', 'open', 'pending') THEN 0 ELSE 1 END")
                ->orderBy('billing_period', 'desc')
                ->orderBy('DueDate', 'desc')
                ->get(['StudentClassID', 'billing_period', 'Status', 'DueDate', 'IssueDate'])
                ->groupBy('StudentClassID');

            foreach ($classes as $class) {
                if (!in_array((int) $class->ID, $monthlyClassIds, true)) {
                    continue;
                }
                $period = $this->resolveMonthlyDisplayPeriod($class, $monthlyInvoiceRowsByClass->get($class->ID));
                $monthlyBillingPeriods[(int) $class->ID] = $period;
                $monthlyDisplayLabels[(int) $class->ID] = $this->formatBillingPeriodLabel($period);
            }

            $periodStarts = collect($monthlyBillingPeriods)->map(fn ($period) => $period . '-01');
            $queryStart = $periodStarts->min() ?? Carbon::now()->startOfMonth()->toDateString();
            $queryEnd = $periodStarts->max()
                ? Carbon::parse($periodStarts->max())->endOfMonth()->toDateString()
                : Carbon::now()->endOfMonth()->toDateString();

            $attendedThisMonth = ClassSession::whereIn('StudentClassID', $monthlyClassIds)
                ->whereBetween('SessionDate', [$queryStart, $queryEnd])
                ->whereIn('Status', ['completed', 'attended', 'late'])
                ->get(['StudentClassID', 'SessionDate'])
                ->filter(function ($session) use ($monthlyBillingPeriods) {
                    $classId = (int) $session->StudentClassID;
                    $period = $monthlyBillingPeriods[$classId] ?? null;
                    if (!$period) {
                        return false;
                    }
                    return substr((string) $session->SessionDate, 0, 7) === $period;
                })
                ->groupBy('StudentClassID')
                ->map(fn ($group) => $group->count())
                ->toArray();
        }

        $sessionMetrics = static function (StudentClass $c) use ($observedUsedByClass, $packageMap): array {
            $mode = (string) ($c->ScheduleMode ?? 'count');

            // 共用方案成員（堂數制）：已用/剩餘一律以「池子」為準，避免每科各算造成
            // 顯示與總計重複（每科 = 總堂數）。月結制共用方案不走堂數池，維持原邏輯。
            $pkgId = (int) ($c->PackageID ?? 0);
            if ($mode === 'count' && $pkgId > 0 && isset($packageMap[$pkgId])) {
                $pkg = $packageMap[$pkgId];
                return [
                    'used' => max(0, (int) $pkg->used_sessions),
                    'remaining' => max(0, (int) $pkg->remaining_sessions),
                ];
            }

            $purchased = max(0, (int) ($c->SessionCount ?? 0));
            if ($mode !== 'count' || $purchased <= 0) {
                return [
                    'used' => max(0, (int) ($c->UsedSessions ?? 0)),
                    'remaining' => max(0, (int) ($c->RemainingSessions ?? 0)),
                ];
            }
            $observed = max(0, (int) ($observedUsedByClass[$c->ID] ?? 0));
            $used = min($purchased, $observed);

            return [
                'used' => $used,
                'remaining' => max(0, $purchased - $used),
            ];
        };

        // Learning records (approved only) — paginated
        $lrPage    = max(1, (int) $request->query('lr_page', 1));
        $lrPerPage = min(50, max(5, (int) $request->query('lr_per_page', 10)));
        $records      = [];
        $lrTotal      = 0;
        $lrHasMore    = false;
        if (!empty($classIds)) {
            $lrQuery = LearningRecord::active()
                ->whereIn('StudentClassID', $classIds)
                ->where('Status', 'approved');
            $lrTotal = $lrQuery->count();
            $recordsRaw = $lrQuery
                ->orderBy('ApprovedAt', 'desc')
                ->skip(($lrPage - 1) * $lrPerPage)
                ->take($lrPerPage)
                ->get();

            $sessionNumbers = LearningRecordController::batchSessionNumbers($recordsRaw);
            $feedbacks = LearningRecordFeedback::whereIn('learning_record_id', $recordsRaw->pluck('id'))
                ->get()
                ->keyBy('learning_record_id');
            // 批次載入回覆串 + 員工姓名（避免 N+1），供家長端顯示老師/主任回覆與「有新回覆」紅點。
            $repliesByFeedback = \App\Models\LearningRecordFeedbackReply::whereIn('feedback_id', $feedbacks->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('feedback_id');
            $replyAuthorIds = $repliesByFeedback->flatten(1)->pluck('author_user_id')->filter()->unique()->values();
            $replyAuthorNames = $replyAuthorIds->isNotEmpty()
                ? \Illuminate\Support\Facades\DB::table('User')->whereIn('id', $replyAuthorIds)->pluck('Name', 'id')->toArray()
                : [];
            // #986: batch-load record teachers (may differ from course teacher on substitutes).
            $recordTeacherIds = $recordsRaw->pluck('TeacherID')->filter()->unique()->values();
            $recordTeacherNames = $recordTeacherIds->isNotEmpty()
                ? User::query()->whereIn('id', $recordTeacherIds)->pluck('Name', 'id')
                : collect();

            $records = $recordsRaw->map(function ($rec) use ($classes, $sessionNumbers, $feedbacks, $repliesByFeedback, $replyAuthorNames, $recordTeacherNames, $studentCampusMap) {
                    $rec->teacher_name = $rec->TeacherID ? ($recordTeacherNames[$rec->TeacherID] ?? null) : null;
                    $sc = $classes->firstWhere('ID', $rec->StudentClassID);
                    $campus = $sc ? $studentCampusMap->get((int) $sc->StudentID, []) : [];
                    $rec->campus_id = $campus['campus_id'] ?? null;
                    $rec->campus_name = $campus['campus_name'] ?? null;
                    $fromCourse = $sc ? $this->resolveSubjectName($sc) : null;
                    $rawSubject = trim((string) ($rec->Subject ?? ''));
                    // Prefer a meaningful course-level subject name (not the generic fallback '課程').
                    // When the course has no subject configured, normalise the record's own Subject field.
                    $meaningful = ($fromCourse && $fromCourse !== '課程') ? $fromCourse : null;
                    if ($meaningful) {
                        $rec->Subject = $meaningful;
                    } elseif ($rawSubject !== '') {
                        $mapped = $this->mapSubjectLabel($rawSubject);
                        $rec->Subject = $mapped !== '' ? $mapped : $rawSubject;
                    } else {
                        $rec->Subject = $fromCourse ?: '課程';
                    }
                    $rec->session_number = $sessionNumbers[(int) $rec->id] ?? null;
                    $fb = $feedbacks->get((int) $rec->id);
                    $fbReplies = $fb ? ($repliesByFeedback->get((int) $fb->id) ?? collect()) : collect();
                    $lastParentRead = $fb && $fb->last_read_by_parent_at ? $fb->last_read_by_parent_at : null;
                    $hasUnreadReply = $fbReplies->contains(function ($r) use ($lastParentRead) {
                        if ((string) $r->author_role === 'parent' || !$r->created_at) return false;
                        return !$lastParentRead || $r->created_at->gt($lastParentRead);
                    });
                    $rec->parent_feedback = $fb ? [
                        'id' => (int) $fb->id,
                        'content' => $fb->content,
                        'updated_at' => optional($fb->updated_at)->toIso8601String(),
                        'has_unread_reply' => $hasUnreadReply,
                        'replies' => $fbReplies->map(fn ($r) => [
                            'id' => (int) $r->id,
                            'author_role' => (string) $r->author_role,
                            'author_name' => ((string) $r->author_role !== 'parent' && $r->author_user_id)
                                ? ($replyAuthorNames[$r->author_user_id] ?? null) : null,
                            'content' => $r->content,
                            'created_at' => optional($r->created_at)->toIso8601String(),
                        ])->values()->all(),
                    ] : null;
                    return $rec;
                });
            $lrHasMore = ($lrPage * $lrPerPage) < $lrTotal;
        }

        // Attendance history — FR-B-003: date / time / subject / teacher / status
        $signIns = StudentSignIn::query()->whereIn('StudentID', $studentIds)
            ->orderBy('SignInDT', 'desc')
            ->limit(100)
            ->get();
        $sessionIds = $signIns->pluck('ClassSessionID')->filter()->unique()->values()->all();
        $sessionsById = !empty($sessionIds)
            ? ClassSession::whereIn('id', $sessionIds)->get()->keyBy('id')
            : collect();
        // #986: batch-load course teacher names once (was User::find per sign-in row, ≤100/req).
        $courseTeacherIds = $classes->pluck('TeacherID')->filter()->unique()->values();
        $courseTeacherNames = $courseTeacherIds->isNotEmpty()
            ? User::query()->whereIn('id', $courseTeacherIds)->pluck('Name', 'id')
            : collect();
        $attendance = $signIns->map(function ($row) use ($classes, $sessionsById, $courseTeacherNames, $studentCampusMap) {
            $status = (string) ($row->Status ?? '');
            $row->status_label = match ($status) {
                'present' => '到班',
                'late' => '遲到',
                'absent' => '缺席',
                'leave', 'excused' => '請假',
                default => $status,
            };
            $row->is_late = $status === 'late';

            $session = $row->ClassSessionID ? $sessionsById->get($row->ClassSessionID) : null;
            $studentClass = $session ? $classes->firstWhere('ID', $session->StudentClassID) : null;

            $date = null;
            $time = null;
            if ($session) {
                $date = $session->SessionDate;
                $start = $this->trimToHM($session->StartTime);
                $end = $this->trimToHM($session->EndTime);
                $time = $start !== '' && $end !== '' ? "$start-$end" : $start;
            } elseif ($row->SignInDT) {
                try {
                    $dt = Carbon::parse($row->SignInDT);
                    $date = $dt->toDateString();
                    $time = $dt->format('H:i');
                } catch (\Throwable $e) {
                }
            }
            $row->date = $date;
            $row->time = $time;
            $row->subject = $studentClass ? $this->resolveSubjectName($studentClass) : null;
            $campus = $studentClass ? $studentCampusMap->get((int) $studentClass->StudentID, []) : [];
            $row->campus_id = $campus['campus_id'] ?? null;
            $row->campus_name = $campus['campus_name'] ?? null;

            $row->teacher_name = ($studentClass && !empty($studentClass->TeacherID))
                ? ($courseTeacherNames[$studentClass->TeacherID] ?? null)
                : null;

            return $row;
        });

        // Per-course breakdown — 家長端「進行中」：
        //   - 堂數制：剩餘 > 0 才顯示（已用完或已停課+已繳不列）
        //   - 月結制：保留已繳結案課程，前端以「已繳費・課程已結束」降低家長誤解
        $perCourse = $classes
            ->filter(function ($c) use ($sessionMetrics) {
                $paid    = (bool) $c->Paid;
                $stopped = (bool) $c->Stop;
                $isCount = (string) ($c->ScheduleMode ?? 'count') === 'count';

                if ($isCount && $stopped && $paid) {
                    return false;
                }

                if ($isCount) {
                    return (int) $sessionMetrics($c)['remaining'] > 0;
                }

                // monthly mode：持續進行，不受 RemainingSessions 影響
                return true;
            })
            ->map(function ($c) use ($sessionMetrics, $attendedThisMonth, $monthlyBillingPeriods, $monthlyDisplayLabels, $paidAtMap, $packageMap, $studentCampusMap) {
                $metrics   = $sessionMetrics($c);
                $isMonthly = (string) ($c->ScheduleMode ?? 'count') !== 'count';
                $monthlyTarget  = (int) ($c->monthly_sessions ?? 0);
                $monthlyFee     = $isMonthly ? $this->resolveMonthlyFee($c) : 0;
                $attended       = $isMonthly ? (int) ($attendedThisMonth[$c->ID] ?? 0) : 0;
                $paid           = $this->isClassPaid($c, $paidAtMap);
                $stopped        = (bool) $c->Stop;

                // 共用方案成員：附帶池子資訊，讓前端把每張卡標記為「共用方案」並用同一池數字，
                // 避免家長誤以為每科各有一份總堂數。
                $pkgId   = (int) ($c->PackageID ?? 0);
                $pkg     = ($pkgId > 0 && isset($packageMap[$pkgId])) ? $packageMap[$pkgId] : null;
                $isPkg   = $pkg !== null && !$isMonthly;

                $campus = $studentCampusMap->get((int) $c->StudentID, []);
                return [
                    'id'                   => $c->ID,
                    'student_id'           => (int) $c->StudentID,
                    'campus_id'            => $campus['campus_id'] ?? (int) ($c->student->CampusID ?? 0),
                    'campus_name'          => $campus['campus_name'] ?? null,
                    'subject'              => $this->resolveSubjectName($c),
                    'schedule_mode'        => $c->ScheduleMode,
                    'sessions_purchased'   => $c->SessionCount,
                    'remaining_sessions'   => $metrics['remaining'],
                    'used_sessions'        => $metrics['used'],
                    'is_stopped'           => $stopped,
                    'paid'                 => $paid,
                    'payment_status'       => $paid ? 'paid' : 'unpaid',
                    'payment_status_label' => $paid ? '已繳費' : '未繳費',
                    'lifecycle_status'     => $stopped ? 'closed' : 'active',
                    'lifecycle_status_label' => $stopped ? '課程已結束' : '進行中',
                    // 共用方案池（堂數制）：null 代表非共用方案，前端維持原本 per-course 顯示。
                    'is_package'                 => $isPkg,
                    'package_id'                 => $isPkg ? (int) $pkgId : null,
                    'package_name'               => $isPkg ? ((string) ($pkg->name ?? '') ?: null) : null,
                    'package_total_sessions'     => $isPkg ? max(0, (int) $pkg->total_sessions) : null,
                    'package_remaining_sessions' => $isPkg ? max(0, (int) $pkg->remaining_sessions) : null,
                    'package_used_sessions'      => $isPkg ? max(0, (int) $pkg->used_sessions) : null,
                    'billing_period'       => $isMonthly ? ($monthlyBillingPeriods[(int) $c->ID] ?? null) : null,
                    'display_month_label'  => $isMonthly ? ($monthlyDisplayLabels[(int) $c->ID] ?? $currentMonthLabel) : null,
                    'settlement_day'       => $isMonthly ? ((int) ($c->settlement_day ?? 0) ?: null) : null,
                    'monthly_target'       => $isMonthly ? ($monthlyTarget ?: null) : null,
                    'attended_this_month'  => $isMonthly ? $attended : null,
                    'monthly_fee_estimate' => $isMonthly ? $monthlyFee : null,
                ];
            })
            ->values();
        $visibleClassIds = $perCourse->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        // 總剩餘堂數：非共用方案逐課加總；共用方案「每池只算一次」（避免每科重複加總成數倍）。
        $nonPackageRemaining = $perCourse
            ->filter(fn ($row) => (string) ($row['schedule_mode'] ?? 'count') === 'count')
            ->filter(fn ($row) => (int) ($row['package_id'] ?? 0) <= 0)
            ->sum(fn ($row) => (int) ($row['remaining_sessions'] ?? 0));
        $visiblePackageIds = $perCourse
            ->filter(fn ($row) => (int) ($row['package_id'] ?? 0) > 0)
            ->pluck('package_id')->unique()->values();
        $packageRemaining = $visiblePackageIds
            ->sum(fn ($pid) => isset($packageMap[$pid]) ? max(0, (int) $packageMap[$pid]->remaining_sessions) : 0);
        $remainingTotal = $nonPackageRemaining + $packageRemaining;

        // 科目剩餘明細：非共用方案照科目分組；共用方案以「池子」為單位顯示一筆（合併科目名 + 共用標記），
        // 不再每科都掛上池總堂數。
        $remainingBySubject = [];
        foreach ($perCourse as $row) {
            if ((string) ($row['schedule_mode'] ?? 'count') !== 'count') {
                continue;
            }
            if ((int) ($row['package_id'] ?? 0) > 0) {
                continue;
            }
            $rem = (int) ($row['remaining_sessions'] ?? 0);
            if ($rem <= 0) {
                continue;
            }
            $subj = (string) $row['subject'];
            $remainingBySubject[$subj] = ($remainingBySubject[$subj] ?? 0) + $rem;
        }
        foreach ($visiblePackageIds as $pid) {
            $pkg = $packageMap[$pid] ?? null;
            if (!$pkg) {
                continue;
            }
            $rem = max(0, (int) $pkg->remaining_sessions);
            if ($rem <= 0) {
                continue;
            }
            $subs = $perCourse
                ->filter(fn ($row) => (int) ($row['package_id'] ?? 0) === (int) $pid)
                ->pluck('subject')->unique()->implode('/');
            $label = (string) (($pkg->name ?? '') ?: $subs);
            $remainingBySubject[$label . '（共用）'] = $rem;
        }
        arsort($remainingBySubject);

        // Payment alerts — only show courses that still require parent action
        $paymentAlerts = $classes
            ->filter(function ($c) use ($paidAtMap) {
                if ($c->ScheduleMode !== 'count' && ($c->SessionCount ?? 0) <= 0) {
                    return false;
                }

                $paid = $this->isClassPaid($c, $paidAtMap);
                $stopped = (bool) $c->Stop;

                // Parent portal reminders are payment actions, not director renewal alerts.
                if ($paid) {
                    return false;
                }

                if ($stopped) {
                    return false;
                }

                return true;
            })
            ->map(function ($c) use ($sessionMetrics, $paidAtMap, $studentCampusMap) {
                $campus = $studentCampusMap->get((int) $c->StudentID, []);
                return [
                    'class_id'           => $c->ID,
                    'campus_id'          => $campus['campus_id'] ?? null,
                    'campus_name'        => $campus['campus_name'] ?? null,
                    'subject'            => $this->resolveSubjectName($c),
                    'remaining_sessions' => (int) $sessionMetrics($c)['remaining'],
                    'paid'               => $this->isClassPaid($c, $paidAtMap),
                    'is_stopped'         => (bool) $c->Stop,
                ];
            })
            ->values();

        $upcomingClassIds = $classes->filter(function ($c) use ($sessionMetrics) {
            if ((string) ($c->ScheduleMode ?? 'count') === 'count') {
                return (int) $sessionMetrics($c)['remaining'] > 0;
            }
            if ((bool) $c->Stop && (bool) $c->Paid) {
                return false;
            }

            return true;
        })->pluck('ID')->all();

        // Upcoming sessions
        $upcomingSessions = [];
        if (!empty($upcomingClassIds)) {
            $upcomingSessions = ClassSession::whereIn('StudentClassID', $upcomingClassIds)
                ->where('SessionDate', '>=', Carbon::today())
                ->whereIn('Status', ['scheduled', 'rescheduled', 'leave_requested'])
                ->orderBy('SessionDate', 'asc')
                ->orderBy('StartTime', 'asc')
                ->limit(20)
                ->get()
                ;
            $leaveWorkflows = ExceptionWorkflow::query()->whereIn('student_id', $studentIds)
                ->where('type', 'student_leave')
                ->whereIn('class_session_id', $upcomingSessions->pluck('id')->all())
                ->get()
                ->keyBy('class_session_id');
            $upcomingSessions = $upcomingSessions->map(function ($session) use ($classes, $leaveWorkflows, $studentCampusMap) {
                $c = $classes->firstWhere('ID', $session->StudentClassID);
                $session->Subject = $c ? $this->resolveSubjectName($c) : null;
                $campus = $c ? $studentCampusMap->get((int) $c->StudentID, []) : [];
                $session->campus_id = $campus['campus_id'] ?? null;
                $session->campus_name = $campus['campus_name'] ?? null;
                $session->StartTime = $this->trimToHM($session->StartTime);
                $session->EndTime   = $this->trimToHM($session->EndTime);
                $workflow = $leaveWorkflows->get($session->id);
                $session->LeaveWorkflowStatus = $workflow?->status;
                $session->LeaveWorkflowReason = is_array($workflow?->payload)
                    ? ($workflow->payload['rejection_reason'] ?? null)
                    : null;
                return $session;
            });
        }

        $invoices = [];
        try {
            $invoices = !empty($visibleClassIds)
                ? Invoice::with(['items', 'payments'])
                    ->whereIn('StudentID', $studentIds)
                    ->whereIn('StudentClassID', $visibleClassIds)
                    ->notVoided()
                    ->orderBy('IssueDate', 'desc')
                    ->get()
                    ->map(function ($invoice) use ($studentCampusMap) {
                        $campus = $studentCampusMap->get((int) $invoice->StudentID, []);
                        $invoice->campus_id = $campus['campus_id'] ?? null;
                        $invoice->campus_name = $campus['campus_name'] ?? null;
                        return $invoice;
                    })
                : collect();
        } catch (\Exception $e) {}

        $announcements = [];
        try {
            $announcements = Announcement::where('IsActive', true)
                ->where(function ($query) use ($campusIds) {
                    $query->whereNull('BranchID')
                        ->orWhereIn('BranchID', $campusIds ?: [0]);
                })
                ->where(function ($query) use ($studentIds) {
                    $query->whereNull('TargetStudentID')
                        ->orWhereIn('TargetStudentID', $studentIds ?: [0]);
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($ann) use ($campusMap) {
                    $ann->campus_id = $ann->BranchID ? (int) $ann->BranchID : null;
                    $ann->campus_name = $ann->BranchID ? optional($campusMap->get((int) $ann->BranchID))->name : null;
                    return $ann;
                });
        } catch (\Exception $e) {}

        $campusName = null;
        try {
            $campus = \App\Models\Campus::find($student->CampusID);
            $campusName = $campus ? $campus->getAttribute('name') : null;
        } catch (\Exception $e) {}

        // PRD-B FR-B-001: Siblings 僅透過 LINE 綁定解析，不再以相同 Phone 自動帶出。
        // 只接受有效 LINE user ID 格式（U+32hex），過濾 backfill 產生的無效值。
        $lineUserIds = StudentLineBinding::where('student_id', $student->id)
            ->verified()
            ->pluck('line_user_id')
            ->filter(fn ($id) => $this->isValidLineUserId($id));
        $siblingIdsByLine = $lineUserIds->isNotEmpty()
            ? StudentLineBinding::query()
                ->whereNotNull('verified_at')
                ->whereIn('line_user_id', $lineUserIds)
                ->where('student_id', '!=', $student->id)
                ->pluck('student_id')
                ->unique()
            : collect();

        $siblingStudents = $siblingIdsByLine->isNotEmpty()
            ? Student::whereIn('id', $siblingIdsByLine)->get()
            : collect();
        $siblingStudents = $siblingStudents->reject(fn ($s) => in_array((int) $s->id, $studentIds, true));
        $allStudents = collect([['id' => $student->id, 'name' => $student->name]])
            ->concat($siblingStudents->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]))
            ->values();

        $assessmentProgress = $this->buildParentAssessmentProgress(
            $studentIds,
            $campusIds,
            $classes,
            $studentCampusMap,
            $memberRows
        );

        $progressSummary = $this->buildProgressSummary(
            $session,
            $student,
            $classes,
            $perCourse,
            $paymentAlerts,
            $upcomingSessions,
            $records
        );

        return response()->json([
            'student' => [
                'id'          => $student->id,
                'name'        => $student->name,
                'grade'       => $student->ClassID ?? null,
                'school'      => $student->SchoolName ?? null,
                'campus_name' => $campusName,
                'campus_id'   => (int) ($student->CampusID ?? 0),
                'line_linked' => StudentLineBinding::where('student_id', $student->id)->verified()->exists(),
                'identity_group_id' => $crossCampusEnabled ? (int) $identityGroup->id : null,
            ],
            'identity_group_id' => $crossCampusEnabled ? (int) $identityGroup->id : null,
            'active_scope' => $activeScope,
            'cross_campus_access' => $crossCampusEnabled ? $accessMode : StudentIdentityService::MODE_OFF,
            'enrollments' => $crossCampusEnabled ? $memberRows->map(function ($member) use ($campusMap) {
                return [
                    'student_id' => (int) $member->student_id,
                    'campus_id' => (int) $member->campus_id,
                    'campus_name' => optional($campusMap->get((int) $member->campus_id))->getAttribute('name'),
                    'name' => (string) ($member->student->name ?? ''),
                ];
            })->values()->all() : [],
            'students' => $allStudents->count() > 1 ? $allStudents->toArray() : null,
            'current_month_label'      => $currentMonthLabel,
            'remaining_sessions_total' => $remainingTotal,
            'remaining_by_subject'     => $remainingBySubject,
            'classes'                  => $perCourse,
            'payment_alerts'           => $paymentAlerts,
            'learning_records'         => $records,
            'learning_records_meta'    => [
                'page'     => $lrPage,
                'per_page' => $lrPerPage,
                'total'    => $lrTotal,
                'has_more' => $lrHasMore,
            ],
            'attendance_history'       => $attendance,
            'upcoming_sessions'        => $upcomingSessions,
            'invoices'                 => $invoices,
            'announcements'            => $announcements,
            'assessment_progress'      => $assessmentProgress,
            'progress_summary'         => $progressSummary,
        ]);
    }

    /**
     * Parent-safe assessment projection: reviewed results only, scoped by the
     * already-resolved parent student/campus context. Internal notes, IDs and
     * teacher remediation instructions never leave the staff workflow.
     */
    private function buildParentAssessmentProgress(
        array $studentIds,
        array $campusIds,
        $classes,
        $studentCampusMap,
        $memberRows
    ): array {
        $publishedAssessmentIds = Assessment::query()
            ->whereIn('campus_id', $campusIds ?: [0])
            ->whereIn('status', ['published', 'closed'])
            ->pluck('id');
        $query = AssessmentResult::query()
            ->with('assessment')
            ->whereIn('student_id', $studentIds ?: [0])
            ->where('status', 'reviewed')
            ->whereIn('assessment_id', $publishedAssessmentIds);

        $total = (clone $query)->count();
        $results = $query
            ->orderByDesc(DB::raw('COALESCE(reviewed_at, recorded_at, created_at)'))
            ->limit(20)
            ->get();

        if ($results->isEmpty()) {
            return [
                'version' => 'v1',
                'items' => [],
                'meta' => ['total_reviewed' => 0, 'returned' => 0, 'has_more' => false],
            ];
        }

        $resultIds = $results->pluck('id')->map(fn ($id) => (int) $id)->all();
        $actions = AssessmentRemediationAction::query()
            ->whereIn('assessment_result_id', $resultIds)
            ->whereIn('student_id', $studentIds ?: [0])
            ->whereIn('campus_id', $campusIds ?: [0])
            ->where('status', '!=', AssessmentRemediationAction::STATUS_CANCELLED)
            ->get()
            ->groupBy('assessment_result_id');
        $classesById = collect($classes)->keyBy('ID');
        $studentNames = collect($memberRows)->mapWithKeys(fn ($member) => [
            (int) $member->student_id => (string) ($member->student->name ?? ''),
        ]);
        $subjectIds = $results->map(fn ($result) => (int) ($result->assessment->subject_id ?? 0))
            ->filter()->unique()->values();
        $subjectNames = $subjectIds->isNotEmpty()
            ? Subject::query()->whereIn('id', $subjectIds)->pluck('Subject_Name', 'id')
            : collect();

        $items = $results->map(function (AssessmentResult $result) use ($actions, $classesById, $studentCampusMap, $studentNames, $subjectNames) {
            $assessment = $result->assessment;
            $class = $classesById->get((int) $result->student_class_id);
            $subject = $assessment->subject_id
                ? (string) ($subjectNames->get((int) $assessment->subject_id) ?? '')
                : ($class ? $this->resolveSubjectName($class) : '課程');
            $subject = $subject !== '' ? $subject : ($class ? $this->resolveSubjectName($class) : '課程');
            $activeActions = $actions->get((int) $result->id, collect());
            $statuses = $activeActions->pluck('status')->all();
            $remediationStatus = empty($statuses)
                ? 'none'
                : (in_array(AssessmentRemediationAction::STATUS_IN_PROGRESS, $statuses, true)
                    ? AssessmentRemediationAction::STATUS_IN_PROGRESS
                    : (in_array(AssessmentRemediationAction::STATUS_OPEN, $statuses, true)
                        ? AssessmentRemediationAction::STATUS_OPEN
                        : AssessmentRemediationAction::STATUS_COMPLETED));
            $passingScore = $assessment?->passing_score;
            $outcome = $passingScore === null
                ? null
                : ((float) $result->score >= (float) $passingScore ? 'achieved' : 'practice');
            $campus = $studentCampusMap->get((int) $result->student_id, []);

            return [
                'student_name' => $studentNames->get((int) $result->student_id) ?: null,
                'campus_name' => $campus['campus_name'] ?? null,
                'title' => (string) ($assessment->title ?? '學習檢測'),
                'subject' => $subject,
                'score' => (float) $result->score,
                'max_score' => (float) $result->max_score_snapshot,
                'percent' => (float) $result->percent,
                'outcome' => $outcome,
                'outcome_label' => $outcome === 'achieved' ? '已達標' : ($outcome === 'practice' ? '建議再練習' : '已完成檢測'),
                'remediation_status' => $remediationStatus,
                'remediation_status_label' => match ($remediationStatus) {
                    AssessmentRemediationAction::STATUS_IN_PROGRESS => '補強進行中',
                    AssessmentRemediationAction::STATUS_OPEN => '待開始補強',
                    AssessmentRemediationAction::STATUS_COMPLETED => '補強已完成',
                    default => '尚未安排補強',
                },
                'focus_areas' => $activeActions->pluck('knowledge_tag')->filter()->unique()->values()->take(4)->all(),
                'reviewed_at' => optional($result->reviewed_at ?? $result->recorded_at ?? $result->getAttribute('created_at'))->toIso8601String(),
            ];
        })->values()->all();

        return [
            'version' => 'v1',
            'items' => $items,
            'meta' => [
                'total_reviewed' => (int) $total,
                'returned' => count($items),
                'has_more' => $total > count($items),
            ],
        ];
    }

    /**
     * 家長進度中心摘要（PRD: enterprise dashboard parent portal v2）。
     * 提供四個聚合卡片資料：本週學習、下次課程、待確認事項、繳費狀態。
     * 僅做唯讀聚合，不改寫既有資料。
     */
    private function buildProgressSummary(
        ParentSession $session,
        Student $student,
        $classes,
        $perCourse,
        $paymentAlerts,
        $upcomingSessions,
        $records
    ): array {
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();
        $todayStr = Carbon::now()->toDateString();

        $weekRecords = collect($records)->filter(function ($rec) use ($weekStart, $weekEnd) {
            $date = (string) ($rec->SessionDate ?? '');
            if ($date === '') {
                return false;
            }
            try {
                $d = Carbon::parse($date);
                return $d->betweenIncluded($weekStart, $weekEnd);
            } catch (\Throwable $e) {
                return false;
            }
        });

        $weekClassIds = $classes->pluck('ID')->all() ?: [0];
        $weeklyAttended = ClassSession::query()
            ->whereIn('StudentClassID', $weekClassIds)
            ->whereBetween('SessionDate', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereIn('Status', ['attended', 'completed', 'late'])
            ->count();

        $weeklyScheduled = ClassSession::query()
            ->whereIn('StudentClassID', $weekClassIds)
            ->whereBetween('SessionDate', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->count();

        $nextSession = collect($upcomingSessions)->first();
        $nextSessionPayload = null;
        if ($nextSession) {
            $nextSessionPayload = [
                'session_id'  => (int) ($nextSession->id ?? 0),
                'date'        => (string) ($nextSession->SessionDate ?? ''),
                'start_time'  => (string) ($nextSession->StartTime ?? ''),
                'end_time'    => (string) ($nextSession->EndTime ?? ''),
                'subject'     => (string) ($nextSession->Subject ?? '課程'),
                'status'      => (string) ($nextSession->Status ?? 'scheduled'),
                'is_today'    => ((string) ($nextSession->SessionDate ?? '')) === $todayStr,
            ];
        }

        $pendingActions = [];
        if (!empty($paymentAlerts) && count($paymentAlerts) > 0) {
            $pendingActions[] = [
                'key'   => 'payment',
                'title' => '繳費提醒',
                'count' => count($paymentAlerts),
                'cta_target' => 'billing',
            ];
        }
        if ($nextSessionPayload && $nextSessionPayload['is_today']) {
            $pendingActions[] = [
                'key'   => 'today_session',
                'title' => '今日有課程',
                'count' => 1,
                'cta_target' => 'schedule',
            ];
        }
        $unreadFeedback = collect($records)->filter(fn ($r) => empty($r->parent_feedback))->count();
        if ($unreadFeedback > 0) {
            $pendingActions[] = [
                'key'   => 'feedback',
                'title' => '評量待回饋',
                'count' => $unreadFeedback,
                'cta_target' => 'learning',
            ];
        }

        $flowRows = \App\Models\ExceptionWorkflow::query()
            ->where('source_type', 'parent_portal')
            ->where('parent_session_id', (int) $session->id)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'type', 'status', 'updated_at', 'due_at']);

        $interactionStatuses = [];
        foreach ($flowRows as $flow) {
            $interactionStatuses[] = [
                'kind' => (string) $flow->type,
                'flow_id' => (int) $flow->id,
                'status' => $this->mapParentFlowStatus((string) $flow->status),
                'raw_status' => (string) $flow->status,
                'updated_at' => optional($flow->updated_at)->toIso8601String(),
                'due_at' => optional($flow->due_at)->toIso8601String(),
            ];
        }

        $feedbackRows = collect($records)
            ->filter(fn ($r) => !empty($r->parent_feedback))
            ->take(10);
        foreach ($feedbackRows as $rec) {
            $interactionStatuses[] = [
                'kind' => 'learning_feedback',
                'flow_id' => (int) ($rec->id ?? 0),
                'status' => 'submitted',
                'raw_status' => 'submitted',
                'updated_at' => (string) ($rec->parent_feedback['updated_at'] ?? ''),
                'due_at' => null,
            ];
        }
        usort($interactionStatuses, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        $interactionStatuses = array_slice($interactionStatuses, 0, 10);

        $notificationGroups = [];
        foreach ($pendingActions as $action) {
            $target = (string) ($action['cta_target'] ?? 'learning');
            if (!isset($notificationGroups[$target])) {
                $notificationGroups[$target] = [
                    'target' => $target,
                    'title' => (string) ($action['title'] ?? '待處理事項'),
                    'count' => 0,
                    'severity' => $target === 'billing' ? 'warning' : 'normal',
                ];
            }
            $notificationGroups[$target]['count'] += (int) ($action['count'] ?? 0);
        }

        $unpaidCount = is_countable($paymentAlerts) ? count($paymentAlerts) : 0;
        $totalCourses = is_countable($perCourse) ? count($perCourse) : 0;
        $paidCount = max(0, $totalCourses - $unpaidCount);
        $paymentStatus = $unpaidCount === 0 ? 'all_clear' : ($unpaidCount >= $totalCourses ? 'all_pending' : 'partial');
        $feedbackProgram = $this->buildParentFeedbackProgramSummary($student);

        return [
            'week_label' => $weekStart->format('m/d') . '–' . $weekEnd->format('m/d'),
            'week_progress' => [
                'attended' => (int) $weeklyAttended,
                'scheduled' => (int) $weeklyScheduled,
                'records_filled' => $weekRecords->count(),
            ],
            'next_session' => $nextSessionPayload,
            'pending_actions' => array_values($pendingActions),
            'pending_total' => array_sum(array_map(fn ($p) => (int) ($p['count'] ?? 0), $pendingActions)),
            'interaction_statuses' => $interactionStatuses,
            'notifications' => array_values($notificationGroups),
            'payment' => [
                'status' => $paymentStatus,
                'paid_courses' => $paidCount,
                'unpaid_courses' => $unpaidCount,
                'total_courses' => $totalCourses,
            ],
            'feedback_program' => $feedbackProgram,
            'feedback_program_version' => 'v1',
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    private function buildParentFeedbackProgramSummary(Student $student): array
    {
        // v1 contract: timing policy + digest preview are read-only hints for parent portal UX.
        $windowStart = Carbon::now()->subDays(89)->startOfDay();
        $windowEnd = Carbon::now()->endOfDay();

        $approvedBase = LearningRecord::query()
            ->where('StudentID', $student->id)
            ->where('LearningRecord.Status', 'approved')
            ->whereNotNull('LearningRecord.ApprovedAt')
            ->whereBetween('LearningRecord.ApprovedAt', [$windowStart, $windowEnd]);

        $approvedCount = (clone $approvedBase)->count();
        $repliedCount = (clone $approvedBase)
            ->join('learning_record_feedbacks as lf', 'lf.learning_record_id', '=', 'LearningRecord.id')
            ->distinct('LearningRecord.id')
            ->count('LearningRecord.id');
        $unrepliedCount = max(0, $approvedCount - $repliedCount);
        $replyRate = $approvedCount > 0 ? round(($repliedCount / $approvedCount) * 100, 1) : 0.0;

        $unrepliedPreview = (clone $approvedBase)
            ->leftJoin('learning_record_feedbacks as lf', 'lf.learning_record_id', '=', 'LearningRecord.id')
            ->leftJoin('User as u', 'u.id', '=', 'LearningRecord.TeacherID')
            ->whereNull('lf.id')
            ->orderByDesc('LearningRecord.ApprovedAt')
            ->limit(3)
            ->get([
                'LearningRecord.id as learning_record_id',
                'LearningRecord.SessionDate as session_date',
                'LearningRecord.Subject as subject',
                'LearningRecord.ApprovedAt as approved_at',
                'u.Name as teacher_name',
            ])
            ->map(function ($row) {
                $approvedAt = $row->approved_at ? Carbon::parse($row->approved_at) : null;
                return [
                    'learning_record_id' => (int) $row->learning_record_id,
                    'session_date' => (string) ($row->session_date ?? ''),
                    'subject' => (string) ($row->subject ?? ''),
                    'teacher_name' => (string) ($row->teacher_name ?? ''),
                    'approved_at' => $approvedAt ? $approvedAt->toIso8601String() : null,
                    'recommended_reminder_at' => $this->toReminderWindow($approvedAt)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'version' => 'v1',
            'window' => [
                'start' => $windowStart->toDateString(),
                'end' => $windowEnd->toDateString(),
                'days' => 90,
            ],
            'funnel' => [
                'approved_records' => (int) $approvedCount,
                'replied_records' => (int) $repliedCount,
                'reply_rate_pct' => $replyRate,
                'unreplied_records' => (int) $unrepliedCount,
            ],
            'quick_templates' => [
                ['id' => 'encourage', 'label' => '鼓勵', 'text' => '謝謝老師這週的指導，孩子回家有主動分享上課內容。'],
                ['id' => 'question', 'label' => '提問', 'text' => '想請老師補充：孩子在這個單元還有哪一段需要再練習？'],
                ['id' => 'focus', 'label' => '請老師加強', 'text' => '想請老師下堂課優先協助加強這次作業／測驗的弱項。'],
            ],
            'reminder_policy' => [
                'trigger_window_hours' => ['min' => 2, 'max' => 4],
                'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
                'throttle' => ['daily_cap' => 1, 'weekly_digest_cap' => 1],
                'mute_options' => ['today', 'this_week'],
            ],
            'digest' => [
                'unreplied_preview' => $unrepliedPreview,
                'next_digest_at' => Carbon::now()->next(Carbon::SUNDAY)->setTime(19, 0)->toIso8601String(),
            ],
        ];
    }

    private function toReminderWindow(?Carbon $approvedAt): Carbon
    {
        $base = ($approvedAt ? $approvedAt->copy() : Carbon::now())->addHours(2);
        $hour = (int) $base->format('H');
        if ($hour < 8) {
            return $base->setTime(9, 0);
        }
        if ($hour >= 21) {
            return $base->addDay()->setTime(9, 0);
        }
        return $base;
    }

    private function mapParentFlowStatus(string $status): string
    {
        $s = strtolower(trim($status));
        if ($s === 'open') {
            return 'submitted';
        }
        if ($s === 'candidate_ready' || $s === 'processing') {
            return 'in_progress';
        }
        if (in_array($s, ['resolved', 'done', 'closed'], true)) {
            return 'resolved';
        }

        return 'in_progress';
    }

    public function recordParentEvent(Request $request)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->validate([
            'event' => 'required|string|max:64',
            'meta' => 'nullable|array',
        ]);

        Log::channel('daily')->info('parent_adoption_event', [
            'event' => (string) $payload['event'],
            'student_id' => (int) $session->StudentID,
            'parent_session_id' => (int) $session->id,
            'meta' => $payload['meta'] ?? [],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * 家長通知偏好：學習回饋 LINE 推播開關（個資退出權）。
     * 以目前 session 的 LINE binding 為單位（per-binding）；預設開啟。
     * 多家長同學生時，不得以 student_id 一次改寫其他家長的偏好。
     */
    public function getNotificationPreferences(Request $request)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $binding = $this->sessionScopedBinding($session);
        if ($binding === null) {
            $anyLinked = StudentLineBinding::where('student_id', $session->StudentID)->verified()->exists();
            // Phone login with multiple LINE parents: no safe single preference.
            if ($anyLinked && !$this->sessionLineUserId($session)) {
                return response()->json([
                    'learning_feedback_push' => true,
                    'line_linked' => true,
                    'binding_scoped' => false,
                ]);
            }

            return response()->json([
                'learning_feedback_push' => true,
                'line_linked' => false,
                'binding_scoped' => false,
            ]);
        }

        return response()->json([
            'learning_feedback_push' => (bool) ($binding->notify_learning_feedback ?? true),
            'line_linked' => true,
            'binding_scoped' => true,
        ]);
    }

    public function setNotificationPreferences(Request $request)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate(['learning_feedback_push' => 'required|boolean']);
        $binding = $this->sessionScopedBinding($session);

        if ($binding === null) {
            // Stale LINE session (line_user_id set but binding revoked): fail closed.
            // Never fall back to another parent's verified binding.
            if ($this->sessionLineUserId($session) !== null) {
                return response()->json([
                    'message' => '此 LINE 綁定已失效，請重新以 LINE 登入或完成綁定後再調整通知設定',
                ], 422);
            }

            $verifiedCount = StudentLineBinding::where('student_id', $session->StudentID)->verified()->count();
            if ($verifiedCount > 1) {
                return response()->json([
                    'message' => '此學生有多位 LINE 家長綁定，請改以 LINE 登入後再調整通知設定',
                ], 422);
            }
            if ($verifiedCount === 0) {
                return response()->json([
                    'message' => '綁定 LINE 後才可調整推播通知',
                ], 422);
            }
            // Exactly one verified binding and phone-login session (no line_user_id):
            // update that sole binding only — still never fan-out by student_id.
            $binding = StudentLineBinding::where('student_id', $session->StudentID)->verified()->first();
        }

        if (!$binding) {
            return response()->json([
                'message' => '綁定 LINE 後才可調整推播通知',
            ], 422);
        }

        $binding->notify_learning_feedback = $data['learning_feedback_push'] ? 1 : 0;
        $binding->save();

        return response()->json([
            'ok' => true,
            'learning_feedback_push' => (bool) $data['learning_feedback_push'],
            'binding_scoped' => true,
        ]);
    }

    // ── Director: generate copyable payment notification text ─────────────

    public function paymentMessage(Request $request, int $studentId)
    {
        $student = Student::find($studentId);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $role = (string) $request->attributes->get('auth_role', '');
        $campusIds = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        if ($role !== 'super_admin' && !empty($campusIds)) {
            $studentCampusId = (int) ($student->CampusID ?? 0);
            if ($studentCampusId > 0 && !in_array($studentCampusId, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $classes = StudentClass::where('StudentID', $student->id)
            ->where('Stop', 0)
            ->where('ScheduleMode', 'count')
            ->where('Paid', 0)
            ->orderBy('StartDate')
            ->orderBy('ID')
            ->get();

        if ($classes->isEmpty()) {
            return response()->json(['message' => '此學生目前無待繳費課程']);
        }

        $lineItems = [];
        $totalAmount = 0;
        foreach ($classes as $c) {
            $subject = $this->resolveSubjectName($c);
            $sessionCount = (int) ($c->SessionCount ?? 0);
            if ($sessionCount <= 0) {
                $sessionCount = max((int) ($c->RemainingSessions ?? 0), 0);
            }
            if ($sessionCount <= 0) {
                continue;
            }

            $unitPrice = $this->resolveUnitPrice($c, $sessionCount);
            $subtotal = $this->resolveSubtotal($c, $unitPrice, $sessionCount);
            $totalAmount += $subtotal;

            $lineItems[] = [
                'subject' => $subject,
                'start_date' => $this->formatRocDate($c->StartDate),
                'session_count' => $sessionCount,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ];
        }

        if (empty($lineItems)) {
            return response()->json(['message' => '此學生目前無待繳費課程']);
        }

        $parts = [
            '媽媽你好:',
            '本期學費',
        ];

        foreach ($lineItems as $item) {
            $parts[] = (string) $item['subject'];
            $parts[] = "{$item['start_date']}~{$item['session_count']}堂";
            $parts[] = "{$this->formatAmount($item['unit_price'], false)}*{$item['session_count']} = *{$this->formatAmount($item['subtotal'])}*";
            $parts[] = '';
        }

        $parts[] = '共' . $this->formatAmount($totalAmount) . '元';
        $parts[] = '再麻煩媽媽 幫我繳款 謝謝你';
        $message = implode("\n", $parts);

        return response()->json([
            'message' => $message,
            'student_name' => $student->name,
            'total_amount' => $totalAmount,
            'items' => $lineItems,
        ]);
    }

    // ── Leave request ─────────────────────────────────────────────────────

    public function requestLeave(Request $request, $sessionId)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $classSession = ClassSession::with('studentClass')->findOrFail($sessionId);
        $studentClass = $classSession->studentClass;
        if (!$studentClass) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $targetGroup = $this->identityService()->groupForStudent((int) $studentClass->StudentID);
        $sessionGroupId = (int) ($session->getAttribute('identity_group_id') ?: 0);
        $ownsClass = (int) $studentClass->StudentID === (int) $session->StudentID;
        if (!$ownsClass && $sessionGroupId > 0 && $targetGroup && (int) $targetGroup->id === $sessionGroupId) {
            $ownsClass = $this->identityService()->accessMode($sessionGroupId) === StudentIdentityService::MODE_ACTIONS;
        }

        if (!$ownsClass) {
            return response()->json(['message' => 'Forbidden: This class does not belong to the authenticated student.'], 403);
        }

        if (!in_array($classSession->Status, ['scheduled', 'rescheduled', 'leave_requested'], true)) {
            return response()->json(['message' => 'Session cannot be altered.'], 422);
        }

        [$workflow, $classSession] = DB::transaction(function () use ($classSession, $studentClass, $session, $data) {
            /** @var ClassSession|null $classSession */
            $classSession = ClassSession::query()
                ->where('id', (int) $classSession->getKey())
                ->lockForUpdate()
                ->first();
            if (!$classSession) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }
            if (!in_array(strtolower((string) $classSession->getAttribute('Status')), ['scheduled', 'rescheduled', 'leave_requested'], true)) {
                throw new \InvalidArgumentException('Session cannot be altered.');
            }

            $workflow = app(ExceptionWorkflowService::class)->createOrGet([
                'source_key' => "parent_leave:class_session:{$classSession->id}",
                'campus_id' => (int) ($studentClass->student->CampusID ?? $this->studentCampusId($session->StudentID)),
                'student_id' => (int) $studentClass->StudentID,
                'student_class_id' => (int) $studentClass->ID,
                'class_session_id' => (int) $classSession->id,
                'type' => 'student_leave',
                'status' => 'open',
                'severity' => 'medium',
                'source_type' => 'parent_portal',
                'source_id' => (string) $classSession->id,
                'parent_session_id' => (int) $session->id,
                'due_at' => now()->addDay(),
                'payload' => [
                    'parent_student_id' => (int) $session->StudentID,
                    'reason' => trim((string) ($data['reason'] ?? '')),
                    'requested_at' => now()->toIso8601String(),
                    'session_date' => (string) $classSession->SessionDate,
                    'start_time' => $this->trimToHM($classSession->StartTime),
                    'end_time' => $this->trimToHM($classSession->EndTime),
                ],
            ]);

            if ($classSession->getAttribute('Status') !== 'leave_requested') {
                $classSession->setAttribute('Status', 'leave_requested');
                $classSession->save();
            }

            return [$workflow, $classSession];
        });

        return response()->json([
            'message' => 'Leave requested successfully.',
            'workflow' => [
                'id' => $workflow->id,
                'type' => $workflow->type,
                'status' => $workflow->status,
            ],
            'session' => [
                'id' => $classSession->id,
                'status' => $classSession->Status,
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function createSession(
        Student $student,
        ?int $identityGroupId = null,
        ?string $verifiedPhone = null,
        ?string $lineUserId = null
    ): array {
        $token = Str::random(48);
        $hash = hash('sha256', $token);

        $identityGroupId = $identityGroupId ?: $this->identityService()->groupForStudent((int) $student->getAttribute('id'))?->id;
        if ($identityGroupId && !$this->identityGroupPhoneVerified((int) $identityGroupId, $verifiedPhone ?: StudentContactPhone::normalizedDigits($student))) {
            $identityGroupId = null;
        }

        $normalizedLineUserId = null;
        if (is_string($lineUserId) && $this->isValidLineUserId($lineUserId)) {
            $normalizedLineUserId = $lineUserId;
        }

        ParentSession::create([
            'StudentID' => $student->id,
            'identity_group_id' => $identityGroupId,
            'line_user_id' => $normalizedLineUserId,
            'TokenHash' => $hash,
            'ExpiresAt' => Carbon::now()->addDays(30),
        ]);

        return [
            'token' => $token,
            'student' => [
                'id'   => $student->id,
                'name' => $student->name,
            ],
            'identity_group_id' => $identityGroupId ? (int) $identityGroupId : null,
        ];
    }

    private function sessionLineUserId(ParentSession $session): ?string
    {
        $lineUserId = trim((string) ($session->getAttribute('line_user_id') ?? ''));
        if ($lineUserId === '' || !$this->isValidLineUserId($lineUserId)) {
            return null;
        }

        return $lineUserId;
    }

    /**
     * Resolve the verified SLB row owned by this parent session.
     * LINE login sessions match (student_id, line_user_id).
     * Phone-login sessions with exactly one verified binding may use that sole row.
     */
    private function sessionScopedBinding(ParentSession $session): ?StudentLineBinding
    {
        $lineUserId = $this->sessionLineUserId($session);
        if ($lineUserId !== null) {
            return StudentLineBinding::where('student_id', $session->StudentID)
                ->where('line_user_id', $lineUserId)
                ->verified()
                ->first();
        }

        $bindings = StudentLineBinding::where('student_id', $session->StudentID)->verified()->get();
        if ($bindings->count() === 1) {
            return $bindings->first();
        }

        return null;
    }

    private function identityGroupPhoneVerified(int $groupId, string $phoneNorm): bool
    {
        if ($phoneNorm === '') {
            return false;
        }
        $members = $this->identityService()->activeMembers($groupId);
        return $members->count() > 1
            && $members->every(fn ($member) => StudentContactPhone::normalizedDigits($member->student) === $phoneNorm);
    }

    private function parentStudentSwitcherRows($students): array
    {
        $seenGroups = [];
        return collect($students)->filter(function ($student) use (&$seenGroups) {
            $groupId = $this->identityService()->groupForStudent((int) $student->id)?->id;
            if (!$groupId) {
                return true;
            }
            if (isset($seenGroups[(int) $groupId])) {
                return false;
            }
            $seenGroups[(int) $groupId] = true;
            return true;
        })->map(fn ($student) => [
            'id' => (int) $student->id,
            'name' => (string) $student->name,
        ])->values()->all();
    }

    private function identityGroupRows($students): array
    {
        return collect($students)
            ->map(fn ($student) => $this->identityService()->groupForStudent((int) $student->id))
            ->filter()
            ->unique('id')
            ->map(function ($group) {
                $members = $this->identityService()->activeMembers((int) $group->id);
                return [
                    'id' => (int) $group->id,
                    'name' => (string) ($group->display_name ?: ($members->first()?->student?->getAttribute('name') ?? '學生')),
                    'members' => $members->map(function ($member) {
                        $campus = \App\Models\Campus::query()->find($member->campus_id);
                        return [
                            'student_id' => (int) $member->student_id,
                            'campus_id' => (int) $member->campus_id,
                            'campus_name' => $campus?->getAttribute('name'),
                        ];
                    })->values()->all(),
                ];
            })->values()->all();
    }

    public function billingHistory(Request $request)
    {
        $session = $this->resolveSession($request);
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = Student::find($session->StudentID);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        [$identityGroup, $accessMode, $identityMembers] = $session->getAttribute('identity_group_id')
            ? $this->identityService()->parentContext((int) $student->id)
            : [null, StudentIdentityService::MODE_OFF, collect()];
        $studentIds = $identityMembers->pluck('student_id')->map(fn ($id) => (int) $id)->values()->all() ?: [(int) $student->id];
        $billingCampusMap = \App\Models\Campus::query()->whereIn('id', Student::query()->whereIn('id', $studentIds)->pluck('CampusID')->unique()->all())->get()->keyBy('id');
        $classes = StudentClass::query()->whereIn('StudentID', $studentIds)
            ->where('Charge', '>', 0)
            ->orderByDesc('StartDate')
            ->get();

        $records = $classes->map(function ($course) use ($billingCampusMap) {
            $charge = (int) ($course->Charge ?? 0);
            $paid = (int) ($course->Pay ?? 0);
            $isPaid = $paid >= $charge;
            $campusId = (int) ($course->student->CampusID ?? 0);

            return [
                'student_class_id' => (int) $course->ID,
                'campus_id' => $campusId,
                'campus_name' => optional($billingCampusMap->get($campusId))->getAttribute('name'),
                'subject' => $course->displaySubjectName(),
                'period' => $course->StartDate ? substr($course->StartDate, 0, 7) : null,
                'charge' => $charge,
                'paid' => min($paid, $charge),
                'status' => $isPaid ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            ];
        })->values();

        return response()->json([
            'records' => $records,
            'identity_group_id' => $identityGroup?->id,
            'active_scope' => $identityGroup && $accessMode !== StudentIdentityService::MODE_OFF ? 'all' : 'campus',
        ]);
    }

    private function resolveSession(Request $request): ?ParentSession
    {
        $auth = $request->header('Authorization');
        if (!$auth || !str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($auth, 7));
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);

        $session = ParentSession::where('TokenHash', $hash)
            ->where('ExpiresAt', '>', Carbon::now())
            ->first();
        if (!$session) {
            return null;
        }

        // When portal dual-read is on, a revoked guardian link must fail closed
        // even if ExpiresAt has not been swept yet.
        $lineUserId = trim((string) ($session->getAttribute('line_user_id') ?? ''));
        if ($lineUserId !== ''
            && $this->isValidLineUserId($lineUserId)
            && ParentGuardianAccessService::portalDualReadEnabled()
            && !app(ParentGuardianAccessService::class)->lineMayAccessStudent($lineUserId, (int) $session->StudentID)
        ) {
            $session->ExpiresAt = Carbon::now();
            $session->save();
            return null;
        }

        return $session;
    }

    private function trimToHM(?string $time): string
    {
        if (!$time) {
            return '';
        }
        // "HH:mm:ss" or "H:mm:ss" → "HH:mm" or "H:mm"
        return preg_replace('/^(\d{1,2}:\d{2}).*$/', '$1', $time);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * 驗證是否為有效的 LINE user ID 格式（U + 32 位小寫 hex）。
     * 防止 backfill 的無效值（電話、placeholder 等）產生跨家庭兄弟姐妹群組。
     */
    private function isValidLineUserId(string $id): bool
    {
        return (bool) preg_match('/^U[0-9a-f]{32}$/i', $id);
    }

    /**
     * 家長入口驗證用的聯絡手機：優先用 parent_phone（UI「家長手機」欄），
     * 若空則 fallback 到 Phone（舊資料相容）。
     */
    private function resolveContactPhone(Student $student): string
    {
        return StudentContactPhone::forStudent($student);
    }

    private function studentCampusId(int $studentId): int
    {
        return (int) (Student::where('id', $studentId)->value('CampusID') ?? 0);
    }

    /**
     * PRD-H：月結課程的預估「每月應繳」金額，給家長端學習情況卡片顯示參考。
     * 計算邏輯：
     *   1) 若有 `Charge` 欄位（通常 = 月費或整期總額），先把它當成月費回傳；
     *   2) 否則 rate × monthly_sessions（rate_unit=session 直接乘；hour 要換算 SessionDuration）。
     * 僅用於「預估顯示」，不影響實際對帳 / invoice 金額。
     */
    private function resolveMonthlyFee(StudentClass $class): int
    {
        // 月結課程的「每月應繳」估算：
        //   1) 若設定了 monthly_sessions 且有 Rate → monthly_sessions × 單堂單價（優先，最直覺）
        //   2) 否則使用 Charge 欄位（當該欄儲存月費時）
        //   3) 最後退而求其次用 Pay
        $monthlySessions = (int) ($class->monthly_sessions ?? 0);
        if ($monthlySessions > 0) {
            $unitPrice = $this->resolveUnitPrice($class, $monthlySessions);
            if ($unitPrice > 0) {
                return (int) round($unitPrice * $monthlySessions);
            }
        }

        $charge = (float) ($class->Charge ?? 0);
        if ($charge > 0) {
            return (int) round($charge);
        }

        $pay = (float) ($class->Pay ?? 0);
        if ($pay > 0) {
            return (int) round($pay);
        }

        return 0;
    }

    private function isClassPaid(StudentClass $class, array $paidAtMap): bool
    {
        return (bool) ($class->Paid ?? false) || array_key_exists((int) $class->ID, $paidAtMap);
    }

    private function resolveMonthlyDisplayPeriod(StudentClass $class, $invoiceRows): string
    {
        $invoiceRows = collect($invoiceRows ?? []);
        $invoice = $invoiceRows->first(function ($row) {
            return preg_match('/^\d{4}-\d{2}$/', (string) ($row->billing_period ?? ''));
        });

        if ($invoice) {
            return (string) $invoice->billing_period;
        }

        if (!empty($class->StartDate)) {
            try {
                return Carbon::parse($class->StartDate)->format('Y-m');
            } catch (\Throwable $e) {
            }
        }

        return Carbon::now()->format('Y-m');
    }

    private function formatBillingPeriodLabel(?string $period): string
    {
        if ($period && preg_match('/^\d{4}-(\d{2})$/', $period, $m)) {
            return ((int) $m[1]) . '月';
        }

        return Carbon::now()->format('n') . '月';
    }

    private function resolveUnitPrice(StudentClass $class, int $sessionCount): float
    {
        $rateUnit = (string) ($class->rate_unit ?? 'session');
        $rate = (float) ($class->Rate ?? 0);

        if ($rateUnit === 'hour' && $rate > 0) {
            $durationMinutes = max(30, (int) ($class->SessionDuration ?? 120));
            return $rate * ($durationMinutes / 60.0);
        }

        if ($rate > 0) {
            return $rate;
        }

        $charge = (float) ($class->Charge ?? 0);
        if ($charge > 0 && $sessionCount > 0) {
            return $charge / $sessionCount;
        }

        $pay = (float) ($class->Pay ?? 0);
        if ($pay > 0 && $sessionCount > 0) {
            return $pay / $sessionCount;
        }

        return 0;
    }

    private function resolveSubtotal(StudentClass $class, float $unitPrice, int $sessionCount): int
    {
        $charge = (float) ($class->Charge ?? 0);
        if ($charge > 0) {
            return (int) round($charge);
        }

        if ($unitPrice > 0 && $sessionCount > 0) {
            return (int) round($unitPrice * $sessionCount);
        }

        $pay = (float) ($class->Pay ?? 0);
        if ($pay > 0) {
            return (int) round($pay);
        }

        return 0;
    }

    private function formatRocDate($value): string
    {
        if (!$value) {
            return '日期待確認';
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable $e) {
            return '日期待確認';
        }

        $rocYear = $date->year - 1911;
        return sprintf('%d.%d.%d', $rocYear, $date->month, $date->day);
    }

    private function formatAmount($amount, bool $withThousands = true): string
    {
        $numeric = (float) $amount;
        $isInt = abs($numeric - round($numeric)) < 0.00001;
        if ($isInt) {
            return $withThousands
                ? number_format((int) round($numeric))
                : (string) ((int) round($numeric));
        }

        $formatted = number_format($numeric, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function resolveSubjectName(StudentClass $class): string
    {
        $raw = trim((string) ($class->Subject ?? ''));
        if ($raw !== '') {
            if (!ctype_digit($raw)) {
                $mapped = $this->mapSubjectLabel($raw);
                return $mapped !== '' ? $mapped : $raw;
            }

            $subjectFromRawId = Subject::query()->where('id', (int) $raw)->value('Subject_Name');
            if (!empty($subjectFromRawId)) {
                return trim((string) $subjectFromRawId);
            }
        }

        $subjectId = trim((string) ($class->SubjectID ?? ''));
        if ($subjectId !== '') {
            $subjectIdInt = (int) $subjectId;
            if ($subjectIdInt > 0) {
                static $subjectNameCache = [];
                if (!array_key_exists($subjectIdInt, $subjectNameCache)) {
                    // Check BaseData table first (legacy course mapping), then Subject table
                    $name = \Illuminate\Support\Facades\DB::table('BaseData')
                        ->where('Name', '課程')
                        ->where('id', $subjectIdInt)
                        ->value('Val');
                    if (empty($name)) {
                        $name = Subject::query()->where('id', $subjectIdInt)->value('Subject_Name');
                    }
                    $subjectNameCache[$subjectIdInt] = trim((string) ($name ?? ''));
                }
                if ($subjectNameCache[$subjectIdInt] !== '') {
                    return $subjectNameCache[$subjectIdInt];
                }
            }
        }

        $mappedById = $this->mapSubjectLabel($subjectId);
        if ($mappedById !== '') {
            return $mappedById;
        }

        return '課程';
    }

    private function mapSubjectLabel(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }

        $map = [
            '1' => '國文',  '2' => '英文',  '3' => '數學',
            '4' => '理化',  '5' => '社會',
            // 英文別名
            'Chinese'  => '國文', '國文課' => '國文',
            'English'  => '英文', '英文課' => '英文',
            'Math'     => '數學', '數學課' => '數學',
            'Science'  => '理化', '理化課' => '理化', '理化' => '理化',
            'Social'   => '社會', '社會課' => '社會',
            'Physics'  => '物理', '物理課' => '物理',
            'Chemistry'=> '化學', '化學課' => '化學',
            'Biology'  => '生物', '生物課' => '生物',
        ];

        return $map[$v] ?? '';
    }
}
