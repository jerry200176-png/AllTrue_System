<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
        ]);

        $input = $data['email'];
        $password = $data['password'];

        // Try email (LoginName), then username (Name)
        $user = User::where('LoginName', $input)->first()
            ?? User::where('Name', $input)->first();

        if (!$user) {
            return response()->json(['message' => '找不到此帳號，請確認姓名是否正確'], 401);
        }

        // Password check: plain text or hashed
        $valid = false;
        if (!empty($user->PSW)) {
            if (password_verify($password, $user->PSW)) {
                $valid = true;
            } elseif ($user->PSW === $password) {
                $valid = true;
            } elseif (md5($password) === $user->PSW) {
                $valid = true;
            }
        }

        if (!$valid) {
            return response()->json(['message' => '密碼錯誤'], 401);
        }

        $role = $this->resolveRole($user);

        $token = Str::random(64);
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $campusIds = UserCampus::where('UserID', $user->id)
            ->pluck('CampusID')
            ->map(fn ($id) => (int) $id)
            ->all();

        $userPayload = [
            'id'       => $user->id,
            'name'     => $user->Name,
            'email'    => $user->LoginName,
            'role'     => $role,
            'campuses' => $campusIds,
        ];

        return response()->json([
            'data' => [
                'session' => [
                    'access_token' => $token,
                    'token_type'   => 'Bearer',
                    'user'         => $userPayload,
                ],
                'user' => $userPayload,
            ],
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:64',
            'email'    => 'required|string|max:128',
            'password' => 'required|string|min:6|max:128',
            'role'     => 'nullable|string|in:teacher,director',
        ]);

        $exists = User::where('LoginName', $data['email'])->exists();
        if ($exists) {
            return response()->json(['message' => '此帳號已存在'], 409);
        }

        $type = match ($data['role'] ?? 'teacher') {
            'director' => 'D',
            default    => 'T',
        };

        $user = User::create([
            'LoginName' => $data['email'],
            'Name'      => $data['name'],
            'PSW'       => password_hash($data['password'], PASSWORD_DEFAULT),
            'type'      => $type,
        ]);

        return response()->json(['message' => '帳號建立成功', 'user_id' => $user->id], 201);
    }

    public function me(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $role = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        return response()->json([
            'id'       => $user->id,
            'name'     => $user->Name,
            'email'    => $user->LoginName,
            'role'     => $role,
            'campuses' => $campusIds,
        ]);
    }

    public function updateMe(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'name'     => 'nullable|string|max:64',
            'password' => 'nullable|string|min:6|max:128',
        ]);

        if (!empty($data['name'])) {
            $user->Name = $data['name'];
        }
        if (!empty($data['password'])) {
            $user->PSW = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $user->save();

        return response()->json(['message' => '已更新']);
    }

    private function resolveRole(User $user): string
    {
        return match ($user->type) {
            'S' => 'super_admin',
            'T' => 'teacher',
            'U' => 'pending',
            default => 'director',
        };
    }
}
