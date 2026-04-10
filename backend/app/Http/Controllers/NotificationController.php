<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\NotificationRead;
use App\Services\NotificationSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'branch_id' => 'nullable|integer',
            'type' => 'nullable|string|max:120',
            'read' => 'nullable|in:all,read,unread',
            'include_resolved' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        [$campusIds] = $this->resolveCampusScope($request);
        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $perPage = (int) $request->input('per_page', 20);
        $readFilter = (string) $request->input('read', 'all');
        $includeResolved = filter_var($request->input('include_resolved', false), FILTER_VALIDATE_BOOLEAN);
        $types = $this->parseTypes($request->input('type'));

        $query = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->select([
                'Notifications.*',
                'nr.ReadAt as read_at',
                'nr.ArchivedAt as archived_at',
            ]);

        if (!empty($campusIds)) {
            $query->whereIn('Notifications.CampusID', $campusIds);
        }
        if (!empty($types)) {
            $query->whereIn('Notifications.Type', $types);
        }
        if (!$includeResolved) {
            $query->whereNull('Notifications.ResolvedAt');
        }
        if ($readFilter === 'unread') {
            $query->whereNull('nr.ReadAt');
        } elseif ($readFilter === 'read') {
            $query->whereNotNull('nr.ReadAt');
        }

        $query->orderByRaw('CASE WHEN nr.ReadAt IS NULL THEN 0 ELSE 1 END ASC')
            ->orderByRaw("CASE Notifications.Severity
                WHEN 'high' THEN 0
                WHEN 'medium' THEN 1
                WHEN 'low' THEN 2
                ELSE 3
            END ASC")
            ->orderByRaw('COALESCE(Notifications.OccurredAt, Notifications.created_at) DESC')
            ->orderBy('Notifications.id', 'DESC');

        $rows = $query->paginate($perPage);
        $payload = $rows->toArray();
        $payload['unread_count'] = $this->countUnread($userId, $campusIds);
        $payload['urgent_unread_count'] = $this->countUrgentUnread($userId, $campusIds);

        return response()->json($payload);
    }

    public function sync(Request $request)
    {
        $request->validate([
            'branch_id' => 'nullable|integer',
        ]);

        [$campusIds, , $branchId] = $this->resolveCampusScope($request);
        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $syncResult = NotificationSyncService::sync($campusIds, $branchId);

        return response()->json([
            ...$syncResult,
            'unread_count' => $this->countUnread($userId, $campusIds),
            'urgent_unread_count' => $this->countUrgentUnread($userId, $campusIds),
        ]);
    }

    public function markRead(Request $request, int $notificationId)
    {
        [$campusIds] = $this->resolveCampusScope($request);
        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $notification = $this->findScopedNotification($notificationId, $campusIds);
        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        NotificationRead::updateOrCreate(
            [
                'NotificationID' => $notification->id,
                'UserID' => $userId,
            ],
            [
                'ReadAt' => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'notification_id' => $notification->id,
            'unread_count' => $this->countUnread($userId, $campusIds),
            'urgent_unread_count' => $this->countUrgentUnread($userId, $campusIds),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $request->validate([
            'branch_id' => 'nullable|integer',
            'type' => 'nullable|string|max:120',
            'include_resolved' => 'nullable|boolean',
        ]);

        [$campusIds] = $this->resolveCampusScope($request);
        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $includeResolved = filter_var($request->input('include_resolved', false), FILTER_VALIDATE_BOOLEAN);
        $types = $this->parseTypes($request->input('type'));

        $query = Notification::query()->select('id');
        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }
        if (!empty($types)) {
            $query->whereIn('Type', $types);
        }
        if (!$includeResolved) {
            $query->whereNull('ResolvedAt');
        }

        $notificationIds = $query->pluck('id')->all();
        if (empty($notificationIds)) {
            return response()->json([
                'status' => 'ok',
                'read_count' => 0,
                'unread_count' => $this->countUnread($userId, $campusIds),
                'urgent_unread_count' => $this->countUrgentUnread($userId, $campusIds),
            ]);
        }

        $now = now();
        $existing = NotificationRead::where('UserID', $userId)
            ->whereIn('NotificationID', $notificationIds)
            ->pluck('NotificationID')
            ->all();
        $existingSet = array_flip($existing);

        NotificationRead::where('UserID', $userId)
            ->whereIn('NotificationID', $notificationIds)
            ->update(['ReadAt' => $now, 'updated_at' => $now]);

        $insertRows = [];
        foreach ($notificationIds as $id) {
            if (isset($existingSet[$id])) {
                continue;
            }
            $insertRows[] = [
                'NotificationID' => $id,
                'UserID' => $userId,
                'ReadAt' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($insertRows)) {
            DB::table('NotificationReads')->insert($insertRows);
        }

        return response()->json([
            'status' => 'ok',
            'read_count' => count($notificationIds),
            'unread_count' => $this->countUnread($userId, $campusIds),
            'urgent_unread_count' => $this->countUrgentUnread($userId, $campusIds),
        ]);
    }

    public function unreadCount(Request $request)
    {
        [$campusIds] = $this->resolveCampusScope($request);
        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $byType = $this->countUnreadByType($userId, $campusIds);

        $pendingQuery = DB::table('User')
            ->join('UserCampus', 'User.id', '=', 'UserCampus.UserID')
            ->where('User.type', 'T')
            ->where('UserCampus.Approved', false);
        if (!empty($campusIds)) {
            $pendingQuery->whereIn('UserCampus.CampusID', $campusIds);
        }
        $pendingCount = $pendingQuery->count();
        if ($pendingCount > 0) {
            $byType['pending_teachers'] = ['total' => $pendingCount, 'urgent' => 0];
        }

        return response()->json([
            'unread_count'        => $this->countUnread($userId, $campusIds),
            'urgent_unread_count' => $this->countUrgentUnread($userId, $campusIds),
            'by_type'             => $byType,
        ]);
    }

    /**
     * @return array{0: array<int>, 1: bool, 2: ?int}
     */
    private function resolveCampusScope(Request $request): array
    {
        $role = $request->attributes->get('auth_role');
        $isSuperAdmin = $role === 'super_admin';
        $campusIds = $isSuperAdmin
            ? []
            : array_map('intval', $request->attributes->get('auth_campus_ids', []));

        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId > 0) {
            if (!$isSuperAdmin && !in_array($branchId, $campusIds, true)) {
                abort(403, 'Forbidden');
            }
            $campusIds = [$branchId];
        }

        return [$campusIds, $isSuperAdmin, $branchId > 0 ? $branchId : null];
    }

    private function resolveAuthUserId(Request $request): ?int
    {
        $authUser = $request->attributes->get('auth_user');
        if ($authUser && isset($authUser->id)) {
            return (int) $authUser->id;
        }

        $headerId = (int) $request->header('X-User-Id', 0);
        return $headerId > 0 ? $headerId : null;
    }

    /**
     * @return array<int, string>
     */
    private function parseTypes($raw): array
    {
        if (!$raw) {
            return [];
        }

        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $types = array_values(array_filter(array_map(function ($v) {
            return trim((string) $v);
        }, $parts)));

        if (empty($types)) {
            return [];
        }

        return array_values(array_unique($types));
    }

    private function countUnreadByType(int $userId, array $campusIds): array
    {
        $query = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt')
            ->whereNull('nr.ReadAt')
            ->select([
                'Notifications.Type',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN Notifications.Severity = 'high' THEN 1 ELSE 0 END) as urgent"),
            ])
            ->groupBy('Notifications.Type');

        if (!empty($campusIds)) {
            $query->whereIn('Notifications.CampusID', $campusIds);
        }

        $result = [];
        foreach ($query->get() as $row) {
            $result[$row->Type] = [
                'total'  => (int) $row->total,
                'urgent' => (int) $row->urgent,
            ];
        }
        return $result;
    }

    private function countUnread(int $userId, array $campusIds): int
    {
        $query = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt')
            ->whereNull('nr.ReadAt');

        if (!empty($campusIds)) {
            $query->whereIn('Notifications.CampusID', $campusIds);
        }

        return (int) $query->count('Notifications.id');
    }

    private function countUrgentUnread(int $userId, array $campusIds): int
    {
        $query = Notification::query()
            ->leftJoin('NotificationReads as nr', function ($join) use ($userId) {
                $join->on('Notifications.id', '=', 'nr.NotificationID')
                    ->where('nr.UserID', '=', $userId);
            })
            ->whereNull('Notifications.ResolvedAt')
            ->whereNull('nr.ReadAt')
            ->where('Notifications.Severity', 'high');

        if (!empty($campusIds)) {
            $query->whereIn('Notifications.CampusID', $campusIds);
        }

        return (int) $query->count('Notifications.id');
    }

    private function findScopedNotification(int $notificationId, array $campusIds): ?Notification
    {
        $query = Notification::query()->where('id', $notificationId);
        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }

        return $query->first();
    }
}
