<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

class AttachAuthUser
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $userId = $request->header('X-User-Id');
            $roleHeader = $request->header('X-User-Role');
            $teacherHeader = $request->header('X-Teacher-Id');

            // ── Resolve user from Bearer token if no X-User-Id header ───
            if (!$userId) {
                $bearer = $request->bearerToken();
                $authToken = $bearer ? AuthToken::where('token', $bearer)->first() : null;
                if ($authToken) {
                    // Check token expiration
                    if ($authToken->expires_at && Carbon::parse($authToken->expires_at)->isPast()) {
                        $authToken->delete();
                        return response()->json(['message' => 'Token expired'], 401);
                    }
                    $userId = $authToken->user_id;
                }
            }

            if ($userId) {
                $user = User::find($userId);
                if (!$user) {
                    return response()->json(['message' => 'Invalid user'], 401);
                }

                $role = $this->resolveRole($user, $roleHeader);
                $teacherId = $teacherHeader ?: ($role === 'teacher' ? $user->id : null);
                $campusIds = UserCampus::where('UserID', $user->id)
                    ->where(function ($q) {
                        $q->where('Approved', true)->orWhereNull('Approved');
                    })
                    ->pluck('CampusID')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $request->attributes->set('auth_user', $user);
                $request->attributes->set('auth_role', $role);
                $request->attributes->set('auth_teacher_id', $teacherId ? (int) $teacherId : null);
                $request->attributes->set('auth_campus_ids', $campusIds);
                $request->attributes->set('auth_has_campus', !empty($campusIds));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[AttachAuthUser] ' . $e->getMessage());
            // Continue without auth for public routes (e.g. /branches)
        }

        return $next($request);
    }

    private function resolveRole(User $user, ?string $roleHeader): string
    {
        // Super admin is resolved strictly from DB type — cannot be overridden by header
        if ($user->type === 'S') {
            return 'super_admin';
        }

        if ($roleHeader && in_array($roleHeader, ['director', 'teacher'], true)) {
            return $roleHeader;
        }

        return match ($user->type) {
            'T' => 'teacher',
            'U' => 'pending',
            default => 'director',
        };
    }
}

