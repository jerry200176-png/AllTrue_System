<?php

namespace App\Http\Controllers;

use App\Models\AdmissionInquiry;
use App\Models\Campus;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\AdmissionInquiryService;
use App\Services\EnrollmentService;
use App\Services\ParentBinding\GuardianSyncService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdmissionInquiryController extends Controller
{
    public function store(Request $request, AdmissionInquiryService $service)
    {
        abort_unless($service::enabled(), 404);
        $data = $request->validate([
            'campus_id' => 'required|integer|min:1',
            'parent_name' => 'required|string|max:64',
            'parent_phone' => 'required|string|max:32',
            'student_name' => 'required|string|max:64',
            'grade' => 'required|in:P1,P2,P3,P4,P5,P6,J1,J2,J3,H1,H2,H3',
            'school_name' => 'required|string|max:128',
            'subject' => 'required|in:Chinese,English,Math,Physics,Chemistry,Science,Biology,Social',
            'preferred_slots' => 'present|array|max:7',
            'preferred_slots.*' => 'string|max:64',
            'public_notes' => 'nullable|string|max:500',
            'consent' => 'accepted',
        ]);
        $phone = $service::normalizePhone($data['parent_phone']);
        abort_if(strlen($phone) < 8, 422, '請提供可聯絡的電話。');
        $campus = Campus::query()->whereKey($data['campus_id']);
        if (Schema::hasColumn('Campus', 'active')) {
            $campus->where('active', true);
        }
        abort_unless($campus->exists(), 422, '此分校目前無法受理問班。');

        $service->submit($data);
        return response()->json(['message' => '已收到問班需求，主任將與您聯絡。'], 202);
    }

    public function index(Request $request, AdmissionInquiryService $service)
    {
        abort_unless($service::enabled(), 404);
        $query = AdmissionInquiry::query()->orderByDesc('created_at');
        $this->scope($query, $request);
        if ($request->filled('campus_id')) {
            $query->where('campus_id', (int) $request->query('campus_id'));
        }
        $perPage = min((int) $request->query('per_page', '50'), 50);
        /** @var \Illuminate\Pagination\LengthAwarePaginator $page */
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (AdmissionInquiry $i) => [
            'id' => $i->id, 'campus_id' => $i->campus_id, 'status' => $i->status,
            'student_name' => AdmissionInquiry::mask($i->student_name),
            'parent_phone' => AdmissionInquiry::maskPhone($i->parent_phone),
            'subject' => $i->subject, 'created_at' => $i->created_at,
            'student_id' => $i->student_id, 'trial_student_class_id' => $i->trial_student_class_id,
        ]);
        return response()->json($page);
    }

    public function show(Request $request, AdmissionInquiry $admissionInquiry, AdmissionInquiryService $service)
    {
        abort_unless($service::enabled(), 404);
        $this->authorizeInquiry($request, $admissionInquiry);
        return response()->json([
            'id' => $admissionInquiry->id, 'campus_id' => $admissionInquiry->campus_id,
            'status' => $admissionInquiry->status, 'parent_name' => $admissionInquiry->parent_name,
            'parent_phone' => $admissionInquiry->parent_phone, 'student_name' => $admissionInquiry->student_name,
            'grade' => $admissionInquiry->grade, 'school_name' => $admissionInquiry->school_name,
            'subject' => $admissionInquiry->subject, 'preferred_slots' => $admissionInquiry->preferred_slots,
            'public_notes' => $admissionInquiry->public_notes, 'staff_notes' => $admissionInquiry->staff_notes,
            'trial_result' => $admissionInquiry->trial_result, 'student_id' => $admissionInquiry->student_id,
            'trial_student_class_id' => $admissionInquiry->trial_student_class_id,
            'enrolled_student_class_id' => $admissionInquiry->enrolled_student_class_id,
            'timestamps' => [
                'created_at' => $admissionInquiry->created_at, 'contacted_at' => $admissionInquiry->contacted_at,
                'trial_scheduled_at' => $admissionInquiry->trial_scheduled_at,
                'trial_completed_at' => $admissionInquiry->trial_completed_at, 'enrolled_at' => $admissionInquiry->enrolled_at,
            ],
        ]);
    }

    public function contact(Request $request, AdmissionInquiry $admissionInquiry)
    {
        $this->ensureEnabled();
        $this->authorizeInquiry($request, $admissionInquiry);
        $data = $request->validate(['staff_notes' => 'nullable|string|max:1000']);
        abort_if(in_array($admissionInquiry->status, [AdmissionInquiry::STATUS_ENROLLED, AdmissionInquiry::STATUS_LOST], true), 422, '此詢問已結案。');
        $admissionInquiry->fill(['status' => AdmissionInquiry::STATUS_CONTACTED, 'staff_notes' => $data['staff_notes'] ?? $admissionInquiry->staff_notes, 'contacted_at' => $admissionInquiry->contacted_at ?: now(), 'assigned_to' => $request->attributes->get('auth_user')->id ?? null])->save();
        $this->audit($request, $admissionInquiry, 'contacted');
        return response()->json(['status' => $admissionInquiry->status]);
    }

    public function scheduleTrial(Request $request, AdmissionInquiry $admissionInquiry, EnrollmentService $enrollmentService)
    {
        $this->ensureEnabled();
        $this->authorizeInquiry($request, $admissionInquiry);
        $data = $request->validate(['teacher_id' => 'required|integer|exists:User,id', 'trial_date' => 'required|date', 'start_time' => 'required|date_format:H:i', 'duration_minutes' => 'required|integer|min:30|max:480']);
        return DB::transaction(function () use ($request, $data, $admissionInquiry, $enrollmentService) {
            // Serialize the handoff before invoking EnrollmentService. Without the
            // row lock, two retries arriving together can both observe an inquiry
            // without a trial class and create duplicate Student records.
            $admissionInquiry = AdmissionInquiry::query()
                ->whereKey($admissionInquiry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($admissionInquiry->trial_student_class_id) {
                return response()->json(['status' => $admissionInquiry->status, 'student_id' => $admissionInquiry->student_id, 'student_class_id' => $admissionInquiry->trial_student_class_id]);
            }
            abort_unless(in_array($admissionInquiry->status, [AdmissionInquiry::STATUS_NEW, AdmissionInquiry::STATUS_CONTACTED], true), 422, '目前狀態不可安排試聽。');

            $trialDate = Carbon::parse($data['trial_date'])->toDateString();
            $enrollmentRequest = $request;
            $payload = [
                'student' => ['name' => $admissionInquiry->student_name, 'grade' => $admissionInquiry->grade, 'school' => $admissionInquiry->school_name, 'parent_name' => $admissionInquiry->parent_name, 'parent_phone' => $admissionInquiry->parent_phone],
                'teacher_id' => $data['teacher_id'], 'subject' => $admissionInquiry->subject, 'class_type' => 'trial', 'total_classes' => 1,
                'confirmed_dates' => [], 'future_dates' => [$trialDate], 'start_time' => $data['start_time'], 'duration_minutes' => $data['duration_minutes'],
                'price_per_session' => 0, 'payment_type' => 'session', 'branch_id' => $admissionInquiry->campus_id, 'mode' => 'enrollment',
            ];
            $response = $enrollmentService->store($enrollmentRequest, $payload);
            if ($response->getStatusCode() >= 300) {
                return $response;
            }
            $result = $response->getData(true);
            $studentId = (int) ($result['student_id'] ?? 0);
            $classId = (int) ($result['student_class_id'] ?? ($result['student_class_ids'][0] ?? 0));
            if ($studentId <= 0 || $classId <= 0) {
                throw new \RuntimeException('Trial enrollment returned incomplete identity.');
            }
            $admissionInquiry->update([
                'status' => AdmissionInquiry::STATUS_TRIAL_SCHEDULED, 'student_id' => $studentId,
                'trial_student_class_id' => $classId, 'contacted_at' => $admissionInquiry->contacted_at ?: now(), 'trial_scheduled_at' => now(),
            ]);
            $student = Student::query()->whereKey($studentId)->firstOrFail();
            app(GuardianSyncService::class)->syncPrimaryFromStudent($student);
            $this->audit($request, $admissionInquiry, 'trial_scheduled');
            return response()->json(['status' => $admissionInquiry->status, 'student_id' => $studentId, 'student_class_id' => $classId], 201);
        });
    }

    public function recordResult(Request $request, AdmissionInquiry $admissionInquiry)
    {
        $this->ensureEnabled();
        $this->authorizeInquiry($request, $admissionInquiry);
        $data = $request->validate(['trial_result' => 'required|in:attended,no_show,cancelled,not_suitable']);
        if ($admissionInquiry->status === AdmissionInquiry::STATUS_TRIAL_COMPLETED && $admissionInquiry->trial_result === $data['trial_result']) {
            return response()->json(['status' => $admissionInquiry->status, 'trial_result' => $admissionInquiry->trial_result]);
        }
        abort_unless($admissionInquiry->status === AdmissionInquiry::STATUS_TRIAL_SCHEDULED, 422, '目前狀態不可記錄試聽結果。');
        $admissionInquiry->update(['status' => AdmissionInquiry::STATUS_TRIAL_COMPLETED, 'trial_result' => $data['trial_result'], 'trial_completed_at' => now()]);
        $this->audit($request, $admissionInquiry, 'trial_completed');
        return response()->json(['status' => $admissionInquiry->status, 'trial_result' => $admissionInquiry->trial_result]);
    }

    public function linkEnrollment(Request $request, AdmissionInquiry $admissionInquiry)
    {
        $this->ensureEnabled();
        $this->authorizeInquiry($request, $admissionInquiry);
        $data = $request->validate(['student_class_id' => 'required|integer|exists:StudentClass,ID']);
        if ($admissionInquiry->status === AdmissionInquiry::STATUS_ENROLLED && (int) $admissionInquiry->enrolled_student_class_id === (int) $data['student_class_id']) {
            return response()->json(['status' => $admissionInquiry->status, 'student_id' => $admissionInquiry->student_id, 'student_class_id' => $data['student_class_id']]);
        }
        abort_unless($admissionInquiry->status === AdmissionInquiry::STATUS_TRIAL_COMPLETED && $admissionInquiry->trial_result === 'attended', 422, '請先記錄已出席的試聽結果。');
        $newCourse = StudentClass::query()->whereKey($data['student_class_id'])->firstOrFail();
        abort_unless((int) $newCourse->getAttribute('StudentID') === (int) $admissionInquiry->student_id, 422, '報名課程與詢問學生不一致。');
        $trial = StudentClass::query()->whereKey($admissionInquiry->trial_student_class_id)->firstOrFail();
        abort_unless((int) $trial->getAttribute('trial_converted_to_id') === (int) $newCourse->getAttribute('ID'), 422, '請先完成既有試聽轉正式流程。');
        $admissionInquiry->update(['status' => AdmissionInquiry::STATUS_ENROLLED, 'enrolled_student_class_id' => $newCourse->getAttribute('ID'), 'enrolled_at' => now()]);
        $this->audit($request, $admissionInquiry, 'enrolled');
        return response()->json(['status' => $admissionInquiry->status, 'student_id' => $admissionInquiry->student_id, 'student_class_id' => $newCourse->getAttribute('ID')]);
    }

    private function scope($query, Request $request): void
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return;
        }
        $query->whereIn('campus_id', array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])));
    }

    private function ensureEnabled(): void
    {
        abort_unless(AdmissionInquiryService::enabled(), 404);
    }

    private function authorizeInquiry(Request $request, AdmissionInquiry $inquiry): void
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return;
        }
        if (!in_array((int) $inquiry->campus_id, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true)) {
            throw new AuthorizationException('Forbidden');
        }
    }

    private function audit(Request $request, AdmissionInquiry $inquiry, string $reason): void
    {
        $actor = $request->attributes->get('auth_user');
        SecurityAuditEvent::append('admission_inquiry.state_transition', 'success', ['campus_id' => $inquiry->campus_id, 'actor_type' => 'user', 'actor_id' => $actor->id ?? null, 'subject_type' => 'admission_inquiry', 'subject_id' => $inquiry->id], ['reason_code' => $reason]);
    }
}
