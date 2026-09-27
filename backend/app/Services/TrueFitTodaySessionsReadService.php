<?php

namespace App\Services;

use App\Models\Campus;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Pure-read today session list for TrueFit: materialized ClassSession rows plus
 * contract projections and schedule-exception slots, without auto-materialization.
 */
class TrueFitTodaySessionsReadService
{
    public function __construct(
        private ClassSessionIndexReadService $indexReadService,
        private ClassSessionIndexProjectionService $projectionService,
        private ClassSessionScheduleExceptionReadService $scheduleExceptionReadService,
    ) {
    }

    /**
     * @return array{meta: array<string, mixed>, data: list<array<string, mixed>>}
     */
    public function fetchTodaySessions(Request $request, int $teacherId): array
    {
        $today = Carbon::now(config('app.timezone', 'Asia/Taipei'))->toDateString();
        $request->merge([
            'start' => $today,
            'end' => $today,
            'teacher_id' => $teacherId,
        ]);

        $materializedRows = $this->indexReadService->fetchTransformedRows($request);
        $byClass = $this->buildByClassMap($materializedRows);
        $projectedByClass = $this->projectionService->buildProjectedByClassForIndex(
            $request,
            $byClass,
            $today,
            $today,
            [],
            true
        );

        $campusIds = $this->resolveCampusIds($request);
        $occupiedSlotKeys = [];
        $sessions = [];
        $ineligible = ['cancelled'];

        foreach ($materializedRows as $row) {
            $status = strtolower((string) ($row->status ?? ''));
            if (in_array($status, $ineligible, true)) {
                continue;
            }
            $classSessionId = (int) ($row->id ?? 0);
            if ($classSessionId <= 0) {
                continue;
            }

            $date = substr((string) ($row->session_date ?? ''), 0, 10);
            $start = substr((string) ($row->start_time ?? ''), 0, 5);
            if ($date !== '' && $start !== '') {
                $occupiedSlotKeys[(int) ($row->student_class_id ?? 0) . '|' . $date . '|' . $start] = true;
            }

            $sessions[] = $this->mapMaterializedRow($row);
        }

        foreach ($projectedByClass as $slots) {
            foreach ($slots as $slot) {
                if (($slot['session_date'] ?? '') !== $today) {
                    continue;
                }
                $classId = (int) ($slot['student_class_id'] ?? 0);
                $start = substr((string) ($slot['start_time'] ?? ''), 0, 5);
                $slotKey = $classId . '|' . $today . '|' . $start;
                if ($classId <= 0 || $start === '' || isset($occupiedSlotKeys[$slotKey])) {
                    continue;
                }
                $occupiedSlotKeys[$slotKey] = true;
                $sessions[] = $this->mapProjectedSlot($slot, 'contract_projection');
            }
        }

        foreach ($this->scheduleExceptionReadService->readSlotsForDate(
            $request,
            $today,
            $campusIds,
            $teacherId,
            $occupiedSlotKeys
        ) as $slot) {
            $sessions[] = $this->mapProjectedSlot($slot, (string) ($slot['source'] ?? 'schedule_exception'));
        }

        $branchIds = collect($sessions)
            ->map(fn ($row) => (int) ($row['branch_id'] ?? 0))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        $campusNames = $branchIds
            ? Campus::query()->whereIn('id', $branchIds)->pluck('name', 'id')
            : collect();

        $data = collect($sessions)
            ->map(function ($session) use ($campusNames) {
                $branchId = (int) ($session['branch_id'] ?? 0);
                if ($branchId > 0 && empty($session['campus_name'])) {
                    $session['campus_name'] = (string) ($campusNames[$branchId] ?? '');
                }

                return $session;
            })
            ->sortBy([
                ['start_time', 'asc'],
                ['student_name', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'meta' => [
                'date' => $today,
                'teacher_id' => $teacherId,
                'count' => count($data),
                'read_mode' => 'pure_read',
                'completeness' => 'materialized_plus_projected',
            ],
            'data' => $data,
        ];
    }

    /**
     * @param  array<int, object>  $items
     * @return array<string, list<object>>
     */
    private function buildByClassMap(array $items): array
    {
        $byClass = [];
        foreach ($items as $item) {
            $key = (string) ($item->student_class_id ?? 0);
            if (!isset($byClass[$key])) {
                $byClass[$key] = [];
            }
            $byClass[$key][] = $item;
        }

        return $byClass;
    }

    /** @return array<int> */
    private function resolveCampusIds(Request $request): array
    {
        $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0);
        if ($requestedCampus > 0) {
            return [$requestedCampus];
        }

        $campusIds = $request->attributes->get('auth_campus_ids', []);

        return is_array($campusIds) ? array_values(array_filter(array_map('intval', $campusIds))) : [];
    }

    private function mapMaterializedRow(object $row): array
    {
        $branchId = (int) ($row->branch_id ?? 0);

        return [
            'class_session_id' => (int) ($row->id ?? 0),
            'student_class_id' => (int) ($row->student_class_id ?? 0),
            'student_id' => (int) ($row->student_id ?? 0),
            'student_name' => (string) ($row->student_name ?? ''),
            'subject_name' => (string) ($row->subject_name ?? ''),
            'session_date' => (string) ($row->session_date ?? ''),
            'start_time' => (string) ($row->start_time ?? ''),
            'end_time' => (string) ($row->end_time ?? ''),
            'status' => (string) ($row->status ?? ''),
            'session_kind' => SessionProjectionReadService::KIND_MATERIALIZED,
            'source' => 'class_session',
            'branch_id' => $branchId > 0 ? $branchId : null,
            'campus_name' => null,
        ];
    }

    /** @param array<string, mixed> $slot */
    private function mapProjectedSlot(array $slot, string $source): array
    {
        $branchId = (int) ($slot['branch_id'] ?? 0);

        return [
            'class_session_id' => null,
            'student_class_id' => (int) ($slot['student_class_id'] ?? 0),
            'student_id' => isset($slot['student_id']) ? (int) $slot['student_id'] : null,
            'student_name' => (string) ($slot['student_name'] ?? ''),
            'subject_name' => (string) ($slot['subject_name'] ?? ''),
            'session_date' => (string) ($slot['session_date'] ?? ''),
            'start_time' => (string) ($slot['start_time'] ?? ''),
            'end_time' => (string) ($slot['end_time'] ?? ''),
            'status' => (string) ($slot['status'] ?? SessionProjectionReadService::KIND_PROJECTED),
            'session_kind' => SessionProjectionReadService::KIND_PROJECTED,
            'source' => $source,
            'branch_id' => $branchId > 0 ? $branchId : null,
            'campus_name' => null,
        ];
    }
}
