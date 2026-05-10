<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserLoginActivity;
use App\Models\UserNotificationPreference;
use App\Models\UserCampus;
use App\Support\PublicAvatarUrl;
use App\Support\UserEngagementPresenter;
use App\Services\TeacherScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_DECAY_SECONDS = 900; // 15 minutes

    public function login(Request $request)
    {
        $data = $request->validate([
            'account'  => 'nullable|string|max:128|required_without:email',
            'email'    => 'nullable|string|max:128|required_without:account',
            'password' => 'required|string',
            'role'     => 'nullable|string|in:teacher,director,super_admin',
        ]);

        $input = trim((string) ($data['account'] ?? $data['email'] ?? ''));
        if ($input === '') {
            return response()->json(['message' => '帳號不可空白'], 422);
        }
        $password = $data['password'];
        $throttleKey = $this->loginThrottleKey($request, $input, $data['role'] ?? null);

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            return $this->lockedLoginResponse($throttleKey);
        }

        $typeFilter = $this->typeFromRole($data['role'] ?? null);

        $users = User::query()
            ->where(function ($query) use ($input) {
                $query->where('LoginName', $input)
                    ->orWhere('Name', $input);
            })
            // Block merged/disabled accounts. Pending teachers authenticate only after director approval (see below).
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['inactive', 'suspended']);
            })
            ->when($typeFilter !== null, fn ($query) => $query->where('type', $typeFilter))
            ->get();

        if ($users->isEmpty()) {
            return $this->invalidLoginResponse($throttleKey);
        }

        // Support duplicate LoginName across roles (teacher/director).
        // We must test password against all candidates instead of only the first record.
        $matchingUsers = $users
            ->filter(fn (User $candidate) => $this->passwordMatches($candidate, $password))
            ->sortBy(fn (User $candidate) => $this->loginTypePriority($candidate->type))
            ->values();

        if ($matchingUsers->isEmpty()) {
            return $this->invalidLoginResponse($throttleKey);
        }

        $user = $matchingUsers->first();
        if ($this->teacherAwaitingDirectorApproval($user)) {
            return response()->json([
                'message' => '此帳號尚未通過主任審核，請聯繫分校主任完成審核後再行登入。',
                'code' => 'teacher_pending_approval',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $role = $this->resolveRole($user);

        $this->revokeSameDeviceActiveTokens((int) $user->id, (string) $request->userAgent());

        $token = Str::random(64);
        $authToken = AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => Carbon::now()->addDays(7),
        ]);
        $this->recordLoginActivity($request, $user->id, true, $authToken->id);

        $campusIds = UserCampus::where('UserID', $user->id)
            ->pluck('CampusID')
            ->map(fn ($id) => (int) $id)
            ->all();
        $mustChangePassword = Schema::hasColumn('User', 'MustChangePassword')
            ? (bool) ($user->MustChangePassword ?? false)
            : false;

        $userPayload = [
            'id'       => $user->id,
            'name'     => $user->Name,
            'account'  => $user->LoginName,
            'email'    => $user->LoginName,
            'role'     => $role,
            'campuses' => $campusIds,
            'must_change_password' => $mustChangePassword,
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
            'name'      => 'required|string|max:64',
            'account'   => 'nullable|string|max:128|required_without:email',
            'email'     => 'nullable|string|max:128|required_without:account',
            'password'  => 'required|string|min:8|max:128',
            'role'      => 'nullable|string|in:teacher,director',
            'branch_id' => 'nullable|integer',
            'phone'     => 'nullable|string|max:32',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer|min:1',
            'subject_level_scopes' => 'nullable|array',
            'subject_level_scopes.*.subject_id' => 'required_with:subject_level_scopes|integer|min:1',
            'subject_level_scopes.*.level' => 'required_with:subject_level_scopes|string|in:elementary,junior,high',
            'multi_branches' => 'nullable|array',
            'multi_branches.*' => 'integer|min:1',
        ]);
        $loginName = trim((string) ($data['account'] ?? $data['email'] ?? ''));
        if ($loginName === '') {
            return response()->json(['message' => '帳號不可空白'], 422);
        }

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($branchId !== null && !Campus::where('id', $branchId)->exists()) {
            return response()->json(['message' => '分校不存在'], 422);
        }

        $multiBranchIds = array_values(array_unique(array_map(
            'intval',
            (array) ($data['multi_branches'] ?? [])
        )));
        $multiBranchIds = array_values(array_filter($multiBranchIds, static fn (int $id) => $id > 0));
        foreach ($multiBranchIds as $mid) {
            if (!Campus::where('id', $mid)->exists()) {
                return response()->json(['message' => '跨校分校不存在'], 422);
            }
        }

        $type = match ($data['role'] ?? 'teacher') {
            'director' => 'D',
            default    => 'T',
        };

        $existingSameRole = User::where('LoginName', $loginName)->first();
        if ($existingSameRole) {
            return response()->json([
                'message' => '此帳號已存在，請直接登入',
                'user_id' => $existingSameRole->id,
                'already_exists' => true,
            ]);
        }

        $existsConflict = User::where('LoginName', $loginName)
            ->whereIn('type', $this->conflictingTypesForLoginName($type))
            ->exists();
        if ($existsConflict) {
            return response()->json(['message' => '此帳號已存在'], 409);
        }

        $normalizedPhone = $this->normalizePhone($data['phone'] ?? null);

        $initialStatus = $type === 'T' ? 'pending' : 'active';

        $user = DB::transaction(function () use ($data, $type, $normalizedPhone, $branchId, $loginName, $initialStatus, $multiBranchIds) {
            $user = User::create([
                'LoginName' => $loginName,
                'Name'      => $data['name'],
                'PSW'       => password_hash($data['password'], PASSWORD_DEFAULT),
                'type'      => $type,
                'status'    => $initialStatus,
                'phone'     => $normalizedPhone,
            ]);

            $allBranches = [];
            if ($branchId !== null) {
                $allBranches[] = (int) $branchId;
            }
            if ($type === 'T') {
                foreach ($multiBranchIds as $extraId) {
                    if (!in_array($extraId, $allBranches, true)) {
                        $allBranches[] = $extraId;
                    }
                }
            }

            foreach ($allBranches as $campusId) {
                UserCampus::create([
                    'CampusID' => $campusId,
                    'UserID'   => $user->id,
                    'Admin'    => 0,
                    'Approved' => false,
                ]);
            }

            if ($type === 'T') {
                if (Schema::hasTable('Teacher') && !DB::table('Teacher')->where('id', $user->id)->exists()) {
                    DB::table('Teacher')->insertOrIgnore([
                        'id' => $user->id,
                        'T_Name' => $user->Name,
                        'CampusID' => $branchId ?? 0,
                        'Enable' => 1,
                        'MDT' => now(),
                        'TelegramID' => '',
                        'Phone' => $normalizedPhone,
                        'LineID' => '',
                    ]);
                }
                if (Schema::hasTable('teacher_branches') && !empty($allBranches)) {
                    foreach ($allBranches as $campusId) {
                        DB::table('teacher_branches')->insertOrIgnore([
                            'teacher_id' => $user->id,
                            'branch_id' => $campusId,
                        ]);
                    }
                }
                if (Schema::hasTable('teacher_subjects')) {
                    foreach (array_values(array_unique(array_map('intval', (array) ($data['subject_ids'] ?? [])))) as $subjectId) {
                        if ($subjectId > 0) {
                            DB::table('teacher_subjects')->insertOrIgnore([
                                'teacher_id' => $user->id,
                                'subject_id' => $subjectId,
                            ]);
                        }
                    }
                }
                if (array_key_exists('subject_level_scopes', $data)) {
                    TeacherScopeService::replaceScopes($user->id, (array) ($data['subject_level_scopes'] ?? []));
                }
            }

            return $user;
        });

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
        $notificationPreferences = $this->getOrCreateNotificationPreferences((int) $user->id);
        $securitySummary = $this->buildSecuritySummary($request, (int) $user->id);
        $mustChangePassword = Schema::hasColumn('User', 'MustChangePassword')
            ? (bool) ($user->MustChangePassword ?? false)
            : false;
        $teacherConfig = $this->teacherConfigForResponse($user);
        $payload = [
            'id'       => $user->id,
            'name'     => $user->Name,
            'account'  => $user->LoginName,
            'email'    => $user->LoginName,
            'phone'    => $this->resolvePhoneForResponse($user) ?? '',
            'avatar_url' => $this->toAvatarUrl($user->AvatarUrl ?? null),
            'role'     => $role,
            'campuses' => $campusIds,
            'must_change_password' => $mustChangePassword,
            'notification_preferences' => $notificationPreferences,
            'security_summary' => $securitySummary,
            'teacher_config' => $teacherConfig,
        ];
        $engagement = UserEngagementPresenter::forMe($user, (string) $role);
        if ($engagement !== null) {
            $payload['engagement'] = $engagement;
        }

        return response()->json($payload);
    }

    public function updateMe(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'name'     => 'nullable|string|max:64',
            'account'  => 'nullable|string|max:128',
            'email'    => 'nullable|string|max:128',
            'phone'    => 'nullable|string|max:32',
            'avatar_url' => 'nullable|string|max:255',
            'branch_id' => 'nullable|integer|min:1',
            'multi_branches' => 'nullable|array',
            'multi_branches.*' => 'integer|min:1',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer|min:1',
            'subject_level_scopes' => 'nullable|array',
            'subject_level_scopes.*.subject_id' => 'required_with:subject_level_scopes|integer|min:1',
            'subject_level_scopes.*.level' => 'required_with:subject_level_scopes|string|in:elementary,junior,high',
            'current_password' => 'nullable|string|max:128',
            'password' => 'nullable|string|min:8|max:128|confirmed',
        ]);

        if (!empty($data['name'])) {
            $user->Name = $data['name'];
        }
        $newLoginName = null;
        if (!empty($data['account'])) {
            $newLoginName = trim((string) $data['account']);
        } elseif (!empty($data['email'])) {
            $newLoginName = trim((string) $data['email']);
        }
        if (!empty($newLoginName)) {
            $blockedTypes = $this->conflictingTypesForLoginName((string) $user->type);
            $exists = User::where('LoginName', $newLoginName)
                ->where('id', '!=', $user->id)
                ->whereIn('type', $blockedTypes)
                ->exists();
            if ($exists) {
                return response()->json(['message' => '此帳號已存在'], 409);
            }
            $user->LoginName = $newLoginName;
        }
        $normalizedPhone = null;
        $hasPhoneInput = array_key_exists('phone', $data);
        if ($hasPhoneInput) {
            $normalizedPhone = $this->normalizePhone($data['phone'] ?? null);
        }
        if ($hasPhoneInput && Schema::hasColumn('User', 'phone')) {
            $user->phone = $normalizedPhone;
        }
        if (array_key_exists('avatar_url', $data) && Schema::hasColumn('User', 'AvatarUrl')) {
            $user->AvatarUrl = $data['avatar_url'] ?: null;
        }
        $passwordChanged = false;
        if (!empty($data['password'])) {
            if (empty($data['current_password'])) {
                return response()->json(['message' => '修改密碼需先輸入目前密碼'], 422);
            }
            if (!$this->passwordMatches($user, (string) $data['current_password'])) {
                return response()->json(['message' => '目前密碼錯誤'], 422);
            }
            $user->PSW = password_hash($data['password'], PASSWORD_DEFAULT);
            if (Schema::hasColumn('User', 'MustChangePassword')) {
                $user->MustChangePassword = false;
            }
            if (Schema::hasColumn('User', 'PasswordChangedAt')) {
                $user->PasswordChangedAt = now();
            }
            if (Schema::hasColumn('User', 'PasswordSetByUserID')) {
                $user->PasswordSetByUserID = $user->id;
            }
            $passwordChanged = true;
        }
        $user->save();

        if ($user->type === 'T' && Schema::hasTable('Teacher')) {
            $teacherUpdates = [];
            if (!empty($data['name']) && Schema::hasColumn('Teacher', 'T_Name')) {
                $teacherUpdates['T_Name'] = $data['name'];
            }
            if ($hasPhoneInput && Schema::hasColumn('Teacher', 'Phone')) {
                $teacherUpdates['Phone'] = $normalizedPhone;
            }
            if (!empty($teacherUpdates)) {
                DB::table('Teacher')->where('id', $user->id)->update($teacherUpdates);
            }

            if (array_key_exists('branch_id', $data) || array_key_exists('multi_branches', $data)) {
                $mainBranchId = isset($data['branch_id'])
                    ? (int) $data['branch_id']
                    : (int) (DB::table('UserCampus')->where('UserID', $user->id)->value('CampusID') ?? 0);
                if ($mainBranchId <= 0) {
                    return response()->json(['message' => '請提供有效主分校'], 422);
                }
                $multiBranches = is_array($data['multi_branches'] ?? null)
                    ? array_values(array_unique(array_map('intval', $data['multi_branches'] ?? [])))
                    : [];
                $allBranchIds = array_values(array_filter(array_unique(array_merge([$mainBranchId], $multiBranches)), fn ($id) => $id > 0));

                if (!empty($allBranchIds)) {
                    $existing = Campus::query()->whereIn('id', $allBranchIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $missing = array_values(array_diff($allBranchIds, $existing));
                    if (!empty($missing)) {
                        return response()->json(['message' => '部分分校不存在'], 422);
                    }
                    DB::table('UserCampus')->where('UserID', $user->id)->delete();
                    foreach ($allBranchIds as $campusId) {
                        $insert = ['UserID' => $user->id, 'CampusID' => $campusId];
                        if (Schema::hasColumn('UserCampus', 'Approved')) {
                            $insert['Approved'] = true;
                        }
                        DB::table('UserCampus')->insert($insert);
                    }
                    if (Schema::hasTable('teacher_branches')) {
                        DB::table('teacher_branches')->where('teacher_id', $user->id)->delete();
                        foreach ($allBranchIds as $campusId) {
                            DB::table('teacher_branches')->insertOrIgnore([
                                'teacher_id' => $user->id,
                                'branch_id' => $campusId,
                            ]);
                        }
                    }
                    if (Schema::hasTable('Teacher') && Schema::hasColumn('Teacher', 'CampusID') && $mainBranchId > 0) {
                        DB::table('Teacher')->where('id', $user->id)->update(['CampusID' => $mainBranchId]);
                    }
                }
            }

            if (array_key_exists('subject_ids', $data) && Schema::hasTable('teacher_subjects')) {
                DB::table('teacher_subjects')->where('teacher_id', $user->id)->delete();
                $subjectIds = array_values(array_unique(array_map('intval', (array) ($data['subject_ids'] ?? []))));
                foreach ($subjectIds as $subjectId) {
                    if ($subjectId > 0) {
                        DB::table('teacher_subjects')->insertOrIgnore([
                            'teacher_id' => $user->id,
                            'subject_id' => $subjectId,
                        ]);
                    }
                }
            }

            if (array_key_exists('subject_level_scopes', $data)) {
                TeacherScopeService::replaceScopes($user->id, (array) ($data['subject_level_scopes'] ?? []));
            }
        }

        // After password change: revoke ALL existing tokens to force re-login on all devices,
        // then issue a fresh token for the current session so the user stays logged in.
        $newToken = null;
        if ($passwordChanged && Schema::hasTable('auth_tokens')) {
            AuthToken::where('user_id', $user->id)->delete();
            $raw = Str::random(64);
            AuthToken::create([
                'user_id'    => $user->id,
                'token'      => $raw,
                'expires_at' => Carbon::now()->addDays(7),
            ]);
            $newToken = $raw;
        }

        $user = User::find($user->id);
        return response()->json([
            'message' => '已更新',
            'id'      => $user->id,
            'name'    => $user->Name,
            'account' => $user->LoginName,
            'email'   => $user->LoginName,
            'phone'   => $this->resolvePhoneForResponse($user) ?? '',
            'avatar_url' => $this->toAvatarUrl($user->AvatarUrl ?? null),
            'must_change_password' => Schema::hasColumn('User', 'MustChangePassword')
                ? (bool) ($user->MustChangePassword ?? false)
                : false,
            'teacher_config' => $this->teacherConfigForResponse($user),
            'new_token' => $newToken,
        ]);
    }

    public function uploadAvatar(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!Schema::hasColumn('User', 'AvatarUrl')) {
            return response()->json(['message' => '目前系統尚未啟用頭像欄位'], 422);
        }

        // PHP upload errors surface as Laravel rule "uploaded" → raw key "validation.uploaded" without this.
        if ($request->hasFile('avatar')) {
            $uploaded = $request->file('avatar');
            if (!$uploaded->isValid()) {
                $code = $uploaded->getError();
                $hint = '（管理員請將 PHP 的 upload_max_filesize、post_max_size 調為至少 16M，nginx 則 client_max_body_size。）';
                $msg = match ($code) {
                    \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE =>
                        '檔案超過伺服器允許大小，請改選較小的照片後再試。' . $hint,
                    \UPLOAD_ERR_PARTIAL => '上傳未完成（網路中斷），請再試一次。',
                    \UPLOAD_ERR_NO_TMP_DIR => '伺服器暫存目錄設定異常，請聯絡管理員。',
                    \UPLOAD_ERR_CANT_WRITE => '伺服器無法寫入暫存檔，請聯絡管理員。',
                    \UPLOAD_ERR_EXTENSION => '伺服器阻擋了此類上傳，請聯絡管理員。',
                    default => '檔案未成功上傳，請再試或改選較小的圖片。' . $hint,
                };
                return response()->json([
                    'message' => $msg,
                    'errors' => ['avatar' => [$msg]],
                ], 422);
            }
        }

        // max is kilobytes (8192 ≈ 8MB). Many phone photos exceed legacy 2048 (2MB) and caused 422.
        $data = $request->validate(
            [
                'avatar' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192',
            ],
            [
                'avatar.required' => '請選擇圖片檔。',
                'avatar.uploaded' => '檔案未完整上傳，常見原因是超過 PHP 大小限制；請改選較小圖片，或請管理員調高 upload_max_filesize／post_max_size（建議 ≥16M）。',
                'avatar.image' => '請上傳有效的圖片（JPEG、PNG 或 WebP）。',
                'avatar.mimes' => '僅支援 JPEG、PNG、WebP。iPhone「HEIC」請在相機改為「最相容」或先轉成 JPEG 再上傳。',
                'avatar.max' => '圖檔請勿超過 8MB，請縮小或改選較小的照片。',
            ]
        );

        $file = $data['avatar'];
        $folder = "avatars/{$user->id}";
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = match ($file->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
        }
        $fileName = 'avatar.' . $ext;
        try {
            $path = Storage::disk('public')->putFileAs($folder, $file, $fileName);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[Avatar] Storage failed: ' . $e->getMessage(), ['user_id' => $user->id]);
            return response()->json(['message' => '頭像儲存失敗，請稍後再試或聯絡管理員。'], 500);
        }
        if ($path === false || $path === '') {
            return response()->json(['message' => '無法寫入儲存空間，請確認伺服器 storage 權限或聯絡管理員。'], 500);
        }

        $oldAvatar = $user->AvatarUrl ?? null;
        $oldPath = self::avatarStoredPathForDisk($oldAvatar);
        if ($oldPath && $oldPath !== $path && Storage::disk('public')->exists($oldPath)) {
            try {
                Storage::disk('public')->delete($oldPath);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Avatar] Delete old file failed: '.$e->getMessage(), [
                    'user_id' => $user->id,
                    'old_path' => $oldPath,
                ]);
            }
        }

        // Store disk-relative path only — never APP_URL-prefixed URLs (breaks LAN / alternate host).
        $user->AvatarUrl = $path;
        $user->save();

        return response()->json([
            'message' => '頭像上傳成功',
            'avatar_url' => $this->toAvatarUrl($user->AvatarUrl),
        ]);
    }

    public function notificationPreferences(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'data' => $this->getOrCreateNotificationPreferences((int) $user->id),
        ]);
    }

    public function updateNotificationPreferences(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!Schema::hasTable('user_notification_preferences')) {
            return response()->json(['message' => '目前系統尚未啟用通知偏好設定'], 422);
        }

        $data = $request->validate([
            'in_app_enabled' => 'nullable|boolean',
            'email_enabled' => 'nullable|boolean',
            'line_enabled' => 'nullable|boolean',
            'quiet_hours_start' => 'nullable|string|max:5',
            'quiet_hours_end' => 'nullable|string|max:5',
            'event_tuition' => 'nullable|boolean',
            'event_learning_review' => 'nullable|boolean',
            'event_attendance' => 'nullable|boolean',
            'event_system' => 'nullable|boolean',
        ]);

        $defaults = $this->getOrCreateNotificationPreferences((int) $user->id);
        $pref = UserNotificationPreference::firstOrNew(['user_id' => (int) $user->id]);
        $pref->fill(array_merge($defaults, $data, ['user_id' => (int) $user->id]));
        $pref->save();

        return response()->json([
            'message' => '通知偏好已更新',
            'data' => $this->getOrCreateNotificationPreferences((int) $user->id),
        ]);
    }

    public function security(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'data' => $this->buildSecuritySummary($request, (int) $user->id),
        ]);
    }

    public function logoutOtherSessions(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!Schema::hasTable('auth_tokens')) {
            return response()->json([
                'message' => '目前系統尚未啟用登入 token 管理',
                'revoked_count' => 0,
            ]);
        }

        $currentTokenId = null;
        $bearer = $request->bearerToken();
        if ($bearer) {
            $currentTokenId = (int) (AuthToken::where('token', $bearer)->value('id') ?? 0);
        }

        $query = AuthToken::query()->where('user_id', (int) $user->id);
        if ($currentTokenId > 0) {
            $query->where('id', '!=', $currentTokenId);
        }

        $revokedCount = (int) $query->count();
        $query->delete();

        return response()->json([
            'message' => '已登出其他裝置',
            'revoked_count' => $revokedCount,
        ]);
    }

    private function getOrCreateNotificationPreferences(int $userId): array
    {
        $defaults = [
            'in_app_enabled' => true,
            'email_enabled' => false,
            'line_enabled' => false,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '08:00',
            'event_tuition' => true,
            'event_learning_review' => true,
            'event_attendance' => true,
            'event_system' => true,
        ];

        if (!Schema::hasTable('user_notification_preferences')) {
            return $defaults;
        }

        $pref = UserNotificationPreference::firstOrCreate(
            ['user_id' => $userId],
            array_merge(['user_id' => $userId], $defaults)
        );

        return [
            'in_app_enabled' => (bool) $pref->in_app_enabled,
            'email_enabled' => (bool) $pref->email_enabled,
            'line_enabled' => (bool) $pref->line_enabled,
            'quiet_hours_start' => $pref->quiet_hours_start ?: null,
            'quiet_hours_end' => $pref->quiet_hours_end ?: null,
            'event_tuition' => (bool) $pref->event_tuition,
            'event_learning_review' => (bool) $pref->event_learning_review,
            'event_attendance' => (bool) $pref->event_attendance,
            'event_system' => (bool) $pref->event_system,
        ];
    }

    private function buildSecuritySummary(Request $request, int $userId): array
    {
        $recentLogins = [];
        if (Schema::hasTable('user_login_activities')) {
            $recentLogins = UserLoginActivity::query()
                ->where('user_id', $userId)
                ->orderByDesc('login_at')
                ->limit(10)
                ->get(['id', 'login_at', 'ip_address', 'user_agent', 'device_label', 'success'])
                ->map(function ($item) {
                    return [
                        'id' => (int) $item->id,
                        'login_at' => optional($item->login_at)->toIso8601String(),
                        'ip_address' => $item->ip_address,
                        'user_agent' => $item->user_agent,
                        'device_label' => $item->device_label,
                        'success' => (bool) $item->success,
                    ];
                })
                ->values()
                ->all();
        }

        $currentTokenId = null;
        $bearer = $request->bearerToken();
        if ($bearer && Schema::hasTable('auth_tokens')) {
            $currentTokenId = AuthToken::where('token', $bearer)->value('id');
        }

        $activeSessions = [];
        if (Schema::hasTable('auth_tokens')) {
            $tokens = AuthToken::query()
                ->where('user_id', $userId)
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                })
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'created_at', 'expires_at']);

            $activityByToken = collect();
            if (Schema::hasTable('user_login_activities') && $tokens->isNotEmpty()) {
                $activityRows = UserLoginActivity::query()
                    ->whereIn('auth_token_id', $tokens->pluck('id')->all())
                    ->orderByDesc('login_at')
                    ->get(['auth_token_id', 'device_label']);
                foreach ($activityRows as $row) {
                    if (!$activityByToken->has($row->auth_token_id)) {
                        $activityByToken->put($row->auth_token_id, $row->device_label);
                    }
                }
            }

            $activeSessions = $tokens->map(function ($token) use ($activityByToken, $currentTokenId, $request) {
                $deviceLabel = $activityByToken->get($token->id);
                if (!$deviceLabel && $currentTokenId && (int) $token->id === (int) $currentTokenId) {
                    $deviceLabel = $this->guessDeviceLabel($request->userAgent());
                }
                return [
                    'token_id' => (int) $token->id,
                    'device_label' => $deviceLabel ?: 'Unknown Device',
                    'created_at' => $token->created_at ? Carbon::parse($token->created_at)->toIso8601String() : null,
                    'expires_at' => $token->expires_at ? Carbon::parse($token->expires_at)->toIso8601String() : null,
                    'is_current' => $currentTokenId ? ((int) $token->id === (int) $currentTokenId) : false,
                ];
            })->values()->all();
        }

        return [
            'recent_logins' => $recentLogins,
            'active_sessions' => $activeSessions,
        ];
    }

    private function toAvatarUrl(?string $avatar): ?string
    {
        $url = PublicAvatarUrl::forBrowser($avatar);
        if ($url === null || $url === '') {
            return null;
        }
        // Same path after re-upload (e.g. avatars/1/avatar.jpg) must change in the browser; append file mtime.
        $diskPath = self::avatarStoredPathForDisk($avatar);
        try {
            if ($diskPath !== null && $diskPath !== '' && Storage::disk('public')->exists($diskPath)) {
                $v = Storage::disk('public')->lastModified($diskPath);
                $sep = str_contains($url, '?') ? '&' : '?';

                return $url.$sep.'v='.$v;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[toAvatarUrl] storage disk not accessible', [
                'disk_path' => $diskPath,
                'error'     => $e->getMessage(),
            ]);
            // fall through: return URL without version param
        }

        return $url;
    }

    /**
     * Path under storage/app/public for delete/compare (avatars/1/avatar.jpg).
     */
    private static function avatarStoredPathForDisk(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $s = trim($stored);
        if (str_starts_with($s, '/storage/')) {
            return substr($s, strlen('/storage/')) ?: null;
        }
        if (str_starts_with($s, 'http://') || str_starts_with($s, 'https://')) {
            if (preg_match('#/storage/([^?\s#]+)#', $s, $m)) {
                return urldecode($m[1]) ?: null;
            }

            return null;
        }
        if (str_contains($s, '://')) {
            return null;
        }

        return ltrim($s, '/') ?: null;
    }

    private function normalizePhone($raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($raw ?? ''));
        return $digits !== '' ? $digits : null;
    }

    private function teacherConfigForResponse(User $user): ?array
    {
        if ((string) $user->type !== 'T') {
            return null;
        }

        $campusRows = DB::table('UserCampus')->where('UserID', $user->id)->get();
        $branchIds = $campusRows->pluck('CampusID')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $branchId = !empty($branchIds) ? (int) ($branchIds[0] ?? 0) : null;

        $subjectIds = [];
        $subjectNames = [];
        if (Schema::hasTable('teacher_subjects')) {
            $subjectIds = DB::table('teacher_subjects')
                ->where('teacher_id', $user->id)
                ->pluck('subject_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            if (!empty($subjectIds)) {
                $subjectNames = DB::table('Subject')
                    ->whereIn('id', $subjectIds)
                    ->pluck('Subject_Name', 'id')
                    ->all();
            }
        }

        $scopes = TeacherScopeService::getScopesForTeachers([$user->id]);

        return [
            'branch_id' => $branchId,
            'branch_ids' => $branchIds,
            'subject_ids' => $subjectIds,
            'subject_names' => array_values(array_map(fn ($sid) => $subjectNames[$sid] ?? (string) $sid, $subjectIds)),
            'subject_level_scopes' => $scopes[$user->id] ?? [],
        ];
    }

    private function resolvePhoneForResponse(User $user): ?string
    {
        if ((string) $user->type === 'T' && Schema::hasTable('Teacher') && Schema::hasColumn('Teacher', 'Phone')) {
            $teacherPhone = DB::table('Teacher')->where('id', $user->id)->value('Phone');
            if ($teacherPhone !== null && trim((string) $teacherPhone) !== '') {
                return (string) $teacherPhone;
            }
        }

        return $this->normalizePhone($user->phone ?? null);
    }

    private function guessDeviceLabel(?string $userAgent): string
    {
        $ua = mb_strtolower((string) $userAgent);
        if ($ua === '') {
            return 'Unknown Device';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            return 'iOS Device';
        }
        if (str_contains($ua, 'android')) {
            return 'Android Device';
        }
        if (str_contains($ua, 'windows')) {
            return 'Windows Browser';
        }
        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os')) {
            return 'Mac Browser';
        }
        if (str_contains($ua, 'linux')) {
            return 'Linux Browser';
        }
        return 'Web Browser';
    }

    private function recordLoginActivity(Request $request, int $userId, bool $success, ?int $authTokenId = null): void
    {
        if (!Schema::hasTable('user_login_activities')) {
            return;
        }

        try {
            UserLoginActivity::create([
                'user_id' => $userId,
                'login_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'device_label' => $this->guessDeviceLabel($request->userAgent()),
                'success' => $success,
                'auth_token_id' => $authTokenId,
            ]);
        } catch (\Throwable $e) {
            // No-op: login should not fail because of activity logging.
        }
    }

    private function revokeSameDeviceActiveTokens(int $userId, string $userAgent): void
    {
        if ($userId <= 0 || !Schema::hasTable('auth_tokens') || !Schema::hasTable('user_login_activities')) {
            return;
        }

        $ua = trim($userAgent);
        $deviceLabel = $this->guessDeviceLabel($ua);

        $tokenIds = UserLoginActivity::query()
            ->where('user_id', $userId)
            ->where('success', true)
            ->whereNotNull('auth_token_id')
            ->where(function ($query) use ($ua, $deviceLabel) {
                if ($ua !== '') {
                    $query->where('user_agent', $ua);
                } elseif ($deviceLabel !== 'Unknown Device') {
                    $query->where('device_label', $deviceLabel);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('login_at')
            ->limit(50)
            ->pluck('auth_token_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($tokenIds)) {
            return;
        }

        AuthToken::query()
            ->where('user_id', $userId)
            ->whereIn('id', $tokenIds)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->delete();
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

    private function passwordMatches(User $user, string $password): bool
    {
        if (empty($user->PSW)) {
            return false;
        }

        return password_verify($password, $user->PSW);
    }

    private function loginTypePriority(?string $type): int
    {
        return match ($type) {
            'S', 'A' => 0,
            'D' => 1,
            'T' => 2,
            'U' => 3,
            default => 4,
        };
    }

    private function typeFromRole(?string $role): ?string
    {
        return match ($role) {
            'teacher' => 'T',
            'director' => 'D',
            'super_admin' => 'S',
            default => null,
        };
    }

    private function conflictingTypesForLoginName(string $type): array
    {
        return match ($type) {
            // teacher/director can share login account with each other only
            'T' => ['T', 'S', 'A', 'U'],
            'D' => ['D', 'S', 'A', 'U'],
            default => ['T', 'D', 'S', 'A', 'U'],
        };
    }

    /**
     * Self-registered teachers get UserCampus rows with Approved=false until a director approves.
     * If the DB has no Approved column (legacy), fall back to User.status === pending.
     */
    private function teacherAwaitingDirectorApproval(User $user): bool
    {
        if ($user->type !== 'T') {
            return false;
        }
        if (!Schema::hasColumn('UserCampus', 'Approved')) {
            return Schema::hasColumn('User', 'status') && ($user->status ?? '') === 'pending';
        }

        $hasReleasedCampus = UserCampus::query()
            ->where('UserID', $user->id)
            ->where(function ($q) {
                $q->where('Approved', true)->orWhereNull('Approved');
            })
            ->exists();

        return !$hasReleasedCampus;
    }

    private function loginThrottleKey(Request $request, string $account, ?string $role): string
    {
        return implode('|', [
            'login',
            mb_strtolower(trim($account)),
            $role ?: 'any',
            $request->ip(),
        ]);
    }

    private function invalidLoginResponse(string $throttleKey)
    {
        $attempts = RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);
        if ($attempts >= self::LOGIN_MAX_ATTEMPTS) {
            return $this->lockedLoginResponse($throttleKey);
        }

        return response()->json([
            'message' => '帳號或密碼錯誤',
            'remaining_attempts' => max(0, self::LOGIN_MAX_ATTEMPTS - $attempts),
        ], 401);
    }

    private function lockedLoginResponse(string $throttleKey)
    {
        $retryAfter = RateLimiter::availableIn($throttleKey);

        return response()->json([
            'message' => '登入錯誤次數過多，請稍後再試',
            'retry_after_seconds' => $retryAfter,
            'locked_until' => Carbon::now()->addSeconds($retryAfter)->toIso8601String(),
        ], 429);
    }
}
