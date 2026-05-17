<?php

namespace App\Http\Controllers;

use App\Models\DunningEvent;
use App\Services\DunningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DunningController extends Controller
{
    public function rules(): JsonResponse
    {
        $rules = [];
        foreach (DunningService::RULES as $key => $rule) {
            $rules[] = array_merge($rule, ['key' => $key]);
        }
        return response()->json(['rules' => $rules]);
    }

    public function history(Request $request): JsonResponse
    {
        if (!Schema::hasTable('dunning_events')) {
            return response()->json(['events' => [], 'summary' => []]);
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        $query = DunningEvent::query()->orderByDesc('sent_at');

        if ($request->filled('branch_id')) {
            $query->where('campus_id', (int) $request->input('branch_id'));
        } elseif (!empty($campusIds)) {
            $query->whereIn('campus_id', $campusIds);
        }

        if ($request->filled('rule_key')) {
            $query->where('rule_key', $request->input('rule_key'));
        }

        $limit = min(200, max(1, (int) $request->input('limit', 50)));
        $events = $query->limit($limit)->get();

        $summary = DunningEvent::query()
            ->selectRaw('rule_key, COUNT(*) as total, MAX(sent_at) as last_sent')
            ->when(!empty($campusIds), fn ($q) => $q->whereIn('campus_id', $campusIds))
            ->groupBy('rule_key')
            ->get()
            ->keyBy('rule_key');

        return response()->json([
            'events' => $events->map(fn ($e) => [
                'id' => (int) $e->id,
                'student_id' => (int) $e->student_id,
                'student_class_id' => (int) $e->student_class_id,
                'campus_id' => (int) $e->campus_id,
                'rule_key' => $e->rule_key,
                'channel' => $e->channel,
                'sent_at' => $e->sent_at?->toIso8601String(),
            ])->values(),
            'summary' => $summary,
        ]);
    }

    public function trigger(Request $request): JsonResponse
    {
        $request->validate([
            'campus_id' => 'nullable|integer',
        ]);

        $campusId = $request->filled('campus_id') ? (int) $request->input('campus_id') : null;
        $service = new DunningService();
        $events = $service->evaluateAll($campusId);

        return response()->json([
            'triggered' => count($events),
            'events' => $events,
        ]);
    }
}
