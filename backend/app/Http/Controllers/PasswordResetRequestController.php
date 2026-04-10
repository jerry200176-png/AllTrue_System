<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Http\Request;

class PasswordResetRequestController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password
     * Public endpoint. Always returns generic success message.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'account' => 'required|string|max:128',
            'role' => 'nullable|string|in:teacher,director,super_admin',
            'note' => 'nullable|string|max:255',
        ]);

        $account = trim((string) $data['account']);
        $role = $data['role'] ?? null;

        $existingPending = PasswordResetRequest::query()
            ->where('account_input', $account)
            ->where('status', 'pending')
            ->when(
                $role !== null,
                fn ($query) => $query->where('role_input', $role),
                fn ($query) => $query->whereNull('role_input')
            )
            ->where('created_at', '>=', now()->subMinutes(15))
            ->first();

        if (!$existingPending) {
            $user = $this->resolveUserByAccountAndRole($account, $role);

            PasswordResetRequest::create([
                'user_id' => $user?->id,
                'account_input' => $account,
                'role_input' => $role,
                'status' => 'pending',
                'requested_ip' => $request->ip(),
                'request_note' => !empty($data['note']) ? trim((string) $data['note']) : null,
            ]);
        }

        return response()->json([
            'message' => '已收到重設密碼申請，請聯繫主任或管理員協助處理',
        ]);
    }

    private function resolveUserByAccountAndRole(string $account, ?string $role): ?User
    {
        $typeFilter = match ($role) {
            'teacher' => 'T',
            'director' => 'D',
            'super_admin' => 'S',
            default => null,
        };

        return User::query()
            ->where(function ($query) use ($account) {
                $query->where('LoginName', $account)
                    ->orWhere('Name', $account);
            })
            ->when($typeFilter !== null, fn ($query) => $query->where('type', $typeFilter))
            ->orderBy('id')
            ->first();
    }
}
