<?php

namespace App\Http\Controllers;

use App\Models\TrialHold;
use App\Services\ScheduleGuardService;
use App\Services\SubstituteService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 試聽暫留 (in-app #575, #5499): a director reserves a teacher's slot for a trial before the student exists.
 * A held hold occupies the teacher like that trial lesson would (ScheduleGuardService::trialHoldEntry); it never
 * creates a student, course, attendance, deduction or bill. Routes: role director/super_admin, require_campus.
 */
class TrialHoldController extends Controller
{
    private const MAX_RANGE_DAYS = 62;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > self::MAX_RANGE_DAYS) {
            return response()->json(['message' => '查詢區間最多 ' . self::MAX_RANGE_DAYS . ' 天。', 'code' => 'range_too_long'], 422);
        }
        $campusIds = $this->managedCampusIds($request);

        $rows = DB::table('trial_holds as h')
            ->leftJoin('User as u', 'u.id', '=', 'h.teacher_id')
            ->where('h.status', TrialHold::HELD)
            ->whereBetween('h.hold_date', [$data['from'], $data['to']])
            ->when($campusIds !== [], fn ($q) => $q->whereIn('h.campus_id', $campusIds))
            ->orderBy('h.hold_date')->orderBy('h.start_time')->orderBy('h.id')
            ->get(['h.id', 'h.campus_id', 'h.teacher_id', 'u.Name as teacher_name', 'h.hold_date', 'h.start_time', 'h.end_time', 'h.note', 'h.status']);

        return response()->json(['data' => $rows->map(fn ($row) => $this->present($row))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => 'required|integer|min:1',
            'teacher_id' => 'required|integer|min:1',
            'hold_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'note' => 'nullable|string|max:100',
        ]);
        $campusId = (int) $data['campus_id'];
        $teacherId = (int) $data['teacher_id'];
        if (!$this->mayManageCampus($request, $campusId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (!app(SubstituteService::class)->teacherBoundToAny($teacherId, [$campusId])) {
            return response()->json(['message' => '所選老師不是這個分校的老師，不能暫留。沒有變更任何資料。', 'code' => 'teacher_not_assignable'], 422);
        }

        $hold = DB::transaction(function () use ($data, $campusId, $teacherId, $request) {
            // Serialize holds for one teacher; the locked guard read below then sees every committed lesson and hold.
            DB::table('User')->where('id', $teacherId)->lockForUpdate()->value('id');
            $conflicts = app(ScheduleGuardService::class)->validateScheduleOccurrence([
                'teacher_id' => $teacherId,
                'branch_id' => $campusId,
                'schedule_date' => $data['hold_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'class_type' => 'trial',
            ], true);
            if ($conflicts !== []) {
                throw new HttpResponseException(response()->json([
                    'message' => '這個時段老師已經有課或已有試聽，不能暫留。沒有變更任何資料。',
                    'code' => 'trial_hold_conflict',
                    'conflicts' => $conflicts,
                ], 422));
            }

            return TrialHold::create([
                'campus_id' => $campusId,
                'teacher_id' => $teacherId,
                'hold_date' => $data['hold_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'note' => isset($data['note']) ? trim((string) $data['note']) : null,
                'created_by' => $this->actorId($request),
            ]);
        });

        return response()->json(['data' => $this->present($this->row((int) $hold->id))], 201);
    }

    public function cancel(Request $request, int $trialHold): JsonResponse
    {
        return DB::transaction(function () use ($request, $trialHold) {
            $hold = TrialHold::query()->whereKey($trialHold)->lockForUpdate()->first();
            if (!$hold || !$this->mayManageCampus($request, (int) $hold->campus_id)) {
                return response()->json(['message' => '找不到這筆暫留。'], 404);
            }
            if ($hold->status !== TrialHold::HELD) {
                return response()->json(['message' => '這筆暫留已經取消或轉成正式試聽，不能再取消。沒有變更任何資料。', 'code' => 'trial_hold_not_held'], 409);
            }
            $hold->update(['status' => TrialHold::CANCELLED, 'resolved_by' => $this->actorId($request), 'resolved_at' => now()]);

            return response()->json(['data' => $this->present($this->row((int) $hold->id))]);
        });
    }

    /** @return int[] empty = every campus (super_admin) */
    private function managedCampusIds(Request $request): array
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return [];
        }
        $ids = array_values(array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])));

        return $ids === [] ? [-1] : $ids; // require_campus guarantees one; never widen to "all" by accident
    }

    private function mayManageCampus(Request $request, int $campusId): bool
    {
        $ids = $this->managedCampusIds($request);

        return $ids === [] || in_array($campusId, $ids, true);
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->attributes->get('auth_user');

        return $user instanceof \App\Models\User ? (int) $user->getAttribute('id') : null;
    }

    private function row(int $id): object
    {
        return DB::table('trial_holds as h')->leftJoin('User as u', 'u.id', '=', 'h.teacher_id')->where('h.id', $id)
            ->first(['h.id', 'h.campus_id', 'h.teacher_id', 'u.Name as teacher_name', 'h.hold_date', 'h.start_time', 'h.end_time', 'h.note', 'h.status']);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'campus_id' => (int) $row->campus_id,
            'teacher_id' => (int) $row->teacher_id,
            'teacher_name' => (string) ($row->teacher_name ?? ''),
            'hold_date' => substr((string) $row->hold_date, 0, 10),
            'start_time' => substr((string) $row->start_time, 0, 5),
            'end_time' => substr((string) $row->end_time, 0, 5),
            'note' => $row->note,
            'status' => (string) $row->status,
        ];
    }
}
